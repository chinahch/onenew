<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_stat_user', function (Blueprint $table) {
            $table->index(['user_id', 'record_type', 'record_at', 'server_rate'], 'onenew_stat_user_period_rate');
        });
    }

    public function down(): void
    {
        Schema::table('v2_stat_user', function (Blueprint $table) {
            $table->dropIndex('onenew_stat_user_period_rate');
        });
    }
};
