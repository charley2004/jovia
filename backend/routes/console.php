<?php

use App\Services\Orders;
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
