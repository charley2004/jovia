<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Jovia no longer has a referral programme: drop the referral columns, the saved
// referral settings and the "share your referral link" task.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
        DB::table('settings')->whereIn('key', ['referralBonus', 'spilloverLevels'])->delete();
        DB::table('content_items')->where('title', 'Share your referral link')->update(['active' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique();
            $table->foreignId('referred_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
