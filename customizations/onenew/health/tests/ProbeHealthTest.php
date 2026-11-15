<?php

namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Onenew\ProbeOutboundBuilder;
use App\Onenew\ProbeService;
use App\Onenew\ProbeStore;
use App\Onenew\ProbeUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProbeHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis.driver', 'array');
        Cache::purge('redis');
        $this->app->forgetInstance(\App\Support\Setting::class);
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('h', 32)));
        config()->set('onenew.health_store', 'array');
        Cache::store('array')->flush();
    }

    public function test_bearer_machine_authentication_rejects_wrong_and_disabled_tokens(): void
    {
        [$machine] = $this->fixture();
        $this->withToken('wrong')->getJson($this->pollUrl($machine))->assertForbidden();
        $machine->update(['is_active' => false]);
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertForbidden();
    }

    public function test_poll_uses_real_user_and_public_entry_and_encrypts_cached_credentials(): void
    {
        [$machine, $node, $user] = $this->fixture();
        $response = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk();
        $task = $response->json('tasks.0');
        $this->assertCount(1, $response->json('tasks'));
        $this->assertSame(10000, $task['timeout_ms']);
        $this->assertSame('http://cp.cloudflare.com/generate_204', $task['payload']['target']);
        $this->assertSame('public.example', $task['payload']['outbound']['settings']['server']);
        $this->assertSame(443, $task['payload']['outbound']['settings']['server_port']);
        $this->assertSame($user->uuid, $task['payload']['outbound']['settings']['uuid']);
        $stored = app(ProbeStore::class)->task($task['id']);
        $this->assertStringNotContainsString($user->uuid, json_encode($stored));
        $this->assertStringNotContainsString($task['lease'], json_encode($stored));
        $this->assertArrayNotHasKey('ciphertext', app(ProbeStore::class)->latest($node->id));
        $this->assertArrayNotHasKey('lease_hash', app(ProbeStore::class)->latest($node->id));
        $this->assertSame('running', app(ProbeService::class)->status($node)['state']);
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertExactJson(['tasks' => []]);
    }

    public function test_revision_matches_created_reloaded_and_subscription_mapped_native_models(): void
    {
        [$machine, $node] = $this->fixture();
        $service = app(ProbeService::class);
        $revision = new \ReflectionMethod($service, 'revision');
        $reloaded = $node->fresh();
        $this->assertSame($revision->invoke($service, $node), $revision->invoke($service, $reloaded));
        // ServerService::getAvailableServers also converts the public port to int.
        // This in-memory presentation change is the same listener configuration.
        $subscriptionNode = clone $reloaded;
        $subscriptionNode->port = (int) $subscriptionNode->port;
        $this->assertSame($revision->invoke($service, $reloaded), $revision->invoke($service, $subscriptionNode));
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk()->assertJsonCount(1, 'tasks');
        foreach ([$node, $reloaded, $subscriptionNode] as $representation) {
            $this->assertSame('running', $service->status($representation)['state']);
        }
        $subscriptionNode->server_port = 23456;
        $this->assertSame('pending', $service->status($subscriptionNode)['state']);
    }

    public function test_results_require_owner_current_lease_and_a_single_terminal_transition(): void
    {
        [$machine, $node] = $this->fixture();
        $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
        $url = '/api/v3/server/probes/' . $task['id'] . '/result';
        $result = ['machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'succeeded', 'error_code' => '', 'latency_ms' => 0];
        $other = ServerMachine::create(['name' => 'other', 'token' => 'other-secret', 'is_active' => true]);
        $this->withToken($other->token)->postJson($url, array_replace($result, ['machine_id' => $other->id]))->assertConflict();
        $this->withToken($machine->token)->postJson($url, array_replace($result, ['lease' => 'wrong']))->assertConflict();
        $this->withToken($machine->token)->postJson($url, $result)->assertOk();
        $this->withToken($machine->token)->postJson($url, $result)->assertConflict();
        $health = app(ProbeService::class)->status($node);
        $this->assertSame('succeeded', $health['state']);
        $this->assertSame(['state', 'finished_at', 'created_at', 'error_code', 'source', 'latency_ms', 'ping_ms'], array_keys($health));
        $this->travel(601)->seconds();
        $this->assertSame('expired', app(ProbeService::class)->status($node)['state']);
    }

    public function test_expired_lease_is_replaced_on_next_poll_without_waiting_five_minutes(): void
    {
        [$machine, $node] = $this->fixture();
        $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
        $this->travel(121)->seconds();
        $this->assertNull(app(ProbeStore::class)->task($task['id']));
        $this->withToken($machine->token)->postJson('/api/v3/server/probes/' . $task['id'] . '/result', [
            'machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'succeeded',
        ])->assertConflict();
        $this->assertSame('expired', app(ProbeService::class)->status($node)['state']);
        $replacement = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertJsonCount(1, 'tasks')->json('tasks.0');
        $this->assertNotSame($task['id'], $replacement['id']);
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertExactJson(['tasks' => []]);
    }

    public function test_failed_probe_retries_after_short_cooldown_and_recovers(): void
    {
        [$machine, $node] = $this->fixture();
        $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
        $this->withToken($machine->token)->postJson('/api/v3/server/probes/'.$task['id'].'/result', [
            'machine_id'=>$machine->id, 'lease'=>$task['lease'], 'state'=>'failed', 'error_code'=>'timeout',
        ])->assertOk();
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertExactJson(['tasks'=>[]]);
        $this->travel(31)->seconds();
        $next = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertJsonCount(1,'tasks')->json('tasks.0');
        $this->withToken($machine->token)->postJson('/api/v3/server/probes/'.$next['id'].'/result', [
            'machine_id'=>$machine->id, 'lease'=>$next['lease'], 'state'=>'succeeded', 'error_code'=>'',
        ])->assertOk();
        $this->assertSame('succeeded', app(ProbeService::class)->status($node)['state']);
        $this->travel(31)->seconds();
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertExactJson(['tasks'=>[]]);
    }

    public function test_editing_a_node_replaces_a_stale_running_probe_immediately(): void
    {
        [$machine, $node] = $this->fixture();
        $first = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
        $node->update(['server_port' => 23456]);
        // Same second: a new revision must not wait for the old lease or interval.
        $next = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk()->json('tasks.0');
        $this->assertNotNull($next);
        $this->assertNotSame($first['id'], $next['id']);
        $this->assertSame(443, $next['payload']['outbound']['settings']['server_port']);
        $this->assertSame('running', app(ProbeService::class)->status($node->fresh())['state']);
        $this->withToken($machine->token)->postJson('/api/v3/server/probes/' . $first['id'] . '/result', [
            'machine_id' => $machine->id, 'lease' => $first['lease'], 'state' => 'succeeded',
        ])->assertConflict();
    }

    public function test_native_tcp_reality_vision_template_replaces_cached_unsupported_without_reediting_node(): void
    {
        foreach ([false, true] as $encrypted) {
            [$machine, $node, $user] = $this->fixture();
            $settings = ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'network_settings' => ['acceptProxyProtocol' => false, 'header' => ['type' => 'none']],
                'reality_settings' => ['server_name' => 'reality.example', 'public_key' => str_repeat('A', 43), 'short_id' => 'abcd']];
            if ($encrypted) $settings['encryption'] = ['enabled' => true, 'encryption' => 'native-public-test-key', 'decryption' => 'never-export'];
            $node->update(['type' => 'vless', 'protocol_settings' => $settings]);
            app(ProbeStore::class)->save(['id' => (string) \Illuminate\Support\Str::uuid(), 'node_id' => (int) $node->id,
                'machine_id' => (int) $machine->id, 'revision' => 'prior-builder-revision', 'created_at' => now()->timestamp,
                'expires_at' => now()->timestamp + 120, 'finished_at' => now()->timestamp, 'state' => 'unsupported', 'error_code' => 'unsupported']);
            $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk()->json('tasks.0');
            $this->assertNotNull($task);
            $this->assertSame($encrypted ? 'xray' : 'singbox', $task['payload']['kernel_type']);
            $this->assertStringNotContainsString('acceptProxyProtocol', json_encode($task['payload']));
            $this->assertStringNotContainsString('never-export', json_encode($task['payload']));
            $this->assertFalse($node->fresh()->protocol_settings['network_settings']['acceptProxyProtocol']);
            $this->assertSame('running', app(ProbeService::class)->status($node->fresh())['state']);
        }
    }

    public function test_heartbeat_alone_never_creates_proxy_success_and_user_route_requires_authentication(): void
    {
        [$machine, $node] = $this->fixture();
        $machine->update(['last_seen_at' => now()->timestamp]);
        $this->assertSame('pending', app(ProbeService::class)->status($node)['state']);
        $this->getJson('/api/onenew/user/nodes')->assertForbidden();
    }

    public function test_runtime_kernel_metadata_is_allowlisted_in_native_metrics(): void
    {
        [, $node] = $this->fixture();
        \App\Services\ServerService::updateMetrics($node, ['kernel_type' => 'xray', 'onenew_runtime' => true]);
        $this->assertSame('xray', $node->metrics['kernel_type']);
        $this->assertTrue($node->metrics['onenew_runtime']);
        \App\Services\ServerService::updateMetrics($node, ['kernel_type' => 'unknown', 'onenew_runtime' => 'true']);
        $this->assertNull($node->metrics['kernel_type']);
        $this->assertFalse($node->metrics['onenew_runtime']);
    }

    public function test_configuration_failures_remain_pending_and_real_proxy_failures_are_failed(): void
    {
        [$machine, $node] = $this->fixture();
        foreach (['configuration_unavailable' => 'pending', 'proxy_failed' => 'failed'] as $code => $expected) {
            $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
            $this->withToken($machine->token)->postJson('/api/v3/server/probes/' . $task['id'] . '/result', [
                'machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'failed', 'error_code' => $code,
            ])->assertOk();
            $this->assertSame($expected, app(ProbeService::class)->status($node)['state']);
            $this->travel(301)->seconds();
        }
    }

    public function test_account_expiry_quota_ban_and_groups_are_not_online_or_failure(): void
    {
        foreach (['expired_at' => time() - 1, 'transfer_enable' => 0, 'banned' => 1, 'group_id' => 99] as $field => $value) {
            Cache::store('array')->flush();
            [$machine, $node, $user] = $this->fixture();
            $user->update([$field => $value]);
            $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertExactJson(['tasks' => []]);
            $health = app(ProbeService::class)->status($node);
            $this->assertSame('pending', $health['state']);
            $this->assertSame('no_eligible_users', $health['error_code']);
            $user->delete();
        }
    }

    public function test_disabled_or_reassigned_node_cannot_accept_old_success(): void
    {
        [$machine, $node] = $this->fixture();
        $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
        $node->update(['enabled' => false]);
        $this->withToken($machine->token)->postJson('/api/v3/server/probes/' . $task['id'] . '/result', [
            'machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'succeeded',
        ])->assertConflict();
        $this->assertSame('pending', app(ProbeService::class)->status($node)['state']);
    }

    public function test_editing_resolved_route_under_same_id_invalidates_running_lease(): void
    {
        foreach (['match' => ['changed.example'], 'action' => 'direct', 'action_value' => 'landing-two'] as $field => $value) {
            [$machine, $node] = $this->fixture();
            $route = $this->routeFixture($node);
            $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk()->json('tasks.0');
            $this->assertSame('running', app(ProbeService::class)->status($node)['state']);
            $route->update([$field => $value]);
            $this->assertSame([$route->id], $node->fresh()->route_ids);
            $this->assertSame('pending', app(ProbeService::class)->status($node)['state'], $field);
            $this->withToken($machine->token)->postJson('/api/v3/server/probes/' . $task['id'] . '/result', [
                'machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'succeeded',
            ])->assertConflict();
            $this->assertSame('pending', app(ProbeService::class)->status($node)['state'], $field);
        }
    }

    public function test_editing_resolved_route_invalidates_completed_success_but_metadata_does_not(): void
    {
        foreach (['match' => ['changed.example'], 'action' => 'direct', 'action_value' => 'landing-two'] as $field => $value) {
            [$machine, $node] = $this->fixture();
            $route = $this->routeFixture($node);
            $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk()->json('tasks.0');
            $url = '/api/v3/server/probes/' . $task['id'] . '/result';
            $result = ['machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'succeeded'];
            $this->withToken($machine->token)->postJson($url, $result)->assertOk();
            $this->assertSame('succeeded', app(ProbeService::class)->status($node)['state']);
            $route->update(['remarks' => 'Only the administrator label changed']);
            $this->assertSame('succeeded', app(ProbeService::class)->status($node)['state']);
            $route->update([$field => $value]);
            $this->assertSame([$route->id], $node->fresh()->route_ids);
            $this->assertSame('pending', app(ProbeService::class)->status($node)['state'], $field);
            $this->withToken($machine->token)->postJson($url, $result)->assertConflict();
            $this->assertSame('pending', app(ProbeService::class)->status($node)['state'], $field);
        }
    }

    public function test_result_validation_refuses_inconsistent_states_and_diagnostic_secrets(): void
    {
        [$machine] = $this->fixture();
        $task = $this->withToken($machine->token)->getJson($this->pollUrl($machine))->json('tasks.0');
        $base = ['machine_id' => $machine->id, 'lease' => $task['lease'], 'state' => 'succeeded'];
        $url = '/api/v3/server/probes/' . $task['id'] . '/result';
        $this->withToken($machine->token)->postJson($url, $base + ['error_code' => 'proxy_failed'])->assertUnprocessable();
        $this->withToken($machine->token)->postJson($url, $base + ['diagnostics' => ['password' => 'secret']])->assertUnprocessable();
        $this->withToken($machine->token)->postJson($url, $base + ['latency_ms' => 99999])->assertUnprocessable();
    }

    public function test_user_nodes_are_entitlement_filtered_and_never_include_credentials(): void
    {
        [$machine, $node, $user] = $this->fixture();
        $this->withToken($machine->token)->getJson($this->pollUrl($machine))->assertOk();
        Server::create(['name' => 'hidden-group', 'type' => 'vmess', 'host' => 'secret.example', 'port' => 80,
            'server_port' => 80, 'rate' => '1', 'machine_id' => $machine->id, 'group_ids' => ['99'], 'enabled' => true, 'show' => true]);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/onenew/user/nodes')->assertOk()->assertJsonCount(1);
        $this->assertSame($node->id, $response->json('0.id'));
        $this->assertSame(['id', 'name', 'type', 'active', 'machine_last_report_at', 'latest_probe'], array_keys($response->json('0')));
        foreach ([$user->uuid, $machine->token, 'secret.example', 'outbound', 'lease', 'ciphertext'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $user->update(['u' => $user->transfer_enable]);
        $this->getJson('/api/onenew/user/nodes')->assertExactJson([]);
    }

    public function test_builder_preserves_tls_sni_reality_and_refuses_unrepresentable_native_options(): void
    {
        [, $node, $user] = $this->fixture();
        $node->type = 'vless';
        $node->host = '101.69.181.108';
        $node->protocol_settings = ['tls' => 1, 'network' => 'ws',
            'tls_settings' => ['server_name' => 'aethercloud-us.chinaone.ggff.net'],
            'network_settings' => ['path' => '/juhost28000', 'headers' => ['Host' => 'aethercloud-us.chinaone.ggff.net']]];
        $public = app(ProbeOutboundBuilder::class)->build($node, $user)['outbound']['settings'];
        $this->assertSame('101.69.181.108', $public['server']);
        $this->assertSame(443, $public['server_port']);
        $this->assertSame('aethercloud-us.chinaone.ggff.net', $public['tls']['server_name']);
        $this->assertSame('/juhost28000', $public['transport']['path']);
        $this->assertSame('aethercloud-us.chinaone.ggff.net', $public['transport']['headers']['Host']);
        $node->host = 'public.example';
        $node->protocol_settings = ['tls' => 1, 'tls_settings' => ['server_name' => 'certificate.example']];
        $payload = app(ProbeOutboundBuilder::class)->build($node, $user);
        $this->assertSame('certificate.example', $payload['outbound']['settings']['tls']['server_name']);
        $node->type = 'vless';
        $node->protocol_settings = ['tls' => 2, 'reality_settings' => ['server_name' => 'reality.example', 'public_key' => 'public-key', 'short_id' => 'abcd']];
        $out = app(ProbeOutboundBuilder::class)->build($node, $user)['outbound']['settings'];
        $this->assertSame('public-key', $out['tls']['reality']['public_key']);
        $this->assertSame('reality.example', $out['tls']['server_name']);
        $this->assertSame('127.0.0.1', $out['server']);
        $this->assertSame(12345, $out['server_port']);
        $node->protocol_settings = ['network' => 'xhttp', 'tls' => 2,
            'network_settings' => ['path' => '/native', 'host' => 'edge.example', 'mode' => 'packet-up'],
            'reality_settings' => ['server_name' => 'reality.example', 'public_key' => 'public-key', 'private_key' => 'private-never-export', 'short_id' => 'abcd'],
            'encryption' => ['enabled' => true, 'encryption' => 'native-public-key', 'decryption' => 'native-private-never-export']];
        $payload = app(ProbeOutboundBuilder::class)->build($node, $user);
        $this->assertSame('xray', $payload['kernel_type']);
        $this->assertSame('native-public-key', $payload['outbound']['settings']['vnext'][0]['users'][0]['encryption']);
        $this->assertSame('public.example', $payload['outbound']['settings']['vnext'][0]['address']);
        $this->assertSame('xhttp', $payload['outbound']['stream_settings']['network']);
        $this->assertSame('/native', $payload['outbound']['stream_settings']['xhttpSettings']['path']);
        $this->assertSame('public-key', $payload['outbound']['stream_settings']['realitySettings']['publicKey']);
        $this->assertStringNotContainsString('never-export', json_encode($payload));
        $node->protocol_settings = ['network' => 'xhttp', 'network_settings' => ['extra' => ['downloadSettings' => ['address' => 'elsewhere.example']]]];
        $this->expectException(ProbeUnavailable::class);
        app(ProbeOutboundBuilder::class)->build($node, $user);
    }

    private function fixture(): array
    {
        $machine = ServerMachine::create(['name' => 'probe-machine', 'token' => 'machine-secret-' . bin2hex(random_bytes(6)), 'is_active' => true]);
        $node = Server::create(['name' => 'test-node', 'type' => 'vmess', 'host' => 'public.example', 'port' => 443,
            'server_port' => 12345, 'rate' => '1', 'machine_id' => $machine->id, 'group_ids' => ['1'], 'enabled' => true, 'show' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]);
        $user = User::create(['email' => 'probe-' . bin2hex(random_bytes(4)) . '@example.test', 'password' => 'unused',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)),
            'group_id' => 1, 'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'banned' => 0, 'expired_at' => null]);
        return [$machine, $node, $user];
    }

    private function pollUrl(ServerMachine $machine): string
    {
        return '/api/v3/server/probes?machine_id=' . $machine->id;
    }

    private function routeFixture(Server $node): ServerRoute
    {
        $route = ServerRoute::create(['remarks' => 'Probe route', 'match' => ['original.example'],
            'action' => 'proxy', 'action_value' => 'landing-one']);
        $node->update(['route_ids' => [$route->id]]);
        return $route;
    }
}

