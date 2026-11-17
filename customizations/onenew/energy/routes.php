<?php
use App\Onenew\Energy\EnergyController;
use Illuminate\Support\Facades\Route;
Route::prefix('api/onenew/user/energy')->middleware(['api','user','throttle:30,1'])->group(function(){
    Route::get('/',[EnergyController::class,'index']);Route::post('/',[EnergyController::class,'create']);Route::post('{id}/cancel',[EnergyController::class,'cancel']);
});
Route::prefix('api/onenew/admin/energy')->middleware(['api','admin','throttle:90,1'])->group(function(){
    Route::get('attention',[EnergyController::class,'attention']);
    Route::get('/',[EnergyController::class,'adminIndex']);Route::post('{id}/review',[EnergyController::class,'review']);
});
