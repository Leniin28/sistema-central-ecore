<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE E.2: UX y captura preparada para costos_por_linea. El config server-side
 * sigue en "legacy" durante esta fase; aquí se prueba que la infraestructura de
 * captura (formularios, requests, visibilidad) ya está lista para cuando E.3
 * la active, sin romper la operación legacy actual.
 */
function contextoCostosPorLineaUx(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $partnerLogistico = Partner::create(['nombre' => 'Electrocom UX', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $partnerLogistico->id, 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Cliente UX', 'telefono' => '4491119900', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);
    $categoria = CategoriaServicio::create(['nombre' => 'UX costos por línea']);
    $servicio = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Diagnóstico UX', 'precio_base' => 300, 'activo' => true]);

    return compact('admin', 'partnerLogistico', 'logistico', 'cliente', 'equipo', 'servicio');
}

// --- Visibilidad: admin ve el campo de costo interno, socio no -------------

test('admin ve el campo de costo interno de servicio en el formulario de recepción', function () {
    $contexto = contextoCostosPorLineaUx();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.recepciones.create'))
        ->assertOk()
        ->assertSee('servicios[0][costo_unitario]', false);
});

test('socio_logistico no ve el campo de costo interno de servicio ni de refacción', function () {
    $contexto = contextoCostosPorLineaUx();

    $this->actingAs($contexto['logistico'])
        ->get(route('logistica.recepciones.create'))
        ->assertOk()
        ->assertDontSee('servicios[0][costo_unitario]', false)
        ->assertDontSee('refacciones[0][costo_unitario]', false);
});

// --- Request manipulado de socio no persiste costo --------------------------

test('un socio_logistico que envía costo_unitario manipulado recibe error de validación y no persiste costo', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $contexto = contextoCostosPorLineaUx();

    $this->actingAs($contexto['logistico'])
        ->from(route('logistica.recepciones.create'))
        ->post(route('logistica.recepciones.store'), [
            'cliente_modo' => 'existente',
            'cliente_id' => $contexto['cliente']->id,
            'equipo_modo' => 'existente',
            'equipo_id' => $contexto['equipo']->id,
            'orden' => ['tipo_recepcion' => 'sucursal', 'problema_reportado' => 'No enciende'],
            'servicios' => [[
                'servicio_id' => $contexto['servicio']->id,
                'cantidad' => 1,
                'precio_unitario' => 300,
                'costo_unitario' => 999,
            ]],
            'refacciones' => [],
        ])
        ->assertSessionHasErrors('servicios.0.costo_unitario');

    expect(OrdenServicio::count())->toBe(0);
});

// --- Admin puede enviar NULL/0/positivo; cantidad calcula costo_total ------

test('admin puede capturar NULL, 0 y positivo como costo interno de servicio y refacción', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoCostosPorLineaUx();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'comision_recepcion' => 0,
        'notas' => 'Problema reportado:\nCaptura de costos',
    ], [
        // NULL: costo_unitario ausente => pendiente.
        ['servicio_id' => $contexto['servicio']->id, 'descripcion' => 'Sin costo aún', 'cantidad' => 1, 'precio_unitario' => 100],
        // 0 explícito: conocido, cero.
        ['servicio_id' => $contexto['servicio']->id, 'descripcion' => 'Costo cero confirmado', 'cantidad' => 2, 'precio_unitario' => 50, 'costo_unitario' => 0],
        // positivo, cantidad > 1 debe reflejarse en costo_total.
        ['servicio_id' => $contexto['servicio']->id, 'descripcion' => 'Costo positivo', 'cantidad' => 3, 'precio_unitario' => 80, 'costo_unitario' => 20],
    ], [], $contexto['admin']);

    $detalles = $orden->detalles()->orderBy('id')->get();
    expect($detalles[0]->costo_total)->toBeNull()
        ->and((float) $detalles[1]->costo_total)->toBe(0.0)
        ->and((float) $detalles[2]->costo_total)->toBe(60.0) // 3 * 20
        ->and($orden->costos_incompletos)->toBeTrue(); // el primer servicio sigue sin costo.
});

// --- Recepción sin servicios ni refacciones sigue siendo válida ------------

test('una recepción costos_por_linea sin servicios ni refacciones es válida y queda completa si la comisión es 0', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoCostosPorLineaUx();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'comision_recepcion' => 0,
        'notas' => 'Problema reportado:\nSin líneas',
    ], [], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA)
        ->and($orden->costos_incompletos)->toBeFalse();
});

// --- costo_tecnico oculto/visible según modelo en el show ------------------

test('costo técnico se oculta en el show de una orden costos_por_linea', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoCostosPorLineaUx();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'comision_recepcion' => 50,
        'notas' => 'Problema reportado:\nOculta costo tecnico',
    ], [], [], $contexto['admin']);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertDontSee('Costo técnico')
        ->assertSee('Costos por línea');
});

test('costo técnico permanece visible en el show de una orden legacy', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $contexto = contextoCostosPorLineaUx();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nMuestra costo tecnico',
    ], [], [], $contexto['admin']);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Costo técnico')
        ->assertSee('Legacy');
});

// --- Comisión pendiente marcada como requisito real en costos_por_linea ----

test('comisión de recepción pendiente se marca como requisito financiero real en costos_por_linea', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoCostosPorLineaUx();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nComision pendiente',
    ], [], [], $contexto['admin']);

    expect($orden->comision_recepcion)->toBeNull();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('requisito financiero real');
});
