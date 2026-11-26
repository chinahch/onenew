<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('onenew_runtime_log_streams',function(Blueprint $t){$t->bigIncrements('id');$t->unsignedBigInteger('machine_id')->index();$t->char('machine_identity',64);$t->uuid('deployment_id');$t->string('version',40);$t->unsignedBigInteger('last_seq')->default(0);$t->unsignedBigInteger('dropped')->default(0);$t->unsignedBigInteger('filtered')->default(0);$t->unsignedBigInteger('last_received_at')->index();$t->unique(['machine_identity','deployment_id'],'runtime_stream_unique');});
  Schema::create('onenew_runtime_log_batches',function(Blueprint $t){$t->bigIncrements('id');$t->unsignedBigInteger('stream_id');$t->uuid('batch_id');$t->unsignedBigInteger('received_at')->index();$t->unique(['stream_id','batch_id'],'runtime_batch_unique');});
  Schema::create('onenew_runtime_log_entries',function(Blueprint $t){$t->bigIncrements('id');$t->unsignedBigInteger('machine_id');$t->char('machine_identity',64);$t->unsignedBigInteger('stream_id');$t->unsignedBigInteger('seq');$t->string('level',8);$t->unsignedBigInteger('at')->index('runtime_entry_at');$t->text('message');$t->unsignedBigInteger('received_at')->index();$t->unique(['stream_id','seq'],'runtime_entry_unique');$t->index(['machine_id','machine_identity','id'],'runtime_machine_page');});
  Schema::create('onenew_runtime_log_reads',function(Blueprint $t){$t->bigIncrements('id');$t->unsignedBigInteger('admin_id');$t->unsignedBigInteger('machine_id');$t->char('machine_identity',64);$t->unsignedInteger('result_count');$t->boolean('searched')->default(false);$t->unsignedBigInteger('created_at')->index();});
 }
 public function down():void{foreach(['reads','entries','batches','streams'] as $name)Schema::dropIfExists('onenew_runtime_log_'.$name);}
};
