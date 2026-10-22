<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tighter daily earnings: at most ₦12,000 a day from tasks (rates are unchanged).
// Meta tasks are switched off until real tasks are added in Filament.
return new class extends Migration
{
    private const CAPS = ['video' => 4000, 'game' => 6000, 'music' => 2000];

    private const OLD_CAPS = ['video' => 12000, 'game' => 20000, 'music' => 6000];

    public function up(): void
    {
        foreach (self::CAPS as $key => $cap) {
            DB::table('task_modules')->where('key', $key)->update(['daily_cap' => $cap]);
        }
        DB::table('task_modules')->where('key', 'meta')->update(['active' => false]);
    }

    public function down(): void
    {
        foreach (self::OLD_CAPS as $key => $cap) {
            DB::table('task_modules')->where('key', $key)->update(['daily_cap' => $cap]);
        }
        DB::table('task_modules')->where('key', 'meta')->update(['active' => true]);
    }
};
