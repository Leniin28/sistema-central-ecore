<?php

use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\PoliticaCostosOrdenServicio;
use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\Cliente;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Decisión de negocio: comision_recepcion sólo es un requisito real de
 * entrega cuando tipo_recepcion=sucursal. En domicilio (o recepción
 * "directa") NULL no bloquea ni genera un movimiento de $0 artificial --
 * ver PoliticaCostosOrdenServicio::requiereComisionRecepcion().
 */
function contextoComisionModalidad(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Cliente comisión modalidad', 'telefono' => '4491119900', 'tipo_cliente' => 'mantenimiento']);
    $logistico = Partner::create(['nombre' => 'Electrocom modalidad', 'tipo_socio' => 'logistico', 'comision_fija' => 999, 'activo' => true]);

    return compact('admin', 'cliente', 'logistico');
}

function ordenModalidad(array $contexto, array $overrides = []): OrdenServicio
{
    return OrdenServicio::create([
        'folio' => 'OS-MODAL-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $contexto['cliente']->id,
        'creado_por_user_id' => $contexto['admin']->id,
        'tipo_recepcion' => 'sucursal',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 0,
        'comision_recepcion' => null,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
        ...$overrides,
    ]);
}

function agregarServicioModalidad(OrdenServicio $orden, float $precioUnitario, ?float $costoUnitario): void
{
    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio modalidad',
        'cantidad' => 1,
        'precio_unitario' => $precioUnitario,
        'costo_unitario' => $costoUnitario,
    ]));
}

// --- 1/2/3: sucursal -------------------------------------------------------

test('sucursal con comisión NULL queda incompleta y bloquea la entrega', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'sucursal', 'comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);
    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0);
});

test('sucursal con comisión 0 confirmada es válida y no genera movimiento', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'sucursal', 'comision_recepcion' => 0]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'comision_recepcion')->exists())->toBeFalse();
});

test('sucursal con comisión positiva es válida y genera el egreso correcto', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, [
        'tipo_recepcion' => 'sucursal',
        'comision_recepcion' => 75,
        'partner_recepcion_id' => $contexto['logistico']->id,
    ]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    $movimiento = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'comision_recepcion')->sole();
    expect((float) $movimiento->monto)->toBe(75.0)
        ->and($movimiento->partner_id)->toBe($contexto['logistico']->id)
        ->and((float) $orden->fresh()->utilidad_neta)->toBe(125.0); // 300 - 100 - 75
});

// --- 4/5: domicilio ---------------------------------------------------------

test('domicilio con comisión NULL no queda incompleta sólo por eso', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'domicilio', 'comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

test('domicilio con comisión NULL permite la entrega si el resto está completo', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'domicilio', 'comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and((float) $orden->fresh()->utilidad_neta)->toBe(200.0); // 300 - 100, sin comisión
});

// --- 6: domicilio nunca inventa un movimiento de comisión $0 ---------------

test('domicilio sin comisión no genera ningún movimiento de comisión de recepción', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'domicilio', 'comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'comision_recepcion')->exists())->toBeFalse();
});

// --- 7/8: cambio de modalidad antes de entregar -----------------------------

test('cambiar de sucursal a domicilio deja de bloquear por comisión NULL', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'sucursal', 'comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    expect($orden->fresh()->costos_incompletos)->toBeTrue();

    $orden->update(['tipo_recepcion' => 'domicilio']);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden->fresh());

    expect($orden->fresh()->costos_incompletos)->toBeFalse()
        ->and($orden->fresh()->comision_recepcion)->toBeNull();
});

test('cambiar de domicilio a sucursal vuelve a bloquear si la comisión sigue NULL', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'domicilio', 'comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    expect($orden->fresh()->costos_incompletos)->toBeFalse();

    $orden->update(['tipo_recepcion' => 'sucursal']);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden->fresh());

    expect($orden->fresh()->costos_incompletos)->toBeTrue()
        ->and($orden->fresh()->comision_recepcion)->toBeNull();
});

// --- 9: domicilio sin servicios sigue siendo válida -------------------------

test('recepción sin servicios en domicilio sigue siendo válida', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['tipo_recepcion' => 'domicilio', 'comision_recepcion' => null]);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

// --- 10: legacy sin regresiones ---------------------------------------------

test('legacy no requiere comision_recepcion sin importar tipo_recepcion', function () {
    expect(PoliticaCostosOrdenServicio::requiereComisionRecepcion(OrdenServicio::MODELO_FINANCIERO_LEGACY, 'sucursal'))->toBeFalse()
        ->and(PoliticaCostosOrdenServicio::requiereComisionRecepcion(OrdenServicio::MODELO_FINANCIERO_LEGACY, 'domicilio'))->toBeFalse();

    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, [
        'tipo_recepcion' => 'sucursal',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'comision_recepcion' => null,
        'comision_logistica' => 0,
    ]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

// --- 11: socios/OpenClaw no reciben nuevo permiso financiero ----------------

test('socio logistico no puede fijar ni ver comision_recepcion vía el formulario de orden', function () {
    $contexto = contextoComisionModalidad();
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $contexto['logistico']->id, 'email_verified_at' => now()]);

    $this->actingAs($logistico)
        ->post(route('logistica.ordenes-servicio.store'), [
            'cliente_id' => $contexto['cliente']->id,
            'tipo_recepcion' => 'sucursal',
            'comision_recepcion' => 75,
            'servicios' => [['descripcion' => 'Servicio socio', 'cantidad' => 1, 'precio_unitario' => 300]],
            'refacciones' => [],
        ])
        ->assertSessionHasErrors('comision_recepcion');
});

test('OpenClaw no puede enviar comision_recepcion en una recepción', function () {
    config(['services.openclaw.internal_api_token' => 'token-modalidad']);

    $this->withToken('token-modalidad')
        ->postJson('/api/internal/receptions', [
            'cliente' => ['nombre' => 'Cliente OpenClaw modalidad'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
            'tipo_recepcion' => 'sucursal',
            'comision_recepcion' => 75,
            'external_id' => 'openclaw-modalidad-1',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('comision_recepcion');
});

// --- J2: mensaje de entrega bloqueada por comisión de sucursal pendiente ----

test('J2: la pantalla de entrega muestra el mensaje exacto cuando falta la comisión de sucursal', function () {
    $contexto = contextoComisionModalidad();
    $orden = ordenModalidad($contexto, ['comision_recepcion' => null]);
    agregarServicioModalidad($orden, 300, 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    $orden->update(['estado' => 'listo_para_entregar']);

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionHasErrors([
            'estado_nuevo' => 'Confirma la comisión de recepción antes de entregar esta orden. Puedes registrar $0 si no hubo comisión.',
        ]);

    expect($orden->fresh()->estado)->toBe('listo_para_entregar')
        ->and($orden->fresh()->finanzas_generadas)->toBeFalse();
});
