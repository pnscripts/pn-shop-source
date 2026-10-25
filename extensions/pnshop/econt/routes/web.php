<?php

use Illuminate\Support\Facades\Route;
use PnShop\Plugins\Econt\Http\OfficeController;

// The checkout's office picker (same-origin requests with the session's CSRF token).
Route::get('/econt/offices', [OfficeController::class, 'index'])->middleware('throttle:pnshop-econt')->name('econt.offices');
Route::post('/econt/office', [OfficeController::class, 'store'])->middleware('throttle:pnshop-econt')->name('econt.office');
