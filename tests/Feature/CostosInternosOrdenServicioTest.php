<?php

use App\Actions\Cotizaciones\SincronizarLineasCotizacionConOrden;
use App\Actions\Ordenes\ActualizarCostosInternosOrdenServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function contextoCostosInternos(): array
{
    $admin = User::factory()->create(['role' => 'admin']);
    $socio = User::factory()->create(['role' => 'socio_logistico']);
    $cliente = Cliente::create([
        'nombre' => 'Cliente costos internos',
        'telefono' => '4491002000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Framework',
        'modelo' => '16',
    ]);
    $cotizacion = Cotizacion::create([
        'folio' => 'COT-COSTOS-1',
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'creado_por_user_id' => $admin->id,
        'fecha' => today(),
        'estado' => 'aceptada',
        'subtotal' => 600,
        'total' => 600,
        'saldo' => 600,
    ]);
    $itemServicio = $cotizacion->items()->create([
        'tipo' => 'servicio',
        'descripcion' => 'Servicio cotizado',
        'cantidad' => 2,
        'precio_unitario' => 100,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 200,
    ]);
    $itemRefaccion = $cotizacion->items()->create([
        'tipo' => 'refaccion',
        'descripcion' => 'Refacción cotizada',
        'cantidad' => 1,
        'precio_unitario' => 400,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 400,
    ]);
    $orden = OrdenServicio::create([
        'folio' => 'OS-COSTOS-1',
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'sucursal',
        'estado' => 'recibido',
        'total_cliente' => 650,
        'utilidad_estimada' => 650,
        'costos_incompletos' => true,
        'finanzas_generadas' => false,
    ]);
    $servicioCotizado = $orden->detalles()->create([
        'cotizacion_item_id' => $itemServicio->id,
        'descripcion' => 'Servicio cotizado',
        'cantidad' => 2,
        'precio_unitario' => 100,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 200,
        'notas' => 'Trazable',
    ]);
    $refaccionCotizada = $orden->refacciones()->create([
        'cotizacion_item_id' => $itemRefaccion->id,
        'descripcion' => 'Refacción cotizada',
        'cantidad' => 1,
        'precio_unitario_cliente' => 400,
        'costo_unitario' => null,
        'costo_total' => null,
        'precio_total_cliente' => 400,
        'utilidad_refaccion' => 400,
        'notas' => 'Trazable',
    ]);
    $servicioManual = $orden->detalles()->create([
        'descripcion' => 'Servicio manual',
        'cantidad' => 1,
        'precio_unitario' => 50,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 50,
        'notas' => 'Manual',
    ]);

    return compact(
        'admin', 'socio', 'cliente', 'equipo', 'cotizacion', 'itemServicio', 'itemRefaccion',
        'orden', 'servicioCotizado', 'refaccionCotizada', 'servicioManual',
    );
}

function ejecutarCostosInternos(array $contexto, array $servicios, array $refacciones = []): OrdenServicio
{
    return app(ActualizarCostosInternosOrdenServicio::class)->ejecutar(
        $contexto['orden'],
        $servicios,
        $refacciones,
        $contexto['admin'],
    );
}

test('admin captura cero y positivo en lineas cotizadas sin alterar comercio trazabilidad ni ids', function () {
    $contexto = contextoCostosInternos();
    $servicioAntes = $contexto['servicioCotizado']->only([
        'id', 'cotizacion_item_id', 'servicio_id', 'descripcion', 'cantidad', 'precio_unitario', 'subtotal', 'notas',
    ]);
    $refaccionAntes = $contexto['refaccionCotizada']->only([
        'id', 'cotizacion_item_id', 'descripcion', 'cantidad', 'precio_unitario_cliente', 'precio_total_cliente', 'notas',
    ]);

    ejecutarCostosInternos($contexto, [[
        'id' => $contexto['servicioCotizado']->id,
        'costo_unitario' => 0,
        'descripcion' => 'Intento ignorado',
        'precio_unitario' => 999,
        'cantidad' => 99,
        'cotizacion_item_id' => null,
    ]], [[
        'id' => $contexto['refaccionCotizada']->id,
        'costo_unitario' => 125.50,
        'descripcion' => 'Intento ignorado',
        'precio_unitario_cliente' => 999,
        'cantidad' => 99,
        'cotizacion_item_id' => null,
    ]]);

    $servicio = $contexto['servicioCotizado']->fresh();
    $refaccion = $contexto['refaccionCotizada']->fresh();
    expect($servicio->only(array_keys($servicioAntes)))->toBe($servicioAntes)
        ->and($refaccion->only(array_keys($refaccionAntes)))->toBe($refaccionAntes)
        ->and($servicio->costo_unitario)->toBe('0.00')
        ->and($servicio->costo_total)->toBe('0.00')
        ->and($refaccion->costo_unitario)->toBe('125.50')
        ->and($refaccion->costo_total)->toBe('125.50')
        ->and($contexto['itemServicio']->fresh()->costo_unitario)->toBe('0.00')
        ->and($contexto['itemServicio']->fresh()->costo_total)->toBe('0.00')
        ->and($contexto['itemRefaccion']->fresh()->costo_unitario)->toBe('125.50')
        ->and($contexto['orden']->detalles()->count())->toBe(2)
        ->and($contexto['orden']->refacciones()->count())->toBe(1)
        ->and((float) $contexto['orden']->fresh()->total_cliente)->toBe(650.0)
        ->and($contexto['orden']->fresh()->costos_incompletos)->toBeTrue()
        ->and($contexto['orden']->movimientosFinancieros()->count())->toBe(0);
});

test('cantidad aplica la formula existente y una sincronizacion posterior no revierte el costo', function () {
    $contexto = contextoCostosInternos();
    $lineaId = $contexto['servicioCotizado']->id;

    ejecutarCostosInternos($contexto, [[
        'id' => $lineaId,
        'costo_unitario' => 12.50,
    ]]);
    app(SincronizarLineasCotizacionConOrden::class)->sincronizar($contexto['orden'], $contexto['cotizacion']);

    $linea = $contexto['orden']->detalles()->where('cotizacion_item_id', $contexto['itemServicio']->id)->sole();
    expect($linea->id)->toBe($lineaId)
        ->and($linea->costo_unitario)->toBe('12.50')
        ->and($linea->costo_total)->toBe('25.00')
        ->and($contexto['itemServicio']->fresh()->costo_total)->toBe('25.00')
        ->and($contexto['orden']->detalles()->count())->toBe(2);
});

test('linea manual y refaccion admiten costo y completan costos incluso con cero', function () {
    $contexto = contextoCostosInternos();

    $orden = ejecutarCostosInternos($contexto, [[
        'id' => $contexto['servicioCotizado']->id,
        'costo_unitario' => 0,
    ], [
        'id' => $contexto['servicioManual']->id,
        'costo_unitario' => 25,
    ]], [[
        'id' => $contexto['refaccionCotizada']->id,
        'costo_unitario' => 0,
    ]]);

    expect($contexto['servicioManual']->fresh()->costo_unitario)->toBe('25.00')
        ->and($contexto['servicioManual']->fresh()->costo_total)->toBe('25.00')
        ->and($contexto['refaccionCotizada']->fresh()->costo_unitario)->toBe('0.00')
        ->and($contexto['refaccionCotizada']->fresh()->utilidad_refaccion)->toBe('400.00')
        ->and($orden->costos_incompletos)->toBeFalse()
        ->and((float) $orden->utilidad_estimada)->toBe(625.0)
        ->and((float) $orden->total_cliente)->toBe(650.0)
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and($orden->movimientosFinancieros()->count())->toBe(0);
});

test('rechaza una linea perteneciente a otra orden y revierte todos los cambios', function () {
    $contexto = contextoCostosInternos();
    $otra = $contexto['orden']->replicate(['folio', 'cotizacion_id']);
    $otra->folio = 'OS-COSTOS-2';
    $otra->cotizacion_id = null;
    $otra->save();
    $ajena = $otra->detalles()->create([
        'descripcion' => 'Ajena', 'cantidad' => 1, 'precio_unitario' => 10, 'subtotal' => 10,
    ]);

    expect(fn () => ejecutarCostosInternos($contexto, [[
        'id' => $contexto['servicioCotizado']->id, 'costo_unitario' => 10,
    ], [
        'id' => $ajena->id, 'costo_unitario' => 10,
    ]]))->toThrow(ValidationException::class);

    expect($contexto['servicioCotizado']->fresh()->costo_unitario)->toBeNull()
        ->and($ajena->fresh()->costo_unitario)->toBeNull();
});

test('solo admin puede actualizar costos por action y por ruta', function () {
    $contexto = contextoCostosInternos();

    expect(fn () => app(ActualizarCostosInternosOrdenServicio::class)->ejecutar(
        $contexto['orden'],
        [['id' => $contexto['servicioCotizado']->id, 'costo_unitario' => 0]],
        [],
        $contexto['socio'],
    ))->toThrow(HttpException::class);

    $this->actingAs($contexto['socio'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertForbidden();

    $this->actingAs($contexto['socio'])
        ->patch(route('admin.ordenes-servicio.costos.update', $contexto['orden']), [
            'costos_servicios' => [['id' => $contexto['servicioCotizado']->id, 'costo_unitario' => 0]],
            'costos_refacciones' => [],
        ])
        ->assertForbidden();

    expect($contexto['servicioCotizado']->fresh()->costo_unitario)->toBeNull();
});

test('bloquea costos en orden cerrada cancelada o consolidada', function (string $caso) {
    $contexto = contextoCostosInternos();
    $estado = match ($caso) {
        'finanzas' => ['finanzas_generadas' => true],
        'entregada' => ['estado' => 'entregado'],
        'cancelada' => ['estado' => 'cancelado'],
        'consolidada' => ['orden_canonica_id' => $contexto['orden']->id],
    };
    $contexto['orden']->update($estado);

    expect(fn () => ejecutarCostosInternos($contexto, [[
        'id' => $contexto['servicioCotizado']->id,
        'costo_unitario' => 0,
    ]]))->toThrow(ValidationException::class);

    expect($contexto['servicioCotizado']->fresh()->costo_unitario)->toBeNull();
})->with([
    'finanzas generadas' => ['finanzas'],
    'entregada' => ['entregada'],
    'cancelada' => ['cancelada'],
    'consolidada' => ['consolidada'],
]);

test('una orden cancelada no muestra el formulario de costos internos al editar', function () {
    $contexto = contextoCostosInternos();
    $contexto['orden']->update([
        'cotizacion_id' => null,
        'estado' => 'cancelado',
    ]);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertOk()
        ->assertDontSee('Guardar costos internos');
});

test('una orden consolidada no muestra el formulario de costos internos al editar', function () {
    $contexto = contextoCostosInternos();
    $canonica = $contexto['orden']->replicate(['folio', 'cotizacion_id']);
    $canonica->folio = 'OS-COSTOS-CANONICA';
    $canonica->cotizacion_id = null;
    $canonica->save();
    $contexto['orden']->update(['orden_canonica_id' => $canonica->id]);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertOk()
        ->assertDontSee('Guardar costos internos');
});

test('una orden entregada no muestra el formulario de costos internos al intentar editar', function () {
    $contexto = contextoCostosInternos();
    $contexto['orden']->update(['estado' => 'entregado']);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertRedirect(route('admin.ordenes-servicio.show', $contexto['orden']))
        ->assertDontSee('Guardar costos internos');
});

test('una orden con finanzas generadas no muestra el formulario de costos internos al intentar editar', function () {
    $contexto = contextoCostosInternos();
    $contexto['orden']->update(['finanzas_generadas' => true]);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertRedirect(route('admin.ordenes-servicio.show', $contexto['orden']))
        ->assertDontSee('Guardar costos internos');
});

test('admin ve el formulario de costos internos en los estados abiertos', function (string $estado) {
    $contexto = contextoCostosInternos();
    $contexto['orden']->update(['estado' => $estado]);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertOk()
        ->assertSee('Guardar costos internos');
})->with([
    'recibido',
    'en_diagnostico',
    'cotizacion_pendiente',
    'cotizacion_aprobada',
    'en_proceso',
    'en_fixop',
    'listo_para_entregar',
]);

test('la interfaz separa costo pendiente de cero y la ruta solo acepta costos', function () {
    $contexto = contextoCostosInternos();
    $contexto['servicioManual']->update(['costo_unitario' => 0, 'costo_total' => 0]);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $contexto['orden']))
        ->assertOk()
        ->assertSee('Costos internos de líneas existentes')
        ->assertSee('Costo pendiente')
        ->assertSee('$0.00')
        ->assertSee('Los datos comerciales y la trazabilidad no se modifican desde aquí.');

    $this->actingAs($contexto['admin'])
        ->patch(route('admin.ordenes-servicio.costos.update', $contexto['orden']), [
            'costos_servicios' => [[
                'id' => $contexto['servicioCotizado']->id,
                'costo_unitario' => 0,
                'descripcion' => 'Manipulada',
                'precio_unitario' => 999,
                'cantidad' => 99,
                'cotizacion_item_id' => null,
            ], [
                'id' => $contexto['servicioManual']->id,
                'costo_unitario' => 0,
            ]],
            'costos_refacciones' => [[
                'id' => $contexto['refaccionCotizada']->id,
                'costo_unitario' => 0,
            ]],
        ])
        ->assertRedirect(route('admin.ordenes-servicio.show', $contexto['orden']));

    expect($contexto['servicioCotizado']->fresh()->descripcion)->toBe('Servicio cotizado')
        ->and($contexto['servicioCotizado']->fresh()->cantidad)->toBe(2)
        ->and($contexto['servicioCotizado']->fresh()->precio_unitario)->toBe('100.00')
        ->and($contexto['servicioCotizado']->fresh()->cotizacion_item_id)->toBe($contexto['itemServicio']->id);
});

test('la ruta acepta ordenes que solo tienen uno de los tipos de linea', function () {
    $contexto = contextoCostosInternos();
    $contexto['refaccionCotizada']->delete();
    $contexto['servicioManual']->delete();

    $this->actingAs($contexto['admin'])
        ->patch(route('admin.ordenes-servicio.costos.update', $contexto['orden']), [
            'costos_servicios' => [[
                'id' => $contexto['servicioCotizado']->id,
                'costo_unitario' => 0,
            ]],
        ])
        ->assertRedirect(route('admin.ordenes-servicio.show', $contexto['orden']));

    expect($contexto['servicioCotizado']->fresh()->costo_unitario)->toBe('0.00');
});
