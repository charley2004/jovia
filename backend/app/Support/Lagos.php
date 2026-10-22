<?php

namespace App\Support;

use Illuminate\Support\Carbon;

// Jovia runs on Lagos time (WAT, UTC+1). Daily limits reset at Lagos midnight.
// The database stores UTC, so instants used in queries are converted back to UTC.
class Lagos
{
    public static function now(): Carbon
    {
        return Carbon::now('Africa/Lagos');
    }

    public static function startOfDay(): Carbon
    {
        return self::now()->startOfDay()->utc();
    }

    public static function dayKey(): string
    {
        return self::now()->toDateString();
    }

    public static function isFriday(): bool
    {
        return self::now()->isFriday();
    }

    public static function todayAt(int $hour): Carbon
    {
        return self::now()->startOfDay()->addHours($hour)->utc();
    }

    public static function naira(int|float|null $n): string
    {
        return '₦'.number_format((float) $n);
    }
}
