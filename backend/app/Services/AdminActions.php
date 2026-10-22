<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Payment;
use App\Models\TaskSession;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Lagos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Everything an admin can do from Filament. Each method validates the current
// state, so double-clicks or two admins acting at once can't pay twice.
class AdminActions
{
    public static function approvePayment(Payment $payment): void
    {
        if (! in_array($payment->status, ['PENDING', 'REJECTED'], true)) {
            abort(400, 'Only pending payments can be approved.');
        }
        $notices = DB::transaction(function () use ($payment) {
            $p = Payment::lockForUpdate()->find($payment->id);
            if (! in_array($p->status, ['PENDING', 'REJECTED'], true)) {
                abort(400, 'Only pending payments can be approved.');
            }

            return Wallet::activateMember($p);
        });
        Notifier::sendAll($notices);
    }

    public static function rejectPayment(Payment $payment, ?string $note): void
    {
        DB::transaction(function () use ($payment, $note) {
            if (! Payment::whereKey($payment->id)->where('status', 'PENDING')->update(['status' => 'REJECTED', 'admin_note' => $note ?: null, 'reviewed_at' => now()])) {
                abort(400, 'Only pending payments can be rejected.');
            }
            User::whereKey($payment->user_id)->update(['status' => 'PENDING_PAYMENT']);
        });
        Notifier::send($payment->user_id, 'We could not verify your payment', ($note ?: 'Please check your transfer details.').' You can submit your payment again from the dashboard.', 'dashboard.html');
    }

    // Refund sent for an account that was never activated
    public static function markRefunded(Payment $payment, ?string $note): void
    {
        DB::transaction(function () use ($payment, $note) {
            if ($payment->user->status === 'ACTIVE') {
                abort(400, 'This account is active, so it is not eligible for a refund.');
            }
            if (! Payment::whereKey($payment->id)->whereIn('status', ['REFUND_REQUESTED', 'PENDING', 'REJECTED'])->update(['status' => 'REFUNDED', 'admin_note' => $note ?: null, 'reviewed_at' => now()])) {
                abort(400, 'This payment cannot be refunded.');
            }
            User::whereKey($payment->user_id)->update(['status' => 'PENDING_PAYMENT']);
        });
        Notifier::send($payment->user_id, 'Refund sent', 'Your '.Lagos::naira($payment->amount).' activation payment has been refunded to the account you paid from.'.($note ? " {$note}" : ''), 'dashboard.html');
    }

    public static function markWithdrawalPaid(Withdrawal $w, ?string $reference): void
    {
        if (! Withdrawal::whereKey($w->id)->where('status', 'PENDING')->update(['status' => 'PAID', 'admin_note' => $reference ?: null, 'processed_at' => now()])) {
            abort(400, 'Only pending withdrawals can be marked paid.');
        }
        Notifier::send($w->user_id, 'Withdrawal paid', Lagos::naira($w->amount)." has been sent to {$w->bank_name} · {$w->account_number}.".($reference ? " Reference: {$reference}" : ''), 'dashboard.html#wallet');
    }

    // Rejecting returns the money to the member's balance
    public static function rejectWithdrawal(Withdrawal $w, ?string $note): void
    {
        DB::transaction(function () use ($w, $note) {
            if (! Withdrawal::whereKey($w->id)->where('status', 'PENDING')->update(['status' => 'REJECTED', 'admin_note' => $note ?: null, 'processed_at' => now()])) {
                abort(400, 'Only pending withdrawals can be rejected.');
            }
            Wallet::credit($w->user_id, $w->amount, 'WITHDRAWAL_REVERSAL', "Withdrawal #{$w->id} returned".($note ? ": {$note}" : ''));
        });
        Notifier::send($w->user_id, 'Withdrawal returned', 'Your '.Lagos::naira($w->amount).' withdrawal was not sent and the money is back in your wallet.'.($note ? " Reason: {$note}" : ''), 'dashboard.html#wallet');
    }

    public static function approveListing(Listing $l): void
    {
        $reward = Settings::get('marketplaceReward');
        DB::transaction(function () use ($l, $reward) {
            if (! Listing::whereKey($l->id)->where('status', 'PENDING')->update(['status' => 'APPROVED', 'reviewed_at' => now()])) {
                abort(400, 'Only pending listings can be approved.');
            }
            Wallet::credit($l->user_id, $reward, 'MARKETPLACE', "Approved listing: {$l->title}");
        });
        Notifier::send($l->user_id, 'Listing approved', "{$l->title} is now live in the marketplace and ".Lagos::naira($reward).' has been added to your wallet.', 'dashboard.html#marketplace');
    }

    public static function rejectListing(Listing $l, ?string $note): void
    {
        if (! Listing::whereKey($l->id)->where('status', 'PENDING')->update(['status' => 'REJECTED', 'admin_note' => $note ?: null, 'reviewed_at' => now()])) {
            abort(400, 'Only pending listings can be rejected.');
        }
        Notifier::send($l->user_id, 'Listing not approved', "{$l->title} was not approved.".($note ? " Reason: {$note}" : ''), 'dashboard.html#marketplace');
    }

    public static function approveTaskProof(TaskSession $s): void
    {
        DB::transaction(function () use ($s) {
            if (! TaskSession::whereKey($s->id)->where('status', 'PENDING_REVIEW')->update(['status' => 'COMPLETED'])) {
                abort(400, 'Only pending reviews can be approved.');
            }
            if ($s->reward > 0) {
                Wallet::credit($s->user_id, $s->reward, 'TASK', "{$s->module->name} · ".($s->content_title ?: 'task').' (approved)', $s->module_key);
            }
        });
        Notifier::send($s->user_id, 'Task approved', ($s->content_title ?: 'Your task').' was approved and '.Lagos::naira($s->reward).' has been added to your wallet.', 'dashboard.html#tasks');
    }

    public static function rejectTaskProof(TaskSession $s, ?string $note): void
    {
        if (! TaskSession::whereKey($s->id)->where('status', 'PENDING_REVIEW')->update(['status' => 'REJECTED', 'review_note' => $note ?: null])) {
            abort(400, 'Only pending reviews can be rejected.');
        }
        Notifier::send($s->user_id, 'Task not approved', ($s->content_title ?: 'Your task').' was not approved.'.($note ? " Reason: {$note}" : ''), 'dashboard.html#tasks');
    }

    public static function adjustBalance(User $user, int $amount, string $reason): void
    {
        if ($amount === 0) {
            abort(400, 'Enter a non-zero amount.');
        }
        DB::transaction(function () use ($user, $amount, $reason) {
            if ($amount < 0) {
                if (! User::whereKey($user->id)->where('balance', '>=', -$amount)->decrement('balance', -$amount)) {
                    abort(400, 'The member does not have enough balance.');
                }
                Transaction::create(['user_id' => $user->id, 'type' => 'ADJUSTMENT', 'amount' => $amount, 'description' => $reason]);
            } else {
                Wallet::credit($user->id, $amount, 'ADJUSTMENT', $reason);
            }
        });
    }

    // Sets a temporary password and returns it once, for support to pass on
    public static function resetPassword(User $user): string
    {
        $temp = Str::password(10, symbols: false);
        $user->update(['password' => $temp]);

        return $temp;
    }
}
