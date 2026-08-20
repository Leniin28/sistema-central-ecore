<?php

use App\Actions\Cotizaciones\ActualizarCotizacion;
use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function contextoAnticipos(): array
{
    $cliente = Cliente::create([
        'nombre' => 'Cliente anticipos',
        'telefono' => '4491002000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Framework',
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return compact('cliente', 'equipo', 'admin');
}

function crearCotizacionAnticipos(array $contexto, float $total = 1000, float $anticipo = 0, ?array $items = null): Cotizacion
{
    return app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => $anticipo,
    ], $items ?? [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio de prueba de anticipo',
        'cantidad' => 1,
        'precio_unitario' => $total,
        'costo_unitario' => 0,
    ]], $contexto['admin']);
}

function actualizarAnticipo(Cotizacion $cotizacion, User $actor, float $anticipo): Cotizacion
{
    $cotizacion->load('items');
    $items = $cotizacion->items->map(fn ($item): array => [
        'id' => $item->id,
        'tipo' => $item->tipo,
        'servicio_id' => $item->servicio_id,
        'descripcion' => $item->descripcion,
        'cantidad' => $item->cantidad,
        'precio_unitario' => $item->precio_unitario,
        'costo_unitario' => $item->costo_unitario,
    ])->all();

    return app(ActualizarCotizacion::class)->ejecutar($cotizacion, [
        'cliente_id' => $cotizacion->cliente_id,
        'equipo_id' => $cotizacion->equipo_id,
        'orden_servicio_id' => $cotizacion->ordenServicio?->id,
        'fecha' => $cotizacion->fecha->format('Y-m-d'),
        'vigencia' => $cotizacion->vigencia?->format('Y-m-d'),
        'tipo_recepcion' => $cotizacion->tipo_recepcion,
        'direccion_recepcion' => $cotizacion->direccion_recepcion,
        'descuento' => $cotizacion->descuento,
        'anticipo' => $anticipo,
        'notas' => $cotizacion->notas,
    ], $items, $actor);
}

function aceptarCotizacionAnticipos(Cotizacion $cotizacion, User $admin): OrdenServicio
{
    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $admin);

    return $cotizacion->fresh()->ordenServicio()->firstOrFail();
}

test('anticipo cero no crea movimientos', function () {
    $cotizacion = crearCotizacionAnticipos(contextoAnticipos());

    expect($cotizacion->movimientosFinancieros()->count())->toBe(0);
});

test('nuevo anticipo se registra una vez y guardar el mismo valor es idempotente', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 500);

    actualizarAnticipo($cotizacion->fresh(), $contexto['admin'], 500);

    $movimiento = $cotizacion->movimientosFinancieros()->sole();
    expect($movimiento->tipo)->toBe('ingreso')
        ->and($movimiento->categoria)->toBe('anticipo')
        ->and((float) $movimiento->monto)->toBe(500.0)
        ->and($movimiento->cotizacion_id)->toBe($cotizacion->id)
        ->and($cotizacion->movimientosFinancieros()->count())->toBe(1);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.movimientos-financieros.index'))
        ->assertOk()
        ->assertSee('Anticipo')
        ->assertSee($cotizacion->folio);
});

test('aumentar anticipo registra solo la diferencia y acumula el nuevo total', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 500);

    actualizarAnticipo($cotizacion->fresh(), $contexto['admin'], 800);

    expect($cotizacion->movimientosFinancieros()->orderBy('id')->pluck('monto')->map(fn ($monto) => (float) $monto)->all())
        ->toBe([500.0, 300.0])
        ->and((float) $cotizacion->movimientosFinancieros()->sum('monto'))->toBe(800.0);
});

test('disminuir por debajo del anticipo registrado se bloquea sin borrar movimientos', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 800);

    expect(fn () => actualizarAnticipo($cotizacion->fresh(), $contexto['admin'], 500))
        ->toThrow(ValidationException::class);

    expect((float) $cotizacion->fresh()->anticipo)->toBe(800.0)
        ->and((float) $cotizacion->movimientosFinancieros()->sum('monto'))->toBe(800.0);
});

test('anticipo mayor al total y anticipo negativo se rechazan', function (float $anticipo) {
    expect(fn () => crearCotizacionAnticipos(contextoAnticipos(), total: 1000, anticipo: $anticipo))
        ->toThrow(ValidationException::class);
})->with([1000.01, -0.01]);

test('entrega registra solo saldo y los ingresos acumulados igualan el total comercial', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, total: 4220, anticipo: 2030);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden);

    expect((float) $orden->movimientosFinancieros()->where('categoria', 'anticipo')->sum('monto'))->toBe(2030.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'reparacion')->sum('monto'))->toBe(2190.0)
        ->and((float) $orden->movimientosFinancieros()->where('tipo', 'ingreso')->sum('monto'))->toBe(4220.0);
});

test('anticipo total deja saldo cero y no crea otro ingreso de reparacion', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, total: 1000, anticipo: 1000);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden);

    expect($orden->movimientosFinancieros()->where('categoria', 'reparacion')->count())->toBe(0)
        ->and((float) $orden->movimientosFinancieros()->where('tipo', 'ingreso')->sum('monto'))->toBe(1000.0)
        ->and($orden->fresh()->finanzas_generadas)->toBeTrue();
});

test('inconsistencia de anticipos mayores al total bloquea la entrega', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, total: 1000, anticipo: 1000);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);
    $orden->movimientosFinancieros()->create([
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cotizacion->cliente_id,
        'tipo' => 'ingreso',
        'categoria' => 'anticipo',
        'monto' => 1,
        'descripcion' => 'Inconsistencia sintética',
        'fecha' => today(),
    ]);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden))
        ->toThrow(ValidationException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and($orden->movimientosFinancieros()->where('categoria', 'reparacion')->count())->toBe(0);
});

test('anticipo historico sin movimiento estructurado mantiene bloqueada la entrega', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto);
    $cotizacion->update(['anticipo' => 400, 'saldo' => 600]);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden))
        ->toThrow(ValidationException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and($orden->movimientosFinancieros()->count())->toBe(0);
});

test('costos comisiones y utilidad usan la venta total y no se duplican al reintentar', function () {
    $contexto = contextoAnticipos();
    $logistica = Partner::create(['nombre' => 'Electrocom anticipos', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $tecnico = Partner::create(['nombre' => 'Fixop anticipos', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 400, items: [[
        'tipo' => 'servicio', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 700, 'costo_unitario' => 100,
    ], [
        'tipo' => 'refaccion', 'descripcion' => 'Refacción', 'cantidad' => 1, 'precio_unitario' => 300, 'costo_unitario' => 50,
    ]]);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);
    $orden->update([
        'partner_recepcion_id' => $logistica->id,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 150,
    ]);

    $servicio = app(GenerarFinanzasOrdenServicio::class);
    $servicio->generar($orden);
    $cantidad = $orden->movimientosFinancieros()->count();
    $servicio->generar($orden->fresh());

    $orden->refresh();
    expect((float) $orden->movimientosFinancieros()->where('tipo', 'ingreso')->sum('monto'))->toBe(1000.0)
        ->and((float) $orden->utilidad_neta)->toBe(650.0)
        ->and($orden->costos_incompletos)->toBeFalse()
        ->and($orden->movimientosFinancieros()->where('categoria', 'pago_socio_logistico')->count())->toBe(1)
        ->and($orden->movimientosFinancieros()->where('categoria', 'pago_socio_tecnico')->count())->toBe(1)
        ->and($orden->movimientosFinancieros()->where('categoria', 'servicio')->count())->toBe(1)
        ->and($orden->movimientosFinancieros()->where('categoria', 'refaccion')->count())->toBe(1)
        ->and($orden->movimientosFinancieros()->count())->toBe($cantidad);
});

test('socio logistico no puede registrar anticipo', function () {
    $contexto = contextoAnticipos();
    $partner = Partner::create(['nombre' => 'Logística sin cobros', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);
    $logistico = User::factory()->create([
        'role' => 'socio_logistico',
        'partner_id' => $partner->id,
        'email_verified_at' => now(),
    ]);

    expect(fn () => app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'anticipo' => 100,
    ], [[
        'tipo' => 'servicio', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 500,
    ]], $logistico))->toThrow(ValidationException::class);

    expect(Cotizacion::count())->toBe(0)
        ->and(MovimientoFinanciero::count())->toBe(0);
});

test('admin aumenta anticipo de aceptada abierta y el movimiento queda ligado a la orden', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 400);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);

    actualizarAnticipo($cotizacion->fresh(), $contexto['admin'], 600);

    expect((float) $cotizacion->movimientosFinancieros()->sum('monto'))->toBe(600.0)
        ->and((float) $cotizacion->movimientosFinancieros()->latest('id')->firstOrFail()->monto)->toBe(200.0)
        ->and($cotizacion->movimientosFinancieros()->where('orden_servicio_id', $orden->id)->count())->toBe(2);
});

test('orden entregada no permite cambiar anticipo', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 400);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);
    $orden->update(['estado' => 'entregado', 'finanzas_generadas' => true]);

    expect(fn () => actualizarAnticipo($cotizacion->fresh(), $contexto['admin'], 500))
        ->toThrow(ValidationException::class);

    expect((float) $cotizacion->fresh()->anticipo)->toBe(400.0)
        ->and((float) $cotizacion->movimientosFinancieros()->sum('monto'))->toBe(400.0);
});

test('cancelar orden con anticipo preserva el ingreso sin devolución automática', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 400);
    $orden = aceptarCotizacionAnticipos($cotizacion, $contexto['admin']);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'cancelado'])
        ->assertRedirect();

    expect($orden->fresh()->estado)->toBe('cancelado')
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'anticipo')->sum('monto'))->toBe(400.0)
        ->and($orden->movimientosFinancieros()->where('tipo', 'egreso')->count())->toBe(0);
});

test('rechazar cotizacion con anticipo preserva el ingreso sin devolución automática', function () {
    $contexto = contextoAnticipos();
    $cotizacion = crearCotizacionAnticipos($contexto, anticipo: 400);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'rechazada', $contexto['admin']);

    expect($cotizacion->fresh()->estado)->toBe('rechazada')
        ->and((float) $cotizacion->movimientosFinancieros()->where('categoria', 'anticipo')->sum('monto'))->toBe(400.0)
        ->and($cotizacion->movimientosFinancieros()->where('tipo', 'egreso')->count())->toBe(0);
});
