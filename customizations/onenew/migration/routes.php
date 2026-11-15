<?php
use App\Onenew\Migration\MigrationController;
use Illuminate\Support\Facades\Route;
Route::middleware(['api','admin','throttle:30,1'])->prefix('/api/onenew/admin/migration')->group(function(){
    Route::get('/',[MigrationController::class,'info']);
    Route::post('/exports',[MigrationController::class,'create']);
    Route::get('/exports/{id}',[MigrationController::class,'status']);
    Route::post('/exports/{id}/ticket',[MigrationController::class,'ticket']);
});
Route::post('/api/onenew/migration/download',[MigrationController::class,'download'])->middleware(['api','throttle:30,1']);
