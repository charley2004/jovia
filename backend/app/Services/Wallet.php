<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Lagos;

// Every balance change goes through here so the ledger and balance never drift.
// Call inside DB::transaction().
class Wallet
{
    public static function credit(int $userId, int $amount, string $type, string $description, ?string $module = null): void
    {
        if ($amount === 0) {
            return;
        }
        User::whereKey($userId)->increment('balance', $amount);
        Transaction::create(['user_id' => $userId, 'type' => $type, 'amount' => $amount, 'description' => $description, 'module' => $module]);
    }

    // Debit only if the balance covers it. Returns false when it doesn't.
    public static function debit(int $userId, int $amount, string $type, string $description): bool
    {
        $done = User::whereKey($userId)->where('balance', '>=', $amount)->decrement('balance', $amount);
        if (! $done) {
            return false;
        }
        Transaction::create(['user_id' => $userId, 'type' => $type, 'amount' => -$amount, 'description' => $description]);

        return true;
    }

    // Approve an activation payment: activate the member and pay the welcome bonus.
    // Returns notifications to send once the transaction has committed.
    public static function activateMember(Payment $payment): array
    {
        $s = Settings::all();
        $notices = [];
        $payment->update(['status' => 'APPROVED', 'reviewed_at' => now()]);
        $member = User::lockForUpdate()->find($payment->user_id);
        if ($member->status === 'ACTIVE') {
            return $notices;
        }

        $member->update(['status' => 'ACTIVE', 'activated_at' => now()]);
        self::credit($member->id, $s['welcomeBonus'], 'WELCOME_BONUS', 'Welcome Smash Bonus');
        $notices[] = [$member->id, 'Your account is active', 'Your payment was verified and your '.Lagos::naira($s['welcomeBonus']).' Welcome Smash Bonus is in your wallet. Every task module is now unlocked.', 'dashboard.html#overview'];

        return $notices;
    }
}
