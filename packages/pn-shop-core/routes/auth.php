<?php

use Illuminate\Support\Facades\Route;
use PnShop\Storefront\Http\Controllers\Auth\AuthenticatedSessionController;
use PnShop\Storefront\Http\Controllers\Auth\ConfirmablePasswordController;
use PnShop\Storefront\Http\Controllers\Auth\EmailVerificationNotificationController;
use PnShop\Storefront\Http\Controllers\Auth\EmailVerificationPromptController;
use PnShop\Storefront\Http\Controllers\Auth\NewPasswordController;
use PnShop\Storefront\Http\Controllers\Auth\PasswordResetLinkController;
use PnShop\Storefront\Http\Controllers\Auth\RegisteredUserController;
use PnShop\Storefront\Http\Controllers\Auth\VerifyEmailController;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware(['throttle:auth-forms', 'bot-trap']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:auth-forms')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:auth-forms')
        ->name('password.store');
});

// Signed link from the verification email: works signed in or not (Store API customers).
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:auth-forms');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
