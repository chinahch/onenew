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
        $field = self::FIELDS[$table];
        $now = time();
        $cutoff = $table === 'v2_onenew_domain_batches' ? PruneTelemetry::batchCutoff($now) : $now - $days * 86400;
        $query = DB::table($table);
        $expired = $table === 'onenew_runtime_log_entries'
            ? RuntimeLogs::expiredEntries($query, $cutoff)
            : $query->where($field, '<', $cutoff);
        // A bounded subquery avoids long write locks and SQLite parameter limits.
        $ids = (clone $expired)->select('id')->orderBy($field)->orderBy('id')->limit(self::LIMIT);
        $deleted = (clone $expired)->whereIn('id', $ids)->delete();
        return ['deleted' => $deleted, 'remaining' => $deleted === self::LIMIT && $expired->exists()];
    }
}
