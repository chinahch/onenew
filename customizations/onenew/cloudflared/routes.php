<?php
use App\Onenew\Cloudflared\TunnelController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::get('/api/onenew/admin/tunnels/{machineId}', [TunnelController::class, 'show'])->middleware(['admin', 'throttle:90,1']);
    Route::post('/api/onenew/admin/tunnels/{machineId}', [TunnelController::class, 'create'])->middleware(['admin', 'throttle:20,1']);
    Route::post('/api/onenew/admin/tunnels/jobs/{id}/cancel', [TunnelController::class, 'cancel'])->middleware(['admin', 'throttle:30,1']);
    Route::get('/api/v3/server/tunnel/poll', [TunnelController::class, 'poll'])->middleware('throttle:onenew-upgrade-machine');
    Route::post('/api/v3/server/tunnel/{id}/status', [TunnelController::class, 'status'])->middleware('throttle:onenew-upgrade-machine');
});
