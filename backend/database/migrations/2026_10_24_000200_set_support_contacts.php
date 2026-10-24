<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Support email and the Telegram account for payment proofs (both still editable in Filament → Settings).
return new class extends Migration
{
    public function up(): void
    {
        // Only fill the email if it was never set
        $this->set('supportEmail', 'joviaplatform@gmail.com', [null, '', '""']);
        // Replace the earlier placeholder account; keep any link an admin chose themselves
        $this->set('paymentProofUrl', 'https://t.me/Jovia_Registration', [null, '', '""', json_encode('https://t.me/Nextel_Registration')]);
    }

    private function set(string $key, string $value, array $replaceable): void
    {
        $row = DB::table('settings')->where('key', $key)->first();
        if (! $row) {
            DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value)]);
        } elseif (in_array($row->value, $replaceable, true)) {
            DB::table('settings')->where('key', $key)->update(['value' => json_encode($value)]);
        }
    }

    public function down(): void
    {
        // Leave the values; they can be changed in Settings
    }
};
