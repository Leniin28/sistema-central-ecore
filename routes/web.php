<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\CategoriaServicioController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\FinanzasResumenController;
use App\Http\Controllers\LogisticaDashboardController;
use App\Http\Controllers\MovimientoFinancieroController;
use App\Http\Controllers\OrdenServicioController;
use App\Http\Controllers\OrdenServicioEstadoController;
use App\Http\Controllers\RecepcionController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\TecnicoDashboardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardRedirectController::class)->name('dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');
        Route::get('recepciones/create', [RecepcionController::class, 'create'])->name('recepciones.create');
        Route::post('recepciones', [RecepcionController::class, 'store'])->name('recepciones.store');

        Route::get('clientes/buscar', [ClienteController::class, 'buscar'])->name('clientes.buscar');
        Route::get('clientes/{cliente}/equipos/buscar', [EquipoController::class, 'buscar'])->name('clientes.equipos.buscar');

        Route::resource('clientes', ClienteController::class);
        Route::resource('equipos', EquipoController::class);
        Route::get('movimientos-financieros/create', [MovimientoFinancieroController::class, 'create'])
            ->name('movimientos-financieros.create');
        Route::post('movimientos-financieros', [MovimientoFinancieroController::class, 'store'])
            ->name('movimientos-financieros.store');
        Route::get('movimientos-financieros', [MovimientoFinancieroController::class, 'index'])
            ->name('movimientos-financieros.index');
        Route::get('finanzas/resumen', [FinanzasResumenController::class, 'index'])
            ->name('finanzas.resumen');
        Route::post('ordenes-servicio/{ordenServicio}/estado', [OrdenServicioEstadoController::class, 'store'])
            ->name('ordenes-servicio.estado.store');
        Route::patch('ordenes-servicio/{ordenServicio}/costos', [OrdenServicioController::class, 'actualizarCostos'])
            ->name('ordenes-servicio.costos.update');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion', [OrdenServicioController::class, 'notaRecepcion'])
            ->name('ordenes-servicio.nota-recepcion');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion/pdf', [OrdenServicioController::class, 'notaRecepcionPdf'])
            ->name('ordenes-servicio.nota-recepcion.pdf');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion/png', [OrdenServicioController::class, 'notaRecepcionPng'])
            ->name('ordenes-servicio.nota-recepcion.png');
        Route::resource('ordenes-servicio', OrdenServicioController::class)
            ->parameters(['ordenes-servicio' => 'ordenServicio']);
        Route::resource('categorias-servicio', CategoriaServicioController::class)
            ->parameters(['categorias-servicio' => 'categoriaServicio']);
        Route::resource('servicios', ServicioController::class);

        Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf'])
            ->name('cotizaciones.pdf');
        Route::get('cotizaciones/{cotizacion}/png', [CotizacionController::class, 'png'])
            ->name('cotizaciones.png');
        Route::get('cotizaciones/{cotizacion}/plantilla', [CotizacionController::class, 'plantilla'])
            ->name('cotizaciones.plantilla');
        Route::post('cotizaciones/{cotizacion}/estado', [CotizacionController::class, 'cambiarEstado'])
            ->name('cotizaciones.estado.store');
        Route::get('cotizaciones-ordenes-elegibles', [CotizacionController::class, 'ordenesElegibles'])
            ->name('cotizaciones.ordenes-elegibles');
        Route::resource('cotizaciones', CotizacionController::class)
            ->parameters(['cotizaciones' => 'cotizacion']);
    });

    Route::middleware('role:socio_logistico')->prefix('logistica')->name('logistica.')->group(function () {
        Route::get('dashboard', LogisticaDashboardController::class)->name('dashboard');
        Route::get('recepciones/create', [RecepcionController::class, 'create'])->name('recepciones.create');
        Route::post('recepciones', [RecepcionController::class, 'store'])->name('recepciones.store');

        Route::get('clientes/buscar', [ClienteController::class, 'buscar'])->name('clientes.buscar');
        Route::get('clientes/{cliente}/equipos/buscar', [EquipoController::class, 'buscar'])->name('clientes.equipos.buscar');

        Route::resource('clientes', ClienteController::class)
            ->only(['index', 'create', 'store', 'show']);
        Route::resource('equipos', EquipoController::class)
            ->only(['index', 'create', 'store', 'show']);
        Route::post('ordenes-servicio/{ordenServicio}/estado', [OrdenServicioEstadoController::class, 'store'])
            ->name('ordenes-servicio.estado.store');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion', [OrdenServicioController::class, 'notaRecepcion'])
            ->name('ordenes-servicio.nota-recepcion');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion/pdf', [OrdenServicioController::class, 'notaRecepcionPdf'])
            ->name('ordenes-servicio.nota-recepcion.pdf');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion/png', [OrdenServicioController::class, 'notaRecepcionPng'])
            ->name('ordenes-servicio.nota-recepcion.png');
        Route::resource('ordenes-servicio', OrdenServicioController::class)
            ->only(['index', 'create', 'store', 'show'])
            ->parameters(['ordenes-servicio' => 'ordenServicio']);

        Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf'])
            ->name('cotizaciones.pdf');
        Route::get('cotizaciones/{cotizacion}/png', [CotizacionController::class, 'png'])
            ->name('cotizaciones.png');
        Route::get('cotizaciones/{cotizacion}/plantilla', [CotizacionController::class, 'plantilla'])
            ->name('cotizaciones.plantilla');
        Route::post('cotizaciones/{cotizacion}/estado', [CotizacionController::class, 'cambiarEstado'])
            ->name('cotizaciones.estado.store');
        Route::resource('cotizaciones', CotizacionController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
            ->parameters(['cotizaciones' => 'cotizacion']);
    });

    Route::middleware('role:socio_tecnico')->prefix('tecnico')->name('tecnico.')->group(function () {
        Route::get('dashboard', TecnicoDashboardController::class)->name('dashboard');

        Route::post('ordenes-servicio/{ordenServicio}/estado', [OrdenServicioEstadoController::class, 'store'])
            ->name('ordenes-servicio.estado.store');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion', [OrdenServicioController::class, 'notaRecepcion'])
            ->name('ordenes-servicio.nota-recepcion');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion/pdf', [OrdenServicioController::class, 'notaRecepcionPdf'])
            ->name('ordenes-servicio.nota-recepcion.pdf');
        Route::get('ordenes-servicio/{ordenServicio}/nota-recepcion/png', [OrdenServicioController::class, 'notaRecepcionPng'])
            ->name('ordenes-servicio.nota-recepcion.png');
        Route::resource('ordenes-servicio', OrdenServicioController::class)
            ->only(['index', 'show'])
            ->parameters(['ordenes-servicio' => 'ordenServicio']);
    });
});

require __DIR__.'/settings.php';
