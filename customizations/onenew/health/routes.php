<?php

use App\Onenew\ProbeController;
use Illuminate\Support\Facades\Route;

// Loaded once by OnenewServiceProvider, outside the web/CSRF middleware group.
Route::middleware('api')->group(function () {
    Route::get('/api/v3/server/probes', [ProbeController::class, 'poll']);
    Route::post('/api/v3/server/probes/{id}/result', [ProbeController::class, 'result']);
    Route::get('/api/onenew/user/nodes', [ProbeController::class, 'nodes'])->middleware('user');
});
