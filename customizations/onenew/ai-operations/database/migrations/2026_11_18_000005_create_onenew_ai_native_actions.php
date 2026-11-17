<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('onenew_ai_native_actions',function(Blueprint $table){
            $table->uuid('id')->primary(); $table->unsignedBigInteger('admin_id')->index();
            $table->char('capability',64); $table->uuid('request_id')->nullable()->unique();
            $table->string('status',24); $table->text('encrypted_payload'); $table->text('encrypted_result')->nullable();
            $table->unsignedBigInteger('expires_at'); $table->unsignedBigInteger('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('onenew_ai_native_actions'); }
};
