<?php

use App\Actions\Ordenes\AplicarCambiosOrdenDesdeOpenClaw;
use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fixtures only — no real orders are ever moved to costos_por_linea (there is
 * still no path to do so). These tests prove that AplicarCambiosOrdenDesdeOpenClaw
 * respects orden.modelo_financiero via RecalcularTotalesOrdenServicio instead of
 * defaulting a persisted order to legacy.
 */
function ordenOpenClawModelo(array $atributos = []): OrdenServicio
{
    $usuario = User::factory()->create(['role' => 'admin']);
    $cliente = Cliente::create([
        'nombre' => 'Cliente OpenClaw modelo',
        'telefono' => '4499876543',
        'tipo_cliente' => 'mantenimiento',
    ]);

    return OrdenServicio::create([
        'folio' => 'OS-OC-'.fake()->unique()->numerify('#####'),
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

function partnerTecnicoFixopOpenClaw(): Partner
{
    return Partner::create(['nombre' => 'Fixop', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
}

function partnerLogisticoOpenClaw(float $comisionFija = 0): Partner
{
    return Partner::create(['nombre' => 'Electrocom OpenClaw', 'tipo_socio' => 'logistico', 'comision_fija' => $comisionFija, 'activo' => true]);
}

function agregarDetalleDirectoOpenClaw(OrdenServicio $orden, float $precioUnitario, ?float $costoUnitario, int $cantidad = 1): void
{
    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio ya cotizado por el panel',
        'cantidad' => $cantidad,
        'precio_unitario' => $precioUnitario,
        'costo_unitario' => $costoUnitario,
    ]));
}

// --- 5: costos_por_linea produce utilidad=650 ignorando comision_logistica y costo_tecnico ---

test('OpenClaw recalcula costos_por_linea usando comision_recepcion y costos por línea, ignorando comision_logistica y costo_tecnico', function () {
    $tecnico = partnerTecnicoFixopOpenClaw();
    $orden = ordenOpenClawModelo([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 700,
        'comision_logistica' => 999,
        'comision_recepcion' => 50,
    ]);
    agregarDetalleDirectoOpenClaw($orden, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleDirectoOpenClaw($orden, precioUnitario: 200, costoUnitario: 100);

    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($orden, [
        'refacciones' => [['descripcion' => 'Refacción real', 'cantidad' => 1, 'costo_unitario' => 500, 'precio_cliente' => 500]],
    ]);

    $orden->refresh();

    expect((float) $orden->total_cliente)->toBe(1500.0)
        ->and((float) $orden->utilidad_estimada)->toBe(650.0)
        ->and($orden->costos_incompletos)->toBeFalse();
});

// --- 6: incompletitud vía OpenClaw usa la misma política que el resto del sistema ---

test('OpenClaw: comision_recepcion NULL deja costos_incompletos=true', function () {
    $orden = ordenOpenClawModelo([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => null,
    ]);
    agregarDetalleDirectoOpenClaw($orden, precioUnitario: 800, costoUnitario: 200);

    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($orden, []);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('OpenClaw: un servicio agregado sin costo (siempre NULL vía API) deja costos_incompletos=true', function () {
    $categoria = CategoriaServicio::firstOrCreate(['nombre' => 'General OpenClaw']);
    $servicio = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Optimización OpenClaw', 'precio_base' => 550, 'activo' => true]);
    $orden = ordenOpenClawModelo([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);

    $resultado = app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($orden, [
        'servicios' => [['servicio_id' => $servicio->id, 'cantidad' => 1]],
    ]);

    expect($resultado['servicios_agregados'])->toHaveCount(1)
        ->and($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('OpenClaw: una refacción agregada sin costo_unitario deja costos_incompletos=true', function () {
    $orden = ordenOpenClawModelo([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 50,
    ]);

    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($orden, [
        'refacciones' => [['descripcion' => 'Refacción sin costo', 'precio_cliente' => 500]],
    ]);

    expect($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('OpenClaw: todos los costos conocidos incluidos ceros explícitos deja costos_incompletos=false', function () {
    $orden = ordenOpenClawModelo([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => 0,
    ]);
    agregarDetalleDirectoOpenClaw($orden, precioUnitario: 800, costoUnitario: 0);

    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($orden, [
        'refacciones' => [['descripcion' => 'Refacción en cero', 'cantidad' => 1, 'costo_unitario' => 0, 'precio_cliente' => 500]],
    ]);

    expect($orden->fresh()->costos_incompletos)->toBeFalse();
});

// --- 7: el camino normal y el camino OpenClaw no divergen, para legacy y costos_por_linea ---

test('camino normal y camino OpenClaw coinciden en legacy', function () {
    $tecnico = partnerTecnicoFixopOpenClaw();
    $atributos = [
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 200,
        'comision_logistica' => 50,
    ];

    $ordenNormal = ordenOpenClawModelo($atributos);
    agregarDetalleDirectoOpenClaw($ordenNormal, precioUnitario: 1500, costoUnitario: 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($ordenNormal);
    $ordenNormal->refresh();

    $ordenOpenClaw = ordenOpenClawModelo($atributos);
    agregarDetalleDirectoOpenClaw($ordenOpenClaw, precioUnitario: 1500, costoUnitario: 100);
    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($ordenOpenClaw, []);
    $ordenOpenClaw->refresh();

    expect((float) $ordenOpenClaw->total_cliente)->toBe((float) $ordenNormal->total_cliente)
        ->and((float) $ordenOpenClaw->utilidad_estimada)->toBe((float) $ordenNormal->utilidad_estimada)
        ->and($ordenOpenClaw->costos_incompletos)->toBe($ordenNormal->costos_incompletos)
        ->and((float) $ordenNormal->utilidad_estimada)->toBe(1150.0);
});

test('camino normal y camino OpenClaw coinciden en costos_por_linea', function () {
    $tecnico = partnerTecnicoFixopOpenClaw();
    $atributos = [
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 700,
        'comision_logistica' => 999,
        'comision_recepcion' => 50,
    ];

    $ordenNormal = ordenOpenClawModelo($atributos);
    agregarDetalleDirectoOpenClaw($ordenNormal, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleDirectoOpenClaw($ordenNormal, precioUnitario: 200, costoUnitario: 100);
    $ordenNormal->refacciones()->create(app(CalcularTotalesOrdenServicio::class)->refaccion([
        'descripcion' => 'Refacción', 'cantidad' => 1, 'costo_unitario' => 500, 'precio_unitario_cliente' => 500,
    ]));
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($ordenNormal);
    $ordenNormal->refresh();

    $ordenOpenClaw = ordenOpenClawModelo($atributos);
    agregarDetalleDirectoOpenClaw($ordenOpenClaw, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleDirectoOpenClaw($ordenOpenClaw, precioUnitario: 200, costoUnitario: 100);
    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($ordenOpenClaw, [
        'refacciones' => [['descripcion' => 'Refacción', 'cantidad' => 1, 'costo_unitario' => 500, 'precio_cliente' => 500]],
    ]);
    $ordenOpenClaw->refresh();

    expect((float) $ordenOpenClaw->total_cliente)->toBe((float) $ordenNormal->total_cliente)
        ->and((float) $ordenOpenClaw->utilidad_estimada)->toBe((float) $ordenNormal->utilidad_estimada)
        ->and($ordenOpenClaw->costos_incompletos)->toBe($ordenNormal->costos_incompletos)
        ->and((float) $ordenNormal->utilidad_estimada)->toBe(650.0)
        ->and($ordenNormal->costos_incompletos)->toBeFalse();
});

// --- 4: legacy modificado por OpenClaw no cambia su fórmula histórica ---

test('OpenClaw sobre una orden legacy conserva comision_logistica y costo_tecnico en la utilidad', function () {
    $tecnico = partnerTecnicoFixopOpenClaw();
    $logistico = partnerLogisticoOpenClaw(comisionFija: 50);
    $orden = ordenOpenClawModelo([
        'partner_tecnico_id' => $tecnico->id,
        'partner_recepcion_id' => $logistico->id,
        'costo_tecnico' => 200,
        'comision_logistica' => 50,
    ]);
    agregarDetalleDirectoOpenClaw($orden, precioUnitario: 1500, costoUnitario: 100);

    app(AplicarCambiosOrdenDesdeOpenClaw::class)->ejecutar($orden, [
        'refacciones' => [['descripcion' => 'USB adicional', 'costo_unitario' => 50, 'precio_cliente' => 80]],
    ]);
    $orden->refresh();

    expect((float) $orden->total_cliente)->toBe(1580.0)
        ->and((float) $orden->utilidad_estimada)->toBe(1180.0)
        ->and($orden->costos_incompletos)->toBeFalse();
});
