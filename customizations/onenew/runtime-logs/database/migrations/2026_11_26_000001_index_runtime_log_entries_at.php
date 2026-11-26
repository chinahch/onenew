<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('onenew_runtime_log_entries') && !Schema::hasIndex('onenew_runtime_log_entries', 'runtime_entry_at')) {
            Schema::table('onenew_runtime_log_entries', function (Blueprint $table) {
                $table->index('at', 'runtime_entry_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('onenew_runtime_log_entries') && Schema::hasIndex('onenew_runtime_log_entries', 'runtime_entry_at')) {
            Schema::table('onenew_runtime_log_entries', function (Blueprint $table) {
                $table->dropIndex('runtime_entry_at');
            });
        }
    }
};
