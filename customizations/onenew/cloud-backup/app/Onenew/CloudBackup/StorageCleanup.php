<?php
namespace App\Onenew\CloudBackup;

use App\Onenew\RuntimeLogs\Service as RuntimeLogs;
use App\Onenew\Traffic\DomainAuditController;
use App\Onenew\Traffic\PruneTelemetry;
use Illuminate\Support\Facades\DB;

final class StorageCleanup
{
    private const LIMIT = 5000;
    private const FIELDS = [
        'v2_onenew_domain_events' => 'observed_at',
        'v2_onenew_domain_batches' => 'created_at',
        'onenew_runtime_log_entries' => 'at',
        'onenew_runtime_log_batches' => 'received_at',
    ];

    public function policies(): array
    {
        return [
            'v2_onenew_domain_events' => DomainAuditController::retention(),
            'v2_onenew_domain_batches' => PruneTelemetry::BATCH_RETENTION_DAYS,
            'onenew_runtime_log_entries' => RuntimeLogs::RETENTION_DAYS,
            'onenew_runtime_log_batches' => RuntimeLogs::BATCH_RETENTION_DAYS,
        ];
    }

    public function prune(string $table, int $confirmedDays): ?array
    {
        $days = $this->policies()[$table] ?? null;
        if ($days === null || $days !== $confirmedDays) {
            return null;
        }
        $now = time();
        $cutoff = $table === 'v2_onenew_domain_batches' ? PruneTelemetry::batchCutoff($now) : $now - $days * 86400;
        if ($table === 'onenew_runtime_log_entries') {
            // Query each indexed timestamp column separately so SQLite never falls back to a full table scan on OR.
            $ids = DB::table($table)->where('received_at', '<', $cutoff)->orderBy('received_at')->orderBy('id')->limit(self::LIMIT)->pluck('id')->all();
            if (count($ids) < self::LIMIT) {
                $more = DB::table($table)->where('at', '<', $cutoff)->where('received_at', '>=', $cutoff)
                    ->orderBy('at')->orderBy('id')->limit(self::LIMIT - count($ids))->pluck('id')->all();
                $ids = array_merge($ids, $more);
            }
            $deleted = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
            }
            $remaining = count($ids) === self::LIMIT && (
                DB::table($table)->where('received_at', '<', $cutoff)->exists() ||
                DB::table($table)->where('at', '<', $cutoff)->exists()
            );
        } else {
            $field = self::FIELDS[$table];
            $expired = DB::table($table)->where($field, '<', $cutoff);
            // Bounded batches avoid long write locks and SQLite parameter limits.
            $ids = (clone $expired)->orderBy($field)->orderBy('id')->limit(self::LIMIT)->pluck('id')->all();
            $deleted = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
            }
            $remaining = count($ids) === self::LIMIT && $expired->exists();
        }
        if ($deleted > 0 && !$remaining && DB::connection()->getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() !== ':memory:') {
            try { DB::statement('PRAGMA wal_checkpoint(PASSIVE)'); } catch (\Throwable) {}
        }
        return ['deleted' => $deleted, 'remaining' => $remaining];
    }
}
