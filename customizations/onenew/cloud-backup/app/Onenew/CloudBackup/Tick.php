<?php
namespace App\Onenew\CloudBackup;
use Illuminate\Console\Command;
final class Tick extends Command
{
 protected $signature='onenew:cloud-backup-tick';protected $description='Queue due encrypted Google Drive backups and recover expired attempts';
 public function handle(Service $service):int{$service->tick();return 0;}
}
