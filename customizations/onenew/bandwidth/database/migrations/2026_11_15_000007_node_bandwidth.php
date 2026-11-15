<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {Schema::table('v2_server',fn(Blueprint $t)=>$t->unsignedInteger('node_speed_limit_mbps')->default(0));}
    public function down(): void {Schema::table('v2_server',fn(Blueprint $t)=>$t->dropColumn('node_speed_limit_mbps'));}
};
