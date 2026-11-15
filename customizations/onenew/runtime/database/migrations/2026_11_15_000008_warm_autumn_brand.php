<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void {\App\Models\Setting::createOrUpdate('app_name','暖秋');}
    public function down(): void { /* Site branding is an operator setting; rollback never overwrites it. */ }
};
