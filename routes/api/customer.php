<?php

use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\CustomerOrdersController;
use App\Http\Controllers\Customer\CustomerProfileController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::prefix('customer')->group(function (): void {
    Route::post('login', [CustomerAuthController::class, 'login']);
    Route::post('register', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])
        ->middleware('throttle:6,1');
    Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'role:'.Role::USER])->group(function (): void {
        Route::post('logout', [CustomerAuthController::class, 'logout']);
        Route::get('me', [CustomerProfileController::class, 'show']);
        Route::put('profile', [CustomerProfileController::class, 'updateProfile']);
        Route::put('password', [CustomerProfileController::class, 'updatePassword']);
        Route::get('orders', [CustomerOrdersController::class, 'index']);
        Route::get('orders/{order}', [CustomerOrdersController::class, 'show'])->whereNumber('order');
    });
});
