<?php

namespace App\Mail;

use App\Models\TaskModule;
use App\Models\User;
use App\Services\Settings;
use App\Support\Lagos;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

// One of the four "finish activating your account" emails.
class ActivationReminder extends Mailable
{
    public function __construct(public User $user, public int $step) {}

    public static function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $user->id]);
    }

    private function copy(): array
    {
        $s = Settings::all();
        $fee = Lagos::naira($s['activationFee']);
        $bonus = Lagos::naira($s['welcomeBonus']);

        return match ($this->step) {
            1 => [
                'subject' => 'Your Jovia account is almost ready',
                'heading' => 'You’re one step away',
                'lines' => [
                    "Thanks for creating your Jovia Network account. To start earning, activate it with the one-time {$fee} fee.",
                    "Sign in and you’ll see our bank details on the payment screen. After you pay, send your receipt on Telegram and we’ll verify it, usually within 12 hours.",
                    "Activation adds a {$bonus} Welcome Smash Bonus to your wallet.",
                ],
            ],
            2 => [
                'subject' => "Your {$bonus} Welcome Bonus is waiting",
                'heading' => "Your {$bonus} Welcome Bonus is waiting",
                'lines' => [
                    "Your account is set up, but it isn’t active yet. As soon as it is, we add a {$bonus} Welcome Smash Bonus to your wallet and every task unlocks.",
                    'It only takes a bank transfer: sign in, copy the account details on the payment screen and tap “I’ve made the transfer”.',
                ],
            ],
            3 => [
                'subject' => 'Here’s what you can do with Jovia',
                'heading' => 'What activation unlocks',
                'lines' => [
                    'Timed video, music and game tasks, up to '.Lagos::naira(TaskModule::where('active', true)->sum('daily_cap')).' a day.',
                    'The Jovia Marketplace: earn '.Lagos::naira($s['marketplaceReward']).' for every listing we approve, and buy with instalments.',
                    'The Friday Bonus: every Friday at '.str_pad((string) $s['fbrOpenHour'], 2, '0', STR_PAD_LEFT).':00 the fastest members win '.Lagos::naira($s['fbrReward']).'.',
                    'If you pay and your account is never activated, you get a full refund. Earnings depend on the tasks you complete and are not guaranteed.',
                ],
            ],
            default => [
                'subject' => 'Last reminder: activate your Jovia account',
                'heading' => 'This is our last reminder',
                'lines' => [
                    "You signed up for Jovia Network but haven’t activated your account yet. This is the last reminder we’ll send.",
                    "Whenever you’re ready, sign in and activate with the one-time {$fee} fee to unlock every task and your {$bonus} Welcome Bonus.",
                ],
            ],
        };
    }

    public function envelope(): Envelope
    {
        $support = Settings::get('supportEmail');

        // Replies go to the support inbox, not the sending address
        return new Envelope(subject: $this->copy()['subject'], replyTo: $support ? [$support] : []);
    }

    // One-click unsubscribe (required by Gmail and Yahoo for bulk senders)
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.self::unsubscribeUrl($this->user).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $site = rtrim(config('app.url'), '/');

        return new Content(
            view: 'emails.activation-reminder',
            text: 'emails.activation-reminder-text',
            with: $this->copy() + [
                'firstName' => $this->user->first_name,
                'signInUrl' => $site.'/signin.html',
                'helpUrl' => $site.'/contact.html',
                'unsubscribeUrl' => self::unsubscribeUrl($this->user),
                'supportEmail' => Settings::get('supportEmail'),
                'site' => $site,
            ],
        );
    }
}
