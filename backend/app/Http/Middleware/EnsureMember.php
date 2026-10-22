<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Signed-in members only; suspended accounts are blocked everywhere.
class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === 'SUSPENDED') {
            abort(403, 'This account is suspended. Contact support.');
        }

        return $next($request);
    }
}
