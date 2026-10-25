<?php

use Illuminate\Support\Facades\Route;
use PnShop\Plugins\Speedy\Http\OfficeController;

// The checkout's office picker (same-origin requests with the session's CSRF token).
Route::get('/speedy/offices', [OfficeController::class, 'index'])->middleware('throttle:pnshop-speedy')->name('speedy.offices');
Route::post('/speedy/office', [OfficeController::class, 'store'])->middleware('throttle:pnshop-speedy')->name('speedy.office');
