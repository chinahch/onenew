<?php
use App\Onenew\RuntimeLogs\Controller;
use Illuminate\Support\Facades\Route;
Route::middleware('api')->group(function(){
 // Never attach request-body audit logging to the bearer-only ingestion route.
 Route::post('/api/v3/server/runtime-logs',[Controller::class,'upload'])->middleware('throttle:onenew-runtime-logs-machine');
 Route::get('/api/onenew/admin/machines/{id}/runtime-logs',[Controller::class,'index'])->middleware(['admin','throttle:120,1']);
});
