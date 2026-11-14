<?php
use App\Onenew\ExitProbeController;
use Illuminate\Support\Facades\Route;
Route::middleware('api')->group(function () {
    // Deliberately excludes request-body audit middleware: drafts contain credentials.
    Route::post('/api/onenew/admin/exit-probes', [ExitProbeController::class, 'create'])->middleware(['admin', 'throttle:10,1']);
    Route::get('/api/onenew/admin/exit-probes/{id}', [ExitProbeController::class, 'status'])->middleware(['admin', 'throttle:90,1']);
    Route::get('/api/onenew/admin/node-runtime/{node}', [ExitProbeController::class, 'runtime'])->whereNumber('node')->middleware(['admin', 'throttle:90,1']);
    Route::get('/api/v3/server/exit-probes', [ExitProbeController::class, 'poll'])->middleware('throttle:60,1');
    Route::post('/api/v3/server/exit-probes/{id}/result', [ExitProbeController::class, 'result'])->middleware('throttle:60,1');
});
