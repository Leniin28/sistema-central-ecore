<?php

use App\Models\Cliente;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearOrdenModeloFinanciero(array $atributos = []): OrdenServicio
{
    $usuario = User::factory()->create(['role' => 'admin']);
    $cliente = Cliente::create([
        'nombre' => 'Cliente modelo financiero',
        'telefono' => '4490000000',
        'tipo_cliente' => 'mantenimiento',
    ]);

    return OrdenServicio::create([
        'folio' => 'OS-MODELO-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $usuario->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'total_cliente' => 1500,
        'costo_tecnico' => 200,
        'comision_logistica' => 50,
        'utilidad_estimada' => 1250,
        'utilidad_neta' => 1200,
        'finanzas_generadas' => false,
        ...$atributos,
    ]);
}

test('el esquema conserva las ordenes en modelo legacy sin inventar recepcion', function () {
    $orden = crearOrdenModeloFinanciero()->fresh();

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY)
        ->and($orden->usaModeloFinancieroLegacy())->toBeTrue()
        ->and($orden->usaCostosPorLinea())->toBeFalse()
        ->and($orden->comision_recepcion)->toBeNull()
        ->and($orden->nota_recepcion)->toBeNull();
});

test('reconoce el modelo de costos por linea y conserva cero y positivo como snapshots', function (float $comision) {
    $orden = crearOrdenModeloFinanciero([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => $comision,
        'nota_recepcion' => 'Punto Centro',
    ])->fresh();

    expect($orden->usaModeloFinancieroLegacy())->toBeFalse()
        ->and($orden->usaCostosPorLinea())->toBeTrue()
        ->and($orden->comision_recepcion)->toBe(number_format($comision, 2, '.', ''))
        ->and($orden->nota_recepcion)->toBe('Punto Centro');
})->with([0.0, 70.0]);

test('rechaza modelos financieros fuera del contrato de dominio', function () {
    expect(fn () => crearOrdenModeloFinanciero(['modelo_financiero' => 'otro']))
        ->toThrow(InvalidArgumentException::class, 'Modelo financiero no válido');
});

test('los nuevos campos no alteran finanzas historicas ni generan movimientos', function () {
    $orden = crearOrdenModeloFinanciero();
    $finanzasAntes = $orden->only([
        'total_cliente',
        'costo_tecnico',
        'comision_logistica',
        'utilidad_estimada',
        'utilidad_neta',
        'finanzas_generadas',
    ]);

    $orden->update([
        'comision_recepcion' => 0,
        'nota_recepcion' => 'Sucursal Alameda',
    ]);

    expect($orden->fresh()->only(array_keys($finanzasAntes)))->toBe($finanzasAntes)
        ->and(MovimientoFinanciero::count())->toBe(0);
});
