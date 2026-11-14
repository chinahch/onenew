<?php
namespace Tests;

// Only explicitly loaded by ci-bootstrap.php inside the disposable test image.
// Configure the named native Redis cache before routes/Setting are constructed.
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app = require '/www/bootstrap/app.php';
        $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
            function ($application): void {
                $application['config']->set('cache.default', 'array');
                $application['config']->set('cache.stores.redis', ['driver' => 'array', 'serialize' => false]);
                $application['config']->set('queue.default', 'sync');
                $application['config']->set('session.driver', 'array');
            });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
}
