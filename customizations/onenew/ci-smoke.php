<?php
// Isolated CI fixture only. Never run this against an existing deployment.
declare(strict_types=1);
if (getenv('ONENEW_CI_FIXTURE') !== 'isolated-only') { throw new RuntimeException('CI fixture guard required'); }
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (App\Models\User::where('email', 'ci-onenew@example.test')->exists()) { throw new RuntimeException('Fixture must run once in an empty CI deployment'); }
$user = new App\Models\User();
$user->email = 'ci-onenew@example.test';
$user->password = password_hash('ci-onenew-disposable-2048', PASSWORD_DEFAULT);
$user->uuid = (string) Illuminate\Support\Str::uuid();
$user->token = bin2hex(random_bytes(16));
$user->is_admin = true;
$user->banned = false;
$user->group_id = 1;
$user->transfer_enable = 1073741824;
$user->u = 0;
$user->d = 0;
$user->expired_at = time() + 86400;
$user->save();
echo "Isolated Onenew CI account created.\n";
