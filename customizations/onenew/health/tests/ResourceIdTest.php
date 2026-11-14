<?php

namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Jobs\StatServerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResourceIdTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $name = 'node'): Server
    {
        return Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
    }

    public function test_new_nodes_fill_smallest_gap_without_renumbering_and_copy_gets_new_identity(): void
    {
        $a = $this->node(); $b = $this->node(); $c = $this->node();
        $this->assertSame([1, 2, 3], [$a->id, $b->id, $c->id]);
        $b->delete();
        $d = $this->node();
        $this->assertSame(2, $d->id);
        $this->assertNotSame($b->onenew_identity, $d->onenew_identity);
        $this->assertSame(3, $c->fresh()->id);
        $a->delete(); $c->delete();
        $copy = $d->replicate(); $copy->save();
        $this->assertSame(1, $copy->id);
        $this->assertNotSame($d->onenew_identity, $copy->onenew_identity);
        $this->assertSame(3, $this->node()->id);
    }

    public function test_new_machine_reuses_id_but_never_reuses_credentials_or_load(): void
    {
        $a = ServerMachine::create(['name' => 'old', 'token' => ServerMachine::generateToken()]);
        $id = $a->id; $token = $a->token;
        $a->delete();
        $b = ServerMachine::create(['name' => 'new', 'token' => ServerMachine::generateToken()]);
        $this->assertSame($id, $b->id);
        $this->assertNotSame($a->onenew_identity, $b->onenew_identity);
        $this->assertNotSame($token, $b->token);
        $this->assertNull($b->last_seen_at);
        $this->assertStringContainsString('--machine-id '.$id, \App\Onenew\NodeInstallCommand::make('https://example.test', $b->token, $b->id));
    }

    public function test_old_stat_jobs_and_cached_status_cannot_attach_to_reused_id(): void
    {
        $old = $this->node();
        $job = new StatServerJob($old->toArray(), [1 => [123, 456]], 'vless');
        Cache::put('node_ws_alive:'.$old->id, true, 3600);
        $old->delete();
        $new = $this->node();
        $job->handle();
        $this->assertSame($old->id, $new->id);
        $this->assertFalse(Cache::has('node_ws_alive:'.$new->id));
        $this->assertSame(0, (int) $new->fresh()->u);
        $this->assertSame(0, DB::table('v2_stat_server')->count());
    }

    public function test_retired_native_stats_are_preserved_without_counting_against_replacement(): void
    {
        $old = $this->node();
        (new StatServerJob($old->toArray(), [1 => [123, 456]], 'vless'))->handle();
        $this->assertSame(123, (int) $old->fresh()->u);
        $old->delete();
        $new = $this->node();
        $this->assertSame(0, (int) $new->fresh()->u);
        $this->assertSame(1, DB::table('v2_onenew_retired_stats')->count());
        $payload = json_decode(DB::table('v2_onenew_retired_stats')->value('payload'), true);
        $this->assertSame(456, (int) $payload['d']);
        (new StatServerJob($new->toArray(), [1 => [11, 22]], 'vless'))->handle();
        $this->assertSame(11, (int) $new->fresh()->u);
    }

    public function test_old_machine_socket_cannot_be_reused_for_replacement(): void
    {
        $machine = ServerMachine::create(['name' => 'old', 'token' => ServerMachine::generateToken(), 'is_active' => true]);
        $socket = new class { public $machineId; public $onenewCredentialHash; public bool $closed = false;
            public function close(): void { $this->closed = true; } };
        $socket->machineId = $machine->id;
        $socket->onenewCredentialHash = hash('sha256', $machine->token);
        $this->assertTrue(\App\Onenew\ResourceConnections::valid($socket));
        $machine->delete();
        $replacement = ServerMachine::create(['name' => 'new', 'token' => ServerMachine::generateToken(), 'is_active' => true]);
        $this->assertSame($machine->id, $replacement->id);
        $this->assertFalse(\App\Onenew\ResourceConnections::valid($socket));
        $this->assertTrue($socket->closed);
    }

    public function test_machine_socket_cannot_receive_or_report_a_replaced_node(): void
    {
        $machine = ServerMachine::create(['name' => 'old', 'token' => ServerMachine::generateToken(), 'is_active' => true]);
        $node = $this->node(); $node->update(['machine_id' => $machine->id]);
        $socket = new class { public $machineId; public $onenewCredentialHash; public $onenewNodeIdentities; public bool $closed = false;
            public function close(): void { $this->closed = true; } };
        $socket->machineId = $machine->id;
        $socket->onenewCredentialHash = hash('sha256', $machine->token);
        $socket->onenewNodeIdentities = [$node->id => $node->onenew_identity];
        $this->assertTrue(\App\Onenew\ResourceConnections::valid($socket, $node->id));
        $node->delete();
        $replacement = $this->node(); $replacement->update(['machine_id' => $machine->id]);
        $this->assertSame($node->id, $replacement->id);
        $this->assertFalse(\App\Onenew\ResourceConnections::valid($socket, $replacement->id));
        $this->assertTrue($socket->closed);
    }
}
