<?php
use Illuminate\Support\Facades\Route;
use App\Onenew\SubscriptionLogs;
Route::prefix('api/onenew/admin/subscription-logs')->middleware(['api','admin','throttle:90,1'])->group(function(){
    Route::get('/users',[SubscriptionLogs::class,'users']);Route::get('/',[SubscriptionLogs::class,'index']);
});
