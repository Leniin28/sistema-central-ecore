<?php

use App\Http\Controllers\Api\InternalQuoteController;
use App\Http\Controllers\Api\InternalReceptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['internal.api', 'throttle:30,1'])->prefix('internal')->name('api.internal.')->group(function () {
    Route::post('quotes', [InternalQuoteController::class, 'store'])->name('quotes.store');
    Route::get('quotes/{cotizacion}/pdf', [InternalQuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::get('quotes/{cotizacion}/png', [InternalQuoteController::class, 'png'])->name('quotes.png');

    Route::post('receptions', [InternalReceptionController::class, 'store'])->name('receptions.store');
});
