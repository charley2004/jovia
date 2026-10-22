<?php

namespace Database\Seeders;

use App\Models\ContentItem;
use App\Models\Setting;
use App\Models\TaskModule;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

// Task modules, built-in content, default settings and the first admin account.
// Safe to run more than once: existing rows are left as they are.
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Rates from the earnings structure ($1 = ₦1,000). Editable in Filament → Task modules.
        $modules = [
            ['key' => 'video', 'name' => 'Celebrity Video Streaming', 'description' => 'Watch partner video sessions of 20 seconds to 2 minutes.',
                'min_seconds' => 20, 'max_seconds' => 120, 'reward_type' => 'PER_SECOND', 'reward_amount' => 100, 'daily_cap' => 12000, 'sort_order' => 1, 'require_focus' => true],
            ['key' => 'music', 'name' => 'Music Streaming', 'description' => 'Stream partner tracks in 60-second sessions.',
                'min_seconds' => 60, 'max_seconds' => 60, 'reward_type' => 'FLAT', 'reward_amount' => 2000, 'daily_cap' => 6000, 'sort_order' => 2, 'require_focus' => true],
            ['key' => 'game', 'name' => 'Interactive Gameplay', 'description' => 'Play featured games with a countdown timer. Paid on playtime, win or lose.',
                'min_seconds' => 30, 'max_seconds' => 3600, 'reward_type' => 'FLAT', 'reward_amount' => 6000, 'daily_cap' => 20000, 'sort_order' => 3, 'require_focus' => true],
            // Meta tasks are done on other sites, so the timer keeps running when the member switches tabs
            ['key' => 'meta', 'name' => 'Meta Task Activities', 'description' => 'Complete simple online engagement and daily portal tasks.',
                'min_seconds' => 30, 'max_seconds' => 3600, 'reward_type' => 'FLAT', 'reward_amount' => 3000, 'daily_cap' => 3000, 'sort_order' => 4, 'require_focus' => false],
        ];
        foreach ($modules as $m) {
            TaskModule::firstOrCreate(['key' => $m['key']], $m);
        }

        // Built-in content that works without any partner links
        $content = [
            ['module_key' => 'game', 'title' => 'Memory Match', 'url' => '/games/memory.html', 'provider' => 'Jovia Arcade'],
            ['module_key' => 'game', 'title' => 'Snake', 'url' => '/games/snake.html', 'provider' => 'Jovia Arcade'],
        ];
        foreach ($content as $c) {
            ContentItem::firstOrCreate(['module_key' => $c['module_key'], 'title' => $c['title']], $c);
        }

        foreach (Settings::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }

        $email = strtolower((string) env('ADMIN_EMAIL'));
        if ($email && ! User::where('email', $email)->exists()) {
            $password = env('ADMIN_PASSWORD') ?: Str::password(14, symbols: false);
            User::create([
                'first_name' => 'Jovia', 'last_name' => 'Admin', 'email' => $email, 'phone' => '000000000', 'country' => 'Nigeria',
                'password' => $password, 'role' => 'ADMIN', 'status' => 'ACTIVE', 'activated_at' => now(),
            ]);
            $this->command?->info("Admin created: {$email}".(env('ADMIN_PASSWORD') ? '' : " / temporary password: {$password}"));
        }
    }
}
