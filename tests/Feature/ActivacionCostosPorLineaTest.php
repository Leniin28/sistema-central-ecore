<?php

use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE E.3: activa costos_por_linea como default server-side para toda orden
 * NUEVA. Prueba los 4 canales (recepción web, creación directa, cotización,
 * OpenClaw) bajo el nuevo default, la coexistencia con legacy, y un ciclo de
 * entrega completo por la ruta HTTP real desde una orden creada por el flujo
 * nuevo (no fabricada a mano con modelo_financiero=costos_por_linea).
 */
function contextoActivacionCostosPorLinea(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logisticoPartner = Partner::create(['nombre' => 'Electrocom activación', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $tecnicoPartner = Partner::create(['nombre' => 'Fixop activación', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente activación', 'telefono' => '4491230099', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);
    $categoria = CategoriaServicio::create(['nombre' => 'Activación']);
    $servicioA = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Servicio activación A', 'precio_base' => 700, 'activo' => true]);
    $servicioB = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Servicio activación B', 'precio_base' => 400, 'activo' => true]);

    return compact('admin', 'logisticoPartner', 'tecnicoPartner', 'cliente', 'equipo', 'servicioA', 'servicioB');
}

// --- E3.1/E3.2: config default y los 4 canales ------------------------------

test('config activa costos_por_linea como default sin override de ENV', function () {
    expect(config('negocio.modelo_financiero_nuevas_ordenes'))->toBe('costos_por_linea');
});

test('recepción web nace costos_por_linea bajo el default activado', function () {
    $contexto = contextoActivacionCostosPorLinea();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nActivación web',
    ], [], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA);
});

test('creación directa nace costos_por_linea bajo el default activado', function () {
    $contexto = contextoActivacionCostosPorLinea();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nActivación directa',
    ], [
        ['servicio_id' => $contexto['servicioA']->id, 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 700, 'costo_unitario' => 200],
    ], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA);
});

// --- E3.4: nueva orden costos_por_linea completa por creación directa ------

test('nueva orden costos_por_linea con partners opcionales calcula correctamente y no requiere costo_tecnico', function () {
    $contexto = contextoActivacionCostosPorLinea();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'partner_recepcion_id' => $contexto['logisticoPartner']->id,
        'partner_tecnico_id' => $contexto['tecnicoPartner']->id,
        'comision_recepcion' => 50,
        'notas' => 'Problema reportado:\nActivación completa',
    ], [
        ['servicio_id' => $contexto['servicioA']->id, 'descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 700, 'costo_unitario' => 200],
        ['servicio_id' => $contexto['servicioB']->id, 'descripcion' => 'Servicio B', 'cantidad' => 1, 'precio_unitario' => 400, 'costo_unitario' => 100],
    ], [
        ['descripcion' => 'Refacción activación', 'cantidad' => 1, 'precio_unitario_cliente' => 400, 'costo_unitario' => 500],
    ], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA)
        ->and((float) $orden->total_cliente)->toBe(1500.0)
        // 1500 - 200 - 100 - 500 - 50 comisión = 650, sin costo_tecnico.
        ->and((float) $orden->utilidad_estimada)->toBe(650.0)
        ->and($orden->costos_incompletos)->toBeFalse()
        ->and($orden->costo_tecnico)->toBeNull();
});

// --- E3.5: OpenClaw crea costos_por_linea sin confirmar comisión/costos ----

test('OpenClaw crea orden costos_por_linea con comisión y costos NULL, queda incompleta y bloqueada para entregar', function () {
    config(['services.openclaw.internal_api_token' => 'token-activacion']);

    $response = $this->withToken('token-activacion')
        ->postJson('/api/internal/receptions', [
            'cliente' => ['nombre' => 'Cliente OpenClaw activación'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
            'recepcion' => ['falla_reportada' => 'No enciende'],
            'external_id' => 'openclaw-activacion-1',
        ]);
    $response->assertCreated();

    $orden = OrdenServicio::firstWhere('external_id', 'openclaw-activacion-1');
    expect($orden)->not->toBeNull()
        ->and($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA)
        ->and($orden->comision_recepcion)->toBeNull()
        ->and($orden->costos_incompletos)->toBeTrue();

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $orden->update(['estado' => 'listo_para_entregar']);

    $this->actingAs($admin)
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertSessionHasErrors('estado_nuevo');

    expect($orden->fresh()->estado)->not->toBe('entregado')
        ->and($orden->fresh()->finanzas_generadas)->toBeFalse();
});

// --- E3.6: cotización nueva sigue el default; costos conocidos se conservan -

test('cotización nueva convertida en orden nace costos_por_linea y conserva costos conocidos de sus items', function () {
    $contexto = contextoActivacionCostosPorLinea();

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 0,
    ], [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio con costo conocido', 'cantidad' => 1, 'precio_unitario' => 500, 'costo_unitario' => 150],
        ['tipo' => 'servicio', 'descripcion' => 'Servicio sin costo aún', 'cantidad' => 1, 'precio_unitario' => 300],
    ], $contexto['admin']);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $contexto['admin']);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();
    $orden->loadMissing('detalles');

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA)
        // La comisión de recepción no forma parte de la cotización: queda
        // NULL/pendiente hasta que el admin la confirme.
        ->and($orden->comision_recepcion)->toBeNull();

    $conCosto = $orden->detalles->firstWhere('descripcion', 'Servicio con costo conocido');
    $sinCosto = $orden->detalles->firstWhere('descripcion', 'Servicio sin costo aún');
    expect((float) $conCosto->costo_total)->toBe(150.0)
        ->and($sinCosto->costo_total)->toBeNull();
});

// --- E3.7: ciclo real crear -> completar -> entregar por HTTP --------------

test('E2E activación: orden creada por el flujo nuevo se completa y entrega por ruta HTTP real', function () {
    $contexto = contextoActivacionCostosPorLinea();

    // 1) Crear por el canal real de cotización -> orden nueva (con anticipo
    // reconciliado como en el flujo real), sin fabricar modelo_financiero a
    // mano.
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 400,
    ], [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 700, 'costo_unitario' => 200],
        ['tipo' => 'servicio', 'descripcion' => 'Servicio B', 'cantidad' => 1, 'precio_unitario' => 400, 'costo_unitario' => 100],
        ['tipo' => 'refaccion', 'descripcion' => 'Refacción', 'cantidad' => 1, 'precio_unitario' => 400, 'costo_unitario' => 500],
    ], $contexto['admin']);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $contexto['admin']);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA);

    // 2) Completar comisión de recepción (admin) y avanzar hasta listo para
    // entregar.
    $orden->update([
        'partner_recepcion_id' => $contexto['logisticoPartner']->id,
        'comision_recepcion' => 50,
        'estado' => 'listo_para_entregar',
    ]);

    // 3) Entregar por la ruta HTTP real.
    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionDoesntHaveErrors();

    $orden->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeTrue()
        ->and((float) $orden->utilidad_neta)->toBe(650.0);

    $ingresos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'ingreso')->get();
    expect((float) $ingresos->where('categoria', 'anticipo')->sum('monto'))->toBe(400.0)
        ->and((float) $ingresos->where('categoria', 'reparacion')->sum('monto'))->toBe(1100.0);

    $egresos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'egreso')->get();
    expect($egresos->where('categoria', 'servicio')->pluck('monto')->map(fn ($m): float => (float) $m)->sort()->values()->all())
        ->toBe([100.0, 200.0])
        ->and((float) $egresos->where('categoria', 'refaccion')->sum('monto'))->toBe(500.0)
        ->and((float) $egresos->where('categoria', 'comision_recepcion')->sum('monto'))->toBe(50.0)
        ->and($egresos->where('categoria', 'pago_socio_tecnico'))->toHaveCount(0)
        ->and($egresos->where('categoria', 'pago_socio_logistico'))->toHaveCount(0);

    expect(HistorialEstado::where('orden_servicio_id', $orden->id)->where('estado_nuevo', 'entregado')->exists())->toBeTrue();
});

// --- E3.8: coexistencia — un fixture legacy explícito sigue funcionando ----

test('coexistencia: una orden legacy explícita sigue calculando, editando y entregando con lógica histórica aunque el default sea costos_por_linea', function () {
    $contexto = contextoActivacionCostosPorLinea();

    // Fixture legacy explícito: representa una orden ya existente (como las
    // 25 reales) creada antes de esta activación, no una orden nueva.
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $ordenLegacy = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'partner_recepcion_id' => $contexto['logisticoPartner']->id,
        'partner_tecnico_id' => $contexto['tecnicoPartner']->id,
        'costo_tecnico' => 200,
        'notas' => 'Problema reportado:\nFixture legacy coexistencia',
    ], [
        ['servicio_id' => $contexto['servicioA']->id, 'descripcion' => 'Servicio legacy', 'cantidad' => 1, 'precio_unitario' => 1500, 'costo_unitario' => 100],
    ], [], $contexto['admin']);
    // Reactiva el default para el resto del ciclo de vida de esta orden.
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);

    expect($ordenLegacy->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);

    $ordenLegacy->update(['estado' => 'listo_para_entregar']);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $ordenLegacy), ['estado_nuevo' => 'entregado'])
        ->assertSessionDoesntHaveErrors();

    $ordenLegacy->refresh();
    expect($ordenLegacy->estado)->toBe('entregado')
        ->and($ordenLegacy->finanzas_generadas)->toBeTrue()
        // 1500 - 100 costo servicio - 200 costo técnico - 50 comisión logística = 1150.
        ->and((float) $ordenLegacy->utilidad_neta)->toBe(1150.0);

    $egresos = MovimientoFinanciero::where('orden_servicio_id', $ordenLegacy->id)->where('tipo', 'egreso')->get();
    expect((float) $egresos->where('categoria', 'pago_socio_tecnico')->sum('monto'))->toBe(200.0)
        ->and((float) $egresos->where('categoria', 'pago_socio_logistico')->sum('monto'))->toBe(50.0)
        ->and($egresos->where('categoria', 'comision_recepcion'))->toHaveCount(0);
});
