<?php
use App\Onenew\LandingLibrary\LandingController;
use Illuminate\Support\Facades\Route;
// Credentials deliberately bypass request-body audit logging, like exit probes.
Route::middleware(['api','admin','throttle:90,1'])->prefix('/api/onenew/admin/landings')->group(function(){
    Route::get('',[LandingController::class,'index']);
    Route::post('',[LandingController::class,'save']);
    Route::get('/{id}',[LandingController::class,'show'])->whereNumber('id');
    Route::post('/{id}',[LandingController::class,'save'])->whereNumber('id');
    Route::post('/{id}/delete',[LandingController::class,'delete'])->whereNumber('id');
});
