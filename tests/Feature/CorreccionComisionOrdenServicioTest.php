<?php

use App\Actions\Finanzas\CorregirComisionOrdenEntregada;
use App\Models\AjusteFinancieroOrden;
use App\Models\Cliente;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * FASE H.5: corrección de comisión (recepción en costos_por_linea, logística
 * en legacy) de una orden ya entregada. El admin fija el importe real
 * explícitamente -- nunca se relee Partner.comision_fija.
 */
function adminCorreccionComision(): User
{
    return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
}

function partnerRecepcionComision(float $comisionFija = 999): Partner
{
    return Partner::create(['nombre' => 'Electrocom comisión', 'tipo_socio' => 'logistico', 'comision_fija' => $comisionFija, 'activo' => true]);
}

function ordenEntregadaLegacyConComision(float $comisionInicial, ?Partner $partner = null): OrdenServicio
{
    $admin = adminCorreccionComision();
    $partner ??= partnerRecepcionComision($comisionInicial);
    $cliente = Cliente::create(['nombre' => 'Cliente comisión legacy', 'telefono' => '4491115500', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-COMIS-LEG-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'partner_recepcion_id' => $partner->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'sucursal',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 1000,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    return $orden->fresh();
}

function ordenEntregadaCplConComision(float $comisionInicial): OrdenServicio
{
    $admin = adminCorreccionComision();
    $partner = partnerRecepcionComision(999);
    $cliente = Cliente::create(['nombre' => 'Cliente comisión cpl', 'telefono' => '4491115501', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-COMIS-CPL-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'partner_recepcion_id' => $partner->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'sucursal',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 1000,
        'comision_recepcion' => $comisionInicial,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    return $orden->fresh();
}

test('corrige al alza la comisión logística (legacy) con un egreso compensatorio', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 70, 'Ajuste real de comisión', $actor);

    $orden->refresh();
    expect((float) $orden->comision_logistica)->toBe(70.0);

    $ajuste = AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->sole();
    expect($ajuste->tipo)->toBe(AjusteFinancieroOrden::TIPO_CORRECCION_COMISION_LOGISTICA)
        ->and((float) $ajuste->valor_anterior)->toBe(50.0)
        ->and((float) $ajuste->valor_nuevo)->toBe(70.0)
        ->and((float) $ajuste->delta)->toBe(20.0);

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->tipo)->toBe('egreso')
        ->and((float) $movimiento->monto)->toBe(20.0)
        ->and($movimiento->categoria)->toBe('pago_socio_logistico');
});

test('corrige a la baja la comisión logística (legacy) con un ingreso compensatorio', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 30, 'Ajuste real de comisión', $actor);

    $ajuste = AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->sole();
    expect((float) $ajuste->delta)->toBe(-20.0);

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->tipo)->toBe('ingreso')
        ->and((float) $movimiento->monto)->toBe(20.0);
});

test('corregir con el mismo importe de comisión se rechaza como no-op', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();

    expect(fn () => app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 50, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('corrige la comisión de recepción en costos_por_linea, no la logística', function () {
    $orden = ordenEntregadaCplConComision(50);
    $actor = adminCorreccionComision();

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 70, 'motivo', $actor);

    $orden->refresh();
    expect((float) $orden->comision_recepcion)->toBe(70.0)
        ->and((float) $orden->comision_logistica)->toBe(0.0);

    $ajuste = AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->sole();
    expect($ajuste->tipo)->toBe(AjusteFinancieroOrden::TIPO_CORRECCION_COMISION_RECEPCION);

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->categoria)->toBe('comision_recepcion');
});

test('el movimiento original de comisión permanece intacto tras la corrección', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();
    $original = MovimientoFinanciero::where('orden_servicio_id', $orden->id)
        ->where('categoria', 'pago_socio_logistico')
        ->sole();

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 70, 'motivo', $actor);

    $original->refresh();
    expect((float) $original->monto)->toBe(50.0)
        ->and($original->ajuste_financiero_orden_id)->toBeNull();
});

test('la utilidad neta se actualiza tras corregir la comisión', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();
    $utilidadAntes = (float) $orden->utilidad_neta;

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 70, 'motivo', $actor);

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe($utilidadAntes - 20)
        ->and((float) $orden->utilidad_estimada)->toBe($utilidadAntes - 20);
});

test('la corrección no relee Partner.comision_fija: el admin establece el valor explícito', function () {
    $partner = partnerRecepcionComision(50);
    $orden = ordenEntregadaLegacyConComision(50, $partner);
    $actor = adminCorreccionComision();

    // El partner cambia su comisión fija después de la entrega; la corrección
    // NO debe usar ese valor -- sólo el importe que el admin escribe.
    $partner->update(['comision_fija' => 999]);

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden->fresh(), 65, 'motivo', $actor);

    $orden->refresh();
    expect((float) $orden->comision_logistica)->toBe(65.0);
});

test('socio logistico y socio tecnico reciben 403 al corregir comisión', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);

    $this->actingAs($logistico)
        ->post(route('admin.ordenes-servicio.comision.store', $orden), ['comision_nueva' => 70, 'motivo' => 'x'])
        ->assertForbidden();

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('la orden sigue entregada tras corregir la comisión', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();

    app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, 70, 'motivo', $actor);

    $orden->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeTrue();
});

test('el detalle de la orden renderiza el formulario de corrección de comisión', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $admin = adminCorreccionComision();

    $this->actingAs($admin)
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Corregir comisión');
});

test('una comisión negativa es rechazada', function () {
    $orden = ordenEntregadaLegacyConComision(50);
    $actor = adminCorreccionComision();

    expect(fn () => app(CorregirComisionOrdenEntregada::class)->ejecutar($orden, -10, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});
