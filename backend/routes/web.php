<?php

use App\Http\Controllers\EmailPreferenceController;
use App\Http\Controllers\SiteController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The website and member dashboard are static files in the folder above backend/.
// Filament registers /admin itself; everything else falls through to the site.
// Unsubscribe from reminder emails (signed links from the email itself)
Route::get('/email/unsubscribe/{user}', [EmailPreferenceController::class, 'unsubscribe'])->name('email.unsubscribe')->middleware('signed');
Route::post('/email/unsubscribe/{user}', [EmailPreferenceController::class, 'oneClick'])->middleware('signed')->withoutMiddleware([ValidateCsrfToken::class]);

// Static files need no session: skipping it saves a database query and two cookies per file.
$static = [EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class];
Route::get('/', SiteController::class)->withoutMiddleware($static);
Route::get('/{path}', SiteController::class)->where('path', '^(?!admin|livewire|filament|storage|api|sanctum|up|email/).*$')->withoutMiddleware($static);
