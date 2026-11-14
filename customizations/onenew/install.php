<?php
// Apply the small, source-controlled overlay to the exact upstream image.
// Copyright and license files are deliberately retained with the base image.
declare(strict_types=1);

$root = $argv[1] ?? '/www';
$custom = __DIR__;
function replaceOnce(string $path, string $old, string $new): void
{
    $source = file_get_contents($path);
    if ($source !== false) { $source = str_replace("\r\n", "\n", $source); }
    if ($source === false || substr_count($source, $old) !== 1) {
        throw new RuntimeException('Pinned upstream anchor changed: ' . basename($path));
    }
    if (file_put_contents($path, str_replace($old, $new, $source)) === false) {
        throw new RuntimeException('Cannot write overlay target');
    }
}
function copyOverlay(string $source, string $target): void
{
    if (!is_dir($source)) {
        throw new RuntimeException('Missing overlay source: ' . $source);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink()) {
            throw new RuntimeException('Only regular overlay files are supported');
        }
        $destination = $target . DIRECTORY_SEPARATOR . substr($file->getPathname(), strlen($source) + 1);
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
            throw new RuntimeException('Cannot create overlay directory');
        }
        if (!copy($file->getPathname(), $destination)) {
            throw new RuntimeException('Cannot copy overlay file');
        }
    }
}

copyOverlay($custom . '/health/app', $root . '/app');
copyOverlay($custom . '/user-portal/backend/app', $root . '/app');
copyOverlay($custom . '/runtime/app', $root . '/app');
// Only a fresh native installation reaches this point in the command. Existing
// deployments keep their administrator-selected registration and brand policy.
replaceOnce($root . '/app/Console/Commands/XboardInstall.php',
    "            \$this->info('正在安装默认插件...');",
    "            \\App\\Models\\Setting::createOrUpdate('invite_force', 1);\n            \\App\\Models\\Setting::createOrUpdate('app_name', 'onenew');\n            \$this->info('正在安装默认插件...');");
replaceOnce($root . '/config/app.php',
    'App\\Providers\\ProtocolServiceProvider::class,',
    'App\\Providers\\ProtocolServiceProvider::class,' . "\n        App\\Onenew\\OnenewServiceProvider::class,");
replaceOnce($root . '/app/Models/Server.php', "class Server extends Model\n{",
    "class Server extends Model\n{\n    use \\App\\Onenew\\NodeHealthAttribute;");

// Keep the existing secure admin URL and subscription routes. Replace only
// the user homepage with the maintained Vue user centre.
$web = $root . '/routes/web.php';
$source = file_get_contents($web);
$start = strpos($source, "Route::get('/', function (Request \$request) {");
$end = strpos($source, "Route::get('/' . admin_setting('secure_path'");
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Pinned homepage route anchor changed');
}
$homepage = <<<'PHP'
Route::get('/', function () {
    return response()->file(public_path('onenew-user/index.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        'Referrer-Policy' => 'same-origin',
    ]);
});

PHP;
file_put_contents($web, str_replace("admin_setting('app_name', 'XBoard')", "admin_setting('app_name', 'onenew')", substr($source, 0, $start) . $homepage . substr($source, $end)));

// Rename runtime commands and worker process labels without changing model
// identities, DB tables, protocol names, or third-party copyright notices.
foreach (['app', 'config'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $text = file_get_contents($file->getPathname());
        $next = str_replace(['xboard:', 'xboard-ws-server'], ['onenew:', 'onenew-ws'], $text);
        $next = str_replace(["admin_setting('app_name', 'XBoard')", "admin_setting('app_name', 'Xboard')"], "admin_setting('app_name', 'onenew')", $next);
        if ($file->getFilename() === 'horizon.php') { $next = str_replace("'Xboard' =>", "'onenew' =>", $next); }
        if ($next !== $text) { file_put_contents($file->getPathname(), $next); }
    }
}
$env = $root . '/.env.example';
file_put_contents($env, str_replace(['APP_NAME=XBoard', 'DB_DATABASE=xboard'], ['APP_NAME=onenew', 'DB_DATABASE=onenew'], file_get_contents($env)));
$entrypoint = '/entrypoint.sh';
if ($root !== '/www') { $entrypoint = $root . '/entrypoint.sh'; }
$entry = file_get_contents($entrypoint);
$entry = str_replace(['xboard:', 'xboard-ws-server'], ['onenew:', 'onenew-ws'], $entry);
file_put_contents($entrypoint, $entry);

$supervisor = $root === '/www' ? '/etc/supervisor/conf.d/supervisord.conf' : $root . '/supervisord.conf';
$text = file_get_contents($supervisor);
foreach (['octane' => 'web', 'horizon' => 'queue', 'redis' => 'cache', 'ws-server' => 'ws', 'caddy' => 'proxy'] as $old => $new) {
    $text = str_replace('[program:' . $old . ']', '[program:onenew-' . $new . ']', $text);
}
$text .= "\n[program:onenew-scheduler]\ncommand=php /www/artisan schedule:work\nautostart=true\nautorestart=true\nuser=www\nredirect_stderr=true\nstdout_logfile=/dev/stdout\nstdout_logfile_maxbytes=0\nstopasgroup=true\nkillasgroup=true\npriority=600\n";
file_put_contents($supervisor, $text);
echo "Onenew overlay applied.\n";
