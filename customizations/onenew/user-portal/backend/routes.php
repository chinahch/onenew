<?php
use App\Onenew\PortalController;
use Illuminate\Support\Facades\Route;
Route::prefix('api/onenew')->middleware(['api'])->group(function(){
    Route::get('invites/{code}',[PortalController::class,'verifyInvite'])->middleware('throttle:30,1');
    Route::post('register',[PortalController::class,'register'])->middleware('throttle:10,1');
    Route::prefix('user')->middleware('user')->group(function(){
        Route::get('me',[PortalController::class,'me']);
        Route::post('logout',[PortalController::class,'logout']);
        Route::get('subscription',[PortalController::class,'link']);
        Route::post('subscription-token',[PortalController::class,'rotate'])->middleware('throttle:5,1');
        Route::get('renewal-plan',[PortalController::class,'renewalPlan']);
        Route::get('orders',[PortalController::class,'orders']);
        Route::get('renewals',[PortalController::class,'orders']);
        Route::post('renewals',[PortalController::class,'renew'])->middleware('throttle:20,1');
        Route::post('renewals/{trade}/cancel',[PortalController::class,'cancel']);
        Route::get('invite',[PortalController::class,'invite']);
        Route::post('invite',[PortalController::class,'createInvite'])->middleware('throttle:10,1');
        Route::get('usage',[PortalController::class,'usage']);
    });
});
