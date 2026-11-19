<?php

use App\Jobs\TrafficFetchJob;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (getenv('ONENEW_CI_FIXTURE') !== 'isolated-only') {
    throw new RuntimeException('Disposable deployment required');
}
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::create([
    'email' => 'quota-queue@example.test', 'password' => 'unused', 'uuid' => (string) Str::uuid(),
    'token' => bin2hex(random_bytes(16)), 'u' => 0, 'd' => 0, 'transfer_enable' => 100000,
]);
$queue = Queue::connection('redis');
$name = 'ci-quota-'.Str::uuid();
$queue->push(new TrafficFetchJob(['rate' => 2], [$user->id => [10, 20]], 'vmess', time()), '', $name);
$first = $queue->pop($name) ?? throw new RuntimeException('Redis did not deliver quota job');
$body = $first->getRawBody();
$first->fire();
$queue->pushRaw($body, $name);
$replay = $queue->pop($name) ?? throw new RuntimeException('Redis did not redeliver quota job');
$replay->fire();
$user->refresh();
if ((int) $user->u !== 20 || (int) $user->d !== 40) {
    throw new RuntimeException('Redis redelivery duplicated or dropped quota writes');
}
echo "Redis quota job redelivery preserved counters\n";
