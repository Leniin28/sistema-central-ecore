<?php

use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\CategoriaServicioController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\MovimientoFinancieroController;
use App\Http\Controllers\OrdenServicioController;
use App\Http\Controllers\OrdenServicioEstadoController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\LogisticaDashboardController;
use App\Http\Controllers\TecnicoDashboardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardRedirectController::class)->name('dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        Route::resource('clientes', ClienteController::class);
        Route::resource('equipos', EquipoController::class);
        Route::get('movimientos-financieros/create', [MovimientoFinancieroController::class, 'create'])
            ->name('movimientos-financieros.create');
        Route::post('movimientos-financieros', [MovimientoFinancieroController::class, 'store'])
            ->name('movimientos-financieros.store');
        Route::get('movimientos-financieros', [MovimientoFinancieroController::class, 'index'])
            ->name('movimientos-financieros.index');
        Route::post('ordenes-servicio/{ordenServicio}/estado', [OrdenServicioEstadoController::class, 'store'])
            ->name('ordenes-servicio.estado.store');
        Route::resource('ordenes-servicio', OrdenServicioController::class)
            ->parameters(['ordenes-servicio' => 'ordenServicio']);
        Route::resource('categorias-servicio', CategoriaServicioController::class)
            ->parameters(['categorias-servicio' => 'categoriaServicio']);
        Route::resource('servicios', ServicioController::class);
    });

    Route::middleware('role:socio_logistico')->prefix('logistica')->name('logistica.')->group(function () {
        Route::get('dashboard', LogisticaDashboardController::class)->name('dashboard');

        Route::resource('clientes', ClienteController::class)
            ->only(['index', 'create', 'store', 'show']);
        Route::resource('equipos', EquipoController::class)
            ->only(['index', 'create', 'store', 'show']);
        Route::post('ordenes-servicio/{ordenServicio}/estado', [OrdenServicioEstadoController::class, 'store'])
            ->name('ordenes-servicio.estado.store');
        Route::resource('ordenes-servicio', OrdenServicioController::class)
            ->only(['index', 'create', 'store', 'show'])
            ->parameters(['ordenes-servicio' => 'ordenServicio']);
    });

    Route::middleware('role:socio_tecnico')->prefix('tecnico')->name('tecnico.')->group(function () {
        Route::get('dashboard', TecnicoDashboardController::class)->name('dashboard');

        Route::post('ordenes-servicio/{ordenServicio}/estado', [OrdenServicioEstadoController::class, 'store'])
            ->name('ordenes-servicio.estado.store');
        Route::resource('ordenes-servicio', OrdenServicioController::class)
            ->only(['index', 'show'])
            ->parameters(['ordenes-servicio' => 'ordenServicio']);
    });
});

require __DIR__.'/settings.php';
