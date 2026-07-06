<?php

use App\Http\Controllers\Api\InternalExpenseController;
use App\Http\Controllers\Api\InternalFollowUpController;
use App\Http\Controllers\Api\InternalQuoteController;
use App\Http\Controllers\Api\InternalReceptionController;
use App\Http\Controllers\Api\InternalReportController;
use App\Http\Controllers\Api\InternalSearchController;
use App\Http\Controllers\Api\InternalServiceOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['internal.api', 'throttle:30,1'])->prefix('internal')->name('api.internal.')->group(function () {
    Route::post('quotes', [InternalQuoteController::class, 'store'])->name('quotes.store');
    // "pending" antes que {cotizacion} para que no lo capture el binding.
    Route::get('quotes/pending', [InternalQuoteController::class, 'pending'])->name('quotes.pending');
    Route::get('quotes/{cotizacion}/pdf', [InternalQuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::get('quotes/{cotizacion}/png', [InternalQuoteController::class, 'png'])->name('quotes.png');

    // Convertir una cotización aceptada en orden de servicio (idempotente por external_id).
    Route::post('quotes/{cotizacion}/convert-to-order', [InternalQuoteController::class, 'convertToOrder'])
        ->name('quotes.convert-to-order');

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

    // Generar texto de mensaje para el cliente (no envía nada).
    Route::post('service-orders/{orden}/message-template', [InternalServiceOrderController::class, 'messageTemplate'])
        ->name('service-orders.message-template');

    // Búsqueda unificada de clientes/órdenes/cotizaciones.
    Route::get('search', InternalSearchController::class)->name('search');

    // Reportes operativos y corte.
    Route::get('reports/daily', [InternalReportController::class, 'daily'])->name('reports.daily');
    Route::get('reports/weekly', [InternalReportController::class, 'weekly'])->name('reports.weekly');
    Route::get('reports/cash-cut', [InternalReportController::class, 'cashCut'])->name('reports.cash-cut');

    // Pendientes/atrasos para seguimiento (OpenClaw decide cuándo consultarlos).
    Route::get('follow-ups', InternalFollowUpController::class)->name('follow-ups');

    // Gastos operativos manuales (egresos sin orden asociada).
    Route::post('expenses', [InternalExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses', [InternalExpenseController::class, 'index'])->name('expenses.index');
});
