<?php
namespace Tests\Feature;

use App\Onenew\Migration\MigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EphemeralPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_ephemeral_logs_removes_old_rows_and_all_option_clears_everything(): void
    {
        $old = time() - 90000;
        $recent = time() - 600;
        DB::table('v2_onenew_traffic_receipts')->insert([
            ['batch_id' => 'old-receipt', 'created_at' => $old],
            ['batch_id' => 'recent-receipt', 'created_at' => $recent],
        ]);
        DB::table('onenew_runtime_log_batches')->insert([
            ['stream_id' => 1, 'batch_id' => 'old-batch', 'received_at' => $old],
            ['stream_id' => 1, 'batch_id' => 'recent-batch', 'received_at' => $recent],
        ]);

        $this->artisan('onenew:prune-ephemeral-logs')->assertSuccessful();
        $this->assertDatabaseMissing('v2_onenew_traffic_receipts', ['batch_id' => 'old-receipt']);
        $this->assertDatabaseHas('v2_onenew_traffic_receipts', ['batch_id' => 'recent-receipt']);
        $this->assertDatabaseMissing('onenew_runtime_log_batches', ['batch_id' => 'old-batch']);
        $this->assertDatabaseHas('onenew_runtime_log_batches', ['batch_id' => 'recent-batch']);

        $this->artisan('onenew:prune-ephemeral-logs', ['--all' => true])->assertSuccessful();
        $this->assertDatabaseCount('v2_onenew_traffic_receipts', 0);
        $this->assertDatabaseCount('onenew_runtime_log_batches', 0);
    }

    public function test_migration_snapshot_strips_ephemeral_tables_while_preserving_core_data(): void
    {
        $root = sys_get_temp_dir() . '/onenew-snap-' . Str::uuid();
        mkdir($root . '/.docker/.data', 0700, true);
        file_put_contents($root . '/.env', 'APP_KEY=' . config('app.key') . "\n");
        $dbPath = $root . '/.docker/.data/database.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec("INSERT INTO v2_user (id, email) VALUES (1, 'keep@example.test')");
        $pdo->exec('CREATE TABLE v2_onenew_traffic_receipts (id INTEGER PRIMARY KEY, batch_id TEXT)');
        $pdo->exec("INSERT INTO v2_onenew_traffic_receipts (id, batch_id) VALUES (1, 'drop-me')");
        $pdo = null;

        config([
            'onenew.migration_source' => $root,
            'onenew.migration_database' => $dbPath,
            'onenew.migration_revision' => str_repeat('a', 40),
        ]);

        $id = (string) Str::uuid();
        Cache::shouldReceive('store')->with('redis')->andReturn(new class($id) {
            private array $data;
            public function __construct(string $id) { $this->data = ['onenew:migration:job:' . $id => ['id' => $id, 'state' => 'queued']]; }
            public function get(string $k) { return $this->data[$k] ?? null; }
            public function put(string $k, $v, $ttl = null): void { $this->data[$k] = $v; }
        });

        $service = new MigrationService();
        try {
            $service->prepare($id);
            $stage = $service->snapshot($id);
            $snapDb = $stage . '/data/database/database.sqlite';
            $check = new \PDO('sqlite:' . $snapDb);
            self::assertSame(1, (int) $check->query('SELECT COUNT(*) FROM v2_user')->fetchColumn());
            self::assertSame(0, (int) $check->query('SELECT COUNT(*) FROM v2_onenew_traffic_receipts')->fetchColumn());
            $check = null;
            // Live database untouched
            $live = new \PDO('sqlite:' . $dbPath);
            self::assertSame(1, (int) $live->query('SELECT COUNT(*) FROM v2_onenew_traffic_receipts')->fetchColumn());
            $live = null;
        } finally {
            if (is_dir($root)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
                rmdir($root);
            }
        }
    }
}
