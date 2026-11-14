<?php

namespace App\Onenew;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/** Bounded latest-per-node storage; no task history or arbitrary enqueue endpoint. */
class ProbeStore
{
    public const RETENTION = 86400;

    public function cache(): Repository
    {
        // Production always uses Redis for shared atomic locks and TTLs. Test override
        // cannot silently turn a deployment into process-local task scheduling.
        return Cache::store(app()->environment('testing') ? config('onenew.health_store', 'redis') : 'redis');
    }

    public function locked(int $machine, callable $callback): mixed
    {
        $lock = $this->cache()->lock('onenew:probe:lock:' . $machine, 30);
        if (!$lock->get()) {
            abort(409, 'Probe scheduler busy');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function task(string $id): ?array
    {
        return $this->cache()->get('onenew:probe:task:' . $id);
    }

    public function latest(int $node): ?array
    {
        return $this->cache()->get('onenew:probe:node:' . $node);
    }

    public function active(int $machine): ?array
    {
        $id = $this->cache()->get('onenew:probe:active:' . $machine);
        return $id ? $this->task($id) : null;
    }

    public function save(array $task): void
    {
        $running = $task['state'] === 'running';
        // Credentials and lease hashes expire with the lease even if the machine
        // disappears forever. Long-lived status records contain only metadata.
        $this->cache()->put('onenew:probe:task:' . $task['id'], $task,
            $running ? max(1, $task['expires_at'] - now()->timestamp) : 1200);
        $public = $task;
        unset($public['ciphertext'], $public['lease_hash'], $public['user_id'], $public['user_revision']);
        $latest = $this->latest((int) $task['node_id']);
        // An old machine's late result must not replace a new owner's task.
        if (!$latest || $latest['id'] === $task['id'] || $task['created_at'] > $latest['created_at']
            || ($running && $task['created_at'] === $latest['created_at'])) {
            $this->cache()->put('onenew:probe:node:' . $task['node_id'], $public, self::RETENTION);
        }
        if ($task['state'] === 'running') {
            $this->cache()->put('onenew:probe:active:' . $task['machine_id'], $task['id'], 300);
        } elseif ($this->cache()->get('onenew:probe:active:' . $task['machine_id']) === $task['id']) {
            $this->cache()->forget('onenew:probe:active:' . $task['machine_id']);
        }
    }
}
