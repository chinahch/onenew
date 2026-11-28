<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Onenew\SelfSteal\SelfStealService;
use App\Services\AuthService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SelfStealTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $u = new User();
        $u->email = 'admin-ss-' . Str::random(6) . '@example.test';
        $u->password = password_hash('password', PASSWORD_DEFAULT);
        $u->uuid = (string) Str::uuid();
        $u->token = Str::random(32);
        $u->is_admin = true;
        $u->save();
        return ['Authorization' => (new AuthService($u))->generateAuthData()['auth_data']];
    }

    private function machine(): ServerMachine
    {
        $m = new ServerMachine();
        $m->name = 'ss-node';
        $m->token = Str::random(40);
        $m->is_active = true;
        $m->onenew_identity = (string) Str::uuid();
        $m->save();
        return $m;
    }

    public function test_reality_self_steal_injects_loopback_dest_without_mutating_sni(): void
    {
        $node = new Server();
        $node->name = 'US-SelfSteal';
        $node->type = 'vless';
        $node->host = '104.233.228.56';
        $node->port = '25001';
        $node->server_port = 25001;
        $node->rate = '1';
        $node->group_ids = [1];
        $node->show = true;
        $node->protocol_settings = [
            'tls' => 2,
            'flow' => 'xtls-rprx-vision',
            'network' => 'tcp',
            'reality_settings' => [
                'server_name' => 'blog.example.com',
                'server_port' => 8443,
                'public_key' => 'pub',
                'private_key' => 'priv',
                'short_id' => '1234',
                'self_steal' => true,
            ],
        ];
        $node->save();

        $cfg = ServerService::getNodeConfig($node);
        self::assertSame('blog.example.com', $cfg['tls_settings']['server_name']);
        self::assertSame('127.0.0.1:8443', $cfg['tls_settings']['dest']);

        // When self_steal is false, dest is not overridden
        $off = SelfStealService::applyRealityDest([
            'server_name' => 'addons.mozilla.org',
            'server_port' => 443,
            'self_steal' => false,
        ]);
        self::assertArrayNotHasKey('dest', $off);

        // ServerSave validation must accept reality_settings.self_steal
        $req = new \App\Http\Requests\Admin\ServerSave(['type' => 'vless']);
        $rules = $req->rules();
        self::assertArrayHasKey('protocol_settings.reality_settings.self_steal', $rules);
    }

    public function test_self_steal_agent_poll_dispatch_and_status_lifecycle(): void
    {
        $headers = $this->adminHeaders();
        $m = $this->machine();
        $mHeaders = ['Authorization' => 'Bearer ' . $m->token];

        // Agent announces capability
        $this->getJson('/api/v3/server/self-steal/poll?machine_id=' . $m->id . '&arch=amd64&installed=0&running=0&domain=&port=0&version=&assistant_version=v0.1.20', $mHeaders)
            ->assertOk()
            ->assertJsonPath('job', null);

        // Admin dispatches install job
        $jobId = $this->postJson('/api/onenew/admin/self-steal/' . $m->id, [
            'action' => 'install',
            'domain' => 'Blog.Example.COM',
            'port' => 8443,
        ], $headers)->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('domain', 'blog.example.com')->json('id');

        // Agent polls and receives leased job
        $poll = $this->getJson('/api/v3/server/self-steal/poll?machine_id=' . $m->id . '&arch=amd64&installed=0&running=0&domain=&port=0&version=&assistant_version=v0.1.20', $mHeaders)
            ->assertOk()
            ->assertJsonPath('job.id', $jobId)
            ->assertJsonPath('job.action', 'install')
            ->assertJsonPath('job.domain', 'blog.example.com')
            ->assertJsonPath('job.port', 8443);
        $lease = $poll->json('job.lease');

        // Agent reports succeeded
        $this->postJson('/api/v3/server/self-steal/' . $jobId . '/status', [
            'machine_id' => $m->id,
            'lease' => $lease,
            'state' => 'succeeded',
            'installed' => true,
            'running' => true,
            'domain' => 'blog.example.com',
            'port' => 8443,
            'version' => '2.9.1',
            'error_code' => '',
        ], $mHeaders)->assertOk();

        // Admin sees active site in both show() and index()
        $this->getJson('/api/onenew/admin/self-steal/' . $m->id, $headers)
            ->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('running', true)
            ->assertJsonPath('domain', 'blog.example.com')
            ->assertJsonPath('port', 8443);

        $this->getJson('/api/onenew/admin/self-steal', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.machine_id', $m->id)
            ->assertJsonPath('data.0.domain', 'blog.example.com')
            ->assertJsonPath('data.0.port', 8443);
    }
}
