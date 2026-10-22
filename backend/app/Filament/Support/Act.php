<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Runs an AdminActions call from a Filament button and shows the result as a
// toast. Business-rule failures (e.g. "already approved") become a red toast.
class Act
{
    public static function run(callable $action, string $success): void
    {
        try {
            $action();
            Notification::make()->success()->title($success)->send();
        } catch (HttpExceptionInterface $e) {
            Notification::make()->danger()->title($e->getMessage() ?: 'That action could not be completed.')->send();
        }
    }

    public static function naira(int|float|null $n): string
    {
        return '₦'.number_format((float) $n);
    }
}
