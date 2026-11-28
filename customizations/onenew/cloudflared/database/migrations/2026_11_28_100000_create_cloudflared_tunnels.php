<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('v2_onenew_tunnel_mutex', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedBigInteger('revision')->default(0);
        });
        DB::table('v2_onenew_tunnel_mutex')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('v2_onenew_tunnel_agents', function (Blueprint $t) {
            $t->unsignedBigInteger('machine_id')->primary();
            $t->string('identity', 36);
            $t->string('credential_hash', 64);
            $t->string('arch', 16);
            $t->boolean('installed')->default(false);
            $t->boolean('running')->default(false);
            $t->string('version', 64)->default('');
            $t->string('assistant_version', 64);
            $t->unsignedBigInteger('seen_at');
        });

        Schema::create('v2_onenew_tunnel_jobs', function (Blueprint $t) {
            $t->bigIncrements('sequence');
            $t->uuid('id')->unique();
            $t->unsignedBigInteger('machine_id')->index();
            $t->string('identity', 36);
            $t->string('credential_hash', 64);
            $t->unsignedBigInteger('admin_id');
            $t->string('action', 24);
            $t->text('token_encrypted')->nullable();
            $t->string('state', 24)->index();
            $t->string('error_code', 32)->default('');
            $t->string('lease_hash', 64)->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('lease_expires_at')->nullable();
            $t->index(['machine_id', 'sequence']);
        });
    }

    public function down(): void {
        foreach (['jobs', 'agents', 'mutex'] as $name) {
            Schema::dropIfExists('v2_onenew_tunnel_' . $name);
        }
    }
};
