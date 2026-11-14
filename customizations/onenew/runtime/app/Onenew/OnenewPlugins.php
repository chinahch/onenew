<?php
namespace App\Onenew;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;

final class OnenewPlugins
{
    public static function installMissing(): void
    {
        // install() leaves the plugin disabled. Never overwrite operator config
        // or enable automatic account penalties during an application update.
        if (!Plugin::where('code', 'subscription_guard')->exists()) {
            app(PluginManager::class)->install('subscription_guard');
        }
    }
}
