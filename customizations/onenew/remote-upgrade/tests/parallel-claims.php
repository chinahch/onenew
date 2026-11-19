<?php

use App\Models\ServerMachine;
use App\Models\User;
use App\Onenew\RemoteUpgrade\UpgradeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('ONENEW_CI_FIXTURE') !== 'isolated-only') {
    throw new RuntimeException('Disposable deployment required');
}
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$service = app(UpgradeService::class);
$capability = ['version' => 'v0.1.15', 'assistant_version' => 'v0.1.16', 'arch' => 'amd64'];

if (($argv[1] ?? '') === '--machine') {
    if (!ctype_digit($argv[2] ?? '')) {
        throw new RuntimeException('Expected fixture machine ID');
    }
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout=10000');
    }
    $machine = ServerMachine::findOrFail((int) $argv[2]);
    if (!str_starts_with($machine->name, 'ci-parallel-upgrade-')) {
        throw new RuntimeException('Expected disposable machine');
    }
    echo json_encode(['claimed' => $service->poll($machine, $capability) !== null]);
    exit;
}

$machines = [];
$workers = [];
try {
    foreach (range(1, 7) as $i) {
        $machine = ServerMachine::create([
            'name' => 'ci-parallel-upgrade-'.$i, 'token' => bin2hex(random_bytes(24)), 'is_active' => true,
        ]);
        $machines[] = $machine;
        $service->poll($machine, $capability);
    }
    $ids = array_map(fn ($machine) => $machine->id, $machines);
    $service->create($ids, 'v0.1.16', User::where('email', 'ci-onenew@example.test')->firstOrFail()->id);

    // Independent Laravel processes contend on the same persisted database.
    foreach ($machines as $machine) {
        $process = proc_open([PHP_BINARY, __FILE__, '--machine', (string) $machine->id],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start parallel claim fixture');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $claims = 0;
    foreach ($workers as [$process, $pipes]) {
        $result = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Parallel claim worker failed');
        }
        $claims += (int) json_decode($result, true, 8, JSON_THROW_ON_ERROR)['claimed'];
    }
    $active = DB::table('v2_onenew_upgrade_jobs')->whereIn('machine_id', $ids)->whereIn('state', UpgradeService::ACTIVE)->count();
    if ($claims !== 5 || $active !== 5) {
        throw new RuntimeException('Parallel claim limit was not respected');
    }
    echo "Seven independent upgrade polls claimed exactly five leases\n";
} finally {
    $ids = array_map(fn ($machine) => $machine->id, $machines);
    DB::table('v2_onenew_upgrade_jobs')->whereIn('machine_id', $ids)->delete();
    DB::table('v2_onenew_upgrade_agents')->whereIn('machine_id', $ids)->delete();
    foreach ($machines as $machine) {
        $machine->delete();
    }
}
