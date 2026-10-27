<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

// Business settings, stored as JSON in the `settings` table and edited in Filament.
class Settings
{
    public const DEFAULTS = [
        'activationFee' => 15000,
        'welcomeBonus' => 15000,
        'marketplaceReward' => 3000,
        'fbrReward' => 10000,
        'fbrMaxWinners' => 10,
        'fbrOpenHour' => 12, // Lagos time, on Fridays
        'minWithdrawal' => 0,
        'usdToNgn' => 1000,
        // Marketplace orders (paid from the buyer's wallet, held until delivery is confirmed)
        'instalmentDepositPercent' => 50,
        'instalmentDays' => 14,
        'autoReleaseDays' => 7,
        'marketplaceFeePercent' => 0,
        'assistantDailyLimit' => 40,
        // Reminder emails to members who signed up but never activated (needs a mail account in .env)
        'activationReminders' => true,
        'activationBankName' => '',
        'activationAccountName' => '',
        'activationAccountNumber' => '',
        'supportUrl' => '',
        // Members send their transfer receipt here after pressing "I've made the transfer"
        'paymentProofUrl' => 'https://t.me/Jovia_Registration',
        'supportEmail' => 'support@joviaplatform.ng',
    ];

    public static function all(): array
    {
        return Cache::remember('jovia.settings', 30, function () {
            $out = self::DEFAULTS;
            foreach (Setting::all() as $row) {
                $out[$row->key] = json_decode($row->value, true);
            }

            return $out;
        });
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function save(array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                Setting::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
            }
        }
        Cache::forget('jovia.settings');

        return self::all();
    }
}
