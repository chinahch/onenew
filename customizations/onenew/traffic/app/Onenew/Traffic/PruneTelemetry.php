<?php

namespace App\Onenew\Traffic;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTelemetry extends Command
{
    protected $signature = 'onenew:prune-telemetry';
    protected $description = 'Remove expired domain metadata while retaining traffic replay receipts';

    public function handle(): int
    {
        $count = $this->prune('v2_onenew_domain_events', 'observed_at', time() - DomainAuditController::retention() * 86400);
        // The maximum accepted event age is 30 days. Keep replay receipts longer
        // than that, so an original expired batch cannot be re-ingested after GC.
        $count += $this->prune('v2_onenew_domain_batches', 'created_at', time() - 31 * 86400);
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
