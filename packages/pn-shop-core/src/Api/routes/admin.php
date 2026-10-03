<?php

use Illuminate\Support\Facades\Route;
use PnShop\Api\Http\Controllers\Admin\BrandController;
use PnShop\Api\Http\Controllers\Admin\CategoryController;
use PnShop\Api\Http\Controllers\Admin\CustomerController;
use PnShop\Api\Http\Controllers\Admin\MeController;
use PnShop\Api\Http\Controllers\Admin\MediaController;
use PnShop\Api\Http\Controllers\Admin\OrderController;
use PnShop\Api\Http\Controllers\Admin\PageController;
use PnShop\Api\Http\Controllers\Admin\PriceListController;
use PnShop\Api\Http\Controllers\Admin\ProductController;
use PnShop\Api\Http\Controllers\Admin\PromotionController;
use PnShop\Api\Http\Controllers\Admin\ReturnController;
use PnShop\Api\Http\Controllers\Admin\SettingsController;
use PnShop\Api\Http\Controllers\Admin\SystemController;
use PnShop\Api\Http\Controllers\Admin\VariantController;

/*
| Admin API v1: /api/admin/v1. Staff tokens only; each endpoint checks the permission it
| needs against the token's abilities and the owner's roles. See docs/api.
*/

Route::get('me', [MeController::class, 'show'])->name('me');

Route::apiResource('products', ProductController::class)->whereNumber('product');
Route::get('variants', [VariantController::class, 'index'])->name('variants.index');
Route::post('products/{product}/variants', [VariantController::class, 'store'])->whereNumber('product')->name('variants.store');
Route::patch('variants/{variant}', [VariantController::class, 'update'])->whereNumber('variant')->name('variants.update');
Route::delete('variants/{variant}', [VariantController::class, 'destroy'])->whereNumber('variant')->name('variants.destroy');
Route::post('variants/{variant}/stock', [VariantController::class, 'stock'])->whereNumber('variant')->name('variants.stock');

Route::apiResource('categories', CategoryController::class)->whereNumber('category');
Route::apiResource('brands', BrandController::class)->whereNumber('brand');
Route::apiResource('price-lists', PriceListController::class)->parameters(['price-lists' => 'priceList'])->whereNumber('priceList');
Route::get('price-lists/{priceList}/prices', [PriceListController::class, 'entries'])->whereNumber('priceList')->name('price-lists.prices.index');
Route::put('price-lists/{priceList}/prices', [PriceListController::class, 'setEntries'])->whereNumber('priceList')->middleware('pnshop.idempotent')->name('price-lists.prices.update');
Route::post('media', [MediaController::class, 'store'])->name('media.store');

Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
Route::post('orders/{order}/transitions', [OrderController::class, 'transition'])->whereNumber('order')->middleware('pnshop.idempotent')->name('orders.transition');
Route::post('orders/{order}/notes', [OrderController::class, 'note'])->whereNumber('order')->middleware('pnshop.idempotent')->name('orders.notes.store');
Route::post('orders/{order}/shipments', [OrderController::class, 'ship'])->whereNumber('order')->middleware('pnshop.idempotent')->name('orders.shipments.store');
Route::post('orders/{order}/refunds', [OrderController::class, 'refund'])->whereNumber('order')->middleware('pnshop.idempotent')->name('orders.refunds.store');

Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
Route::get('returns/{return}', [ReturnController::class, 'show'])->whereNumber('return')->name('returns.show');
Route::post('returns/{return}/transitions', [ReturnController::class, 'transition'])->whereNumber('return')->middleware('pnshop.idempotent')->name('returns.transition');

Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
Route::patch('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');

Route::apiResource('pages', PageController::class)->whereNumber('page');

Route::apiResource('promotions', PromotionController::class)->whereNumber('promotion');
Route::post('promotions/{promotion}/coupons', [PromotionController::class, 'generateCoupons'])->whereNumber('promotion')->name('promotions.coupons.store');

Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('settings/{namespace}', [SettingsController::class, 'show'])->where('namespace', '[a-z0-9_.-]+')->name('settings.show');
Route::patch('settings/{namespace}', [SettingsController::class, 'update'])->where('namespace', '[a-z0-9_.-]+')->name('settings.update');

Route::get('extensions', [SystemController::class, 'extensions'])->name('extensions.index');
Route::get('themes', [SystemController::class, 'themes'])->name('themes.index');
