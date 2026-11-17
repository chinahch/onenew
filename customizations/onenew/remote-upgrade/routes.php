<?php
use App\Onenew\RemoteUpgrade\UpgradeController;
use Illuminate\Support\Facades\Route;
Route::middleware('api')->group(function () {
    Route::get('/api/onenew/admin/upgrades',[UpgradeController::class,'index'])->middleware(['admin','throttle:90,1']);
    Route::post('/api/onenew/admin/upgrades',[UpgradeController::class,'create'])->middleware(['admin','throttle:10,1']);
    Route::post('/api/onenew/admin/upgrades/{id}/cancel',[UpgradeController::class,'cancel'])->middleware(['admin','throttle:30,1']);
    // Token lives only in Authorization; no body-audit middleware or raw result logs.
    Route::get('/api/v3/server/upgrade/poll',[UpgradeController::class,'poll'])->middleware('throttle:onenew-upgrade-machine');
    Route::post('/api/v3/server/upgrade/{id}/status',[UpgradeController::class,'status'])->middleware('throttle:onenew-upgrade-machine');
});
