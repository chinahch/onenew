<?php

use App\Onenew\MachineEgressController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'admin', 'throttle:90,1'])->group(function () {
    Route::get('/api/onenew/admin/machine-egress', [MachineEgressController::class, 'fetch']);
    Route::post('/api/onenew/admin/machine-egress', [MachineEgressController::class, 'save']);
});
