<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Finanzas\RevertirMovimientoFinanciero;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * FASE G: reversión segura de movimientos financieros. V1 solo permite
 * revertir movimientos manuales/independientes (sin orden_servicio_id ni
 * cotizacion_id) mediante un movimiento compensatorio; el original nunca se
 * edita ni se elimina, y los movimientos generados estructuralmente por
 * GenerarFinanzasOrdenServicio / RegistrarAnticipoCotizacion quedan
 * bloqueados.
 */
function admin(): User
{
    return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
}

function movimientoManual(array $overrides = []): MovimientoFinanciero
{
    return MovimientoFinanciero::create([
        'orden_servicio_id' => null,
        'cotizacion_id' => null,
        'tipo' => 'egreso',
        'categoria' => 'gasolina',
        'monto' => 100,
        'descripcion' => 'Gasolina camioneta',
        'fecha' => today(),
        ...$overrides,
    ]);
}

function ordenParaMovimientoEstructurado(): OrdenServicio
{
    $usuario = admin();
    $cliente = Cliente::create(['nombre' => 'Cliente reversion', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);

    return OrdenServicio::create([
        'folio' => 'OS-REV-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $usuario->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'entregado',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 1000,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => true,
        'fecha_entrega' => today(),
    ]);
}

test('admin puede revertir un egreso manual y se genera un ingreso compensatorio del mismo monto', function () {
    $actor = admin();
    $original = movimientoManual(['tipo' => 'egreso', 'monto' => 250, 'categoria' => 'transporte']);

    $reversion = app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'Gasto duplicado por error de captura', $actor);

    expect($reversion->tipo)->toBe('ingreso')
        ->and((float) $reversion->monto)->toBe(250.0)
        ->and($reversion->categoria)->toBe('transporte')
        ->and($reversion->movimiento_original_id)->toBe($original->id)
        ->and($reversion->motivo_reversion)->toBe('Gasto duplicado por error de captura')
        ->and($reversion->revertido_por_user_id)->toBe($actor->id)
        ->and($reversion->fecha->toDateString())->toBe(today()->toDateString());

    $original->refresh();
    expect($original->tipo)->toBe('egreso')
        ->and((float) $original->monto)->toBe(250.0)
        ->and($original->descripcion)->toBe('Gasolina camioneta');

    expect(MovimientoFinanciero::count())->toBe(2);
});

test('admin puede revertir un ingreso manual y se genera un egreso compensatorio', function () {
    $actor = admin();
    $original = movimientoManual(['tipo' => 'ingreso', 'monto' => 500, 'categoria' => 'otro']);

    $reversion = app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'Depósito registrado por error', $actor);

    expect($reversion->tipo)->toBe('egreso')
        ->and((float) $reversion->monto)->toBe(500.0);

    $original->refresh();
    expect($original->tipo)->toBe('ingreso');
});

test('el movimiento original permanece intacto tras revertirse', function () {
    $actor = admin();
    $original = movimientoManual(['descripcion' => 'Descripción original', 'categoria' => 'insumos', 'monto' => 42]);

    app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'motivo', $actor);

    $original->refresh();
    expect($original->descripcion)->toBe('Descripción original')
        ->and($original->categoria)->toBe('insumos')
        ->and((float) $original->monto)->toBe(42.0)
        ->and($original->movimiento_original_id)->toBeNull();
});

test('la relacion original/reversion es consultable en ambos sentidos', function () {
    $actor = admin();
    $original = movimientoManual();

    $reversion = app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'motivo', $actor);

    expect($reversion->movimientoOriginal->id)->toBe($original->id);
    expect($original->fresh()->movimientoReversion->id)->toBe($reversion->id);
});

test('el motivo de reversion es obligatorio', function () {
    $admin = admin();
    $original = movimientoManual();

    $this->actingAs($admin)
        ->post(route('admin.movimientos-financieros.revertir', $original), [])
        ->assertSessionHasErrors(['motivo_reversion' => 'El motivo es obligatorio para registrar esta reversión.']);

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('se almacena el actor que realizo la reversion', function () {
    $actor = admin();
    $original = movimientoManual();

    $reversion = app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'motivo', $actor);

    expect($reversion->revertidoPor->id)->toBe($actor->id);
});

test('un movimiento original no puede revertirse dos veces', function () {
    $actor = admin();
    $original = movimientoManual();

    app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'primera reversion', $actor);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($original->fresh(), 'segunda reversion', $actor))
        ->toThrow(ValidationException::class, 'Este movimiento ya fue revertido.');

    expect(MovimientoFinanciero::count())->toBe(2);
});

/**
 * FASE J.8: dos reversiones realmente simultáneas sobre el mismo movimiento
 * pueden pasar ambas el chequeo yaRevertido() bajo lockForUpdate() y chocar
 * en el INSERT contra el UNIQUE de movimiento_original_id. Simular dos
 * conexiones concurrentes reales está fuera de alcance para un test
 * automatizado de un solo proceso; en su lugar confirmamos el contrato del
 * que depende el catch(QueryException) de la Action: el UNIQUE sigue
 * protegido a nivel de base de datos con SQLSTATE 23000, el mismo código
 * que la Action traduce a un mensaje amigable en vez de dejarlo crudo.
 */
test('el UNIQUE de movimiento_original_id sigue protegido a nivel de base de datos', function () {
    $original = movimientoManual();
    $otro = movimientoManual(['descripcion' => 'Otro movimiento manual', 'categoria' => 'otro']);

    MovimientoFinanciero::create([
        'tipo' => 'ingreso',
        'categoria' => $original->categoria,
        'monto' => $original->monto,
        'descripcion' => 'Reversión de movimiento #'.$original->id,
        'fecha' => today(),
        'movimiento_original_id' => $original->id,
        'motivo_reversion' => 'primera reversión',
        'revertido_por_user_id' => admin()->id,
    ]);

    $actorId = admin()->id;
    $codigo = null;

    try {
        MovimientoFinanciero::create([
            'tipo' => 'ingreso',
            'categoria' => $otro->categoria,
            'monto' => $otro->monto,
            'descripcion' => 'Reversión concurrente de movimiento #'.$original->id,
            'fecha' => today(),
            'movimiento_original_id' => $original->id,
            'motivo_reversion' => 'reversión concurrente',
            'revertido_por_user_id' => $actorId,
        ]);
    } catch (Illuminate\Database\QueryException $exception) {
        $codigo = $exception->getCode();
    }

    expect($codigo)->toBe('23000');
});

test('la segunda reversión (ya revertido) redirige con mensaje amigable en vez de un error crudo', function () {
    $admin = admin();
    $original = movimientoManual();

    app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'primera reversion', $admin);

    $this->actingAs($admin)
        ->from(route('admin.movimientos-financieros.index'))
        ->post(route('admin.movimientos-financieros.revertir', $original), ['motivo_reversion' => 'segunda reversion'])
        ->assertRedirect(route('admin.movimientos-financieros.index'))
        ->assertSessionHasErrors(['movimiento' => 'Este movimiento ya fue revertido.']);

    $this->actingAs($admin)
        ->followingRedirects()
        ->post(route('admin.movimientos-financieros.revertir', $original), ['motivo_reversion' => 'tercera reversion'])
        ->assertOk()
        ->assertSee('Este movimiento ya fue revertido.')
        ->assertDontSee('QueryException')
        ->assertDontSee('SQLSTATE');
});

test('una reversion no puede volver a revertirse', function () {
    $actor = admin();
    $original = movimientoManual();
    $reversion = app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'primera reversion', $actor);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($reversion, 'reversion de reversion', $actor))
        ->toThrow(ValidationException::class);

    expect(MovimientoFinanciero::count())->toBe(2);
});

test('socio logistico recibe 403 al intentar revertir', function () {
    $socio = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);
    $original = movimientoManual();

    $this->actingAs($socio)
        ->post(route('admin.movimientos-financieros.revertir', $original), ['motivo_reversion' => 'x'])
        ->assertForbidden();

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('socio tecnico recibe 403 al intentar revertir', function () {
    $socio = User::factory()->create(['role' => 'socio_tecnico', 'email_verified_at' => now()]);
    $original = movimientoManual();

    $this->actingAs($socio)
        ->post(route('admin.movimientos-financieros.revertir', $original), ['motivo_reversion' => 'x'])
        ->assertForbidden();

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('un movimiento estructurado de una orden queda bloqueado para reversion', function () {
    $actor = admin();
    $orden = ordenParaMovimientoEstructurado();
    $estructurado = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id,
        'cotizacion_id' => null,
        'tipo' => 'ingreso',
        'categoria' => 'reparacion',
        'monto' => 1000,
        'descripcion' => 'Saldo de orden '.$orden->folio,
        'fecha' => today(),
    ]);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($estructurado, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('un anticipo estructurado queda bloqueado para reversion', function () {
    $actor = admin();
    $cliente = Cliente::create(['nombre' => 'Cliente anticipo', 'telefono' => '4491112200', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 0,
    ], [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio de prueba',
        'cantidad' => 1,
        'precio_unitario' => 1000,
        'costo_unitario' => 0,
    ]], $actor);
    $anticipo = MovimientoFinanciero::create([
        'orden_servicio_id' => null,
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'tipo' => 'ingreso',
        'categoria' => 'anticipo',
        'monto' => 300,
        'descripcion' => 'Anticipo COT-TEST',
        'fecha' => today(),
    ]);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($anticipo, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('servicio y refaccion generados por una orden quedan bloqueados', function () {
    $actor = admin();
    $orden = ordenParaMovimientoEstructurado();

    $servicio = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'tipo' => 'egreso', 'categoria' => 'servicio',
        'monto' => 50, 'descripcion' => 'Costo interno de servicio', 'fecha' => today(),
    ]);
    $refaccion = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'tipo' => 'egreso', 'categoria' => 'refaccion',
        'monto' => 80, 'descripcion' => 'Compra de refacción', 'fecha' => today(),
    ]);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($servicio, 'motivo', $actor))->toThrow(ValidationException::class);
    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($refaccion, 'motivo', $actor))->toThrow(ValidationException::class);
    expect(MovimientoFinanciero::count())->toBe(2);
});

test('la comision de recepcion generada por una orden queda bloqueada', function () {
    $actor = admin();
    $orden = ordenParaMovimientoEstructurado();
    $comision = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'tipo' => 'egreso', 'categoria' => 'comision_recepcion',
        'monto' => 50, 'descripcion' => 'Comisión de recepción', 'fecha' => today(),
    ]);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($comision, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('un pago a socio historico ligado a una orden queda bloqueado', function () {
    $actor = admin();
    $orden = ordenParaMovimientoEstructurado();
    $pago = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'tipo' => 'egreso', 'categoria' => 'pago_socio_logistico',
        'monto' => 50, 'descripcion' => 'Comisión logística', 'fecha' => today(),
    ]);

    expect(fn () => app(RevertirMovimientoFinanciero::class)->ejecutar($pago, 'motivo', $actor))
        ->toThrow(ValidationException::class);

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('el resumen financiero refleja el movimiento compensatorio en su propia fecha', function () {
    $actor = admin();
    $original = movimientoManual(['tipo' => 'egreso', 'monto' => 100, 'fecha' => today()->subDays(20)]);
    app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'motivo', $actor);

    $response = $this->actingAs($actor)->get(route('admin.finanzas.resumen', ['periodo' => 'mes']));

    $response->assertOk();
    // El egreso original cae fuera del rango (hace 20 días puede seguir en el mes actual o no,
    // así que fijamos rango personalizado para asegurar que ambos días entran).
    $desde = today()->subDays(25)->toDateString();
    $hasta = today()->toDateString();
    $rango = $this->actingAs($actor)->get(route('admin.finanzas.resumen', [
        'periodo' => 'personalizado', 'fecha_desde' => $desde, 'fecha_hasta' => $hasta,
    ]));
    $rango->assertOk();
    expect($rango->viewData('ingresosTotal'))->toBe(100.0);
    expect($rango->viewData('egresosTotal'))->toBe(100.0);
    expect($rango->viewData('balance'))->toBe(0.0);
});

test('el rango de fechas del resumen respeta la fecha real de la reversion, no la del original', function () {
    $actor = admin();
    $original = movimientoManual(['tipo' => 'egreso', 'monto' => 100, 'fecha' => today()->subDays(10)]);
    app(RevertirMovimientoFinanciero::class)->ejecutar($original, 'motivo', $actor);

    // Rango que sólo cubre el día de la reversión (hoy), no el día del original.
    $response = $this->actingAs($actor)->get(route('admin.finanzas.resumen', [
        'periodo' => 'personalizado', 'fecha_desde' => today()->toDateString(), 'fecha_hasta' => today()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->viewData('ingresosTotal'))->toBe(100.0);
    expect($response->viewData('egresosTotal'))->toBe(0.0);
});

test('cero escrituras si el guard de estructurado falla', function () {
    $actor = admin();
    $orden = ordenParaMovimientoEstructurado();
    $estructurado = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'tipo' => 'ingreso', 'categoria' => 'reparacion',
        'monto' => 1000, 'descripcion' => 'Saldo', 'fecha' => today(),
    ]);

    try {
        app(RevertirMovimientoFinanciero::class)->ejecutar($estructurado, 'motivo', $actor);
    } catch (ValidationException $e) {
        // esperado
    }

    expect(MovimientoFinanciero::count())->toBe(1)
        ->and(MovimientoFinanciero::whereNotNull('movimiento_original_id')->count())->toBe(0);
});

