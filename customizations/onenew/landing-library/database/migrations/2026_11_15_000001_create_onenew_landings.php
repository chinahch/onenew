<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('onenew_landings',function(Blueprint $t){$t->bigIncrements('id');$t->string('name',160);$t->text('link_ciphertext');$t->boolean('enabled')->default(true);$t->integer('sort')->default(0);$t->unsignedInteger('version')->default(1);$t->timestamps();});
        Schema::create('onenew_landing_lock',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedBigInteger('revision')->default(0);});
        DB::table('onenew_landing_lock')->insert(['id'=>1,'revision'=>0]);
        Schema::table('v2_server',function(Blueprint $t){$t->json('onenew_landing_bindings')->nullable();});
    }
    public function down(): void {Schema::table('v2_server',fn(Blueprint $t)=>$t->dropColumn('onenew_landing_bindings'));Schema::dropIfExists('onenew_landings');Schema::dropIfExists('onenew_landing_lock');}
};
