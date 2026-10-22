<?php

use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// The website and member dashboard are static files in the folder above backend/.
// Filament registers /admin itself; everything else falls through to the site.
Route::get('/', SiteController::class);
Route::get('/{path}', SiteController::class)->where('path', '^(?!admin|livewire|filament|storage|api|sanctum|up).*$');
