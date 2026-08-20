<?php

use App\Actions\Cotizaciones\RegistrarAnticipoHistorico;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{admin: User, socio: User, cotizacion: Cotizacion, orden: OrdenServicio} */
function casoAnticipoHistorico(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $socio = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);
    $cliente = Cliente::create([
        'nombre' => 'Cliente anticipo histórico',
        'telefono' => '4492030000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Prueba histórica',
    ]);
    $cotizacion = Cotizacion::create([
        'folio' => RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'creado_por_user_id' => $admin->id,
        'fecha' => '2026-08-09',
        'estado' => 'aceptada',
        'tipo_recepcion' => 'en_negocio',
        'subtotal' => 4220,
        'descuento' => 0,
        'anticipo' => 2030,
        'total' => 4220,
        'saldo' => 2190,
    ]);
    $orden = OrdenServicio::create([
        'folio' => RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'total_cliente' => 4220,
        'comision_logistica' => 0,
        'utilidad_estimada' => 0,
        'costos_incompletos' => false,
        'utilidad_neta' => 0,
        'fecha_recepcion' => '2026-08-09',
        'finanzas_generadas' => false,
    ]);

    return compact('admin', 'socio', 'cotizacion', 'orden');
}

/** @return array<string, mixed> */
function parametrosAnticipoHistorico(array $cambios = []): array
{
    return array_replace([
        'cotizacion' => RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        '--orden' => RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        '--monto' => '2030',
        '--fecha' => RegistrarAnticipoHistorico::FECHA_AUTORIZADA,
    ], $cambios);
}

test('dry-run valida el caso y no escribe', function () {
    casoAnticipoHistorico();

    $this->artisan('cotizaciones:registrar-anticipo-historico', parametrosAnticipoHistorico())
        ->expectsOutputToContain('DRY-RUN: no se realizaron escrituras.')
        ->expectsOutputToContain('2190.00')
        ->assertSuccessful();

    expect(MovimientoFinanciero::count())->toBe(0);
});

test('apply crea exactamente el anticipo autorizado con fecha y vínculos correctos', function () {
    $caso = casoAnticipoHistorico();

    $this->artisan('cotizaciones:registrar-anticipo-historico', parametrosAnticipoHistorico([
        '--apply' => true,
        '--actor' => (string) $caso['admin']->id,
    ]))->assertSuccessful();

    $movimiento = MovimientoFinanciero::sole();
    expect($movimiento->tipo)->toBe('ingreso')
        ->and($movimiento->categoria)->toBe('anticipo')
        ->and((float) $movimiento->monto)->toBe(2030.0)
        ->and($movimiento->cotizacion_id)->toBe($caso['cotizacion']->id)
        ->and($movimiento->orden_servicio_id)->toBe($caso['orden']->id)
        ->and($movimiento->fecha->format('Y-m-d'))->toBe('2026-08-11')
        ->and($movimiento->descripcion)->toContain('Anticipo histórico');
});

test('apply exige un actor administrador real', function () {
    $caso = casoAnticipoHistorico();

    $this->artisan('cotizaciones:registrar-anticipo-historico', parametrosAnticipoHistorico([
        '--apply' => true,
        '--actor' => (string) $caso['socio']->id,
    ]))->assertFailed();

    expect(MovimientoFinanciero::count())->toBe(0);
});

test('rechaza monto fecha cotizacion u orden distintos al caso autorizado', function (array $cambio) {
    casoAnticipoHistorico();

    $this->artisan('cotizaciones:registrar-anticipo-historico', parametrosAnticipoHistorico($cambio))
        ->assertFailed();

    expect(MovimientoFinanciero::count())->toBe(0);
})->with([
    'monto' => [['--monto' => '2029.99']],
    'fecha' => [['--fecha' => '2026-08-12']],
    'cotización' => [['cotizacion' => 'COT-20260809-0002']],
    'orden' => [['--orden' => 'OS-20260809-0002']],
]);

test('finanzas generadas bloquean el registro', function () {
    $caso = casoAnticipoHistorico();
    $caso['orden']->update(['finanzas_generadas' => true]);

    expect(fn () => app(RegistrarAnticipoHistorico::class)->aplicar(
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2030,
        '2026-08-11',
        $caso['admin']->id,
    ))->toThrow(ValidationException::class, 'La orden ya tiene sus finanzas generadas.');

    expect(MovimientoFinanciero::count())->toBe(0);
});

test('un movimiento incompatible bloquea el registro', function () {
    $caso = casoAnticipoHistorico();
    $caso['orden']->movimientosFinancieros()->create([
        'cliente_id' => $caso['cotizacion']->cliente_id,
        'tipo' => 'ingreso',
        'categoria' => 'reparacion',
        'monto' => 10,
        'descripcion' => 'Movimiento incompatible',
        'fecha' => '2026-08-10',
    ]);

    expect(fn () => app(RegistrarAnticipoHistorico::class)->aplicar(
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2030,
        '2026-08-11',
        $caso['admin']->id,
    ))->toThrow(ValidationException::class, 'La orden tiene movimientos financieros incompatibles.');

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('un anticipo preexistente no generado por el one-shot bloquea la duplicacion', function () {
    $caso = casoAnticipoHistorico();
    $caso['orden']->movimientosFinancieros()->create([
        'cotizacion_id' => $caso['cotizacion']->id,
        'cliente_id' => $caso['cotizacion']->cliente_id,
        'tipo' => 'ingreso',
        'categoria' => 'anticipo',
        'monto' => 2030,
        'descripcion' => 'Anticipo preexistente',
        'fecha' => '2026-08-11',
    ]);

    expect(fn () => app(RegistrarAnticipoHistorico::class)->aplicar(
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2030,
        '2026-08-11',
        $caso['admin']->id,
    ))->toThrow(ValidationException::class, 'Ya existe un movimiento de anticipo distinto');

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('un anticipo existente distinto bloquea y el exacto hace idempotente el segundo apply', function () {
    $caso = casoAnticipoHistorico();
    $accion = app(RegistrarAnticipoHistorico::class);
    $argumentos = [
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2030,
        '2026-08-11',
        $caso['admin']->id,
    ];

    $primero = $accion->aplicar(...$argumentos);
    $segundo = $accion->aplicar(...$argumentos);

    expect($primero['creado'])->toBeTrue()
        ->and($segundo['creado'])->toBeFalse()
        ->and(MovimientoFinanciero::count())->toBe(1);

    expect(fn () => $accion->aplicar(
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2029,
        '2026-08-11',
        $caso['admin']->id,
    ))->toThrow(ValidationException::class);
    expect(fn () => $accion->aplicar(
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2030,
        '2026-08-12',
        $caso['admin']->id,
    ))->toThrow(ValidationException::class);
    expect(MovimientoFinanciero::count())->toBe(1);

    MovimientoFinanciero::query()->update(['fecha' => '2026-08-12']);
    expect(fn () => $accion->aplicar(...$argumentos))
        ->toThrow(ValidationException::class, 'Ya existe un movimiento de anticipo distinto');
    expect(MovimientoFinanciero::count())->toBe(1);
});

test('despues del registro conserva anticipo total y saldo proyectado sin generar la entrega', function () {
    $caso = casoAnticipoHistorico();

    $resultado = app(RegistrarAnticipoHistorico::class)->aplicar(
        RegistrarAnticipoHistorico::COTIZACION_AUTORIZADA,
        RegistrarAnticipoHistorico::ORDEN_AUTORIZADA,
        2030,
        '2026-08-11',
        $caso['admin']->id,
    );

    expect((float) $caso['cotizacion']->fresh()->anticipo)->toBe(2030.0)
        ->and((float) $caso['cotizacion']->movimientosFinancieros()->where('categoria', 'anticipo')->sum('monto'))->toBe(2030.0)
        ->and((float) $caso['orden']->fresh()->total_cliente)->toBe(4220.0)
        ->and($resultado['saldo_proyectado_entrega'])->toBe(2190.0)
        ->and($caso['orden']->movimientosFinancieros()->where('categoria', 'reparacion')->count())->toBe(0)
        ->and($caso['orden']->fresh()->estado)->not->toBe('entregado')
        ->and($caso['orden']->fresh()->finanzas_generadas)->toBeFalse();
});
