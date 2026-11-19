<?php

namespace Tests\Feature\Onenew;

use App\Jobs\StatServerJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\StatUser;
use App\Models\User;
use App\Onenew\Traffic\LedgerStatUserJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis.driver', 'array');
        Cache::purge('redis');
    }

    public function test_native_daily_statistics_and_node_ledger_are_atomic_and_retry_idempotent(): void
    {
        [$user, $server] = $this->fixture();
        $job = new LedgerStatUserJob($server, [$user->id => [101, 203]], 'vmess');
        $job->handle();
        $job->handle();
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 1);
        $this->assertDatabaseHas('v2_stat_user', ['user_id' => $user->id, 'u' => 151, 'd' => 304]);
        $this->assertDatabaseHas('v2_onenew_user_node_traffic', ['user_id' => $user->id, 'server_id' => $server['id'],
            'raw_u' => 101, 'raw_d' => 203, 'u' => 151, 'd' => 304]);
        (new LedgerStatUserJob($server, [$user->id => [101, 203]], 'vmess'))->handle();
        $this->assertDatabaseHas('v2_onenew_user_node_traffic', ['raw_u' => 202, 'raw_d' => 406, 'u' => 302, 'd' => 608]);
    }

    public function test_failure_after_native_stat_update_rolls_back_receipt_and_all_ledger_writes(): void
    {
        [$user, $server] = $this->fixture();
        $job = new class($server, [$user->id => [100, 200]], 'vmess') extends LedgerStatUserJob {
            public bool $fail = true;
            protected function processUserStat(int $uid, array $v, int $recordAt): void
            {
                parent::processUserStat($uid, $v, $recordAt);
                if ($this->fail) {
                    throw new \RuntimeException('Injected native processing failure');
                }
            }
        };
        try {
            $job->handle();
            $this->fail('Expected native processing failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected native processing failure', $e->getMessage());
        }
        $this->assertDatabaseCount('v2_stat_user', 0);
        $this->assertDatabaseCount('v2_onenew_user_node_traffic', 0);
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 0);
        $job->fail = false;
        $job->handle();
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 1);
    }

    public function test_node_totals_roll_back_together_and_queue_replay_does_not_add_them_twice(): void
    {
        [, $server] = $this->fixture();
        $job = new class($server, [1 => [10, 20]], 'vmess') extends StatServerJob {
            public bool $fail = true;

            protected function updateServerTraffic(int $u, int $d): void
            {
                parent::updateServerTraffic($u, $d);
                if ($this->fail) {
                    throw new \RuntimeException('Injected node counter failure');
                }
            }
        };
        try {
            $job->handle();
            $this->fail('Expected node counter failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected node counter failure', $e->getMessage());
        }
        $this->assertDatabaseCount('v2_stat_server', 0);
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 0);
        $job->fail = false;
        $job->handle();
        $job->handle();
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 1);
        $this->assertDatabaseHas('v2_stat_server', ['server_id' => $server['id'], 'u' => 10, 'd' => 20]);
        $this->assertDatabaseHas('v2_server', ['id' => $server['id'], 'u' => 10, 'd' => 20]);
    }

    public function test_quota_retry_after_redis_failure_does_not_rebill_committed_bytes(): void
    {
        [$user, $server] = $this->fixture();
        $user->update(['u' => 0, 'd' => 0]);
        \Illuminate\Support\Facades\Redis::shouldReceive('sadd')->once()->ordered()
            ->with('traffic:pending_check', $user->id)->andThrow(new \RuntimeException('Injected Redis failure'));
        \Illuminate\Support\Facades\Redis::shouldReceive('sadd')->once()->ordered()
            ->with('traffic:pending_check', $user->id)->andReturn(1);
        $job = new TrafficFetchJob($server, [$user->id => [10, 20]], 'vmess', time());
        $serialized = serialize($job);
        try {
            $job->handle();
            $this->fail('Expected Redis failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected Redis failure', $e->getMessage());
        }
        unserialize($serialized)->handle();
        $this->assertDatabaseHas('v2_user', ['id' => $user->id, 'u' => 15, 'd' => 30]);
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 1);
    }

    public function test_quota_batch_failure_rolls_back_all_users_and_receipt(): void
    {
        [$user] = $this->fixture();
        $user->update(['u' => 0, 'd' => 0]);
        try {
            \App\Onenew\Traffic\TrafficBatch::apply((string) \Illuminate\Support\Str::uuid(), function () use ($user): void {
                User::whereKey($user->id)->increment('u', 10);
                throw new \RuntimeException('Injected quota write failure');
            });
            $this->fail('Expected quota failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected quota write failure', $e->getMessage());
        }
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 0);
    }

    public function test_administrator_day_view_preserves_node_rate_and_deleted_node_attribution(): void
    {
        [$user, $server] = $this->fixture();
        (new LedgerStatUserJob($server, [$user->id => [100, 200]], 'vmess'))->handle();
        (new LedgerStatUserJob(array_replace($server, ['rate' => 2]), [$user->id => [50, 100]], 'vmess'))->handle();
        Server::findOrFail($server['id'])->delete();
        $this->actingAdmin();
        $response = $this->getJson($this->url('nodes', $user))->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('coverage.state', 'complete')->assertJsonPath('totals.total', 750);
        $this->assertTrue($response->json('data.0.node_deleted'));
        $this->assertSame('snapshot-node', $response->json('data.0.node_name'));
        $this->assertSame(300, $response->json('totals.raw_d'));
        $this->assertSame(750, $response->json('native_totals.total'));
    }

    public function test_legacy_totals_never_turn_into_invented_per_node_rows(): void
    {
        [$user] = $this->fixture();
        StatUser::create(['user_id' => $user->id, 'server_rate' => 1, 'u' => 100, 'd' => 200,
            'record_type' => 'd', 'record_at' => strtotime('today')]);
        $this->actingAdmin();
        $this->getJson($this->url('nodes', $user))->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('coverage.state', 'unavailable')->assertJsonPath('unattributed.total', 300);
    }

    public function test_user_isolation_bounds_and_admin_authentication(): void
    {
        [$user] = $this->fixture();
        $this->getJson($this->url('nodes', $user))->assertForbidden();
        Sanctum::actingAs($user);
        $this->getJson($this->url('nodes', $user))->assertForbidden();
        $this->actingAdmin();
        $this->getJson($this->url('nodes', $user) . '&per_page=101')->assertUnprocessable();
        $this->getJson($this->url('nodes', $user) . '&page=100001')->assertUnprocessable();
        $this->getJson('/api/onenew/admin/traffic/nodes?user_id=' . $user->id . '&record_at=' . (strtotime('today') + 1))->assertUnprocessable();
        $this->getJson('/api/onenew/admin/traffic/nodes?user_id=999999&record_at=' . strtotime('today'))->assertNotFound();
    }

    public function test_records_expose_panel_date_and_keep_monthly_rows_distinguishable(): void
    {
        [$user] = $this->fixture();
        foreach (['d', 'm'] as $type) {
            StatUser::create(['user_id' => $user->id, 'server_rate' => $type === 'd' ? 1 : 2, 'u' => 1, 'd' => 2,
                'record_type' => $type, 'record_at' => strtotime('today')]);
        }
        $this->actingAdmin();
        $response = $this->getJson('/api/onenew/admin/traffic/records?user_id=' . $user->id)->assertOk();
        $this->assertSame(config('app.timezone'), $response->json('timezone'));
        $this->assertSame(date('Y-m-d'), $response->json('data.0.date'));
        $this->assertEqualsCanonicalizing(['d', 'm'], array_column($response->json('data'), 'record_type'));
    }

    public function test_domain_reports_are_opt_in_owned_bounded_and_batch_idempotent(): void
    {
        [$user, $server, $machine] = $this->fixture();
        $body = ['machine_id' => $machine->id, 'node_id' => $server['id'], 'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'events' => [['user_id' => $user->id, 'domain' => 'EXAMPLE.COM.', 'observed_at' => time()]]];
        $url = '/api/v3/server/domain-audit';
        $this->withToken($machine->token)->postJson($url, $body)->assertOk()->assertJsonPath('enabled', false);
        $this->assertDatabaseCount('v2_onenew_domain_events', 0);
        admin_setting(['onenew_domain_audit_enable' => 1]);
        $this->withToken('wrong')->postJson($url, $body)->assertForbidden();
        $other = ServerMachine::create(['name' => 'other', 'token' => bin2hex(random_bytes(20)), 'is_active' => true]);
        $this->withToken($other->token)->postJson($url, array_replace($body, ['machine_id' => $other->id]))->assertForbidden();
        $this->withToken($machine->token)->postJson($url, $body)->assertOk();
        $this->withToken($machine->token)->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('v2_onenew_domain_events', ['domain' => 'example.com', 'user_id' => $user->id]);
        $this->assertDatabaseCount('v2_onenew_domain_events', 1);
        foreach (['127.0.0.1', 'https://example.com/path', 'example.com/path', "bad\n.example"] as $invalid) {
            $bad = array_replace($body, ['batch_id' => (string) \Illuminate\Support\Str::uuid(),
                'events' => [['user_id' => $user->id, 'domain' => $invalid, 'observed_at' => time()]]]);
            $this->withToken($machine->token)->postJson($url, $bad)->assertUnprocessable();
        }
        $this->actingAdmin();
        $this->getJson($this->url('domains', $user))->assertOk()->assertJsonPath('data.0.domain', 'example.com')
            ->assertJsonPath('data.0.hit_count', 1)->assertJsonPath('coverage.state', 'available');
    }

    public function test_background_domains_are_filtered_before_count_and_pagination_without_deleting_history(): void
    {
        [$user,$server,$machine]=$this->fixture();admin_setting(['onenew_domain_audit_enable'=>1]);
        $domains=['www.cloudflare.com','api.example.com','telemetry.example.com','analytics.example.com','metrics.example.com','chatgpt.com','capital.example.com','example.com'];
        $body=['machine_id'=>$machine->id,'node_id'=>$server['id'],'batch_id'=>(string)\Illuminate\Support\Str::uuid(),
            'events'=>array_map(fn($domain)=>['user_id'=>$user->id,'domain'=>$domain,'observed_at'=>time()],$domains)];
        $this->withToken($machine->token)->postJson('/api/v3/server/domain-audit',$body)->assertOk();
        $this->actingAdmin();$url=$this->url('domains',$user);
        $this->getJson($url.'&per_page=1')->assertOk()->assertJsonPath('total',3)->assertJsonCount(1,'data');
        $shown=$this->getJson($url)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['chatgpt.com','capital.example.com','example.com'],array_column($shown,'domain'));
        $this->getJson($url.'&include_background=1')->assertOk()->assertJsonPath('total',8);
        $this->assertDatabaseCount('v2_onenew_domain_events',8);
    }

    public function test_domain_settings_retention_and_user_isolation(): void
    {
        [$user, $server, $machine] = $this->fixture();
        $config = '/api/v3/server/domain-audit/config?machine_id=' . $machine->id;
        $this->withToken('wrong')->getJson($config)->assertForbidden();
        $this->withToken($machine->token)->getJson($config)->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('retention_days', 7);
        Sanctum::actingAs($user);
        $this->postJson('/api/onenew/admin/traffic/audit-config', ['enabled' => true, 'retention_days' => 1])->assertForbidden();
        $this->actingAdmin();
        $this->postJson('/api/onenew/admin/traffic/audit-config', ['enabled' => true, 'retention_days' => 1])->assertOk()->assertJsonPath('enabled', true);
        $this->postJson('/api/onenew/admin/traffic/audit-config', ['enabled' => true, 'retention_days' => 31])->assertUnprocessable();
        $body = ['machine_id' => $machine->id, 'node_id' => $server['id'], 'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'events' => [['user_id' => $user->id, 'domain' => 'example.com', 'observed_at' => time() - 86401]]];
        $this->withToken($machine->token)->postJson('/api/v3/server/domain-audit', $body)->assertUnprocessable();
        $body['events'][0]['observed_at'] = time();
        $this->withToken($machine->token)->postJson('/api/v3/server/domain-audit', $body)->assertOk();
        $other = User::create(['email' => 'other-domain@example.test', 'password' => 'unused',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16))]);
        $this->getJson($this->url('domains', $other))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('coverage.state', 'unavailable');
        $this->artisan('onenew:prune-telemetry')->assertSuccessful();
    }

    public function test_domain_batches_require_existing_node_group_users_and_reject_atomically(): void
    {
        [$user, $server, $machine] = $this->fixture();
        admin_setting(['onenew_domain_audit_enable' => 1]);
        $otherGroup = User::create(['email' => 'other-audit-group@example.test', 'password' => 'unused',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'group_id' => 99]);
        foreach ([$otherGroup->id, 999999999] as $invalidUserId) {
            $body = ['machine_id' => $machine->id, 'node_id' => $server['id'], 'batch_id' => (string) \Illuminate\Support\Str::uuid(),
                'events' => [
                    ['user_id' => $user->id, 'domain' => 'valid.example', 'observed_at' => time()],
                    ['user_id' => $invalidUserId, 'domain' => 'unauthorized.example', 'observed_at' => time()],
                ]];
            $this->withToken($machine->token)->postJson('/api/v3/server/domain-audit', $body)->assertUnprocessable();
            $this->assertDatabaseCount('v2_onenew_domain_events', 0);
            $this->assertDatabaseCount('v2_onenew_domain_batches', 0);
        }
        // Eligibility at delivery must not erase legitimate buffered metadata.
        $user->update(['transfer_enable' => 0, 'expired_at' => time() - 1, 'banned' => true]);
        $body = ['machine_id' => $machine->id, 'node_id' => $server['id'], 'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'events' => [['user_id' => $user->id, 'domain' => 'buffered.example', 'observed_at' => time() - 60]]];
        $this->withToken($machine->token)->postJson('/api/v3/server/domain-audit', $body)->assertOk()->assertJsonPath('accepted', 1);
    }

    private function fixture(): array
    {
        $machine = ServerMachine::create(['name' => 'traffic-machine', 'token' => bin2hex(random_bytes(20)), 'is_active' => true]);
        $node = Server::create(['name' => 'snapshot-node', 'type' => 'vmess', 'host' => 'private.example', 'port' => 443,
            'server_port' => 443, 'machine_id' => $machine->id, 'rate' => '1.5', 'enabled' => true, 'group_ids' => ['1']]);
        $user = User::create(['email' => bin2hex(random_bytes(8)) . '@example.test', 'password' => 'unused',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'group_id' => 1]);
        return [$user, ['id' => $node->id, 'name' => $node->name, 'rate' => 1.5, 'onenew_identity' => $node->onenew_identity], $machine];
    }

    public function test_reused_node_id_keeps_old_and_new_traffic_separate(): void
    {
        [$user, $server, $machine] = $this->fixture();
        $oldJob = new LedgerStatUserJob($server, [$user->id => [10, 20]], 'vmess');
        $oldJob->handle();
        Server::findOrFail($server['id'])->delete();
        $new = Server::create(['name' => 'replacement', 'type' => 'vmess', 'host' => 'new.example',
            'port' => 443, 'server_port' => 443, 'rate' => 1.5, 'machine_id' => $machine->id, 'group_ids' => ['1']]);
        $this->assertSame($server['id'], $new->id);
        (new LedgerStatUserJob($new->toArray(), [$user->id => [30, 40]], 'vmess'))->handle();
        // A delayed archive still belongs to the old incarnation, without
        // discarding the user's real traffic from their daily usage record.
        (new LedgerStatUserJob($server, [$user->id => [1, 2]], 'vmess'))->handle();
        $this->assertSame(2, DB::table('v2_onenew_user_node_traffic')->count());
        $this->actingAdmin();
        $rows = $this->getJson($this->url('nodes', $user))->assertOk()->json('data');
        $old = collect($rows)->firstWhere('node_name', 'snapshot-node');
        $replacement = collect($rows)->firstWhere('node_name', 'replacement');
        $this->assertTrue($old['node_deleted']);
        $this->assertFalse($replacement['node_deleted']);
        $this->assertSame(11, $old['raw_u']);
        $this->assertSame(30, $replacement['raw_u']);
    }

    private function actingAdmin(): void
    {
        $user = User::create(['email' => bin2hex(random_bytes(8)) . '@admin.test', 'password' => 'unused',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => true]);
        Sanctum::actingAs($user);
    }

    private function url(string $section, User $user): string
    {
        return '/api/onenew/admin/traffic/' . $section . '?user_id=' . $user->id . '&record_at=' . strtotime('today');
    }
}
