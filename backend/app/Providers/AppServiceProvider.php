<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Sign-up, sign-in and password reset: 30 attempts per 15 minutes per IP
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinutes(15, 30)->by($request->ip())
            ->response(fn () => response()->json(['error' => 'Too many attempts. Please wait a few minutes and try again.'], 429)));
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by($request->ip())
            ->response(fn () => response()->json(['error' => "You've sent several messages already. We'll reply to those first; please try again later."], 429)));
    }
}
