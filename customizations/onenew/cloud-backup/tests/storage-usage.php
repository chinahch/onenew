<?php
require __DIR__ . '/../app/Onenew/CloudBackup/StorageUsage.php';

use App\Onenew\CloudBackup\StorageUsage;

function checkUsage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/onenew-storage-usage-' . bin2hex(random_bytes(8));
mkdir($root . '/plugins/example', 0700, true);
mkdir($root . '/storage/theme', 0700, true);
mkdir($root . '/onenew-migrations', 0700, true);
$database = $root . '/database.sqlite';
$outside = $root . '-outside';
try {
    file_put_contents($root . '/plugins/example/plugin.php', str_repeat('p', 128));
    file_put_contents($root . '/storage/theme/theme.css', str_repeat('t', 256));
    file_put_contents($root . '/onenew-migrations/package.onm', str_repeat('b', 8192));
    file_put_contents($outside, str_repeat('s', 4096));
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE v2_onenew_traffic_receipts (batch_id TEXT PRIMARY KEY, created_at INTEGER)');
    $pdo->exec("INSERT INTO v2_onenew_traffic_receipts VALUES ('fixture-batch', 1)");
    $pdo->exec('CREATE TABLE discarded (id INTEGER PRIMARY KEY, body TEXT)');
    $pdo->exec('INSERT INTO discarded(body) VALUES (zeroblob(262144))');
    $pdo->exec('DELETE FROM discarded');
    $expectedFree = (int) $pdo->query('PRAGMA freelist_count')->fetchColumn() * (int) $pdo->query('PRAGMA page_size')->fetchColumn();
    checkUsage($expectedFree > 0, 'fixture must contain reusable free pages');
    $pdo = null;

    $service = new StorageUsage();
    $before = hash_file('sha256', $database);
    $first = $service->inspect($root, $database);
    checkUsage($first['complete'], 'ordinary directories must be complete');
    checkUsage($first['plugins']['bytes'] === 128 && $first['theme']['bytes'] === 256, 'resource sizes must be exact');
    checkUsage($first['database']['free_bytes'] === $expectedFree, 'free pages must be measured');
    checkUsage($first['total_bytes'] === filesize($database) + 384, 'temporary backup packages and free pages must not be counted twice');
    checkUsage(hash_file('sha256', $database) === $before, 'inspection must not change the database');
    checkUsage(!str_contains(json_encode($first), $root), 'response must not expose absolute paths');
    if ($first['database']['table_sizes_available']) {
        $receipts = array_values(array_filter($first['database']['tables'], fn ($row) => $row['name'] === 'v2_onenew_traffic_receipts'));
        checkUsage(count($receipts) === 1, 'receipts must appear as one table with their index');
        checkUsage($receipts[0]['index_bytes'] > 0, 'the automatic primary-key index must be included');
        checkUsage($receipts[0]['total_bytes'] === $receipts[0]['table_bytes'] + $receipts[0]['index_bytes'], 'table total must include its indexes exactly once');
    } else {
        checkUsage($first['database']['tables'] === [], 'unavailable dbstat must not fabricate table sizes');
    }

    file_put_contents($root . '/plugins/example/plugin.php', str_repeat('p', 512));
    $second = $service->inspect($root, $database);
    checkUsage($second['plugins']['bytes'] === 512, 'another inspection must observe changed files');
    checkUsage($second['total_bytes'] - $first['total_bytes'] === 384, 'the total must refresh without caching');
    $writer = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writer->exec('PRAGMA journal_mode=WAL');
    $writer->exec('PRAGMA wal_autocheckpoint=0');
    $writer->exec("INSERT INTO v2_onenew_traffic_receipts VALUES ('live-wal-batch', 2)");
    $live = $service->inspect($root, $database);
    checkUsage($live['database']['wal_bytes'] > 0 && $live['database']['shm_bytes'] > 0, 'live SQLite sidecar files must be counted');
    checkUsage($live['database']['total_bytes'] === $live['database']['main_bytes'] + $live['database']['wal_bytes'] + $live['database']['shm_bytes'], 'database disk total must include WAL and SHM exactly once');
    checkUsage((int) $writer->query('SELECT COUNT(*) FROM v2_onenew_traffic_receipts')->fetchColumn() === 2, 'inspection must preserve traffic receipts');
    $writer = null;
    if (@symlink($outside, $root . '/plugins/external')) {
        $linked = $service->inspect($root, $database);
        checkUsage(!$linked['complete'] && $linked['plugins']['bytes'] === 512, 'symlinks must be skipped and incomplete totals identified');
    }
    try {
        $service->inspect($root, $root . '/missing.sqlite');
        throw new LogicException('missing databases must fail');
    } catch (RuntimeException) {
        checkUsage(!file_exists($root . '/missing.sqlite'), 'inspection must not create a missing database');
    }
    echo 'Storage usage read-only, freshness, size and index checks passed (dbstat: ' . ($first['database']['table_sizes_available'] ? 'available' : 'unavailable') . ").\n";
} finally {
    foreach (['plugins/external', 'plugins/example/plugin.php', 'storage/theme/theme.css', 'onenew-migrations/package.onm', 'database.sqlite', 'database.sqlite-wal', 'database.sqlite-shm'] as $file) {
        if (is_file($root . '/' . $file) || is_link($root . '/' . $file)) {
            unlink($root . '/' . $file);
        }
    }
    foreach (['plugins/example', 'plugins', 'storage/theme', 'storage', 'onenew-migrations', ''] as $directory) {
        if (is_dir($root . '/' . $directory)) {
            rmdir($root . '/' . $directory);
        }
    }
    if (is_file($outside)) {
        unlink($outside);
    }
}
