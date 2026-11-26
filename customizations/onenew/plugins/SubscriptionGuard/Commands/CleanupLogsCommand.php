<?php

namespace Plugin\SubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupLogsCommand extends Command
{
    protected $signature = 'subscription-guard:cleanup {--days=30 : 清理多少天前的日志}';
    protected $description = '清理订阅拉取日志';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if (!ctype_digit((string)$this->option('days')) || $days < 1 || $days > 365) {
            $this->error('days 必须为 1–365');
            return self::INVALID;
        }
        $before = now()->subDays($days);
        require_once __DIR__ . '/../Plugin.php';
        $logCount = \Plugin\SubscriptionGuard\Plugin::pruneBefore('v2_subscribe_log', $before);
        \Plugin\SubscriptionGuard\Plugin::pruneBefore('v2_subscribe_abuse', $before);

        $this->info("已清理 {$logCount} 条订阅拉取日志 ({$days}天前)");

        return 0;
    }
}
