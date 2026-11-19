<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_onenew_upgrade_jobs', function (Blueprint $table) {
            $table->index(['machine_id', 'sequence'], 'onenew_upgrade_machine_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('v2_onenew_upgrade_jobs', function (Blueprint $table) {
            $table->dropIndex('onenew_upgrade_machine_sequence');
        });
    }
};
