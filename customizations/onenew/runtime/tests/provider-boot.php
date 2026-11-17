<?php
// Boot the real provider up to migration registration without Laravel/DB infrastructure.
namespace Illuminate\Support {
    class ServiceProvider {
        public $app;
        protected function loadMigrationsFrom($path):void {throw new \RuntimeException('migration_registration_reached');}
    }
}
namespace Illuminate\Support\Facades {class RateLimiter {public static function for($name,$callback):void {}}}
namespace {
    function app($class){throw new RuntimeException('Provider eagerly resolved '.$class.' during application boot');}
    require __DIR__.'/../../runtime/app/Onenew/OnenewServiceProvider.php';
    $provider=new App\Onenew\OnenewServiceProvider;
    $provider->app=new class {public function booted($callback):void {$callback();}};
    try{$provider->boot();throw new RuntimeException('Provider boot did not register migrations');}
    catch(RuntimeException $e){if($e->getMessage()!=='migration_registration_reached')throw $e;}
    echo "Provider boot does not resolve scheduler or plugin database\n";
}
