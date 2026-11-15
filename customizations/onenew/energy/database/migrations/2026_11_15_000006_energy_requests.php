<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('onenew_energy_requests',function(Blueprint $t){
        $t->uuid('id')->primary();$t->unsignedBigInteger('user_id')->index();$t->char('request_hash',64);$t->unsignedInteger('amount_cents');
        $t->string('status',16)->default('pending')->index();$t->unsignedBigInteger('created_at')->index();$t->unsignedBigInteger('reviewed_at')->nullable();
        $t->unsignedBigInteger('reviewer_id')->nullable();$t->string('note',500)->default('');$t->bigInteger('balance_before')->nullable();$t->bigInteger('balance_after')->nullable();
        $t->unique(['user_id','request_hash'],'onenew_energy_idempotency');
    }); }
    public function down(): void { Schema::dropIfExists('onenew_energy_requests'); }
};
