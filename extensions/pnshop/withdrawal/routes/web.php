<?php

use Illuminate\Support\Facades\Route;
use PnShop\Plugins\Withdrawal\Http\WithdrawalController;

// The withdrawal function: open to guests and customers, during and after the period.
Route::get('/withdrawal', [WithdrawalController::class, 'create'])->name('withdrawal.create');
Route::post('/withdrawal', [WithdrawalController::class, 'review'])->middleware(['throttle:pnshop-withdrawal', 'bot-trap'])->name('withdrawal.review');
Route::get('/withdrawal/confirm', [WithdrawalController::class, 'confirm'])->name('withdrawal.confirm');
Route::post('/withdrawal/confirm', [WithdrawalController::class, 'store'])->middleware('throttle:pnshop-withdrawal-confirm')->name('withdrawal.store');
Route::get('/withdrawal/sent', [WithdrawalController::class, 'sent'])->name('withdrawal.sent');
