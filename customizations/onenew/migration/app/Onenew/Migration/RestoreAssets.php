<?php
namespace App\Onenew\Migration;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;

final class RestoreAssets extends Command
{
    protected $signature='onenew:migration-assets';
    protected $description='Rebuild installed plugin public assets without changing plugin policy';
    public function handle(): int
    {
        $publisher=new class extends PluginManager {
            public function republish(string $code): void { $this->publishAssets($code); }
        };
        foreach(Plugin::query()->pluck('code') as $code)$publisher->republish($code);
        $this->info('Installed plugin assets restored.');return 0;
    }
}
