<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// All money amounts are whole naira. $1 = ₦1,000 (setting "usdToNgn").
return new class extends Migration
{
    public function up(): void
    {
        // Activation fee payments (bank transfer, verified manually by an admin)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('sender_name', 120);
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 24)->default('PENDING')->index(); // PENDING | APPROVED | REJECTED | REFUND_REQUESTED | REFUNDED
            $table->string('admin_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Wallet ledger — every balance change has a row here
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // WELCOME_BONUS, TASK, REFERRAL, SPILLOVER, MARKETPLACE, FBR, WITHDRAWAL, WITHDRAWAL_REVERSAL, ADJUSTMENT, PURCHASE, PURCHASE_REFUND, SALE
            $table->integer('amount'); // positive = credit, negative = debit
            $table->string('description');
            $table->string('module', 32)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type', 'module', 'created_at']);
        });

        // Timed task modules (video, music, game, meta)
        Schema::create('task_modules', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('name', 80);
            $table->string('description');
            $table->integer('min_seconds');
            $table->integer('max_seconds');
            $table->string('reward_type', 16); // FLAT | PER_SECOND
            $table->integer('reward_amount');
            $table->integer('daily_cap');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('require_focus')->default(true); // pause when the member leaves the page
            $table->timestamps();
        });

        // Partner media, games and portal tasks members run timers on
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->string('module_key', 32);
            $table->foreign('module_key')->references('key')->on('task_modules')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('url', 500)->nullable();
            $table->string('provider', 60)->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('requires_proof')->default(false);
            $table->string('proof_label', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['module_key', 'active']);
        });

        // One countdown timer run. Active time only accrues through heartbeats.
        Schema::create('task_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 32);
            $table->foreign('module_key')->references('key')->on('task_modules')->cascadeOnDelete();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->string('content_title', 160)->nullable();
            $table->integer('duration_seconds');
            $table->integer('active_seconds')->default(0);
            $table->timestamp('last_beat_at', 3)->nullable(); // millisecond precision: fractions carry between beats
            $table->string('status', 24)->default('RUNNING')->index(); // RUNNING | COMPLETED | ABANDONED | PENDING_REVIEW | REJECTED
            $table->integer('reward')->default(0);
            $table->string('proof', 500)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->index(['user_id', 'status']);
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('bank_name', 100);
            $table->string('account_number', 32);
            $table->string('account_name', 120);
            $table->string('status', 16)->default('PENDING')->index(); // PENDING | PAID | REJECTED
            $table->string('admin_note', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('description', 1000);
            $table->integer('price');
            $table->integer('quantity')->default(1);
            $table->string('category', 60);
            $table->string('image_path')->nullable();
            $table->string('status', 16)->default('PENDING')->index(); // PENDING | APPROVED | REJECTED
            $table->string('admin_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Marketplace orders paid from the buyer's wallet, held until delivery is confirmed
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->integer('price');
            $table->integer('paid_amount');
            $table->string('plan', 16); // FULL | INSTALMENT
            $table->string('status', 24)->index(); // AWAITING_BALANCE | PAID | SHIPPED | COMPLETED | CANCELLED | DISPUTED
            $table->string('delivery_name', 120);
            $table->string('delivery_phone', 32);
            $table->string('delivery_address', 500);
            $table->timestamp('due_at')->nullable();
            $table->string('dispute_reason', 500)->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // Friday Bonus Reward — one drop per Friday
        Schema::create('fbr_drops', function (Blueprint $table) {
            $table->id();
            $table->string('day_key', 10)->unique(); // YYYY-MM-DD, Lagos time
            $table->string('code', 8);
            $table->integer('reward');
            $table->integer('max_winners');
            $table->timestamp('opens_at');
            $table->timestamps();
        });

        Schema::create('fbr_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fbr_drop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['fbr_drop_id', 'user_id']);
        });

        Schema::create('member_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('body', 500);
            $table->string('link', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // One row per assistant message, for the daily limit
        Schema::create('assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // Business settings (fees, bonuses, bank details) — edited in Filament
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        foreach (['settings', 'assistant_logs', 'member_notifications', 'fbr_claims', 'fbr_drops', 'orders', 'listings', 'withdrawals',
            'task_sessions', 'content_items', 'task_modules', 'transactions', 'payments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
