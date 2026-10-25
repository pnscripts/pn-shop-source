<?php

use Illuminate\Support\Facades\Route;
use PnShop\Plugins\Speedy\Http\OfficeController;

// The checkout's office picker (same-origin requests with the session's CSRF token).
Route::get('/speedy/offices', [OfficeController::class, 'index'])->middleware('throttle:60,1')->name('speedy.offices');
Route::post('/speedy/office', [OfficeController::class, 'store'])->middleware('throttle:30,1')->name('speedy.office');
