<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::create('onenew_ai_operator_tasks',function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('admin_id');$t->uuid('request_id');$t->string('state',16)->default('queued');$t->mediumText('encrypted_payload')->nullable();$t->mediumText('encrypted_result')->nullable();$t->string('progress',200)->default('等待后台任务');$t->string('error',200)->nullable();$t->unsignedBigInteger('created_at');$t->unsignedBigInteger('started_at')->nullable();$t->unsignedBigInteger('finished_at')->nullable();$t->unsignedBigInteger('expires_at')->index();$t->unique(['admin_id','request_id']);});}
    public function down():void {Schema::dropIfExists('onenew_ai_operator_tasks');}
};
