<?php

use Illuminate\Support\Facades\Route;
use PnShop\Plugins\Econt\Http\OfficeController;

// The checkout's office picker (same-origin requests with the session's CSRF token).
Route::get('/econt/offices', [OfficeController::class, 'index'])->middleware('throttle:60,1')->name('econt.offices');
Route::post('/econt/office', [OfficeController::class, 'store'])->middleware('throttle:30,1')->name('econt.office');
