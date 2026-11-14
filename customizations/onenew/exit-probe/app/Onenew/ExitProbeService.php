<?php

namespace App\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** One short-lived encrypted draft per machine, sharing the automatic scheduler lock. */
class ExitProbeService
{
    public const TARGET = 'https://www.cloudflare.com/cdn-cgi/trace';
    public function __construct(private ProbeStore $store) {}

    private function key(string $suffix): string { return 'onenew:exit-probe:' . $suffix; }
    public function busy(int $machineId): bool
    {
        $task = $this->store->cache()->get($this->key('machine:' . $machineId));
        return $task && $task['expires_at'] > now()->timestamp && in_array($task['state'], ['pending', 'running'], true);
    }

    public function create(ServerMachine $machine, int $admin, string $core, array $outbound, ?Server $node = null): array
    {
        abort_unless($machine->is_active, 422, 'Node machine unavailable');
        $actual = $node ? $this->reportedCore($node) : null;
        if (!$node) {
            $cores = Server::where('machine_id', $machine->id)->where('enabled', true)->get()
                ->map(fn (Server $candidate) => $this->reportedCore($candidate))->unique()->values()->all();
            // Missing reports and mixed runtimes must not silently select a kernel.
            $actual = count($cores) === 1 ? $cores[0] : null;
        }
        abort_unless($actual && $core === $actual, 422, 'Select a node with a current matching runtime report');
        $payload = ExitProbePayload::build($core, $outbound);
        return $this->store->locked((int) $machine->id, function () use ($machine, $node, $admin, $payload) {
            abort_if($this->busy((int) $machine->id), 409, 'An exit test is already queued');
            $task = ['id' => (string) Str::uuid(), 'node_id' => $node ? (int) $node->id : null, 'machine_id' => (int) $machine->id,
                'admin_id' => $admin, 'created_at' => now()->timestamp, 'expires_at' => now()->timestamp + 60,
                'state' => 'pending', 'error_code' => '', 'ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))];
            $this->save($task);
            return $this->project($task);
        });
    }

    private function reportedCore(Server $node): ?string
    {
        $metrics = $node->metrics ?? [];
        $updated = $metrics['updated_at'] ?? null;
        if (!is_numeric($updated) || (int) $updated < now()->timestamp - 600 || (int) $updated > now()->timestamp + 60) return null;
        return match ($metrics['kernel_type'] ?? null) {
            'singbox' => 'singbox',
            'xray' => ($metrics['onenew_runtime'] ?? false) === true ? 'xray-caddy' : 'xray',
            default => null,
        };
    }

    public function poll(ServerMachine $machine): array
    {
        return $this->store->locked((int) $machine->id, function () use ($machine) {
            $active = $this->store->active((int) $machine->id);
            if ($active && $active['expires_at'] > now()->timestamp) return [];
            $task = $this->store->cache()->get($this->key('machine:' . $machine->id));
            if (!$task || $task['state'] !== 'pending' || $task['expires_at'] <= now()->timestamp) return [];
            $node = $task['node_id'] ? Server::find($task['node_id']) : null;
            if ($task['node_id'] && (!$node || (int) $node->machine_id !== (int) $machine->id)) {
                $this->finish($task, 'failed', 'machine_unavailable');
                return [];
            }
            $lease = bin2hex(random_bytes(32));
            $task['state'] = 'running';
            $task['lease_hash'] = hash('sha256', $lease);
            $this->save($task);
            return [['id' => $task['id'], 'lease' => $lease, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $task['expires_at']),
                // Preserve the normalized empty JSON objects across the encrypted queue.
                'timeout_ms' => 10000, 'payload' => json_decode(Crypt::decryptString($task['ciphertext']), false, 32, JSON_THROW_ON_ERROR)]];
        });
    }

    public function accept(ServerMachine $machine, string $id, array $result): void
    {
        $this->store->locked((int) $machine->id, function () use ($machine, $id, $result) {
            $task = $this->store->cache()->get($this->key('task:' . $id));
            abort_unless($task && $task['machine_id'] === (int) $machine->id && $task['state'] === 'running' &&
                $task['expires_at'] > now()->timestamp && hash_equals($task['lease_hash'], hash('sha256', $result['lease'])), 409);
            $node = $task['node_id'] ? Server::find($task['node_id']) : null;
            if ($task['node_id'] && (!$node || (int) $node->machine_id !== (int) $machine->id)) {
                $this->finish($task, 'failed', 'machine_unavailable');
                abort(409);
            }
            if ($result['state'] === 'succeeded') {
                $task['exit_ip'] = $result['exit_ip'];
                $task['country'] = $result['country'];
            }
            $this->finish($task, $result['state'], $result['error_code'] ?? '');
        });
    }

    public function status(string $id, int $admin): array
    {
        $task = $this->store->cache()->get($this->key('task:' . $id)) ?? $this->store->cache()->get($this->key('status:' . $id));
        abort_unless($task && $task['admin_id'] === $admin, 404);
        return $this->project($task);
    }
    private function finish(array $task, string $state, string $error): void
    {
        $task['state'] = $state; $task['error_code'] = $error;
        unset($task['ciphertext'], $task['lease_hash']);
        $this->save($task);
    }
    private function save(array $task): void
    {
        $live = in_array($task['state'], ['pending', 'running'], true);
        $ttl = $live ? max(1, $task['expires_at'] - now()->timestamp) : 120;
        // A public status survives expiration, but credentials never do.
        $public = array_intersect_key($task, array_flip(['id', 'node_id', 'machine_id', 'admin_id', 'state', 'error_code', 'exit_ip', 'country', 'expires_at']));
        $this->store->cache()->put($this->key('status:' . $task['id']), $public, 180);
        $this->store->cache()->put($this->key('task:' . $task['id']), $task, $ttl);
        if ($live) $this->store->cache()->put($this->key('machine:' . $task['machine_id']), $task, $ttl);
        else $this->store->cache()->forget($this->key('machine:' . $task['machine_id']));
    }
    private function project(array $task): array
    {
        $expired = in_array($task['state'], ['pending', 'running'], true) && $task['expires_at'] <= now()->timestamp;
        return ['id' => $task['id'], 'node_id' => $task['node_id'], 'state' => $expired ? 'expired' : $task['state'],
            'error_code' => $expired ? 'expired' : $task['error_code'], 'exit_ip' => $task['exit_ip'] ?? null,
            'country' => $task['country'] ?? null, 'source' => 'node machine · draft landing',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $task['expires_at'])];
    }
}
