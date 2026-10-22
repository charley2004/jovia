<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Features that need an activated account (tasks, withdrawals, buying, selling…)
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'ACTIVE') {
            abort(403, 'Activate your account to use this feature.');
        }

        return $next($request);
    }
}
