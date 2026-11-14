<?php

use Illuminate\Support\Facades\Route;

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

// Public static shell, like the native admin shell. All data/actions require admin bearer auth.
Route::get('/' . $securePath . '/subscription-guard', function () {
    $nonce=base64_encode(random_bytes(18));
    return response()->view('SubscriptionGuard::dashboard', [
        'nonce'=>$nonce,
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
    ])->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')
      ->header('X-Content-Type-Options','nosniff')
      ->header('Content-Security-Policy',"default-src 'none'; script-src 'self'; style-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; frame-ancestors 'self'; form-action 'none'");
})->name('subscription-guard.dashboard');
