<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Onenew\Traffic\NodeTrafficLedger;
use App\Services\AuthService;
use App\Services\DeviceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NodeUsersTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false, string $email = ''): User
    {
        $u = new User();
        $u->email = $email ?: Str::lower(Str::random(8)) . '@example.test';
        $u->password = password_hash('password', PASSWORD_DEFAULT);
        $u->uuid = (string) Str::uuid();
        $u->token = Str::random(32);
        $u->is_admin = $admin;
        $u->save();
        return $u;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => (new AuthService($user))->generateAuthData()['auth_data']];
    }

    private function node(string $name = 'JP-AI'): Server
    {
        $n = new Server();
        $n->name = $name;
        $n->type = 'vless';
        $n->host = '1.2.3.4';
        $n->port = '443';
        $n->server_port = 443;
        $n->rate = '1';
        $n->group_ids = [1];
        $n->show = true;
        $n->onenew_identity = (string) Str::uuid();
        $n->save();
        return $n;
    }

    public function test_admin_only_access(): void
    {
        $node = $this->node();
        $regular = $this->user(false);
        $this->getJson('/api/onenew/admin/traffic/node-users?server_id=' . $node->id)->assertStatus(403);
        $this->getJson('/api/onenew/admin/traffic/node-users?server_id=' . $node->id, $this->headers($regular))->assertStatus(403);
    }

    public function test_returns_merged_online_users_and_daily_usage_breakdown(): void
    {
        $admin = $this->user(true, 'admin@example.test');
        $alice = $this->user(false, 'alice@example.test');
        $bob = $this->user(false, 'bob@example.test');
        $charlie = $this->user(false, 'charlie@example.test');
        $node = $this->node('JP-Home');

        $tz = config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();
        $yesterday = $today->copy()->subDay();

        // Mock DeviceStateService returning alice with 2 IPs
        $mock = $this->mock(DeviceStateService::class);
        $mock->shouldReceive('getNodeDevices')->with($node->id)->andReturn([
            $alice->id => ['183.14.22.91', '112.96.48.10'],
        ]);

        // Record a traffic batch for bob (so bob is active via traffic push even without device_limit IPs)
        app(NodeTrafficLedger::class)->applyBatch(
            (string) Str::uuid(),
            ['id' => $node->id, 'name' => $node->name, 'rate' => 1, 'onenew_identity' => $node->onenew_identity],
            [$bob->id => [1000, 4000]],
            'vless',
            $today->timestamp,
            fn () => null
        );

        // Insert today's traffic for alice (2 rate rows to test grouping) and charlie (offline)
        DB::table('v2_onenew_user_node_traffic')->insert([
            [
                'user_id' => $alice->id, 'server_id' => $node->id, 'node_identity' => $node->onenew_identity,
                'server_type' => 'vless', 'node_name' => $node->name, 'record_at' => $today->timestamp,
                'server_rate' => '1.00000000', 'raw_u' => 5000, 'raw_d' => 10000, 'u' => 5000, 'd' => 10000,
                'created_at' => time(), 'updated_at' => time(),
            ],
            [
                'user_id' => $alice->id, 'server_id' => $node->id, 'node_identity' => $node->onenew_identity,
                'server_type' => 'vless', 'node_name' => $node->name, 'record_at' => $today->timestamp,
                'server_rate' => '2.00000000', 'raw_u' => 1000, 'raw_d' => 2000, 'u' => 2000, 'd' => 4000,
                'created_at' => time(), 'updated_at' => time(),
            ],
            [
                'user_id' => $charlie->id, 'server_id' => $node->id, 'node_identity' => $node->onenew_identity,
                'server_type' => 'vless', 'node_name' => $node->name, 'record_at' => $today->timestamp,
                'server_rate' => '1.00000000', 'raw_u' => 3000, 'raw_d' => 6000, 'u' => 3000, 'd' => 6000,
                'created_at' => time(), 'updated_at' => time(),
            ],
            // Yesterday traffic for charlie
            [
                'user_id' => $charlie->id, 'server_id' => $node->id, 'node_identity' => $node->onenew_identity,
                'server_type' => 'vless', 'node_name' => $node->name, 'record_at' => $yesterday->timestamp,
                'server_rate' => '1.00000000', 'raw_u' => 100, 'raw_d' => 200, 'u' => 100, 'd' => 200,
                'created_at' => time(), 'updated_at' => time(),
            ],
            // Different node_identity (old deleted node with same ID) must be excluded
            [
                'user_id' => $charlie->id, 'server_id' => $node->id, 'node_identity' => 'stale-identity',
                'server_type' => 'vless', 'node_name' => 'Old', 'record_at' => $today->timestamp,
                'server_rate' => '1.00000000', 'raw_u' => 999999, 'raw_d' => 999999, 'u' => 999999, 'd' => 999999,
                'created_at' => time(), 'updated_at' => time(),
            ],
        ]);

        $res = $this->getJson('/api/onenew/admin/traffic/node-users?server_id=' . $node->id, $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('node.id', $node->id)
            ->assertJsonPath('node.online', 2)
            ->assertJsonPath('daily.summary.active_users', 3)
            ->assertJsonPath('daily.summary.total', 35000);

        $online = $res->json('online_users');
        self::assertCount(2, $online);
        self::assertSame($alice->id, $online[0]['user_id']);
        self::assertSame(['183.14.22.91', '112.96.48.10'], $online[0]['ips']);
        self::assertSame(2, $online[0]['device_count']);
        self::assertSame(21000, $online[0]['today_total']);

        self::assertSame($bob->id, $online[1]['user_id']);
        self::assertSame([], $online[1]['ips']);
        self::assertSame(5000, $online[1]['today_total']);

        $daily = $res->json('daily.data');
        self::assertCount(3, $daily);
        self::assertSame([$alice->id, $charlie->id, $bob->id], array_column($daily, 'user_id'));
        self::assertTrue($daily[0]['is_online']);
        self::assertFalse($daily[1]['is_online']);
        self::assertTrue($daily[2]['is_online']);

        // Query yesterday
        $this->getJson('/api/onenew/admin/traffic/node-users?server_id=' . $node->id . '&date=' . $yesterday->format('Y-m-d'), $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('daily.summary.active_users', 1)
            ->assertJsonPath('daily.summary.total', 300)
            ->assertJsonPath('daily.data.0.user_id', $charlie->id);

        Cache::forget(NodeTrafficLedger::ACTIVE_USERS_PREFIX . $node->id);
    }

    public function test_excludes_dedicated_probe_accounts_from_online_and_daily_stats(): void
    {
        $admin = $this->user(true, 'admin2@example.test');
        $probe = $this->user(false, '节点探针@qq.com');
        $real = $this->user(false, 'real@example.test');
        $node = $this->node('SG-AI');
        $today = Carbon::now(config('app.timezone', 'UTC'))->startOfDay();

        $mock = $this->mock(DeviceStateService::class);
        $mock->shouldReceive('getNodeDevices')->with($node->id)->andReturn([
            $probe->id => ['127.0.0.1'],
            $real->id => ['203.0.113.5'],
        ]);

        app(NodeTrafficLedger::class)->applyBatch(
            (string) Str::uuid(),
            ['id' => $node->id, 'name' => $node->name, 'rate' => 1, 'onenew_identity' => $node->onenew_identity],
            [$probe->id => [500, 500], $real->id => [2000, 3000]],
            'vless',
            $today->timestamp,
            fn () => null
        );

        self::assertSame(1, \App\Onenew\ProbeAccount::countRealUsers([$probe->id => [500, 500], $real->id => [2000, 3000]]));

        $this->getJson('/api/onenew/admin/traffic/node-users?server_id=' . $node->id, $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('node.online', 1)
            ->assertJsonPath('online_users.0.user_id', $real->id)
            ->assertJsonPath('daily.summary.active_users', 1)
            ->assertJsonPath('daily.data.0.user_id', $real->id);

        Cache::forget(NodeTrafficLedger::ACTIVE_USERS_PREFIX . $node->id);
    }
}
