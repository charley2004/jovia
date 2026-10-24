<?php

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FbrController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Member API used by the website and dashboard (session cookie auth via Sanctum).
// Admin work happens in the Filament panel at /admin.

Route::get('/health', fn () => ['ok' => true]);
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact');

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/signup', [AuthController::class, 'signup']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot', [AuthController::class, 'forgot']);
        Route::post('/reset', [AuthController::class, 'reset']);
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware(['auth:sanctum', 'member']);
});

Route::middleware(['auth:sanctum', 'member'])->group(function () {
    Route::prefix('me')->controller(MemberController::class)->group(function () {
        Route::get('/overview', 'overview');
        Route::post('/payments', 'submitPayment');
        Route::post('/payments/cancel', 'cancelPayment');
        Route::post('/payments/refund', 'requestRefund');
        Route::put('/bank', 'saveBank');
        Route::get('/withdrawals', 'withdrawals');
        Route::post('/withdrawals', 'withdraw')->middleware('active');
        Route::get('/transactions', 'transactions');
        Route::get('/notifications', 'notifications');
        Route::post('/notifications/read', 'readNotifications');
        Route::put('/profile', 'profile');
        Route::put('/password', 'password');
    });

    Route::prefix('tasks')->middleware('active')->controller(TaskController::class)->group(function () {
        Route::get('/modules', 'modules');
        Route::get('/modules/{key}/content', 'content');
        Route::get('/sessions/current', 'current');
        Route::get('/sessions', 'history');
        Route::post('/sessions', 'start');
        Route::post('/sessions/{id}/beat', 'beat');
        Route::post('/sessions/{id}/resume', 'resume');
        Route::post('/sessions/{id}/complete', 'complete');
        Route::post('/sessions/{id}/abandon', 'abandon');
    });

    Route::prefix('marketplace')->controller(MarketplaceController::class)->group(function () {
        Route::get('/categories', 'categories');
        Route::get('/', 'index');
        Route::get('/mine', 'mine');
        Route::post('/', 'store')->middleware('active');
        Route::delete('/{id}', 'destroy')->whereNumber('id');
        Route::get('/order-terms', 'orderTerms');
        Route::get('/orders', 'orders');
        Route::get('/sales', 'sales');
        Route::post('/orders', 'placeOrder')->middleware('active');
        Route::post('/orders/{id}/pay-balance', 'payBalance')->middleware('active');
        Route::post('/orders/{id}/cancel', 'cancel');
        Route::post('/orders/{id}/ship', 'ship');
        Route::post('/orders/{id}/received', 'received');
        Route::post('/orders/{id}/dispute', 'dispute');
    });

    Route::prefix('fbr')->middleware('active')->controller(FbrController::class)->group(function () {
        Route::get('/', 'show');
        Route::post('/claim', 'claim');
    });

    Route::prefix('assistant')->middleware('active')->controller(AssistantController::class)->group(function () {
        Route::get('/status', 'status');
        Route::post('/', 'chat');
    });
});
