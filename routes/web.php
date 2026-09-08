<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});

Route::post('/logout', [AuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::view('/', 'pages.dashboard')->name('dashboard');
    Route::view('/products', 'pages.products')->name('products');
    Route::view('/shopping-list', 'pages.shopping-list')->name('shopping-list');

    // Barcode scanning now lives as a tab on the products page.
    Route::redirect('/scan', '/products');
});
