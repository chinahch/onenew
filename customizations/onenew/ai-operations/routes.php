<?php
use App\Onenew\AiOperations\Controller;
use Illuminate\Support\Facades\Route;
// Intentionally excludes body-audit middleware: prompts and API credentials are private.
Route::middleware(['api','admin'])->prefix('/api/onenew/admin/ai')->group(function(){
    Route::get('/config',[Controller::class,'config'])->middleware('throttle:90,1');
    Route::post('/config',[Controller::class,'save'])->middleware('throttle:30,1');
    Route::get('/options',[Controller::class,'options'])->middleware('throttle:90,1');
    Route::post('/node-links',[Controller::class,'nodeLinks'])->middleware('throttle:30,1');
    Route::post('/preview',[Controller::class,'preview'])->middleware('throttle:30,1');
    Route::post('/chat',[Controller::class,'chat'])->middleware('throttle:onenew-ai-external');
    Route::post('/test',[Controller::class,'test'])->middleware('throttle:onenew-ai-external');
    Route::post('/operator/tasks',[Controller::class,'operatorCreate'])->middleware('throttle:onenew-ai-external');
    Route::get('/operator/tasks/{id}',[Controller::class,'operatorView'])->middleware('throttle:120,1');
    Route::post('/actions/{id}/execute',[Controller::class,'execute'])->middleware('throttle:30,1');
    Route::post('/native/{id}/execute',[Controller::class,'executeNative'])->middleware('throttle:30,1');
    Route::post('/code/{id}/publish',[Controller::class,'publish'])->middleware('throttle:10,1');
    Route::get('/repository',[Controller::class,'repository'])->middleware('throttle:90,1');
    Route::post('/repository',[Controller::class,'saveRepository'])->middleware('throttle:30,1');
});
