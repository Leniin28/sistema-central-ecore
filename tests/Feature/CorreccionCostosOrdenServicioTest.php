<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Finanzas\CorregirCostoInternoOrdenEntregada;
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
 * FASE H.4: corrección de costo interno (servicio/refacción) de una orden ya
 * entregada. El movimiento original de GenerarFinanzasOrdenServicio nunca se
 * edita; el delta se compensa con un movimiento nuevo.
 */
function adminCorreccionCosto(): User
{
    return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
}

function ordenEntregadaConLineas(float $costoServicio, float $costoRefaccion): OrdenServicio
{
    $admin = adminCorreccionCosto();
    $cliente = Cliente::create(['nombre' => 'Cliente corrección costo', 'telefono' => '4491114400', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-CCOSTO-'.fake()->unique()->numerify('#####'),
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

    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio a corregir',
        'cantidad' => 1,
        'precio_unitario' => 1000,
        'costo_unitario' => $costoServicio,
    ]));

    $orden->refacciones()->create(app(CalcularTotalesOrdenServicio::class)->refaccion([
        'descripcion' => 'Refacción a corregir',
        'cantidad' => 1,
        'costo_unitario' => $costoRefaccion,
        'precio_unitario_cliente' => 500,
    ]));

    $orden->update(['total_cliente' => 1500]);
    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    return $orden->fresh(['detalles', 'refacciones']);
}

test('corrige a la baja el costo de un servicio y genera un ingreso compensatorio', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();

    $resultado = app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 600, 'Costo capturado de más', $actor,
    );

    $detalle->refresh();
    expect((float) $detalle->costo_unitario)->toBe(600.0)
        ->and((float) $detalle->costo_total)->toBe(600.0);

    $ajuste = AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->sole();
    expect($ajuste->tipo)->toBe(AjusteFinancieroOrden::TIPO_CORRECCION_COSTO_SERVICIO)
        ->and((float) $ajuste->delta)->toBe(-100.0);

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->tipo)->toBe('ingreso')
        ->and((float) $movimiento->monto)->toBe(100.0)
        ->and($movimiento->categoria)->toBe('servicio');
});

test('corrige al alza el costo de un servicio y genera un egreso compensatorio', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 600, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 800, 'Faltó considerar mano de obra extra', $actor,
    );

    $ajuste = AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->sole();
    expect((float) $ajuste->delta)->toBe(200.0);

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->tipo)->toBe('egreso')
        ->and((float) $movimiento->monto)->toBe(200.0);
});

test('corrige a la baja el costo de una refacción', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 100, costoRefaccion: 300);
    $actor = adminCorreccionCosto();
    $refaccion = $orden->refacciones->first();

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'refaccion', $refaccion->id, 250, 'Precio real de proveedor', $actor,
    );

    $refaccion->refresh();
    expect((float) $refaccion->costo_unitario)->toBe(250.0)
        ->and((float) $refaccion->costo_total)->toBe(250.0)
        ->and((float) $refaccion->utilidad_refaccion)->toBe(250.0);

    $ajuste = AjusteFinancieroOrden::where('orden_servicio_id', $orden->id)->sole();
    expect($ajuste->tipo)->toBe(AjusteFinancieroOrden::TIPO_CORRECCION_COSTO_REFACCION);

    $movimiento = MovimientoFinanciero::where('ajuste_financiero_orden_id', $ajuste->id)->sole();
    expect($movimiento->tipo)->toBe('ingreso')
        ->and((float) $movimiento->monto)->toBe(50.0)
        ->and($movimiento->categoria)->toBe('refaccion');
});

test('corrige al alza el costo de una refacción', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 100, costoRefaccion: 250);
    $actor = adminCorreccionCosto();
    $refaccion = $orden->refacciones->first();

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'refaccion', $refaccion->id, 300, 'El proveedor subió el precio', $actor,
    );

    $movimiento = MovimientoFinanciero::where('orden_servicio_id', $orden->id)
        ->where('categoria', 'refaccion')
        ->whereNotNull('ajuste_financiero_orden_id')
        ->sole();
    expect($movimiento->tipo)->toBe('egreso')
        ->and((float) $movimiento->monto)->toBe(50.0);
});

test('cero es un costo corregido válido', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 100, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 0, 'Cortesía, no se cobró el costo interno', $actor,
    );

    $detalle->refresh();
    expect((float) $detalle->costo_unitario)->toBe(0.0)
        ->and((float) $detalle->costo_total)->toBe(0.0);
});

test('corregir con el mismo importe se rechaza como no-op', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();

    expect(fn () => app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 700, 'motivo', $actor,
    ))->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('un costo corregido NULL no es permitido vía HTTP', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $admin = adminCorreccionCosto();
    $detalle = $orden->detalles->first();

    $this->actingAs($admin)
        ->post(route('admin.ordenes-servicio.costo-interno.store', $orden), [
            'linea_tipo' => 'servicio',
            'linea_id' => $detalle->id,
            'motivo' => 'motivo',
        ])
        ->assertSessionHasErrors('costo_nuevo');

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('la corrección de costo mantiene sincronizado el costo interno del CotizacionItem de origen', function () {
    $admin = adminCorreccionCosto();
    $cliente = Cliente::create(['nombre' => 'Cliente sync item', 'telefono' => '4491114401', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 0,
    ], [[
        'tipo' => 'refaccion',
        'descripcion' => 'Refacción trazable',
        'cantidad' => 1,
        'precio_unitario' => 500,
        'costo_unitario' => 300,
    ]], $admin);

    $cotizacionItem = $cotizacion->items()->sole();

    $orden = OrdenServicio::create([
        'folio' => 'OS-CCOSTO-SYNC-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'cotizacion_id' => $cotizacion->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 500,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $refaccion = $orden->refacciones()->create([
        'cotizacion_item_id' => $cotizacionItem->id,
        'descripcion' => 'Refacción trazable',
        'cantidad' => 1,
        'costo_unitario' => 300,
        'precio_unitario_cliente' => 500,
        'costo_total' => 300,
        'precio_total_cliente' => 500,
        'utilidad_refaccion' => 200,
    ]);

    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden->fresh(), 'refaccion', $refaccion->id, 250, 'Sincronizar con cotización', $admin,
    );

    $cotizacionItem->refresh();
    expect((float) $cotizacionItem->costo_unitario)->toBe(250.0)
        ->and((float) $cotizacionItem->costo_total)->toBe(250.0);
});

test('los movimientos originales de la entrega permanecen intactos tras corregir un costo', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();

    $movimientoOriginal = MovimientoFinanciero::where('orden_servicio_id', $orden->id)
        ->where('categoria', 'servicio')
        ->sole();

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 600, 'motivo', $actor,
    );

    $movimientoOriginal->refresh();
    expect((float) $movimientoOriginal->monto)->toBe(700.0)
        ->and($movimientoOriginal->tipo)->toBe('egreso')
        ->and($movimientoOriginal->ajuste_financiero_orden_id)->toBeNull();
});

test('la utilidad neta se actualiza tras corregir un costo interno', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();
    $utilidadAntes = (float) $orden->utilidad_neta;

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 600, 'motivo', $actor,
    );

    $orden->refresh();
    expect((float) $orden->utilidad_neta)->toBe($utilidadAntes + 100)
        ->and((float) $orden->utilidad_estimada)->toBe($utilidadAntes + 100);
});

test('socio logistico y socio tecnico reciben 403 al corregir costos', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $detalle = $orden->detalles->first();
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);

    $this->actingAs($logistico)
        ->post(route('admin.ordenes-servicio.costo-interno.store', $orden), [
            'linea_tipo' => 'servicio', 'linea_id' => $detalle->id, 'costo_nuevo' => 600, 'motivo' => 'x',
        ])
        ->assertForbidden();

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('la orden sigue entregada y la venta no se altera tras corregir un costo', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $actor = adminCorreccionCosto();
    $detalle = $orden->detalles->first();
    $totalClienteAntes = (float) $orden->total_cliente;
    $precioUnitarioAntes = (float) $detalle->precio_unitario;

    app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 600, 'motivo', $actor,
    );

    $orden->refresh();
    $detalle->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and((float) $orden->total_cliente)->toBe($totalClienteAntes)
        ->and((float) $detalle->precio_unitario)->toBe($precioUnitarioAntes);
});

test('el detalle de la orden renderiza el formulario de corrección de costo', function () {
    $orden = ordenEntregadaConLineas(costoServicio: 700, costoRefaccion: 100);
    $admin = adminCorreccionCosto();

    $this->actingAs($admin)
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Corregir costo interno');
});

test('una orden que no está entregada bloquea la corrección de costos', function () {
    $admin = adminCorreccionCosto();
    $cliente = Cliente::create(['nombre' => 'Cliente orden abierta costo', 'telefono' => '4491114402', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-CCOSTO-ABIERTA-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'en_proceso',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 500,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $detalle = $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 500, 'costo_unitario' => 100,
    ]));

    expect(fn () => app(CorregirCostoInternoOrdenEntregada::class)->ejecutar(
        $orden, 'servicio', $detalle->id, 200, 'motivo', $admin,
    ))->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});
