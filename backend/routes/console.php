<?php

use App\Mail\ActivationReminder;
use App\Models\User;
use App\Services\ActivationReminders;
use App\Services\Orders;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Hourly: cancel overdue instalment orders (deposit refunded) and release payment
// on shipped orders the buyer never confirmed. Needs the cron entry:
//   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
Schedule::call(fn () => Orders::settle())->hourly()->name('settle-orders')->withoutOverlapping();

Artisan::command('jovia:settle-orders', function () {
    $r = Orders::settle();
    $this->info("Cancelled {$r['cancelled']} overdue instalment orders, released {$r['released']} shipped orders.");
})->purpose('Settle marketplace orders now');

// Every 15 minutes: remind members who signed up but haven't activated (1 h, 1 day, 3 days, 7 days)
Schedule::call(fn () => ActivationReminders::run())->everyFifteenMinutes()->name('activation-reminders')->withoutOverlapping();

Artisan::command('jovia:send-activation-reminders', function () {
    $r = ActivationReminders::run();
    $this->info($r['skipped'] ?? "Sent {$r['sent']} activation reminders ({$r['failed']} failed).");
})->purpose('Send activation reminder emails that are due now');

// Check the mail account: php artisan jovia:test-mail you@example.com
Artisan::command('jovia:test-mail {email}', function (string $email) {
    if (! ActivationReminders::mailerConfigured()) {
        $this->error('No mail account is set up: MAIL_MAILER is "'.config('mail.default').'". Fill in the MAIL_ lines in .env, then run update.sh.');

        return 1;
    }
    $sample = new User(['first_name' => 'there', 'email' => $email]);
    $sample->id = 0;
    Mail::to($email)->send(new ActivationReminder($sample, 1));
    $this->info("Sent a sample activation reminder to {$email}. Check the inbox (and the spam folder).");
})->purpose('Send a sample reminder email to check the mail settings');
