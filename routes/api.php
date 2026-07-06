<?php

use App\Http\Controllers\Api\InternalQuoteController;
use App\Http\Controllers\Api\InternalReceptionController;
use App\Http\Controllers\Api\InternalSearchController;
use App\Http\Controllers\Api\InternalServiceOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['internal.api', 'throttle:30,1'])->prefix('internal')->name('api.internal.')->group(function () {
    Route::post('quotes', [InternalQuoteController::class, 'store'])->name('quotes.store');
    Route::get('quotes/{cotizacion}/pdf', [InternalQuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::get('quotes/{cotizacion}/png', [InternalQuoteController::class, 'png'])->name('quotes.png');

    Route::post('receptions', [InternalReceptionController::class, 'store'])->name('receptions.store');

    // Consultar una orden por id o folio, con payload seguro (sin password_equipo).
    Route::get('service-orders/{orden}', [InternalServiceOrderController::class, 'show'])
        ->name('service-orders.show');

    // Corregir datos base de una orden (cliente/equipo/recepción/partner).
    Route::post('service-orders/{orden}/profile', [InternalServiceOrderController::class, 'profile'])
        ->name('service-orders.profile');

    // Editar una orden existente (agregar servicios/refacciones/notas). {orden} = id o folio.
    Route::post('service-orders/{orden}/changes', [InternalServiceOrderController::class, 'changes'])
        ->name('service-orders.changes');

    // Cambiar el estado de una orden. `entregado` exige confirm_final_delivery=true.
    Route::post('service-orders/{orden}/status', [InternalServiceOrderController::class, 'status'])
        ->name('service-orders.status');

    // Búsqueda unificada de clientes/órdenes/cotizaciones.
    Route::get('search', InternalSearchController::class)->name('search');
});
