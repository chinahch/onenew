<?php
use App\Onenew\AiOperations\Controller;
use Illuminate\Support\Facades\Route;
// Intentionally excludes body-audit middleware: prompts and API credentials are private.
Route::middleware(['api','admin'])->prefix('/api/onenew/admin/ai')->group(function(){
    Route::get('/config',[Controller::class,'config'])->middleware('throttle:90,1');
    Route::post('/config',[Controller::class,'save'])->middleware('throttle:30,1');
    Route::get('/options',[Controller::class,'options'])->middleware('throttle:90,1');
    Route::post('/preview',[Controller::class,'preview'])->middleware('throttle:30,1');
    Route::post('/chat',[Controller::class,'chat'])->middleware('throttle:onenew-ai-external');
    Route::post('/test',[Controller::class,'test'])->middleware('throttle:onenew-ai-external');
});
