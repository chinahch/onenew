<?php
require __DIR__ . '/MigrationBundle.php';
use App\Onenew\Migration\MigrationBundle;
try {
    if ($argc !== 4 || !preg_match('/^[0-9a-f]{40}$/D', $argv[3])) throw new RuntimeException('Usage: restore.php PACKAGE EMPTY_DESTINATION REVISION');
    $password = fgets(STDIN, 131);
    if ($password === false) throw new RuntimeException('Password input missing.');
    $password = rtrim($password, "\r\n");
    $header = MigrationBundle::inspect($argv[1]);
    if ($header['revision'] !== $argv[3]) throw new RuntimeException('Package revision differs from requested source revision.');
    $result = MigrationBundle::unpack($argv[1], $password, $argv[2]);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $e) { fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n"); exit(1); }
