<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes for the front panel
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api/front/v1.0" middleware group. Enjoy building your API!
|
*/

Route::prefix('/bookings')->namespace('Bookings')->group(function () {
    Route::post('/',  'BookingStoreController')->name('booking-store');
});
