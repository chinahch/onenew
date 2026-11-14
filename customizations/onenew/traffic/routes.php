<?php

use App\Onenew\Traffic\DomainAuditController;
use App\Onenew\Traffic\TrafficController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'admin'])->prefix('/api/onenew/admin/traffic')->group(function () {
    Route::get('/records', [TrafficController::class, 'records']);
    Route::get('/nodes', [TrafficController::class, 'nodes']);
    Route::get('/domains', [TrafficController::class, 'domains']);
    Route::get('/domain-settings', [DomainAuditController::class, 'settings']);
    Route::put('/domain-settings', [DomainAuditController::class, 'settings']);
    Route::get('/audit-config', [DomainAuditController::class, 'settings']);
    Route::post('/audit-config', [DomainAuditController::class, 'settings']);
});
Route::post('/api/v3/server/domain-audit', [DomainAuditController::class, 'ingest'])->middleware('api');
Route::get('/api/v3/server/domain-audit/config', [DomainAuditController::class, 'configuration'])->middleware('api');
