<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_onenew_id_mutex', function (Blueprint $t) {
            $t->string('kind', 64)->primary(); $t->unsignedBigInteger('revision')->default(0);
        });
        foreach (['v2_server', 'v2_server_machine'] as $table) {
            DB::table('v2_onenew_id_mutex')->insert(['kind' => $table, 'revision' => 0]);
            Schema::table($table, fn (Blueprint $t) => $t->string('onenew_identity', 36)->default(''));
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) DB::table($table)->where('id', $row->id)->update(['onenew_identity' => (string) Str::uuid()]);
            });
        }
        foreach (['v2_onenew_user_node_traffic', 'v2_onenew_domain_events'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('node_identity', 36)->default(''));
            DB::table('v2_server')->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $node) DB::table($table)->where('server_id', $node->id)->update(['node_identity' => $node->onenew_identity]);
            });
        }
        Schema::table('v2_onenew_user_node_traffic', function (Blueprint $t) {
            $t->dropUnique('onenew_user_node_day_rate_unique');
            $t->unique(['user_id', 'record_at', 'server_id', 'node_identity', 'server_type', 'server_rate'], 'onenew_user_node_incarnation_unique');
        });
        Schema::create('v2_onenew_retired_stats', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('server_id')->index();
            $t->string('node_identity', 36); $t->longText('payload'); $t->unsignedBigInteger('archived_at');
        });
    }

    public function down(): void
    {
        // Data-bearing identity separation is intentionally forward-only: collapsing
        // the old unique key could merge different incarnations of the same ID.
        throw new RuntimeException('Restore a pre-upgrade backup to roll back resource ID reuse');
    }
};
