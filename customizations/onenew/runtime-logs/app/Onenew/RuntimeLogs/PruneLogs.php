<?php
namespace App\Onenew\RuntimeLogs;
final class PruneLogs extends \Illuminate\Console\Command
{
 protected $signature='onenew:prune-runtime-logs';
 protected $description='Prune expired machine runtime logs and deduplication metadata';
 public function handle(Service $service):int{$service->prune();return 0;}
}
