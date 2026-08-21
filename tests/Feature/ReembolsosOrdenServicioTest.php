<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Finanzas\CorregirComisionOrdenEntregada;
use App\Actions\Finanzas\CorregirCostoInternoOrdenEntregada;
use App\Actions\Finanzas\RegistrarReembolsoOrdenServicio;
use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Models\AjusteFinancieroOrden;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * FASE H.3: reembolsos A/B/G sobre órdenes entregadas. La orden permanece
 * entregada, total_cliente (venta bruta) nunca se toca, y venta_neta es
 * siempre derivada: max(total_cliente - suma de reembolsos, 0).
 */
function adminReembolso(): User
{
    return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
}

function ordenEntregadaParaReembolso(array $overrides = []): OrdenServicio
{
    $admin = adminReembolso();
    $cliente = Cliente::create(['nombre' => 'Cliente reembolso', 'telefono' => '4491113300', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-REEMB-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'listo_para_entregar',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 1500,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
        ...$overrides,
    ]);

    $orden->update(['estado' => 'entregado', 'fecha_entrega' => $overrides['fecha_entrega'] ?? today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    return $orden->fresh();
}

test('admin registra un reembolso parcial legítimo', function () {
    $orden = ordenEntregadaParaReembolso();
    $actor = adminReembolso();

    $ajuste = app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 300, 'Pieza defectuosa parcialmente reembolsada', $actor);

    expect($ajuste->tipo)->toBe(AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE)
        ->and((float) $ajuste->delta)->toBe(300.0)
        ->and($ajuste->motivo)->toBe('Pieza defectuosa parcialmente reembolsada')
        ->and($ajuste->actor_user_id)->toBe($actor->id);

    $orden->refresh();
    expect((float) $orden->total_cliente)->toBe(1500.0)
        ->and($orden->estado)->toBe('entregado')
        ->and($orden->totalReembolsado())->toBe(300.0)
        ->and($orden->ventaNeta())->toBe(1200.0)
        ->and($orden->estadoReembolso())->toBe('reembolso_parcial');

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->tipo)->toBe('egreso')
        ->and($movimiento->categoria)->toBe('reembolso_cliente')
        ->and((float) $movimiento->monto)->toBe(300.0)
        ->and($movimiento->orden_servicio_id)->toBe($orden->id);
});

test('múltiples reembolsos parciales se acumulan correctamente', function () {
    $orden = ordenEntregadaParaReembolso();
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 300, 'primer reembolso', $actor);
    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 200, 'segundo reembolso', $actor);

    $orden->refresh();
    expect($orden->totalReembolsado())->toBe(500.0)
        ->and($orden->ventaNeta())->toBe(1000.0)
        ->and($orden->estadoReembolso())->toBe('reembolso_parcial')
        ->and(AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->count())->toBe(2)
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'reembolso_cliente')->count())->toBe(2);
});

test('admin registra un reembolso total legítimo', function () {
    $orden = ordenEntregadaParaReembolso();
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 1500, 'Devolución total del equipo', $actor);

    $orden->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and((float) $orden->total_cliente)->toBe(1500.0)
        ->and($orden->totalReembolsado())->toBe(1500.0)
        ->and($orden->ventaNeta())->toBe(0.0)
        ->and($orden->estadoReembolso())->toBe('reembolso_total');
});

test('el reembolso no puede exceder la venta bruta acumulada', function () {
    $orden = ordenEntregadaParaReembolso();
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 1000, 'primer reembolso', $actor);

    expect(fn () => app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 600, 'excede', $actor))
        ->toThrow(ValidationException::class);

    $orden->refresh();
    expect($orden->totalReembolsado())->toBe(1000.0);
});

/** J4: el mensaje de exceso debe mostrar total vendido, ya reembolsado y máximo adicional disponible. */
test('J4: el mensaje de reembolso excesivo desglosa venta, reembolsado y máximo disponible', function () {
    $orden = ordenEntregadaParaReembolso();
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 1000, 'primer reembolso', $actor);

    try {
        app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 600, 'excede', $actor);
        $this->fail('Se esperaba ValidationException');
    } catch (ValidationException $exception) {
        $mensaje = $exception->errors()['monto'][0];
        expect($mensaje)->toContain('Total vendido: $1,500.00')
            ->toContain('Total ya reembolsado: $1,000.00')
            ->toContain('Máximo adicional disponible: $500.00');
    }
});

test('un reembolso de monto cero o negativo es bloqueado', function () {
    $orden = ordenEntregadaParaReembolso();
    $actor = adminReembolso();

    expect(fn () => app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 0, 'motivo', $actor))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, -50, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('el motivo del reembolso es obligatorio vía HTTP', function () {
    $orden = ordenEntregadaParaReembolso();
    $admin = adminReembolso();

    $this->actingAs($admin)
        ->post(route('admin.ordenes-servicio.reembolso.store', $orden), ['monto' => 100])
        ->assertSessionHasErrors(['motivo' => 'El motivo es obligatorio para registrar este ajuste.']);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('socio logistico y socio tecnico reciben 403 al intentar reembolsar', function () {
    $orden = ordenEntregadaParaReembolso();
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'email_verified_at' => now()]);

    $this->actingAs($logistico)
        ->post(route('admin.ordenes-servicio.reembolso.store', $orden), ['monto' => 100, 'motivo' => 'x'])
        ->assertForbidden();
    $this->actingAs($tecnico)
        ->post(route('admin.ordenes-servicio.reembolso.store', $orden), ['monto' => 100, 'motivo' => 'x'])
        ->assertForbidden();

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('reembolso funciona igual en legacy y costos_por_linea', function () {
    $ordenCpl = ordenEntregadaParaReembolso(['modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA]);
    $actor = adminReembolso();

    $ajuste = app(RegistrarReembolsoOrdenServicio::class)->ejecutar($ordenCpl, 200, 'motivo costos por linea', $actor);

    expect($ajuste->exists)->toBeTrue();
    $ordenCpl->refresh();
    expect($ordenCpl->totalReembolsado())->toBe(200.0);
});

test('el reembolso no altera un anticipo previamente cobrado', function () {
    $admin = adminReembolso();
    $cliente = Cliente::create(['nombre' => 'Cliente anticipo reembolso', 'telefono' => '4491113301', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 400,
    ], [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio anticipo reembolso',
        'cantidad' => 1,
        'precio_unitario' => 1000,
        'costo_unitario' => 0,
    ]], $admin);

    $orden = OrdenServicio::create([
        'folio' => 'OS-REEMB-ANT-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'cotizacion_id' => $cotizacion->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'listo_para_entregar',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 1000,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $anticipo = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id,
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'tipo' => 'ingreso',
        'categoria' => 'anticipo',
        'monto' => 400,
        'descripcion' => 'Anticipo previo',
        'fecha' => today(),
    ]);

    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 200, 'reembolso parcial', $admin);

    $anticipo->refresh();
    expect((float) $anticipo->monto)->toBe(400.0)
        ->and($anticipo->tipo)->toBe('ingreso')
        ->and($anticipo->ajuste_financiero_orden_id)->toBeNull();
});

test('el resumen financiero refleja el reembolso en su propia fecha sin tocar la venta original', function () {
    $orden = ordenEntregadaParaReembolso(['fecha_entrega' => today()->subDays(15)]);
    $admin = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'motivo', $admin);

    $response = $this->actingAs($admin)->get(route('admin.finanzas.resumen', [
        'periodo' => 'personalizado',
        'fecha_desde' => today()->toDateString(),
        'fecha_hasta' => today()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->viewData('reembolsosTotal'))->toBe(300.0)
        ->and($response->viewData('egresosTotal'))->toBeGreaterThanOrEqual(300.0);
});

test('el registro de un reembolso no modifica total_cliente, finanzas_generadas ni fecha_entrega', function () {
    $orden = ordenEntregadaParaReembolso();
    $admin = adminReembolso();
    $totalOriginal = (float) $orden->total_cliente;
    $fechaEntregaOriginal = $orden->fecha_entrega;

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 100, 'motivo', $admin);

    $orden->refresh();
    expect((float) $orden->total_cliente)->toBe($totalOriginal)
        ->and($orden->finanzas_generadas)->toBeTrue()
        ->and($orden->fecha_entrega->equalTo($fechaEntregaOriginal))->toBeTrue();
});

test('dos reembolsos secuenciales no corrompen el total acumulado (proxy de concurrencia)', function () {
    $orden = ordenEntregadaParaReembolso();
    $admin = adminReembolso();

    for ($i = 0; $i < 3; $i++) {
        app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 100, "reembolso {$i}", $admin);
    }

    $orden->refresh();
    expect($orden->totalReembolsado())->toBe(300.0)
        ->and(AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->count())->toBe(3);
});

test('una orden todavía no entregada bloquea el reembolso', function () {
    $admin = adminReembolso();
    $cliente = Cliente::create(['nombre' => 'Cliente orden abierta', 'telefono' => '4491113302', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-ABIERTA-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'en_proceso',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 500,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    expect(fn () => app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 100, 'motivo', $admin))
        ->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('el detalle de la orden renderiza la sección de ajustes financieros para admin', function () {
    $orden = ordenEntregadaParaReembolso();
    $admin = adminReembolso();
    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 200, 'motivo visible', $admin);

    $this->actingAs($admin)
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Ajustes financieros')
        ->assertSee('Venta neta')
        ->assertSee('motivo visible');
});

// --- utilidad efectiva: utilidad_neta es snapshot, nunca se reescribe ----

test('sin reembolso la utilidad efectiva es igual a la utilidad neta', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850]);

    expect($orden->utilidadEfectiva())->toBe(650.0);
});

test('reembolso parcial reduce la utilidad efectiva sin tocar utilidad_neta', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850]);
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'motivo', $actor);

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe(650.0)
        ->and($orden->utilidadEfectiva())->toBe(350.0);
});

test('reembolso total puede dejar la utilidad efectiva negativa sin acotarla a cero', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850]);
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 1500, 'devolución total', $actor);

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe(650.0)
        ->and($orden->utilidadEfectiva())->toBe(-850.0);
});

test('múltiples reembolsos reducen la utilidad efectiva acumulativamente', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850]);
    $actor = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'primero', $actor);
    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 200, 'segundo', $actor);

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe(650.0)
        ->and($orden->totalReembolsado())->toBe(500.0)
        ->and($orden->utilidadEfectiva())->toBe(150.0);
});

test('una corrección de costo tras un reembolso sigue actualizando utilidad_neta y la utilidad efectiva la refleja', function () {
    $admin = adminReembolso();
    $cliente = Cliente::create(['nombre' => 'Cliente correccion tras reembolso', 'telefono' => '4491113310', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-REEMB-CORR-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 0,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $detalle = $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio corrección tras reembolso',
        'cantidad' => 1,
        'precio_unitario' => 1500,
        'costo_unitario' => 850,
    ]));

    $orden->update(['total_cliente' => 1500]);
    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect((float) $orden->utilidad_neta)->toBe(650.0);

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'reembolso', $admin);

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden->fresh(), 'servicio', $detalle->id, 750, 'costo real era menor', $admin,
    );

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe(750.0)
        ->and($orden->totalReembolsado())->toBe(300.0)
        ->and($orden->utilidadEfectiva())->toBe(450.0);
});

test('una corrección de comisión tras un reembolso sigue actualizando utilidad_neta y la utilidad efectiva la refleja', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850, 'comision_logistica' => 0]);
    $admin = adminReembolso();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'reembolso', $admin);

    app(CorregirComisionOrdenEntregada::class)->ejecutar(
        $orden->fresh(), 100, 'comisión real distinta', $admin,
    );

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe(550.0)
        ->and($orden->totalReembolsado())->toBe(300.0)
        ->and($orden->utilidadEfectiva())->toBe(250.0);
});

test('el detalle de la orden muestra utilidad al entregar, reembolsado y utilidad efectiva cuando hay reembolsos', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850]);
    $admin = adminReembolso();
    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'motivo', $admin);

    $this->actingAs($admin)
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Utilidad al entregar')
        ->assertSee('Utilidad efectiva');
});

test('socio logistico y socio tecnico no ven utilidad efectiva ni ajustes financieros en el detalle', function () {
    $orden = ordenEntregadaParaReembolso(['costo_tecnico' => 850]);
    $admin = adminReembolso();
    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 300, 'motivo', $admin);

    $logistico = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'email_verified_at' => now()]);

    $this->actingAs($logistico)
        ->get(route('logistica.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertDontSee('Utilidad al entregar')
        ->assertDontSee('Utilidad efectiva')
        ->assertDontSee('Ajustes financieros');

    $this->actingAs($tecnico)
        ->get(route('tecnico.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertDontSee('Utilidad al entregar')
        ->assertDontSee('Utilidad efectiva')
        ->assertDontSee('Ajustes financieros');
});

test('una orden cancelada bloquea el reembolso', function () {
    $admin = adminReembolso();
    $cliente = Cliente::create(['nombre' => 'Cliente cancelada', 'telefono' => '4491113303', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-CANCEL-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'cancelado',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 500,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    expect(fn () => app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden, 100, 'motivo', $admin))
        ->toThrow(
            ValidationException::class,
            'Esta orden fue cancelada; no se pueden registrar reembolsos sobre ella.',
        );

    expect(AjusteFinancieroOrden::count())->toBe(0);
});
