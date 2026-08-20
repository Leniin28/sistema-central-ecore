<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Cotizaciones\ReconciliarCotizacionesHistoricas;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\ReconciliacionCotizacionOrden;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{admin: User, cliente: Cliente, equipo: Equipo} */
function contextoReconciliacionHistorica(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create([
        'nombre' => 'Cliente reconciliación histórica',
        'telefono' => '4490000000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Prueba',
        'modelo' => 'Histórica',
    ]);

    return compact('admin', 'cliente', 'equipo');
}

/** @param array<int, array<string, mixed>> $items */
function cotizacionHistorica(array $contexto, string $folio, array $items): Cotizacion
{
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => '2026-08-09',
        'tipo_recepcion' => 'en_negocio',
    ], $items, $contexto['admin']);
    $cotizacion->update(['folio' => $folio, 'estado' => 'aceptada']);

    return $cotizacion->fresh('items');
}

/**
 * @param  array<int, array<string, mixed>>  $servicios
 * @param  array<int, array<string, mixed>>  $refacciones
 */
function ordenHistorica(
    array $contexto,
    string $folio,
    string $createdAt,
    array $servicios = [],
    array $refacciones = [],
    ?int $cotizacionId = null,
): OrdenServicio {
    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cotizacion_id' => $cotizacionId,
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'costo_tecnico' => null,
    ], $servicios, $refacciones, $contexto['admin']);
    $orden->timestamps = false;
    $orden->forceFill([
        'folio' => $folio,
        'created_at' => CarbonImmutable::parse($createdAt),
        'updated_at' => CarbonImmutable::parse($createdAt),
    ])->save();
    $orden->timestamps = true;

    return $orden->fresh(['detalles', 'refacciones']);
}

test('usa el timestamp exacto de Git y solo considera provisionales los servicios estrictamente anteriores', function () {
    $contexto = contextoReconciliacionHistorica();
    cotizacionHistorica($contexto, 'COT-20260728-0001', [[
        'tipo' => 'servicio', 'descripcion' => 'Trabajo autorizado', 'cantidad' => 1, 'precio_unitario' => 100,
    ]]);
    $orden = ordenHistorica($contexto, 'OS-20260728-0001', '2026-08-18T18:22:33-06:00', [[
        'descripcion' => 'Captura obligatoria antigua', 'cantidad' => 1, 'precio_unitario' => 100,
    ]]);

    $enCutoff = app(ReconciliarCotizacionesHistoricas::class)->diagnosticar('cot-20260728-0001')->sole();
    expect(config('historical_quote_reconciliation.cutoff.commit'))
        ->toBe('74ec44b227a4ac256a05dba781cdb99ada3802c2')
        ->and(config('historical_quote_reconciliation.cutoff.committed_at'))->toBe('2026-08-19T00:22:33Z')
        ->and($enCutoff['provisional_services_to_delete'])->toBe(0)
        ->and($enCutoff['projected_order_total'])->toBe(200.0);

    $orden->timestamps = false;
    $orden->forceFill(['created_at' => CarbonImmutable::parse('2026-08-18T18:22:32-06:00')])->save();
    $orden->timestamps = true;
    expect($orden->fresh()->created_at->toJSON())->toBe('2026-08-19T00:22:32.000000Z');
    $antes = app(ReconciliarCotizacionesHistoricas::class)->diagnosticar('cot-20260728-0001')->sole();

    expect($antes['provisional_services_to_delete'])->toBe(1)
        ->and($antes['projected_order_total'])->toBe(100.0)
        ->and($antes['fingerprint'])->not->toBe($enCutoff['fingerprint']);
});

test('el comando usa dry-run por defecto y apply exige caso huella y actor', function () {
    $this->artisan('cotizaciones:reconciliar-ordenes --case=cot-20260728-0001')
        ->expectsOutputToContain('Dry-run por defecto')
        ->assertExitCode(0);

    $this->artisan('cotizaciones:reconciliar-ordenes --apply --case=cot-20260728-0001')
        ->expectsOutputToContain('--apply requiere --case, --fingerprint y --actor')
        ->assertExitCode(2);
});

test('reemplaza servicios provisionales por la cotizacion y la segunda aplicacion es idempotente', function () {
    $contexto = contextoReconciliacionHistorica();
    $cotizacion = cotizacionHistorica($contexto, 'COT-20260809-0001', [[
        'tipo' => 'servicio', 'descripcion' => 'Memoria RAM', 'cantidad' => 1, 'precio_unitario' => 930,
    ], [
        'tipo' => 'servicio', 'descripcion' => 'Instalación RAM', 'cantidad' => 1, 'precio_unitario' => 80,
    ], [
        'tipo' => 'refaccion', 'descripcion' => 'SSD NVME 1tb', 'cantidad' => 1, 'precio_unitario' => 3130,
    ], [
        'tipo' => 'servicio', 'descripcion' => 'Instalación SSD', 'cantidad' => 1, 'precio_unitario' => 80,
    ]]);
    $cotizacion->update(['anticipo' => 2030, 'saldo' => 2190]);
    $orden = ordenHistorica($contexto, 'OS-20260809-0001', '2026-08-09T18:10:39Z', [[
        'descripcion' => 'Línea provisional confirmada', 'cantidad' => 1, 'precio_unitario' => 550,
    ]]);
    $provisionalId = $orden->detalles()->sole()->id;
    $accion = app(ReconciliarCotizacionesHistoricas::class);
    $plan = $accion->diagnosticar('cot-20260809-0001')->sole();

    expect($plan['status'])->toBe('ready')
        ->and($plan['provisional_services_to_delete'])->toBe(1)
        ->and($plan['projected_order_total'])->toBe(4220.0);

    $primera = $accion->aplicar('cot-20260809-0001', $plan['fingerprint'], $contexto['admin']->id);
    $orden->refresh();
    expect($primera['aplicada'])->toBeTrue()
        ->and($orden->cotizacion_id)->toBe($cotizacion->id)
        ->and($orden->detalles()->whereKey($provisionalId)->exists())->toBeFalse()
        ->and($orden->detalles()->whereNotNull('cotizacion_item_id')->count())->toBe(3)
        ->and($orden->refacciones()->whereNotNull('cotizacion_item_id')->count())->toBe(1)
        ->and((float) $orden->total_cliente)->toBe(4220.0)
        ->and(ReconciliacionCotizacionOrden::where('case_key', 'cot-20260809-0001')->count())->toBe(1);

    $conteos = [$orden->detalles()->count(), $orden->refacciones()->count(), ReconciliacionCotizacionOrden::count()];
    $segunda = $accion->aplicar('cot-20260809-0001', $plan['fingerprint'], $contexto['admin']->id);

    expect($segunda['aplicada'])->toBeFalse()
        ->and([$orden->detalles()->count(), $orden->refacciones()->count(), ReconciliacionCotizacionOrden::count()])
        ->toBe($conteos);
});

test('consolida las dos cotizaciones y deja las ordenes origen incapaces de generar finanzas', function () {
    $contexto = contextoReconciliacionHistorica();
    $principal = cotizacionHistorica($contexto, 'COT-20260820-0001', [[
        'tipo' => 'servicio', 'descripcion' => 'Optimización Laptop', 'cantidad' => 1, 'precio_unitario' => 550,
    ], [
        'tipo' => 'refaccion', 'descripcion' => 'SSD 256gb', 'cantidad' => 1, 'precio_unitario' => 890, 'costo_unitario' => 721,
    ]]);
    $adicional = cotizacionHistorica($contexto, 'COT-20260820-0002', [[
        'tipo' => 'servicio', 'descripcion' => 'Paquete Office', 'cantidad' => 1, 'precio_unitario' => 120,
    ]]);
    $ordenCanonica = ordenHistorica($contexto, 'OS-20260820-0001', '2026-08-20T17:30:40Z');
    $ordenPrincipal = ordenHistorica($contexto, 'OS-20260820-0002', '2026-08-20T17:33:41Z', [[
        'descripcion' => 'Optimización Laptop', 'cantidad' => 1, 'precio_unitario' => 550,
    ]], [[
        'descripcion' => 'SSD 256gb', 'cantidad' => 1, 'precio_unitario_cliente' => 890, 'costo_unitario' => 721,
    ]], $principal->id);
    $ordenAdicional = ordenHistorica($contexto, 'OS-20260820-0003', '2026-08-20T17:38:37Z', [[
        'descripcion' => 'Paquete Office', 'cantidad' => 1, 'precio_unitario' => 120,
    ]], [], $adicional->id);
    $itemAdicional = $adicional->items()->sole();
    $ordenAdicional->detalles()->sole()->update(['cotizacion_item_id' => $itemAdicional->id]);

    $accion = app(ReconciliarCotizacionesHistoricas::class);
    $plan = $accion->diagnosticar('cot-20260820-0001-0002')->sole();
    expect($plan['status'])->toBe('ready')
        ->and($plan['projected_quote_total'])->toBe(1560.0)
        ->and($plan['projected_order_total'])->toBe(1560.0);

    $accion->aplicar('cot-20260820-0001-0002', $plan['fingerprint'], $contexto['admin']->id);
    $principal->refresh();
    $adicional->refresh();
    $ordenCanonica->refresh();
    $ordenPrincipal->refresh();
    $ordenAdicional->refresh();

    expect($principal->items()->count())->toBe(3)
        ->and((float) $principal->total)->toBe(1560.0)
        ->and($adicional->cotizacion_canonica_id)->toBe($principal->id)
        ->and($adicional->items()->count())->toBe(0)
        ->and($itemAdicional->fresh()->cotizacion_origen_id)->toBe($adicional->id)
        ->and($ordenCanonica->cotizacion_id)->toBe($principal->id)
        ->and((float) $ordenCanonica->total_cliente)->toBe(1560.0)
        ->and($ordenPrincipal->estado)->toBe('cancelado')
        ->and($ordenAdicional->estado)->toBe('cancelado')
        ->and($ordenPrincipal->orden_canonica_id)->toBe($ordenCanonica->id)
        ->and($ordenAdicional->orden_canonica_id)->toBe($ordenCanonica->id)
        ->and($ordenPrincipal->cotizacion_id)->toBeNull()
        ->and($ordenAdicional->cotizacion_id)->toBeNull()
        ->and($ordenAdicional->detalles()->sole()->cotizacion_item_id)->toBeNull();

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($ordenAdicional))
        ->toThrow(ValidationException::class);
    expect(MovimientoFinanciero::whereIn('orden_servicio_id', [$ordenPrincipal->id, $ordenAdicional->id])->count())
        ->toBe(0);
});

test('bloquea completamente cualquier caso con finanzas o movimientos', function () {
    $contexto = contextoReconciliacionHistorica();
    cotizacionHistorica($contexto, 'COT-20260731-0001', [[
        'tipo' => 'servicio', 'descripcion' => 'Trabajo', 'cantidad' => 1, 'precio_unitario' => 500,
    ]]);
    $orden = ordenHistorica($contexto, 'OS-20260731-0001', '2026-07-31T16:30:48Z');
    $orden->update(['finanzas_generadas' => true]);
    $orden->movimientosFinancieros()->create([
        'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'ingreso',
        'categoria' => 'reparacion',
        'monto' => 500,
        'descripcion' => 'Movimiento existente',
        'fecha' => '2026-07-31',
    ]);

    $accion = app(ReconciliarCotizacionesHistoricas::class);
    $plan = $accion->diagnosticar('cot-20260731-0001')->sole();
    expect($plan['status'])->toBe('protected');

    expect(fn () => $accion->aplicar('cot-20260731-0001', $plan['fingerprint'], $contexto['admin']->id))
        ->toThrow(ValidationException::class);
    expect(ReconciliacionCotizacionOrden::count())->toBe(0)
        ->and($orden->fresh()->cotizacion_id)->toBeNull()
        ->and($orden->fresh()->finanzas_generadas)->toBeTrue();
});

test('rechaza una huella obsoleta y revierte el caso completo', function () {
    $contexto = contextoReconciliacionHistorica();
    cotizacionHistorica($contexto, 'COT-20260808-0001', [[
        'tipo' => 'servicio', 'descripcion' => 'Trabajo autorizado', 'cantidad' => 1, 'precio_unitario' => 360,
    ]]);
    $orden = ordenHistorica($contexto, 'OS-20260808-0001', '2026-08-08T12:49:02Z', [[
        'descripcion' => 'Servicio provisional', 'cantidad' => 1, 'precio_unitario' => 360,
    ]]);
    $accion = app(ReconciliarCotizacionesHistoricas::class);
    $plan = $accion->diagnosticar('cot-20260808-0001')->sole();
    $linea = $orden->detalles()->sole();
    $linea->update(['precio_unitario' => 361, 'subtotal' => 361]);

    expect(fn () => $accion->aplicar('cot-20260808-0001', $plan['fingerprint'], $contexto['admin']->id))
        ->toThrow(ValidationException::class);
    expect($orden->fresh()->cotizacion_id)->toBeNull()
        ->and($orden->detalles()->whereKey($linea->id)->exists())->toBeTrue()
        ->and(ReconciliacionCotizacionOrden::count())->toBe(0);
});

test('una refaccion manual bloquea el caso y nunca se elimina automaticamente', function () {
    $contexto = contextoReconciliacionHistorica();
    cotizacionHistorica($contexto, 'COT-20260704-0001', [[
        'tipo' => 'servicio', 'descripcion' => 'Trabajo', 'cantidad' => 1, 'precio_unitario' => 550,
    ], [
        'tipo' => 'refaccion', 'descripcion' => 'SSD', 'cantidad' => 1, 'precio_unitario' => 869,
    ]]);
    $orden = ordenHistorica($contexto, 'OS-20260704-0001', '2026-07-04T17:18:31Z', [], [[
        'descripcion' => 'SSD', 'cantidad' => 1, 'precio_unitario_cliente' => 869, 'costo_unitario' => 719,
    ]]);
    ordenHistorica($contexto, 'OS-20260706-0001', '2026-07-06T13:35:32Z');

    $plan = app(ReconciliarCotizacionesHistoricas::class)->diagnosticar('cot-20260704-0001')->sole();
    expect($plan['status'])->toBe('blocked')
        ->and($plan['manual_refactions_preserved'])->toBe(1)
        ->and($orden->refacciones()->count())->toBe(1);
});
