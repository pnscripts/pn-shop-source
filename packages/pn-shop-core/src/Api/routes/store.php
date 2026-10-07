<?php

use Illuminate\Support\Facades\Route;
use PnShop\Api\Http\Controllers\Store\AccountController;
use PnShop\Api\Http\Controllers\Store\AuthController;
use PnShop\Api\Http\Controllers\Store\CartController;
use PnShop\Api\Http\Controllers\Store\CategoryController;
use PnShop\Api\Http\Controllers\Store\CheckoutController;
use PnShop\Api\Http\Controllers\Store\ContentController;
use PnShop\Api\Http\Controllers\Store\OrderController;
use PnShop\Api\Http\Controllers\Store\ProductController;
use PnShop\Api\Http\Controllers\Store\StoreController;

/*
| Store API v1: /api/store/v1. Public, with an optional customer token; see docs/api.
*/

Route::get('store', [StoreController::class, 'show'])->name('store');

Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('brands', [CategoryController::class, 'brands'])->name('brands.index');

Route::get('pages', [ContentController::class, 'pages'])->name('pages.index');
Route::get('pages/{slug}', [ContentController::class, 'page'])->name('pages.show');
Route::get('menus/{code}', [ContentController::class, 'menu'])->name('menus.show');

Route::get('cart', [CartController::class, 'show'])->name('cart.show');
Route::middleware('throttle:cart')->group(function () {
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::patch('cart/items/{variant}', [CartController::class, 'update'])->whereNumber('variant')->name('cart.items.update');
    Route::delete('cart/items/{variant}', [CartController::class, 'destroy'])->whereNumber('variant')->name('cart.items.destroy');
    Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.store');
    Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.destroy');
    Route::post('cart/gift-cards', [CartController::class, 'applyGiftCard'])->name('cart.gift-cards.store');
    Route::delete('cart/gift-cards/{giftCard}', [CartController::class, 'removeGiftCard'])->whereNumber('giftCard')->name('cart.gift-cards.destroy');
    Route::put('cart/store-credit', [CartController::class, 'useStoreCredit'])->name('cart.store-credit.update');
});

Route::get('checkout/payment-methods', [CheckoutController::class, 'paymentMethods'])->name('checkout.payment-methods');
Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:60,1')->name('checkout.quote');
Route::post('checkout', [CheckoutController::class, 'store'])->middleware(['throttle:checkout', 'pnshop.idempotent'])->name('checkout.store');

Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
Route::post('orders/{order}/returns', [OrderController::class, 'requestReturn'])->whereNumber('order')->middleware('throttle:10,1')->name('orders.returns.store');

Route::middleware('throttle:auth-forms')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
});

Route::middleware('auth:store-api')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('account', [AccountController::class, 'show'])->name('account.show');
    Route::patch('account', [AccountController::class, 'update'])->name('account.update');
    Route::post('account/email/verification-notification', [AccountController::class, 'resendVerification'])->middleware('throttle:6,1')->name('account.verification.send');
    Route::get('account/orders', [AccountController::class, 'orders'])->name('account.orders');
    Route::get('account/addresses', [AccountController::class, 'addresses'])->name('account.addresses.index');
    Route::post('account/addresses', [AccountController::class, 'storeAddress'])->name('account.addresses.store');
    Route::put('account/addresses/{address}', [AccountController::class, 'updateAddress'])->whereNumber('address')->name('account.addresses.update');
    Route::delete('account/addresses/{address}', [AccountController::class, 'destroyAddress'])->whereNumber('address')->name('account.addresses.destroy');
});
