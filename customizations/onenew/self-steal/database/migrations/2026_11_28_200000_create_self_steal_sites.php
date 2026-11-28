<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_onenew_self_steal_mutex', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedBigInteger('revision')->default(0);
        });
        DB::table('v2_onenew_self_steal_mutex')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('v2_onenew_self_steal_agents', function (Blueprint $t) {
            $t->unsignedBigInteger('machine_id')->primary();
            $t->string('identity', 64);
            $t->string('credential_hash', 64);
            $t->string('arch', 16);
            $t->boolean('installed')->default(false);
            $t->boolean('running')->default(false);
            $t->string('domain', 255)->default('');
            $t->unsignedInteger('port')->default(8443);
            $t->string('version', 64)->default('');
            $t->string('assistant_version', 32);
            $t->unsignedBigInteger('seen_at');
            $t->unsignedBigInteger('updated_at');
        });

        Schema::create('v2_onenew_self_steal_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('sequence')->unique();
            $t->unsignedBigInteger('machine_id')->index();
            $t->string('identity', 64);
            $t->string('credential_hash', 64);
            $t->string('action', 24);
            $t->string('domain', 255)->default('');
            $t->unsignedInteger('port')->default(8443);
            $t->string('state', 24)->index();
            $t->string('lease_hash', 64)->nullable();
            $t->unsignedBigInteger('lease_expires_at')->nullable();
            $t->unsignedBigInteger('expires_at');
            $t->string('error_code', 64)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_onenew_self_steal_jobs');
        Schema::dropIfExists('v2_onenew_self_steal_agents');
        Schema::dropIfExists('v2_onenew_self_steal_mutex');
    }
};
