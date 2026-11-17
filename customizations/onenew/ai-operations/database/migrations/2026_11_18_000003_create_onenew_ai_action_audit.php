<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('onenew_ai_action_audit',function(Blueprint $t){
        $t->bigIncrements('id');$t->unsignedBigInteger('admin_id')->index();$t->uuid('action_id')->unique();$t->uuid('request_id');
        $t->string('kind',40);$t->unsignedBigInteger('target_id')->nullable();$t->string('result_code',32);
        $t->text('encrypted_result');$t->unsignedBigInteger('credential_expires_at');$t->unsignedBigInteger('created_at');
        $t->unique(['admin_id','request_id']);
    }); }
    public function down(): void { Schema::dropIfExists('onenew_ai_action_audit'); }
};
