<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email', 191)->unique();
            $table->string('phone', 32)->unique(); // digits only
            $table->string('country', 64);
            $table->string('password');
            $table->string('role', 16)->default('MEMBER'); // MEMBER | ADMIN
            $table->string('status', 32)->default('PENDING_PAYMENT'); // PENDING_PAYMENT | AWAITING_VERIFICATION | ACTIVE | SUSPENDED
            $table->string('referral_code', 16)->unique();
            $table->foreignId('referred_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('balance')->default(0); // whole naira
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 32)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('users');
    }
};
