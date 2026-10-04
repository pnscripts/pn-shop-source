<?php

use Illuminate\Support\Facades\Route;
use PnShop\Seo\Http\RobotsController;
use PnShop\Seo\Http\SitemapController;
use PnShop\Storefront\Http\Controllers\Account\AddressesController;
use PnShop\Storefront\Http\Controllers\Account\DashboardController;
use PnShop\Storefront\Http\Controllers\Account\OrdersController;
use PnShop\Storefront\Http\Controllers\CartController;
use PnShop\Storefront\Http\Controllers\CheckoutController;
use PnShop\Storefront\Http\Controllers\HomeController;
use PnShop\Storefront\Http\Controllers\InvoiceController;
use PnShop\Storefront\Http\Controllers\OrderController;
use PnShop\Storefront\Http\Controllers\PageController;
use PnShop\Storefront\Http\Controllers\ReturnController;
use PnShop\Storefront\Http\Controllers\ShopController;

Route::get('/', HomeController::class)->name('home');

Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemaps/{file}.xml', [SitemapController::class, 'show'])->name('sitemap.file');

Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/{product:slug}', [ShopController::class, 'show'])->name('shop.show');

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::middleware('throttle:cart')->group(function () {
    Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
    Route::patch('/cart/{variant}', [CartController::class, 'update'])->whereNumber('variant')->name('cart.update');
    Route::delete('/cart/{variant}', [CartController::class, 'destroy'])->whereNumber('variant')->name('cart.destroy');
    Route::post('/cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.store');
    Route::delete('/cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.destroy');
    Route::post('/cart/gift-cards', [CartController::class, 'applyGiftCard'])->name('cart.gift-cards.store');
    Route::delete('/cart/gift-cards/{giftCard}', [CartController::class, 'removeGiftCard'])->whereNumber('giftCard')->name('cart.gift-cards.destroy');
    Route::put('/cart/store-credit', [CartController::class, 'useStoreCredit'])->name('cart.store-credit.update');
});

Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout.create');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])
    ->middleware('throttle:60,1')
    ->name('checkout.quote');
Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware(['throttle:checkout', 'bot-trap'])
    ->name('checkout.store');

Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
Route::post('/orders/{order}/returns', [ReturnController::class, 'store'])->middleware('throttle:10,1')->name('orders.returns.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('account/orders', [OrdersController::class, 'index'])->name('account.orders');
    Route::get('account/addresses', [AddressesController::class, 'index'])->name('account.addresses');
    Route::post('account/addresses', [AddressesController::class, 'store'])->name('account.addresses.store');
    Route::put('account/addresses/{address}', [AddressesController::class, 'update'])->name('account.addresses.update');
    Route::delete('account/addresses/{address}', [AddressesController::class, 'destroy'])->name('account.addresses.destroy');
    Route::post('account/addresses/{address}/default', [AddressesController::class, 'makeDefaultFor'])->name('account.addresses.default');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

Route::get('/preview/pages/{page}', [PageController::class, 'preview'])->middleware('signed')->name('pages.preview');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// CMS pages at /{slug}; every other route takes precedence.
Route::fallback([PageController::class, 'show'])->name('pages.show');
