<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reminder emails to members who signed up but never activated.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('activation_reminders_sent')->default(0);
            $table->timestamp('last_activation_reminder_at')->nullable();
            $table->boolean('email_opt_out')->default(false); // unsubscribed from reminder emails
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['activation_reminders_sent', 'last_activation_reminder_at', 'email_opt_out']);
        });
    }
};
