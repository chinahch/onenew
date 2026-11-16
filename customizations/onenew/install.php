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
require $custom . '/domains/install.php';
applyOnenewUserDomains($root);
copyOverlay($custom . '/migration/app', $root . '/app');
copyOverlay($custom . '/web-restore/app', $root . '/app');
copyOverlay($custom . '/cloud-backup/app', $root . '/app');
require $custom . '/cloud-backup/install.php';
applyOnenewCloudBackup($root);
copyOverlay($custom . '/energy/app', $root . '/app');
copyOverlay($custom . '/bandwidth/app', $root . '/app');
require $custom . '/bandwidth/install.php';
applyOnenewNodeBandwidth($root);
require $custom . '/runtime/install-ids.php';
applyOnenewResourceIds($root);
copyOverlay($custom . '/external/app', $root . '/app');
require $custom . '/external/install.php';
applyOnenewExternalNodes($root);
copyOverlay($custom . '/egress/app', $root . '/app');
replaceOnce($root . '/app/Services/ServerService.php',
    "        return \$response;\n    }",
    "        \$ipFamily = \\App\\Onenew\\MachineEgress::forNode(\$node);\n        if (\$ipFamily !== null) \$response['ip_family'] = \$ipFamily;\n        return \$response;\n    }");
copyOverlay($custom . '/traffic/app', $root . '/app');
copyOverlay($custom . '/protocols/app', $root . '/app');
require $custom . '/protocols/install.php';
applyOnenewProtocolPatch($root);
copyOverlay($custom . '/exit-probe/app', $root . '/app');
copyOverlay($custom . '/landing-library/app', $root . '/app');
require $custom . '/landing-library/install.php';
applyOnenewLandingLibrary($root);
require $custom . '/ss-transport/install.php';
applyOnenewShadowsocksTransport($root);
replaceOnce($root . '/app/Services/ServerService.php',
    "            'kernel_status' => (bool) (\$metrics['kernel_status'] ?? false),",
    "            'kernel_status' => (bool) (\$metrics['kernel_status'] ?? false),\n"
    . "            'kernel_type' => in_array(\$metrics['kernel_type'] ?? null, ['xray', 'singbox'], true) ? \$metrics['kernel_type'] : null,\n"
    . "            'onenew_runtime' => (\$metrics['onenew_runtime'] ?? null) === true,");
replaceOnce($root . '/app/Observers/ServerObserver.php', "            'server_port',", "            'host',\n            'server_port',");
// The native controller previously pointed at cedar2025's stock installer.
$machineController = $root . '/app/Http/Controllers/V2/Admin/Server/MachineController.php';
$machineSource = file_get_contents($machineController);
$machineSource = str_replace("\r\n", "\n", $machineSource);
$startCommand = strpos($machineSource, "        \$installerUrl = 'https://raw.githubusercontent.com/cedar2025/xboard-node/dev/install.sh';");
$endCommand = $startCommand === false ? false : strpos($machineSource, "\n        );", $startCommand);
if ($startCommand === false || $endCommand === false) {
    throw new RuntimeException('Pinned machine installer anchor changed');
}
$machineSource = substr($machineSource, 0, $startCommand)
    . '        return \\App\\Onenew\\NodeInstallCommand::make($panelUrl, $machine->token, (int) $machine->id);'
    . substr($machineSource, $endCommand + strlen("\n        );"));
$machineSource = str_replace(
    "            'command' => \$this->buildInstallCommand(\$request, \$machine),",
    "            'command' => \$this->buildInstallCommand(\$request, \$machine),\n"
        . "            'upgrade_command' => \\App\\Onenew\\NodeInstallCommand::upgrade("
        . "rtrim((string) (admin_setting('app_url') ?: \$request->getSchemeAndHttpHost()), '/'), (int) \$machine->id, \$machine->token),",
    $machineSource,
    $upgradeCount
);
if ($upgradeCount !== 1) {
    throw new RuntimeException('Pinned machine upgrade command anchor changed');
}
file_put_contents($machineController, $machineSource);
require $custom . '/traffic/install.php';
applyOnenewTrafficPatch($root);
replaceOnce($root . '/app/Console/Kernel.php',
    'app(PluginManager::class)->registerPluginSchedules($schedule);',
    'app(PluginManager::class)->registerPluginSchedules($schedule);' . "\n        \$schedule->command('onenew:prune-telemetry')->dailyAt('03:10')->withoutOverlapping();\n        \$schedule->command('onenew:prune-migrations')->everyTenMinutes()->withoutOverlapping();");
foreach (['XboardInstall.php', 'XboardUpdate.php'] as $command) {
    replaceOnce($root . '/app/Console/Commands/' . $command,
        'PluginManager::installDefaultPlugins();',
        'PluginManager::installDefaultPlugins();' . "\n        \\App\\Onenew\\OnenewPlugins::installMissing();");
}
// Only a fresh native installation reaches this point in the command. Existing
// deployments keep their administrator-selected registration and brand policy.
replaceOnce($root . '/app/Console/Commands/XboardInstall.php',
    "            \$this->info('正在安装默认插件...');",
    "            \\App\\Models\\Setting::createOrUpdate('invite_force', 1);\n            \\App\\Models\\Setting::createOrUpdate('app_name', '暖秋');\n            \$this->info('正在安装默认插件...');");
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
file_put_contents($web, str_replace("admin_setting('app_name', 'XBoard')", "admin_setting('app_name', '暖秋')", substr($source, 0, $start) . $homepage . substr($source, $end)));
replaceOnce($web, "    return view('admin', [", "    return response()->view('admin', [");
replaceOnce($web, "    ]);\n});\n\nRoute::get('/' . (admin_setting('subscribe_path', 's'))",
    "    ])->header('Cache-Control', 'no-store');\n});\n\nRoute::get('/' . (admin_setting('subscribe_path', 's'))");

// Rename runtime commands and worker process labels without changing model
// identities, DB tables, protocol names, or third-party copyright notices.
foreach (['app', 'config'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $text = file_get_contents($file->getPathname());
        $next = str_replace(['xboard:', 'xboard-ws-server'], ['onenew:', 'onenew-ws'], $text);
        $next = str_replace(["admin_setting('app_name', 'XBoard')", "admin_setting('app_name', 'Xboard')"], "admin_setting('app_name', '暖秋')", $next);
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
$entry = str_replace('chown redis:redis /data 2>/dev/null || true',
    'chown redis:www /data && chmod 0750 /data', $entry);
$entry = str_replace("set -e\n", "set -e\nphp /opt/onenew/web-restore/agent.php --recover\n", $entry);
$entry = str_replace('exec "$@"', 'php /opt/onenew/seed-plugins.php /www' . "\n" . 'exec "$@"', $entry);
// Seeding precedes the startup migration/plugin check as well as the first install.
$entry = str_replace('php /www/artisan onenew:update --no-interaction', 'php /opt/onenew/seed-plugins.php /www && php /www/artisan onenew:update --no-interaction', $entry);
file_put_contents($entrypoint, $entry);

$supervisor = $root === '/www' ? '/etc/supervisor/conf.d/supervisord.conf' : $root . '/supervisord.conf';
$text = file_get_contents($supervisor);
foreach (['octane' => 'web', 'horizon' => 'queue', 'redis' => 'cache', 'ws-server' => 'ws', 'caddy' => 'proxy'] as $old => $new) {
    $text = str_replace('[program:' . $old . ']', '[program:onenew-' . $new . ']', $text);
}
$text .= "\n[program:onenew-scheduler]\ncommand=php /www/artisan schedule:work\nautostart=true\nautorestart=true\nuser=www\nredirect_stderr=true\nstdout_logfile=/dev/stdout\nstdout_logfile_maxbytes=0\nstopasgroup=true\nkillasgroup=true\npriority=600\n";
$text .= "\n[unix_http_server]\nfile=/run/onenew-supervisor.sock\nchmod=0700\n[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface\n[supervisorctl]\nserverurl=unix:///run/onenew-supervisor.sock\n[program:onenew-restore]\ncommand=php /opt/onenew/web-restore/agent.php\nautostart=true\nautorestart=true\nuser=root\nredirect_stderr=true\nstdout_logfile=/dev/stdout\nstdout_logfile_maxbytes=0\nstopasgroup=true\nkillasgroup=true\npriority=700\n";
file_put_contents($supervisor, $text);
echo "Onenew overlay applied.\n";

