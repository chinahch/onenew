<?php
use App\Onenew\External\ExternalController;
use Illuminate\Support\Facades\Route;
Route::middleware(['api','admin'])->group(function(){
    Route::get('/api/onenew/admin/node-addresses',[\App\Onenew\NodeAddresses::class,'preview']);
    Route::post('/api/onenew/admin/node-addresses',[\App\Onenew\NodeAddresses::class,'apply']);
});
Route::middleware(['api','admin'])->prefix('/api/onenew/admin/external-nodes')->group(function () {
    Route::get('/',[ExternalController::class,'index']);
    Route::get('/lookups',[ExternalController::class,'lookups']);
    Route::get('/{id}',[ExternalController::class,'detail'])->whereNumber('id');
    Route::post('/save',[ExternalController::class,'save']);
    Route::post('/import',[ExternalController::class,'import']);
    Route::post('/toggle',[ExternalController::class,'toggle']);
    Route::post('/table-order',[ExternalController::class,'tableOrder']);
    Route::post('/order',[ExternalController::class,'order']);
    Route::post('/delete',[ExternalController::class,'drop']);
});
