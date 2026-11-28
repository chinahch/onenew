<?php

use App\Onenew\SelfSteal\SelfStealController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::get('/api/onenew/admin/self-steal', [SelfStealController::class, 'index'])->middleware(['admin', 'throttle:90,1']);
    Route::get('/api/onenew/admin/self-steal/{machineId}', [SelfStealController::class, 'show'])->middleware(['admin', 'throttle:90,1']);
    Route::post('/api/onenew/admin/self-steal/{machineId}', [SelfStealController::class, 'create'])->middleware(['admin', 'throttle:20,1']);
    Route::post('/api/onenew/admin/self-steal/jobs/{id}/cancel', [SelfStealController::class, 'cancel'])->middleware(['admin', 'throttle:30,1']);
    Route::get('/api/v3/server/self-steal/poll', [SelfStealController::class, 'poll'])->middleware('throttle:onenew-upgrade-machine');
    Route::post('/api/v3/server/self-steal/{id}/status', [SelfStealController::class, 'status'])->middleware('throttle:onenew-upgrade-machine');
});
