<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_onenew_external_nodes', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('name', 255); $t->longText('link_ciphertext');
            $t->text('user_ids'); $t->text('group_ids'); $t->boolean('enabled')->default(true);
            $t->integer('sort')->default(0); $t->timestamps();
        });
        DB::table('v2_onenew_id_mutex')->insert(['kind'=>'v2_onenew_external_nodes','revision'=>0]);
    }
    public function down(): void { Schema::dropIfExists('v2_onenew_external_nodes'); DB::table('v2_onenew_id_mutex')->where('kind','v2_onenew_external_nodes')->delete(); }
};
