<?php
namespace Tests\Feature;

use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloudflaredTunnelTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'email' => 'tunnel-admin-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'is_admin' => true,
        ]);
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create([
            'name' => 'tunnel-box-' . uniqid(),
            'token' => bin2hex(random_bytes(24)),
            'is_active' => true,
            'onenew_identity' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_install_poll_report_and_token_scrub_lifecycle(): void
    {
        $admin = $this->admin();
        $machine = $this->machine();
        $validToken = 'eyJhIjoi' . str_repeat('AbCdEf0123456789', 4) . '==';

        // Assistant reports initial state
        $this->withHeaders(['Authorization' => 'Bearer ' . $machine->token])
            ->getJson('/api/v3/server/tunnel/poll?machine_id=' . $machine->id . '&arch=amd64&installed=0&running=0&version=&assistant_version=v0.1.18')
            ->assertOk()
            ->assertExactJson(['job' => null]);

        // Admin queues install job with valid token; token is never echoed back
        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/onenew/admin/tunnels/' . $machine->id, ['action' => 'install', 'token' => $validToken])
            ->assertOk();
        $jobId = $create->json('job.id');
        $this->assertNotEmpty($jobId);
        $this->assertStringNotContainsString($validToken, $create->getContent());

        // Reject shell injection or invalid token
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/onenew/admin/tunnels/' . $machine->id, ['action' => 'install', 'token' => 'bad; rm -rf /'])
            ->assertStatus(409);

        // Assistant polls and receives decrypted token + lease
        $poll = $this->withHeaders(['Authorization' => 'Bearer ' . $machine->token])
            ->getJson('/api/v3/server/tunnel/poll?machine_id=' . $machine->id . '&arch=amd64&installed=0&running=0&version=&assistant_version=v0.1.18')
            ->assertOk();
        $this->assertSame($jobId, $poll->json('job.id'));
        $this->assertSame('install', $poll->json('job.action'));
        $this->assertSame($validToken, $poll->json('job.token'));
        $lease = $poll->json('job.lease');

        // Assistant reports succeeded; encrypted token is scrubbed
        $this->withHeaders(['Authorization' => 'Bearer ' . $machine->token])
            ->postJson('/api/v3/server/tunnel/' . $jobId . '/status', [
                'machine_id' => $machine->id,
                'lease' => $lease,
                'state' => 'succeeded',
                'installed' => true,
                'running' => true,
                'version' => '2026.2.0',
                'error_code' => '',
            ])
            ->assertOk();

        $this->assertNull(DB::table('v2_onenew_tunnel_jobs')->where('id', $jobId)->value('token_encrypted'));

        // Admin views updated status
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/onenew/admin/tunnels/' . $machine->id)
            ->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('running', true)
            ->assertJsonPath('version', '2026.2.0')
            ->assertJsonPath('job.state', 'succeeded');
    }

    public function test_invalid_token_and_prohibited_command_rejected(): void
    {
        $admin = $this->admin();
        $machine = $this->machine();

        $this->withHeaders(['Authorization' => 'Bearer ' . $machine->token])
            ->getJson('/api/v3/server/tunnel/poll?machine_id=' . $machine->id . '&arch=arm64&installed=0&running=0&version=&assistant_version=v0.1.18')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/onenew/admin/tunnels/' . $machine->id, ['action' => 'install', 'token' => 'short'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/onenew/admin/tunnels/' . $machine->id, ['action' => 'install', 'token' => str_repeat('a', 64), 'command' => 'id'])
            ->assertStatus(422);
    }
}
