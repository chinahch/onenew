<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('onenew_user_requests',function(Blueprint $table){
        $table->id();$table->unsignedBigInteger('user_id');$table->char('request_hash',64);$table->string('period',32);$table->string('trade_no',36);$table->unsignedBigInteger('created_at');$table->unique(['user_id','request_hash'],'onenew_user_request_unique');
    });
        Schema::create('onenew_user_subscription_links',function(Blueprint $table){
            $table->unsignedBigInteger('user_id')->primary();$table->char('token_hash',64);$table->text('encrypted_url');$table->unsignedBigInteger('updated_at');
        });
    }
    public function down(): void { Schema::dropIfExists('onenew_user_subscription_links');Schema::dropIfExists('onenew_user_requests'); }
};
