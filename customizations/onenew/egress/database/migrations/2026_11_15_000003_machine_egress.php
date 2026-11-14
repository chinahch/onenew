<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_server_machine_egress', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->primary();
            $table->string('mode', 20)->default('inherit');
            $table->json('node_overrides');
            $table->foreign('machine_id')->references('id')->on('v2_server_machine')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_machine_egress');
    }
};
