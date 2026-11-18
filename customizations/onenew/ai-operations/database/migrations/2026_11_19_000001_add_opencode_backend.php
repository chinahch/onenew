<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('onenew_ai_config',function(Blueprint $t){$t->string('backend',32)->default('chat_completions');$t->string('opencode_protocol',8)->default('v2');$t->string('opencode_endpoint',512)->default('');$t->string('opencode_directory',1024)->default('');$t->string('opencode_username',100)->default('opencode');$t->text('encrypted_opencode_password')->nullable();$t->string('opencode_agent',100)->default('');$t->string('opencode_model',300)->default('');});
        Schema::table('onenew_ai_operator_tasks',function(Blueprint $t){$t->string('backend',32)->default('chat_completions');$t->string('remote_stage',32)->nullable();$t->string('remote_session_id',100)->nullable();$t->string('remote_message_id',100)->nullable();});
    }
    public function down():void {
        Schema::table('onenew_ai_operator_tasks',fn(Blueprint $t)=>$t->dropColumn(['backend','remote_stage','remote_session_id','remote_message_id']));
        Schema::table('onenew_ai_config',fn(Blueprint $t)=>$t->dropColumn(['backend','opencode_protocol','opencode_endpoint','opencode_directory','opencode_username','encrypted_opencode_password','opencode_agent','opencode_model']));
    }
};
