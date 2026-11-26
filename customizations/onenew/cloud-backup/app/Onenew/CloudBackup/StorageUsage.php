<?php
namespace App\Onenew\CloudBackup;

use PDO;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use FilesystemIterator;

final class StorageUsage
{
    public function inspect(string $source, string $database): array
    {
        if (is_link($database) || !is_file($database)) {
            throw new RuntimeException('Database unavailable');
        }
        $db = $this->database($database);
        $deadline = microtime(true) + 5;
        $plugins = $this->directory($source . '/plugins', $deadline);
        $theme = $this->directory($source . '/storage/theme', $deadline);

        return [
            'checked_at' => time(),
            'total_bytes' => $db['total_bytes'] + $plugins['bytes'] + $theme['bytes'],
            'complete' => $plugins['complete'] && $theme['complete'],
            'database' => $db,
            'plugins' => $plugins,
            'theme' => $theme,
        ];
    }

    private function database(string $path): array
    {
        // A separate read-only connection cannot create a missing database or
        // change the application's connection settings in a long-lived worker.
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $pdo->beginTransaction();
        try {
            $pageSize = (int) $pdo->query('PRAGMA page_size')->fetchColumn();
            $pageCount = (int) $pdo->query('PRAGMA page_count')->fetchColumn();
            $freePages = (int) $pdo->query('PRAGMA freelist_count')->fetchColumn();
            $tables = $this->tables($pdo);
        } finally {
            $pdo->rollBack();
            $pdo = null;
        }
        $main = $this->fileBytes($path);
        $wal = $this->fileBytes($path . '-wal');
        $shm = $this->fileBytes($path . '-shm');

        return [
            'main_bytes' => $main,
            'wal_bytes' => $wal,
            'shm_bytes' => $shm,
            'total_bytes' => $main + $wal + $shm,
            'page_bytes' => $pageSize * $pageCount,
            'effective_bytes' => max(0, $pageSize * ($pageCount - $freePages)),
            'free_bytes' => $pageSize * $freePages,
            'table_sizes_available' => $tables !== null,
            'tables' => $tables ?? [],
        ];
    }

    private function tables(PDO $pdo): ?array
    {
        try {
            $objects = $pdo->query('SELECT name, SUM(pgsize) AS bytes FROM dbstat GROUP BY name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            // dbstat is optional in SQLite builds. Never report missing details
            // as zero-sized tables or lose the available file-size information.
            return null;
        }
        $schema = [];
        foreach ($pdo->query("SELECT name, type, tbl_name FROM sqlite_master WHERE type IN ('table', 'index')") as $row) {
            $schema[$row['name']] = $row;
        }
        $tables = [];
        foreach ($objects as $object) {
            $name = $object['name'];
            $table = $schema[$name]['tbl_name'] ?? $name;
            $tables[$table] ??= ['name' => $table, 'table_bytes' => 0, 'index_bytes' => 0, 'total_bytes' => 0];
            $bytes = (int) $object['bytes'];
            $column = ($schema[$name]['type'] ?? '') === 'index' ? 'index_bytes' : 'table_bytes';
            $tables[$table][$column] += $bytes;
            $tables[$table]['total_bytes'] += $bytes;
        }
        $tables = array_values($tables);
        usort($tables, fn ($a, $b) => ($b['total_bytes'] <=> $a['total_bytes']) ?: strcmp($a['name'], $b['name']));
        return array_slice($tables, 0, 15);
    }

    private function fileBytes(string $path): int
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Linked database file');
        }
        if (!is_file($path)) {
            return 0;
        }
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('File size unavailable');
        }
        return $size;
    }

    private function directory(string $path, float $deadline): array
    {
        clearstatcache(true, $path);
        $result = ['bytes' => 0, 'complete' => true];
        if (is_link($path)) {
            return ['bytes' => 0, 'complete' => false];
        }
        if (!is_dir($path)) {
            return $result;
        }
        $visited = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $entry) {
                if (++$visited > 50000 || microtime(true) > $deadline) {
                    $result['complete'] = false;
                    break;
                }
                if ($entry->isLink()) {
                    $result['complete'] = false;
                } elseif ($entry->isFile()) {
                    $result['bytes'] += $entry->getSize();
                }
            }
        } catch (\UnexpectedValueException | RuntimeException) {
            $result['complete'] = false;
        }
        return $result;
    }
}
