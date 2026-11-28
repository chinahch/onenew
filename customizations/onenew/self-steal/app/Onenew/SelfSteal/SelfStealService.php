<?php

namespace App\Onenew\SelfSteal;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SelfStealService
{
    public const ACTIONS = ['install', 'restart', 'uninstall'];
    public const ACTIVE = ['leased', 'running'];
    public const TERMINAL = ['succeeded', 'failed', 'cancelled', 'expired'];
    public const ERRORS = [
        'download_failed',
        'install_failed',
        'service_failed',
        'uninstall_failed',
        'invalid_domain',
        'invalid_port',
        'invalid_task',
        'unsupported',
        'expired',
        'internal_error',
    ];
    private const JOBS = 'v2_onenew_self_steal_jobs';
    private const AGENTS = 'v2_onenew_self_steal_agents';

    /**
     * When self_steal is enabled on Reality settings, point node-side handshake dest to 127.0.0.1:<port>
     * while keeping server_name intact for client SNI and TLS certificate validation.
     */
    public static function applyRealityDest(mixed $reality): mixed
    {
        if (!is_array($reality) || empty($reality['self_steal'])) {
            return $reality;
        }
        $port = (int) ($reality['server_port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            $port = 443;
        }
        $reality['dest'] = '127.0.0.1:' . $port;
        return $reality;
    }

    public static function summaryForMachine(ServerMachine $m): array
    {
        try {
            $row = DB::table(self::AGENTS)->where('machine_id', $m->id)->first();
            if (!$row || (!$row->installed && !$row->running)) {
                return ['installed' => false, 'domain' => '', 'port' => 0];
            }
            $identity = (string) $m->onenew_identity;
            $credHash = hash('sha256', (string) $m->token);
            if (!hash_equals((string) $row->identity, $identity) || !hash_equals((string) $row->credential_hash, $credHash)) {
                return ['installed' => false, 'domain' => '', 'port' => 0];
            }
            return [
                'installed' => true,
                'domain' => (string) $row->domain,
                'port' => (int) $row->port,
            ];
        } catch (\Throwable) {
            return ['installed' => false, 'domain' => '', 'port' => 0];
        }
    }

    public function listActive(): array
    {
        $machines = ServerMachine::all()->keyBy('id');
        $rows = DB::table(self::AGENTS)->where(function ($q) {
            $q->where('installed', true)->orWhere('running', true);
        })->get();
        $out = [];
        foreach ($rows as $row) {
            $m = $machines->get($row->machine_id);
            if (!$m || !$this->matches($row, $m) || (string) $row->domain === '') {
                continue;
            }
            $out[] = [
                'machine_id' => (int) $m->id,
                'name' => (string) $m->name,
                'installed' => (bool) $row->installed,
                'running' => (bool) $row->running,
                'domain' => (string) $row->domain,
                'port' => (int) $row->port,
            ];
        }
        return $out;
    }

    private function locked(callable $fn)
    {
        return DB::transaction(function () use ($fn) {
            DB::table('v2_onenew_self_steal_mutex')->where('id', 1)->increment('revision');
            $this->expire();
            return $fn();
        }, 3);
    }

    private function binding(ServerMachine $m): array
    {
        return [
            'identity' => (string) $m->onenew_identity,
            'credential_hash' => hash('sha256', (string) $m->token),
        ];
    }

    private function current(ServerMachine $authenticated): ServerMachine
    {
        $m = ServerMachine::lockForUpdate()->find($authenticated->id);
        abort_unless(
            $m && $m->is_active
                && (string) $m->onenew_identity === (string) $authenticated->onenew_identity
                && hash_equals((string) $m->token, (string) $authenticated->token),
            403
        );
        return $m;
    }

    private function matches($row, ServerMachine $m): bool
    {
        $b = $this->binding($m);
        return $row && hash_equals((string) $row->identity, $b['identity']) && hash_equals((string) $row->credential_hash, $b['credential_hash']);
    }

    private function expire(): void
    {
        $now = now()->timestamp;
        DB::table(self::JOBS)
            ->where('state', 'pending')
            ->where('expires_at', '<=', $now)
            ->update(['state' => 'expired', 'error_code' => 'expired', 'updated_at' => $now]);
        DB::table(self::JOBS)
            ->whereIn('state', self::ACTIVE)
            ->where('lease_expires_at', '<=', $now - 60)
            ->update(['state' => 'expired', 'error_code' => 'expired', 'updated_at' => $now]);
    }

    public function show(int $machineId): array
    {
        return DB::transaction(function () use ($machineId) {
            $now = now()->timestamp;
            $m = ServerMachine::findOrFail($machineId);
            $a = DB::table(self::AGENTS)->where('machine_id', $m->id)->first();
            $valid = $this->matches($a, $m);
            $latest = DB::table(self::JOBS)->where('machine_id', $m->id)->orderByDesc('sequence')->first();
            if ($latest && !$this->matches($latest, $m)) {
                $latest = null;
            }
            if ($latest && (
                ($latest->state === 'pending' && $latest->expires_at <= $now)
                || (in_array($latest->state, self::ACTIVE, true) && $latest->lease_expires_at <= $now - 60)
            )) {
                $latest->state = 'expired';
                $latest->error_code = 'expired';
                $latest->updated_at = $latest->lease_expires_at === null
                    ? (int) $latest->expires_at
                    : (int) $latest->lease_expires_at + 60;
            }

            return [
                'machine_id' => (int) $m->id,
                'name' => (string) $m->name,
                'is_active' => (bool) $m->is_active,
                'capable' => $valid,
                'online' => $valid && $a->seen_at >= $now - 90,
                'installed' => $valid ? (bool) $a->installed : false,
                'running' => $valid ? (bool) $a->running : false,
                'domain' => $valid ? (string) $a->domain : '',
                'port' => $valid ? (int) $a->port : 8443,
                'version' => $valid && (string) $a->version !== '' ? (string) $a->version : null,
                'arch' => $valid ? (string) $a->arch : null,
                'assistant_version' => $valid ? (string) $a->assistant_version : null,
                'seen_at' => $valid ? (int) $a->seen_at : null,
                'job' => $latest ? $this->project($latest) : null,
            ];
        });
    }

    public function create(int $machineId, string $action, ?string $domain, ?int $port, int $adminId): array
    {
        return $this->locked(function () use ($machineId, $action, $domain, $port, $adminId) {
            $m = ServerMachine::lockForUpdate()->findOrFail($machineId);
            $a = DB::table(self::AGENTS)->where('machine_id', $m->id)->first();
            abort_unless($m->is_active && $this->matches($a, $m), 422, '请先在该服务器更新支持伪装站管理的升级助手');
            $binding = $this->binding($m);

            DB::table(self::JOBS)
                ->where('machine_id', $m->id)
                ->where('state', 'pending')
                ->where(function ($q) use ($binding) {
                    $q->where('identity', '!=', $binding['identity'])
                        ->orWhere('credential_hash', '!=', $binding['credential_hash']);
                })
                ->update(['state' => 'failed', 'error_code' => 'invalid_task', 'updated_at' => now()->timestamp]);

            abort_if(
                DB::table(self::JOBS)->where('machine_id', $m->id)->where($binding)->whereIn('state', array_merge(['pending'], self::ACTIVE))->exists(),
                409,
                '该服务器已有进行中的伪装站任务'
            );

            $cleanDomain = '';
            $cleanPort = 8443;
            if ($action === 'install') {
                $cleanDomain = strtolower(trim((string) $domain));
                abort_unless(
                    strlen($cleanDomain) >= 3 && strlen($cleanDomain) <= 253
                        && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $cleanDomain) === 1,
                    422,
                    '伪装域名格式无效'
                );
                $cleanPort = $port !== null && $port > 0 ? $port : 8443;
                abort_unless($cleanPort >= 1 && $cleanPort <= 65535 && $cleanPort !== 80, 422, '本地回落端口无效（需在 1–65535 之间且不能为 80）');
            }

            $now = now()->timestamp;
            $row = array_merge([
                'id' => (string) Str::uuid(),
                'sequence' => (int) DB::table(self::JOBS)->max('sequence') + 1,
                'machine_id' => $m->id,
                'action' => $action,
                'domain' => $cleanDomain,
                'port' => $cleanPort,
                'state' => 'pending',
                'lease_hash' => null,
                'lease_expires_at' => null,
                'expires_at' => $now + 600,
                'error_code' => null,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $binding);

            DB::table(self::JOBS)->insert($row);
            return $this->project((object) $row);
        });
    }

    public function cancel(string $id): void
    {
        $this->locked(function () use ($id) {
            $job = DB::table(self::JOBS)->where('id', $id)->first();
            abort_unless($job, 404);
            abort_unless($job->state === 'pending', 409, '仅等待中的任务可以取消');
            DB::table(self::JOBS)->where('id', $id)->update([
                'state' => 'cancelled',
                'updated_at' => now()->timestamp,
            ]);
        });
    }

    public function poll(ServerMachine $authenticated, array $meta): ?array
    {
        return $this->locked(function () use ($authenticated, $meta) {
            $m = $this->current($authenticated);
            $now = now()->timestamp;
            $b = $this->binding($m);

            DB::table(self::AGENTS)->updateOrInsert(['machine_id' => $m->id], array_merge($b, [
                'arch' => $meta['arch'],
                'installed' => (bool) $meta['installed'],
                'running' => (bool) $meta['running'],
                'domain' => (string) ($meta['domain'] ?? ''),
                'port' => (int) ($meta['port'] ?? 8443),
                'version' => (string) ($meta['version'] ?? ''),
                'assistant_version' => $meta['assistant_version'],
                'seen_at' => $now,
                'updated_at' => $now,
            ]));

            if (DB::table(self::JOBS)->where('machine_id', $m->id)->where($b)->whereIn('state', self::ACTIVE)->exists()) {
                return null;
            }
            $job = DB::table(self::JOBS)
                ->where('machine_id', $m->id)
                ->where($b)
                ->where('state', 'pending')
                ->orderBy('sequence')
                ->first();
            if (!$job) {
                return null;
            }

            $lease = bin2hex(random_bytes(32));
            $expires = $now + 300;
            DB::table(self::JOBS)->where('id', $job->id)->update([
                'state' => 'leased',
                'lease_hash' => hash('sha256', $lease),
                'lease_expires_at' => $expires,
                'updated_at' => $now,
            ]);

            $payload = [
                'id' => $job->id,
                'lease' => $lease,
                'expires_at' => $expires,
                'action' => $job->action,
            ];
            if ($job->action === 'install') {
                $payload['domain'] = (string) $job->domain;
                $payload['port'] = (int) $job->port;
            }
            return $payload;
        });
    }

    public function accept(ServerMachine $authenticated, string $id, array $data): void
    {
        $this->locked(function () use ($authenticated, $id, $data) {
            $m = $this->current($authenticated);
            $job = DB::table(self::JOBS)->where('id', $id)->where('machine_id', $m->id)->first();
            abort_unless($job && $this->matches($job, $m), 403);
            $now = now()->timestamp;
            abort_unless(
                in_array($job->state, self::ACTIVE, true)
                    && $job->lease_hash
                    && hash_equals($job->lease_hash, hash('sha256', $data['lease']))
                    && $job->lease_expires_at >= $now,
                403
            );

            $next = $data['state'];
            $allowed = match ($job->state) {
                'leased' => ['running', 'succeeded', 'failed'],
                'running' => ['running', 'succeeded', 'failed'],
                default => [],
            };
            abort_unless(in_array($next, $allowed, true), 409);

            $update = [
                'state' => $next,
                'error_code' => $next === 'failed' ? ($data['error_code'] ?: 'internal_error') : null,
                'updated_at' => $now,
            ];
            if ($next === 'running') {
                $update['lease_expires_at'] = $now + 300;
            }
            DB::table(self::JOBS)->where('id', $id)->update($update);

            $agentUpdate = [
                'installed' => (bool) $data['installed'],
                'running' => (bool) $data['running'],
                'domain' => (string) ($data['domain'] ?? ''),
                'port' => (int) ($data['port'] ?? 8443),
                'version' => (string) ($data['version'] ?? ''),
                'seen_at' => $now,
                'updated_at' => $now,
            ];
            DB::table(self::AGENTS)->where('machine_id', $m->id)->update($agentUpdate);
        });
    }

    private function project(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'action' => (string) $row->action,
            'domain' => (string) $row->domain,
            'port' => (int) $row->port,
            'state' => (string) $row->state,
            'error_code' => $row->error_code ? (string) $row->error_code : null,
            'created_at' => (int) $row->created_at,
            'updated_at' => (int) $row->updated_at,
        ];
    }
}
