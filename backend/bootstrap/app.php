<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsureMember;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The website calls /api from the same domain, so the API always runs with the
        // session cookie and CSRF protection (token read from the XSRF-TOKEN cookie).
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'member' => EnsureMember::class,
            'active' => EnsureActive::class,
        ]);
        // Signed-out visitors: API calls get a JSON 401, pages go to the sign-in page
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/signin.html');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The dashboard shows {"error": "..."} messages, so every API error uses that shape
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            [$status, $message] = match (true) {
                $e instanceof ValidationException => [400, collect($e->errors())->flatten()->first() ?? 'Invalid input.'],
                $e instanceof AuthenticationException => [401, 'Please sign in.'],
                $e instanceof TokenMismatchException => [419, 'Your session expired. Refresh the page and try again.'],
                $e instanceof ModelNotFoundException => [404, 'Not found.'],
                $e instanceof NotFoundHttpException && (! $e->getMessage() || str_starts_with($e->getMessage(), 'The route ')) => [404, 'Not found.'],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'Request failed.'],
                default => [500, 'Something went wrong. Please try again.'],
            };
            if ($status === 500) {
                report($e);
            }

            return response()->json(['error' => $message], $status);
        });
    })->create();
