<?php
use Illuminate\Support\Facades\Route;
use App\Onenew\CloudBackup\Controller;
Route::middleware(['api','admin','throttle:20,1,cloud-backup'])->prefix('/api/onenew/admin/cloud-backup')->group(function(){
 Route::get('/',[Controller::class,'info']);Route::post('/settings',[Controller::class,'save']);Route::post('/test',[Controller::class,'connection']);Route::post('/run',[Controller::class,'run']);
 Route::get('/storage-usage',[Controller::class,'storageUsage'])->middleware('throttle:6,1,cloud-backup-usage');
 Route::post('/storage-cleanup',[Controller::class,'cleanupStorage'])->middleware('throttle:6,1,cloud-backup-cleanup');
});
