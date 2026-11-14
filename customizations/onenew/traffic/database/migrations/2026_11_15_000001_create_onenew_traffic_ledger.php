<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_onenew_traffic_meta', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value');
        });
        DB::table('v2_onenew_traffic_meta')->insert(['key' => 'recording_started_at', 'value' => (string) time()]);
        Schema::create('v2_onenew_traffic_receipts', function (Blueprint $table) {
            $table->uuid('batch_id')->primary();
            $table->unsignedBigInteger('created_at');
        });
        Schema::create('v2_onenew_user_node_traffic', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->string('server_type', 32);
            $table->string('node_name', 255);
            $table->unsignedBigInteger('record_at');
            $table->decimal('server_rate', 20, 8);
            foreach (['raw_u', 'raw_d', 'u', 'd'] as $field) {
                $table->unsignedBigInteger($field)->default(0);
            }
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['user_id', 'record_at', 'server_id', 'server_type', 'server_rate'], 'onenew_user_node_day_rate_unique');
            $table->index(['user_id', 'record_at'], 'onenew_user_node_day');
        });
        Schema::create('v2_onenew_domain_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('server_id');
            $table->uuid('batch_id');
            $table->unsignedBigInteger('created_at')->index();
            $table->unique(['machine_id', 'server_id', 'batch_id'], 'onenew_domain_batch_unique');
        });
        Schema::create('v2_onenew_domain_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->string('server_type', 32);
            $table->string('node_name', 255);
            $table->string('domain', 253);
            $table->unsignedBigInteger('observed_at')->index();
            $table->index(['user_id', 'observed_at'], 'onenew_domain_user_time');
        });
        // Deliberately no foreign-key cascade: deleted nodes retain attribution.
    }

    public function down(): void
    {
        foreach (['v2_onenew_domain_events', 'v2_onenew_domain_batches', 'v2_onenew_user_node_traffic',
            'v2_onenew_traffic_receipts', 'v2_onenew_traffic_meta'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
