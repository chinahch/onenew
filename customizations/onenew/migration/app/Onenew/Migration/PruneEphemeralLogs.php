<?php
namespace App\Onenew\Migration;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneEphemeralLogs extends Command
{
    protected $signature = 'onenew:prune-ephemeral-logs {--all : Clear all rows instead of keeping the last 24 hours} {--vacuum : Run SQLite VACUUM after pruning}';
    protected $description = 'Daily cleanup of ephemeral telemetry/log tables and SQLite WAL truncation';

    private const TARGETS = [
        ['table' => 'v2_onenew_traffic_receipts', 'column' => 'created_at'],
        ['table' => 'v2_onenew_domain_events', 'column' => 'observed_at'],
        ['table' => 'v2_onenew_domain_batches', 'column' => 'created_at'],
        ['table' => 'onenew_runtime_log_entries', 'column' => 'received_at'],
        ['table' => 'onenew_runtime_log_batches', 'column' => 'received_at'],
    ];

    public function handle(): int
    {
        $clearAll = (bool) $this->option('all');
        $cutoff = time() - 86400;
        $total = 0;

        foreach (self::TARGETS as $target) {
            $table = $target['table'];
            if (!Schema::hasTable($table)) continue;
            if ($clearAll) {
                $total += DB::table($table)->delete();
                continue;
            }
            $col = $target['column'];
            do {
                $ids = DB::table($table)->where($col, '<', $cutoff)->orderBy('id')->limit(2000)->pluck('id');
                if ($ids->isEmpty()) break;
                $total += DB::table($table)->whereIn('id', $ids)->delete();
            } while (true);
        }

        if (config('database.connections.' . config('database.default') . '.driver') === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->exec('PRAGMA busy_timeout = 30000');
            if ($this->option('vacuum')) {
                $pdo->exec('VACUUM');
            }
            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        }

        $this->info('Pruned ' . $total . ' ephemeral log/telemetry rows.');
        return self::SUCCESS;
    }
}
