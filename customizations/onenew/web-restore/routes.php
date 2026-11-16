<?php
use Illuminate\Support\Facades\Route;
use App\Onenew\WebRestore\Controller;
Route::prefix('/api/onenew/admin/restore')->middleware(['api','admin','throttle:600,1'])->group(function(){
 Route::get('/',[Controller::class,'info']);Route::post('/',[Controller::class,'create'])->middleware('throttle:5,1');
 Route::get('/{id}',[Controller::class,'status']);Route::post('/{id}/chunk',[Controller::class,'chunk']);
 Route::post('/{id}/verify',[Controller::class,'verify'])->middleware('throttle:5,1');Route::post('/{id}/confirm',[Controller::class,'confirm'])->middleware('throttle:5,1');Route::post('/{id}/cancel',[Controller::class,'cancel']);
});
Route::post('/api/onenew/restore/progress',[Controller::class,'progress'])->middleware(['api','throttle:60,1']);
