<?php

use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Actions\Ordenes\ValidarCostoTecnicoParaEntrega;
use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\Cliente;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ordenCalculoFinanciero(array $atributos = []): OrdenServicio
{
    $usuario = User::factory()->create(['role' => 'admin']);
    $cliente = Cliente::create([
        'nombre' => 'Cliente cálculo financiero',
        'telefono' => '4491234567',
        'tipo_cliente' => 'mantenimiento',
    ]);

    return OrdenServicio::create([
        'folio' => 'OS-CALC-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $usuario->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'total_cliente' => 0,
        'costo_tecnico' => null,
        'comision_logistica' => 0,
        'comision_recepcion' => null,
        'nota_recepcion' => null,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
        ...$atributos,
    ]);
}

function partnerTecnicoFixop(): Partner
{
    return Partner::create(['nombre' => 'Fixop', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
}

function partnerLogisticoCalculo(float $comisionFija = 0): Partner
{
    return Partner::create(['nombre' => 'Electrocom cálculo', 'tipo_socio' => 'logistico', 'comision_fija' => $comisionFija, 'activo' => true]);
}

function agregarDetalleCalculo(OrdenServicio $orden, float $precioUnitario, ?float $costoUnitario, int $cantidad = 1): void
{
    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio cálculo financiero',
        'cantidad' => $cantidad,
        'precio_unitario' => $precioUnitario,
        'costo_unitario' => $costoUnitario,
    ]));
}

function agregarRefaccionCalculo(OrdenServicio $orden, float $precioUnitarioCliente, ?float $costoUnitario, int $cantidad = 1): void
{
    $orden->refacciones()->create(app(CalcularTotalesOrdenServicio::class)->refaccion([
        'descripcion' => 'Refacción cálculo financiero',
        'cantidad' => $cantidad,
        'costo_unitario' => $costoUnitario,
        'precio_unitario_cliente' => $precioUnitarioCliente,
    ]));
}

// --- A: Legacy conserva la fórmula histórica exacta -------------------------

test('legacy conserva el cálculo histórico exacto', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero([
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 200,
        'comision_logistica' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 1500, costoUnitario: 100);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    $orden->refresh();

    expect((float) $orden->total_cliente)->toBe(1500.0)
        ->and((float) $orden->utilidad_estimada)->toBe(1150.0)
        ->and($orden->costos_incompletos)->toBeFalse();
});

test('legacy conserva la completitud basada en costo_tecnico', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero(['partner_tecnico_id' => $tecnico->id, 'costo_tecnico' => null]);
    agregarDetalleCalculo($orden, precioUnitario: 1500, costoUnitario: 100);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();
});

// --- B: Nuevo modelo completo -------------------------------------------

test('costos_por_linea ignora costo_tecnico y comision_logistica: utilidad=650', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => null,
        'comision_logistica' => 999,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleCalculo($orden, precioUnitario: 200, costoUnitario: 100);
    agregarRefaccionCalculo($orden, precioUnitarioCliente: 500, costoUnitario: 500);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    $orden->refresh();

    expect((float) $orden->total_cliente)->toBe(1500.0)
        ->and((float) $orden->utilidad_estimada)->toBe(650.0)
        ->and($orden->costos_incompletos)->toBeFalse();
});

// --- C: Comisión pendiente -----------------------------------------------

test('costos_por_linea con comisión de recepción NULL queda incompleto', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'tipo_recepcion' => 'sucursal',
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => null,
        'comision_logistica' => 999,
        'comision_recepcion' => null,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleCalculo($orden, precioUnitario: 200, costoUnitario: 100);
    agregarRefaccionCalculo($orden, precioUnitarioCliente: 500, costoUnitario: 500);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();
});

// --- D: Servicio pendiente -------------------------------------------------

test('costos_por_linea con un servicio sin costo queda incompleto', function () {
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: null);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('costos_por_linea con un servicio en cero queda completo', function () {
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 0);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

// --- E: Refacción pendiente --------------------------------------------

test('costos_por_linea con una refacción sin costo queda incompleto', function () {
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);
    agregarRefaccionCalculo($orden, precioUnitarioCliente: 500, costoUnitario: null);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('costos_por_linea con una refacción en cero queda completo', function () {
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);
    agregarRefaccionCalculo($orden, precioUnitarioCliente: 500, costoUnitario: 0);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

// --- F: Ceros explícitos -------------------------------------------------

test('costos_por_linea con ceros explícitos y técnico asignado queda completo', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => null,
        'comision_recepcion' => 0,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 0);
    agregarRefaccionCalculo($orden, precioUnitarioCliente: 500, costoUnitario: 0);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

// --- G: Técnico sin líneas -------------------------------------------------

test('costos_por_linea con técnico asignado y sin líneas no requiere costo_tecnico', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => null,
        'comision_recepcion' => 0,
    ]);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    $orden->refresh();

    expect($orden->detalles()->count())->toBe(0)
        ->and($orden->refacciones()->count())->toBe(0)
        ->and($orden->costos_incompletos)->toBeFalse()
        ->and((float) $orden->utilidad_estimada)->toBe(0.0);
});

// --- CalcularTotales y RecalcularTotales no divergen ------------------------

test('CalcularTotalesOrdenServicio y RecalcularTotalesOrdenServicio coinciden en el ejemplo nuevo completo', function () {
    $tecnico = partnerTecnicoFixop();

    $detalles = [
        ['descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 800, 'costo_unitario' => 200],
        ['descripcion' => 'Servicio B', 'cantidad' => 1, 'precio_unitario' => 200, 'costo_unitario' => 100],
    ];
    $refacciones = [
        ['descripcion' => 'Refacción', 'cantidad' => 1, 'costo_unitario' => 500, 'precio_unitario_cliente' => 500],
    ];

    $resumen = app(CalcularTotalesOrdenServicio::class)->resumen(
        $detalles,
        $refacciones,
        costoTecnico: null,
        comision: 999,
        tienePartnerTecnico: true,
        modeloFinanciero: OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        comisionRecepcion: 50,
    );

    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => null,
        'comision_logistica' => 999,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleCalculo($orden, precioUnitario: 200, costoUnitario: 100);
    agregarRefaccionCalculo($orden, precioUnitarioCliente: 500, costoUnitario: 500);

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    $orden->refresh();

    expect((float) $orden->total_cliente)->toBe($resumen['total_cliente'])
        ->and((float) $orden->utilidad_estimada)->toBe($resumen['utilidad_estimada'])
        ->and($orden->costos_incompletos)->toBe($resumen['costos_incompletos'])
        ->and($resumen['utilidad_estimada'])->toBe(650.0)
        ->and($resumen['costos_incompletos'])->toBeFalse();
});

// --- GenerarFinanzasOrdenServicio: costos_por_linea genera finanzas --------
// Cobertura detallada en GenerarFinanzasCostosPorLineaTest.php; aquí solo se
// confirma que el fail-closed temporal de FASE C ya no aplica.

test('GenerarFinanzasOrdenServicio ya genera finanzas para costos_por_linea', function () {
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 200);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'servicio')->exists())->toBeTrue();
});

test('legacy sigue generando finanzas exactamente como antes', function () {
    $tecnico = partnerTecnicoFixop();
    $logistico = partnerLogisticoCalculo(comisionFija: 50);
    $orden = ordenCalculoFinanciero([
        'partner_tecnico_id' => $tecnico->id,
        'partner_recepcion_id' => $logistico->id,
        'costo_tecnico' => 200,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 1500, costoUnitario: 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    $orden->refresh();

    app(GenerarFinanzasOrdenServicio::class)->generar($orden);
    $orden->refresh();

    expect($orden->finanzas_generadas)->toBeTrue()
        ->and((float) $orden->comision_logistica)->toBe(50.0)
        ->and((float) $orden->utilidad_estimada)->toBe(1150.0)
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'pago_socio_logistico')->exists())->toBeTrue()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'pago_socio_tecnico')->exists())->toBeTrue()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'servicio')->exists())->toBeTrue();
});

// --- ValidarCostoTecnicoParaEntrega: el modelo nuevo no bloquea por costo_tecnico

test('costos_por_linea no bloquea entrega por partner_tecnico_id con costo_tecnico NULL', function () {
    $tecnico = partnerTecnicoFixop();
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => null,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleCalculo($orden, precioUnitario: 800, costoUnitario: 200);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(ValidarCostoTecnicoParaEntrega::class)->ejecutar($orden->fresh()))
        ->not->toThrow(CostoTecnicoPendienteException::class);
});

test('costos_por_linea bloquea entrega cuando quedan costos incompletos', function () {
    $orden = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'tipo_recepcion' => 'sucursal',
        'comision_recepcion' => null,
    ]);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(ValidarCostoTecnicoParaEntrega::class)->ejecutar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);
});

// --- Cero explícito no se confunde con NULL ---------------------------------

test('en costos_por_linea comisión cero y comisión NULL producen resultados distintos', function () {
    $ordenCero = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'tipo_recepcion' => 'sucursal',
        'comision_recepcion' => 0,
    ]);
    agregarDetalleCalculo($ordenCero, precioUnitario: 100, costoUnitario: 0);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($ordenCero);

    $ordenNull = ordenCalculoFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'tipo_recepcion' => 'sucursal',
        'comision_recepcion' => null,
    ]);
    agregarDetalleCalculo($ordenNull, precioUnitario: 100, costoUnitario: 0);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($ordenNull);

    expect($ordenCero->fresh()->costos_incompletos)->toBeFalse()
        ->and($ordenNull->fresh()->costos_incompletos)->toBeTrue()
        ->and((float) $ordenCero->fresh()->utilidad_estimada)->toBe(100.0)
        ->and((float) $ordenNull->fresh()->utilidad_estimada)->toBe(100.0);
});
