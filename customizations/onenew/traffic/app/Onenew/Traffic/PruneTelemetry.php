<?php

namespace App\Onenew\Traffic;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTelemetry extends Command
{
    public const BATCH_RETENTION_DAYS = 1;

    public static function batchCutoff(int $now): int
    {
        // Even events at the maximum allowed future timestamp must have expired.
        return $now - self::BATCH_RETENTION_DAYS * 86400 - DomainAuditController::FUTURE_SKEW_SECONDS;
    }
    protected $signature = 'onenew:prune-telemetry';
    protected $description = 'Remove expired domain metadata while retaining traffic replay receipts';

    public function handle(): int
    {
        $count = $this->prune('v2_onenew_domain_events', 'observed_at', time() - DomainAuditController::retention() * 86400);
        $count += $this->prune('v2_onenew_domain_batches', 'created_at', self::batchCutoff(time()));
        $this->info('Removed ' . $count . ' expired domain metadata rows.');
        return self::SUCCESS;
    }

    private function prune(string $table, string $field, int $cutoff): int
    {
        $count = 0;
        do {
            $ids = DB::table($table)->where($field, '<', $cutoff)->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $count += DB::table($table)->whereIn('id', $ids)->delete();
        } while (true);
        return $count;
    }
}
