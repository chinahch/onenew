<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::create('onenew_ai_config',function(Blueprint $t){$t->unsignedTinyInteger('id')->primary();$t->boolean('enabled')->default(false);$t->string('endpoint',512)->default('');$t->string('model',200)->default('');$t->text('encrypted_key')->nullable();});}
    public function down():void {Schema::dropIfExists('onenew_ai_config');}
};
