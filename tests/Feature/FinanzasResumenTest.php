<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE F: resumen financiero general, admin-only y de solo lectura. No
 * mezcla las dos perspectivas (flujo por MovimientoFinanciero.fecha vs
 * operación por OrdenServicio.fecha_entrega), no llama "utilidad" al
 * balance de flujo, no duplica anticipos en ventas, y excluye canceladas y
 * consolidadas de la operación.
 */
function contextoFinanzasResumen(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logisticoPartner = Partner::create(['nombre' => 'Electrocom resumen', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $logisticoPartner->id, 'email_verified_at' => now()]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Cliente resumen', 'telefono' => '4491230011', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);

    return compact('admin', 'logisticoPartner', 'logistico', 'tecnico', 'cliente', 'equipo');
}

/** Orden entregada fabricada directamente para probar solo la agregación de F, no el cálculo financiero (ya cubierto en D/E). */
function ordenEntregadaResumen(array $contexto, array $overrides = []): OrdenServicio
{
    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nResumen financiero',
    ], [], [], $contexto['admin']);

    $orden->update(array_merge([
        'estado' => 'entregado',
        'fecha_entrega' => today(),
        'total_cliente' => 1000,
        'utilidad_neta' => 400,
        'costos_incompletos' => false,
        'finanzas_generadas' => true,
    ], $overrides));

    return $orden->fresh();
}

// --- F9: permisos ------------------------------------------------------

test('admin accede al resumen financiero', function () {
    $contexto = contextoFinanzasResumen();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen'))
        ->assertOk()
        ->assertSee('Resumen financiero');
});

test('socio_logistico no accede al resumen financiero', function () {
    $contexto = contextoFinanzasResumen();

    $this->actingAs($contexto['logistico'])
        ->get('/admin/finanzas/resumen')
        ->assertForbidden();
});

test('socio_tecnico no accede al resumen financiero', function () {
    $contexto = contextoFinanzasResumen();

    $this->actingAs($contexto['tecnico'])
        ->get('/admin/finanzas/resumen')
        ->assertForbidden();
});

// --- F2/F8: periodos y filtros ------------------------------------------

test('periodo hoy, semana y mes son aceptados', function (string $periodo) {
    $contexto = contextoFinanzasResumen();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => $periodo]))
        ->assertOk();
})->with(['hoy', 'semana', 'mes']);

test('periodo personalizado requiere fecha_desde y fecha_hasta válidas', function () {
    $contexto = contextoFinanzasResumen();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'personalizado']))
        ->assertSessionHasErrors(['fecha_desde', 'fecha_hasta']);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', [
            'periodo' => 'personalizado',
            'fecha_desde' => today()->addDay()->format('Y-m-d'),
            'fecha_hasta' => today()->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors('fecha_hasta');

    $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', [
            'periodo' => 'personalizado',
            'fecha_desde' => today()->subDays(3)->format('Y-m-d'),
            'fecha_hasta' => today()->format('Y-m-d'),
        ]))
        ->assertOk();
});

// --- F2.A: flujo financiero por MovimientoFinanciero.fecha ---------------

test('ingresos, egresos y balance se calculan por fecha de movimiento, no por fecha de entrega', function () {
    $contexto = contextoFinanzasResumen();
    $orden = ordenEntregadaResumen($contexto);

    MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'ingreso', 'categoria' => 'reparacion', 'monto' => 1000,
        'descripcion' => 'Dentro del periodo', 'fecha' => today(),
    ]);
    MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'egreso', 'categoria' => 'refaccion', 'monto' => 300,
        'descripcion' => 'Dentro del periodo', 'fecha' => today(),
    ]);
    // Fuera del rango "hoy": no debe contarse aunque la orden se entregó hoy.
    MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'ingreso', 'categoria' => 'reparacion', 'monto' => 5000,
        'descripcion' => 'Fuera del periodo', 'fecha' => today()->subDays(10),
    ]);

    $response = $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'hoy']));

    $response->assertOk()
        ->assertSee('Balance')
        ->assertSee('No es utilidad');
    expect($response->viewData('ingresosTotal'))->toBe(1000.0)
        ->and($response->viewData('egresosTotal'))->toBe(300.0)
        ->and($response->viewData('balance'))->toBe(700.0);
});

// --- F7: anticipos no duplican venta -------------------------------------

test('el anticipo no duplica la venta de la orden entregada', function () {
    $contexto = contextoFinanzasResumen();
    $orden = ordenEntregadaResumen($contexto, ['total_cliente' => 1500]);

    MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'ingreso', 'categoria' => 'anticipo', 'monto' => 400,
        'descripcion' => 'Anticipo', 'fecha' => today(),
    ]);
    MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'ingreso', 'categoria' => 'reparacion', 'monto' => 1100,
        'descripcion' => 'Saldo', 'fecha' => today(),
    ]);

    $response = $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'hoy']));

    // Flujo: 400 + 1100 = 1500 (no 1500 + 400).
    expect($response->viewData('ingresosTotal'))->toBe(1500.0)
        // Operación: usa total_cliente de la orden, no total_cliente + anticipo.
        ->and($response->viewData('ventasEntregadas'))->toBe(1500.0);
});

// --- F2.B/F3: operación, utilidad conocida, ticket promedio -------------

test('operación cuenta solo entregadas, excluye no entregadas, canceladas y consolidadas', function () {
    $contexto = contextoFinanzasResumen();
    ordenEntregadaResumen($contexto, ['total_cliente' => 1000, 'utilidad_neta' => 400]);
    ordenEntregadaResumen($contexto, ['total_cliente' => 2000, 'utilidad_neta' => 800]);

    $noEntregada = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id, 'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo', 'notas' => 'Problema reportado:\nNo entregada',
    ], [], [], $contexto['admin']);
    $noEntregada->update(['total_cliente' => 9999]);

    $cancelada = ordenEntregadaResumen($contexto, ['total_cliente' => 9999]);
    $cancelada->update(['estado' => 'cancelado']);

    $canonica = ordenEntregadaResumen($contexto, ['total_cliente' => 500]);
    $consolidada = ordenEntregadaResumen($contexto, ['total_cliente' => 9999]);
    $consolidada->update(['orden_canonica_id' => $canonica->id]);

    $response = $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'hoy']));

    // 2 fabricadas "limpias" + la canónica = 3 entregadas válidas; ninguna 9999.
    expect($response->viewData('ordenesEntregadasCount'))->toBe(3)
        ->and($response->viewData('ventasEntregadas'))->toBe(3500.0)
        ->and($response->viewData('ticketPromedio'))->toBe(round(3500 / 3, 2));
});

test('utilidad conocida excluye órdenes entregadas con costos incompletos', function () {
    $contexto = contextoFinanzasResumen();
    ordenEntregadaResumen($contexto, ['utilidad_neta' => 400, 'costos_incompletos' => false]);
    ordenEntregadaResumen($contexto, ['utilidad_neta' => 999, 'costos_incompletos' => true]);

    $response = $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'hoy']));

    expect($response->viewData('utilidadConocida'))->toBe(400.0)
        ->and($response->viewData('ordenesConCostosIncompletos'))->toBe(1);
    $response->assertSee('Con costos pendientes');
});

test('sin órdenes entregadas el ticket promedio es cero sin división inválida', function () {
    $contexto = contextoFinanzasResumen();

    $response = $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'hoy']));

    $response->assertOk();
    expect($response->viewData('ordenesEntregadasCount'))->toBe(0)
        ->and($response->viewData('ticketPromedio'))->toBe(0.0);
});

// --- F6: legacy y costos_por_linea coexisten sin mezclarse ---------------

test('legacy y costos_por_linea son visibles y sus categorías no se mezclan', function () {
    $contexto = contextoFinanzasResumen();
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $legacy = ordenEntregadaResumen($contexto);
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $cpl = ordenEntregadaResumen($contexto);

    MovimientoFinanciero::create([
        'orden_servicio_id' => $legacy->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'egreso', 'categoria' => 'pago_socio_logistico', 'monto' => 50,
        'descripcion' => 'Legacy', 'fecha' => today(),
    ]);
    MovimientoFinanciero::create([
        'orden_servicio_id' => $cpl->id, 'cliente_id' => $contexto['cliente']->id,
        'tipo' => 'egreso', 'categoria' => 'comision_recepcion', 'monto' => 30,
        'descripcion' => 'Costos por línea', 'fecha' => today(),
    ]);

    $response = $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'hoy']));

    $response->assertOk()
        ->assertSee('Legacy')
        ->assertSee('Costos por línea')
        ->assertSee('Comisión de recepción')
        ->assertSee('Pago socio logístico');

    // Cada categoría se agrega por separado; el egreso total es la suma de
    // ambas sin fusionarlas en una sola fila.
    expect($response->viewData('egresosTotal'))->toBe(80.0);
    $categorias = $response->viewData('desglosePorCategoria')->pluck('categoria')->all();
    expect($categorias)->toContain('pago_socio_logistico')
        ->and($categorias)->toContain('comision_recepcion');
});

// --- F10/no writes: la consulta no escribe nada ---------------------------

test('consultar el resumen no crea ni modifica movimientos ni órdenes', function () {
    $contexto = contextoFinanzasResumen();
    ordenEntregadaResumen($contexto);
    $movimientosAntes = MovimientoFinanciero::count();
    $ordenesAntes = OrdenServicio::count();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.finanzas.resumen', ['periodo' => 'mes']))
        ->assertOk();

    expect(MovimientoFinanciero::count())->toBe($movimientosAntes)
        ->and(OrdenServicio::count())->toBe($ordenesAntes);
});
