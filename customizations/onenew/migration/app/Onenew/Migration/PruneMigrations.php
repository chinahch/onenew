<?php
namespace App\Onenew\Migration;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class PruneMigrations extends Command
{
    protected $signature='onenew:prune-migrations';
    protected $description='Remove expired private migration artifacts';
    public function handle(MigrationService $service): int
    {
        if(!is_dir($service->root())||is_link($service->root()))return 0;
        foreach(new \DirectoryIterator($service->root()) as $entry){
            if($entry->isDot()||$entry->isLink()||!$entry->isDir()||!Str::isUuid($entry->getFilename()))continue;
            $state=$service->status($entry->getFilename());
            if(($state&&$state['expires_at']<=time())||(!$state&&$entry->getMTime()<time()-3600))$service->removeTree($entry->getPathname());
        }
        return 0;
    }
}
