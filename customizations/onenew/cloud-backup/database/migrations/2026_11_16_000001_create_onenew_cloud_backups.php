<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('onenew_cloud_backup_config',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->text('encrypted_config')->nullable();$t->boolean('enabled')->default(false);$t->string('daily_time',5)->default('03:00');$t->string('deployment_id',32)->nullable();$t->unsignedBigInteger('next_run_at')->nullable();$t->unsignedInteger('version')->default(1);});
  Schema::create('onenew_cloud_backup_runs',function(Blueprint $t){$t->bigIncrements('sequence');$t->uuid('id')->unique();$t->string('deployment_id',32);$t->string('trigger',16);$t->unsignedBigInteger('scheduled_for')->nullable()->unique();$t->string('state',24);$t->text('encrypted_config')->nullable();$t->text('encrypted_session')->nullable();$t->string('file_id',200)->nullable();$t->string('filename',150);$t->string('revision',40)->nullable();$t->unsignedBigInteger('bytes')->default(0);$t->unsignedBigInteger('uploaded_bytes')->default(0);$t->string('sha256',64)->nullable();$t->string('md5',32)->nullable();$t->string('error_code',32)->nullable();$t->unsignedInteger('attempts')->default(0);$t->string('lease_token',32)->nullable();$t->unsignedBigInteger('lease_until')->nullable();$t->unsignedBigInteger('created_at');$t->unsignedBigInteger('started_at')->nullable();$t->unsignedBigInteger('finished_at')->nullable();$t->index(['state','lease_until']);});
 }
 public function down():void{Schema::dropIfExists('onenew_cloud_backup_runs');Schema::dropIfExists('onenew_cloud_backup_config');}
};
