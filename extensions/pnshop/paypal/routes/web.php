<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use PnShop\Plugins\PayPal\Http\PayPalController;

Route::get('/paypal/return/{payment}', [PayPalController::class, 'return'])->whereNumber('payment')->middleware('throttle:30,1')->name('paypal.return');

// Called by PayPal's servers: no session or CSRF token; the signature authenticates it.
Route::post('/paypal/webhook', [PayPalController::class, 'webhook'])
    ->withoutMiddleware([PreventRequestForgery::class, VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('paypal.webhook');
