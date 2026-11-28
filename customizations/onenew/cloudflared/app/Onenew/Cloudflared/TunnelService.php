<?php
namespace App\Onenew\Cloudflared;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TunnelService
{
    public const ACTIONS = ['install', 'restart', 'uninstall'];
    public const ACTIVE = ['leased', 'running'];
    public const TERMINAL = ['succeeded', 'failed', 'cancelled', 'expired'];
    public const ERRORS = [
        'download_failed',
        'install_failed',
        'service_failed',
        'uninstall_failed',
        'invalid_token',
        'invalid_task',
        'unsupported',
        'expired',
        'internal_error',
    ];
    private const JOBS = 'v2_onenew_tunnel_jobs';
    private const AGENTS = 'v2_onenew_tunnel_agents';

    private function locked(callable $fn)
    {
        return DB::transaction(function () use ($fn) {
            DB::table('v2_onenew_tunnel_mutex')->where('id', 1)->increment('revision');
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
        return $row && hash_equals($row->identity, $b['identity']) && hash_equals($row->credential_hash, $b['credential_hash']);
    }

    private function expire(): void
    {
        $now = now()->timestamp;
        DB::table(self::JOBS)
            ->where('state', 'pending')
            ->where('expires_at', '<=', $now)
            ->update(['state' => 'expired', 'error_code' => 'expired', 'token_encrypted' => null, 'updated_at' => $now]);
        DB::table(self::JOBS)
            ->whereIn('state', self::ACTIVE)
            ->where('lease_expires_at', '<=', $now - 60)
            ->update(['state' => 'expired', 'error_code' => 'expired', 'token_encrypted' => null, 'updated_at' => $now]);
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
                'version' => $valid && (string) $a->version !== '' ? (string) $a->version : null,
                'arch' => $valid ? (string) $a->arch : null,
                'assistant_version' => $valid ? (string) $a->assistant_version : null,
                'seen_at' => $valid ? (int) $a->seen_at : null,
                'job' => $latest ? $this->project($latest) : null,
            ];
        });
    }

    public function create(int $machineId, string $action, ?string $token, int $adminId): array
    {
        return $this->locked(function () use ($machineId, $action, $token, $adminId) {
            $m = ServerMachine::lockForUpdate()->findOrFail($machineId);
            $a = DB::table(self::AGENTS)->where('machine_id', $m->id)->first();
            abort_unless($m->is_active && $this->matches($a, $m), 422, '请先在该服务器更新支持隧道管理的升级助手');
            $binding = $this->binding($m);

            DB::table(self::JOBS)
                ->where('machine_id', $m->id)
                ->where('state', 'pending')
                ->where(function ($q) use ($binding) {
                    $q->where('identity', '!=', $binding['identity'])
                        ->orWhere('credential_hash', '!=', $binding['credential_hash']);
                })
                ->update(['state' => 'failed', 'error_code' => 'invalid_task', 'token_encrypted' => null, 'updated_at' => now()->timestamp]);

            abort_if(
                DB::table(self::JOBS)->where('machine_id', $m->id)->where($binding)->whereIn('state', array_merge(['pending'], self::ACTIVE))->exists(),
                409,
                '该服务器已有进行中的隧道任务'
            );

            $encrypted = null;
            if ($action === 'install') {
                abort_unless(is_string($token) && preg_match('/^[A-Za-z0-9_-]{32,1024}={0,2}$/D', $token) === 1, 422, 'Cloudflare Tunnel Token 格式无效');
                $encrypted = Crypt::encryptString($token);
            }

            $now = now()->timestamp;
            $row = array_merge([
                'id' => (string) Str::uuid(),
                'machine_id' => (int) $m->id,
                'admin_id' => $adminId,
                'action' => $action,
                'token_encrypted' => $encrypted,
                'state' => 'pending',
                'error_code' => '',
                'lease_hash' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $now + 86400,
                'lease_expires_at' => null,
            ], $binding);

            DB::table(self::JOBS)->insert($row);
            return ['job' => $this->project((object) $row)];
        });
    }

    public function poll(ServerMachine $m, array $data): ?array
    {
        return $this->locked(function () use ($m, $data) {
            $m = $this->current($m);
            $now = now()->timestamp;
            $agent = array_merge($this->binding($m), [
                'machine_id' => (int) $m->id,
                'arch' => $data['arch'],
                'installed' => (bool) $data['installed'],
                'running' => (bool) $data['running'],
                'version' => (string) ($data['version'] ?? ''),
                'assistant_version' => $data['assistant_version'],
                'seen_at' => $now,
            ]);
            DB::table(self::AGENTS)->updateOrInsert(['machine_id' => $m->id], $agent);

            if (DB::table(self::JOBS)->where('machine_id', $m->id)->whereIn('state', self::ACTIVE)->exists()) {
                return null;
            }

            $row = DB::table(self::JOBS)->where('machine_id', $m->id)->where('state', 'pending')->orderBy('sequence')->first();
            if (!$row) {
                return null;
            }
            if (!$this->matches($row, $m)) {
                DB::table(self::JOBS)->where('id', $row->id)->update(['state' => 'failed', 'error_code' => 'invalid_task', 'token_encrypted' => null, 'updated_at' => $now]);
                return null;
            }

            $token = '';
            if ($row->action === 'install') {
                if (!$row->token_encrypted) {
                    DB::table(self::JOBS)->where('id', $row->id)->update(['state' => 'failed', 'error_code' => 'invalid_token', 'updated_at' => $now]);
                    return null;
                }
                try {
                    $token = Crypt::decryptString($row->token_encrypted);
                } catch (\Throwable) {
                    DB::table(self::JOBS)->where('id', $row->id)->update(['state' => 'failed', 'error_code' => 'invalid_token', 'token_encrypted' => null, 'updated_at' => $now]);
                    return null;
                }
            }

            $lease = bin2hex(random_bytes(32));
            $expiry = $now + 600;
            DB::table(self::JOBS)->where('id', $row->id)->update([
                'state' => 'leased',
                'lease_hash' => hash('sha256', $lease),
                'lease_expires_at' => $expiry,
                'updated_at' => $now,
            ]);

            $payload = [
                'id' => $row->id,
                'lease' => $lease,
                'expires_at' => $expiry,
                'action' => $row->action,
            ];
            if ($row->action === 'install') {
                $payload['token'] = $token;
            }
            return $payload;
        });
    }

    public function accept(ServerMachine $m, string $id, array $data): void
    {
        $this->locked(function () use ($m, $id, $data) {
            $m = $this->current($m);
            $now = now()->timestamp;
            $row = DB::table(self::JOBS)->where('id', $id)->first();
            abort_unless(
                $row && (int) $row->machine_id === (int) $m->id
                    && $this->matches($row, $m)
                    && $row->lease_hash
                    && hash_equals($row->lease_hash, hash('sha256', $data['lease'])),
                409
            );

            $state = $data['state'];
            $error = $data['error_code'] ?? '';
            if (in_array($row->state, self::TERMINAL, true)) {
                abort_unless($row->state === $state && $row->error_code === $error, 409);
                return;
            }
            abort_unless(in_array($row->state, self::ACTIVE, true) && $row->lease_expires_at > $now, 409, 'Lease expired');
            if ($state === 'succeeded') {
                abort_unless($error === '', 422);
            }
            if ($state === 'failed') {
                abort_unless($error !== '', 422);
            }
            if ($state === 'running') {
                abort_unless($error === '', 422);
            }

            $update = ['state' => $state, 'error_code' => $error, 'updated_at' => $now];
            if (in_array($state, self::TERMINAL, true)) {
                $update['token_encrypted'] = null;
            }
            DB::table(self::JOBS)->where('id', $id)->update($update);
            DB::table(self::AGENTS)->where('machine_id', $m->id)->update([
                'installed' => (bool) $data['installed'],
                'running' => (bool) $data['running'],
                'version' => (string) ($data['version'] ?? ''),
                'seen_at' => $now,
            ]);
        });
    }

    public function cancel(string $id): void
    {
        $this->locked(function () use ($id) {
            $row = DB::table(self::JOBS)->where('id', $id)->first();
            abort_unless($row, 404);
            $machine = ServerMachine::find($row->machine_id);
            abort_unless($machine && $this->matches($row, $machine), 409, 'Server binding changed');
            abort_unless($row->state === 'pending', 409, '仅可取消等待中的任务');
            DB::table(self::JOBS)->where('id', $id)->update([
                'state' => 'cancelled',
                'token_encrypted' => null,
                'updated_at' => now()->timestamp,
            ]);
        });
    }

    private function project(object $row): array
    {
        return [
            'id' => $row->id,
            'machine_id' => (int) $row->machine_id,
            'action' => $row->action,
            'state' => $row->state,
            'error_code' => $row->error_code,
            'created_at' => (int) $row->created_at,
            'updated_at' => (int) $row->updated_at,
            'expires_at' => (int) $row->expires_at,
            'lease_expires_at' => $row->lease_expires_at ? (int) $row->lease_expires_at : null,
        ];
    }
}
