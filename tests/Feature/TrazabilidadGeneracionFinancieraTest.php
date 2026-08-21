<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\GeneracionFinancieraOrden;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE H.2: trazabilidad prospectiva de generación financiera. Cada ejecución
 * de GenerarFinanzasOrdenServicio crea un lote (GeneracionFinancieraOrden) y
 * etiqueta sus movimientos con ese id -- nunca a los anticipos preexistentes,
 * que se crearon antes de que el lote existiera.
 */
function adminTrazabilidad(): User
{
    return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
}

function ordenTrazabilidadLegacy(array $overrides = []): OrdenServicio
{
    $admin = adminTrazabilidad();
    $cliente = Cliente::create(['nombre' => 'Cliente trazabilidad', 'telefono' => '4491110000', 'tipo_cliente' => 'mantenimiento']);

    return OrdenServicio::create([
        'folio' => 'OS-TRZ-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
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
        ...$overrides,
    ]);
}

function ordenTrazabilidadCostosPorLinea(array $overrides = []): OrdenServicio
{
    return ordenTrazabilidadLegacy([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        ...$overrides,
    ]);
}

test('la entrega legacy crea un lote de generación y etiqueta sus movimientos', function () {
    $orden = ordenTrazabilidadLegacy();

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect(GeneracionFinancieraOrden::count())->toBe(1);

    $lote = GeneracionFinancieraOrden::sole();
    expect($lote->orden_servicio_id)->toBe($orden->id)
        ->and($lote->tipo)->toBe(GeneracionFinancieraOrden::TIPO_ENTREGA)
        ->and($lote->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY)
        ->and($lote->fecha->toDateString())->toBe(today()->toDateString())
        ->and($lote->estaAnulada())->toBeFalse();

    $movimientos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->get();
    expect($movimientos)->not->toBeEmpty();
    expect($movimientos->every(fn (MovimientoFinanciero $m): bool => $m->generacion_financiera_orden_id === $lote->id))->toBeTrue();
});

test('la entrega costos_por_linea crea un lote de generación y etiqueta sus movimientos', function () {
    $orden = ordenTrazabilidadCostosPorLinea(['comision_recepcion' => 50]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect(GeneracionFinancieraOrden::count())->toBe(1);

    $lote = GeneracionFinancieraOrden::sole();
    expect($lote->orden_servicio_id)->toBe($orden->id)
        ->and($lote->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA);

    $movimientos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->get();
    expect($movimientos)->not->toBeEmpty();
    expect($movimientos->every(fn (MovimientoFinanciero $m): bool => $m->generacion_financiera_orden_id === $lote->id))->toBeTrue();
});

test('los anticipos preexistentes no quedan marcados como creados por la entrega', function () {
    $admin = adminTrazabilidad();
    $cliente = Cliente::create(['nombre' => 'Cliente anticipo trazabilidad', 'telefono' => '4491110001', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 400,
    ], [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio trazabilidad',
        'cantidad' => 1,
        'precio_unitario' => 1000,
        'costo_unitario' => 0,
    ]], $admin);

    $orden = ordenTrazabilidadLegacy([
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'total_cliente' => 1000,
    ]);

    $anticipo = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id,
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'tipo' => 'ingreso',
        'categoria' => 'anticipo',
        'monto' => 400,
        'descripcion' => 'Anticipo '.$cotizacion->folio,
        'fecha' => today(),
    ]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    $anticipo->refresh();
    expect($anticipo->generacion_financiera_orden_id)->toBeNull();

    $lote = GeneracionFinancieraOrden::sole();
    $otrosMovimientos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)
        ->where('id', '!=', $anticipo->id)
        ->get();
    expect($otrosMovimientos)->not->toBeEmpty();
    expect($otrosMovimientos->every(fn (MovimientoFinanciero $m): bool => $m->generacion_financiera_orden_id === $lote->id))->toBeTrue();
});

test('generar() es idempotente y no crea un segundo lote en la segunda llamada', function () {
    $orden = ordenTrazabilidadLegacy();

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect(GeneracionFinancieraOrden::count())->toBe(1);
});

