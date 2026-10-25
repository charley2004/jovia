<?php

namespace App\Services;

use App\Mail\ActivationReminder;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Emails members who signed up but never paid the activation fee, up to four times,
// and stops as soon as they activate, unsubscribe or ask for a refund.
class ActivationReminders
{
    // Hours after sign-up for reminder 1, 2, 3 and 4
    public const SCHEDULE = [1, 24, 72, 168];

    // Never two reminders within this many hours, and leave old sign-ups alone
    private const MIN_GAP_HOURS = 20;

    private const MAX_ACCOUNT_AGE_DAYS = 30;

    public static function mailerConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    // Members who may still receive a reminder (due or not)
    public static function eligible()
    {
        return User::query()
            ->where('role', 'MEMBER')
            ->where('status', 'PENDING_PAYMENT')
            ->where('email_opt_out', false)
            ->where('activation_reminders_sent', '<', count(self::SCHEDULE))
            ->where('created_at', '>=', now()->subDays(self::MAX_ACCOUNT_AGE_DAYS))
            ->whereDoesntHave('payments', fn ($q) => $q->whereIn('status', ['REFUND_REQUESTED', 'REFUNDED']));
    }

    public static function isDue(User $u): bool
    {
        $step = $u->activation_reminders_sent;
        if ($step >= count(self::SCHEDULE)) {
            return false;
        }
        if ($u->created_at->gt(now()->subHours(self::SCHEDULE[$step]))) {
            return false;
        }

        return ! $u->last_activation_reminder_at || $u->last_activation_reminder_at->lte(now()->subHours(self::MIN_GAP_HOURS));
    }

    // Send every reminder that is due. Returns ['sent' => n, 'failed' => n, 'skipped' => reason|null].
    public static function run(int $limit = 50): array
    {
        if (! Settings::get('activationReminders')) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 'Activation reminders are switched off in Settings.'];
        }
        // Without a real mailer nothing would arrive, so don't use up anyone's reminders
        if (! self::mailerConfigured()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 'No mail account is set up (MAIL_MAILER is "'.config('mail.default').'").'];
        }
        $sent = $failed = 0;
        foreach (self::eligible()->orderBy('created_at')->cursor() as $u) {
            if ($sent + $failed >= $limit) {
                break;
            }
            if (! self::isDue($u)) {
                continue;
            }
            self::sendTo($u) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => null];
    }

    // Send the member's next reminder now (also used by the admin "Send reminder" button)
    public static function sendTo(User $u): bool
    {
        $step = min($u->activation_reminders_sent, count(self::SCHEDULE) - 1) + 1;
        try {
            Mail::to($u->email)->send(new ActivationReminder($u, $step));
        } catch (\Throwable $e) {
            Log::warning("activation reminder to user {$u->id} failed: ".$e->getMessage());

            return false;
        }
        $u->forceFill([
            'activation_reminders_sent' => min($u->activation_reminders_sent + 1, count(self::SCHEDULE)),
            'last_activation_reminder_at' => now(),
        ])->save();

        return true;
    }
}
