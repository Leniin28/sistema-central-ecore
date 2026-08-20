<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contextoSincronizacionAceptada(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $partner = Partner::create([
        'nombre' => 'Logística sincronización',
        'tipo_socio' => 'logistico',
        'comision_fija' => 0,
        'activo' => true,
    ]);
    $logistico = User::factory()->create([
        'role' => 'socio_logistico',
        'partner_id' => $partner->id,
        'email_verified_at' => now(),
    ]);
    $cliente = Cliente::create([
        'nombre' => 'Cliente sincronización aceptada',
        'telefono' => '4493004000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Framework',
        'modelo' => '13',
        'accesorios_recibidos' => 'Cargador y funda',
        'estado_fisico_inicial' => 'Rayón leve en tapa',
    ]);
    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'partner_recepcion_id' => $partner->id,
        'tipo_recepcion' => 'sucursal',
        'notas' => 'Problema reportado: reinicios. Notas internas de recepción.',
    ], [[
        'descripcion' => 'Línea manual preservada',
        'cantidad' => 1,
        'precio_unitario' => 50,
        'costo_unitario' => 25,
        'notas' => 'Manual',
    ]], [], $admin);
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'orden_servicio_id' => $orden->id,
        'fecha' => today()->format('Y-m-d'),
        'tipo_recepcion' => 'en_negocio',
        'descuento' => 10,
        'anticipo' => 20,
        'notas' => 'Cotización inicial',
    ], [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio trazable inicial',
        'cantidad' => 1,
        'precio_unitario' => 300,
        'costo_unitario' => 100,
    ], [
        'tipo' => 'refaccion',
        'descripcion' => 'Refacción trazable inicial',
        'cantidad' => 1,
        'precio_unitario' => 200,
        'costo_unitario' => null,
    ]], $admin);

    test()->actingAs($admin)
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect();

    return compact('admin', 'partner', 'logistico', 'cliente', 'equipo', 'orden', 'cotizacion');
}

/** @param array<int, array<string, mixed>> $items */
function payloadSincronizacionAceptada(array $contexto, array $items): array
{
    return [
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'orden_servicio_id' => $contexto['orden']->id,
        'fecha' => today()->format('Y-m-d'),
        'tipo_recepcion' => 'en_negocio',
        'descuento' => 999,
        'anticipo' => (float) $contexto['cotizacion']->anticipo,
        'notas' => 'Cotización aceptada actualizada',
        'items' => $items,
    ];
}

test('admin sincroniza items de aceptada preservando ids lineas manuales estado datos costos y totales', function () {
    $contexto = contextoSincronizacionAceptada();
    $cotizacion = $contexto['cotizacion']->fresh('items');
    $servicio = $cotizacion->items->firstWhere('tipo', 'servicio');
    $refaccionEliminada = $cotizacion->items->firstWhere('tipo', 'refaccion');
    $lineaServicioId = $contexto['orden']->detalles()->where('cotizacion_item_id', $servicio->id)->sole()->id;
    $lineaManual = $contexto['orden']->detalles()->whereNull('cotizacion_item_id')->sole();
    $notasOrden = $contexto['orden']->notas;
    $contexto['orden']->update(['estado' => 'en_proceso']);

    $items = [[
        'id' => $servicio->id,
        'tipo' => 'servicio',
        'descripcion' => 'Servicio trazable modificado',
        'cantidad' => 2,
        'precio_unitario' => 400,
        'costo_unitario' => 0,
    ], [
        'tipo' => 'refaccion',
        'descripcion' => 'Refacción trazable nueva',
        'cantidad' => 1,
        'precio_unitario' => 150,
        'costo_unitario' => null,
    ]];

    $this->actingAs($contexto['admin'])
        ->put(route('admin.cotizaciones.update', $cotizacion), payloadSincronizacionAceptada($contexto, $items))
        ->assertRedirect(route('admin.cotizaciones.show', $cotizacion));

    $cotizacion->refresh()->load('items');
    $orden = $contexto['orden']->fresh();
    $servicioActualizado = $cotizacion->items->firstWhere('id', $servicio->id);
    $itemNuevo = $cotizacion->items->firstWhere('descripcion', 'Refacción trazable nueva');

    expect($cotizacion->estado)->toBe('aceptada')
        ->and((float) $cotizacion->descuento)->toBe(10.0)
        ->and((float) $cotizacion->anticipo)->toBe(20.0)
        ->and($servicioActualizado->id)->toBe($servicio->id)
        ->and($servicioActualizado->descripcion)->toBe('Servicio trazable modificado')
        ->and($cotizacion->items()->whereKey($refaccionEliminada->id)->exists())->toBeFalse()
        ->and($orden->detalles()->whereKey($lineaServicioId)->where('cotizacion_item_id', $servicio->id)->exists())->toBeTrue()
        ->and($orden->detalles()->where('cotizacion_item_id', $servicio->id)->sole()->descripcion)->toBe('Servicio trazable modificado')
        ->and($orden->detalles()->whereNull('cotizacion_item_id')->whereKey($lineaManual->id)->exists())->toBeTrue()
        ->and($orden->refacciones()->where('cotizacion_item_id', $refaccionEliminada->id)->exists())->toBeFalse()
        ->and($orden->refacciones()->where('cotizacion_item_id', $itemNuevo->id)->count())->toBe(1)
        ->and($orden->estado)->toBe('en_proceso')
        ->and($orden->notas)->toBe($notasOrden)
        ->and($contexto['equipo']->fresh()->accesorios_recibidos)->toBe('Cargador y funda')
        ->and($contexto['equipo']->fresh()->estado_fisico_inicial)->toBe('Rayón leve en tapa')
        ->and((float) $orden->total_cliente)->toBe(1000.0)
        ->and((float) $orden->utilidad_estimada)->toBe(975.0)
        ->and($orden->costos_incompletos)->toBeTrue();

    $repeticion = payloadSincronizacionAceptada($contexto, [
        [...$items[0]],
        [...$items[1], 'id' => $itemNuevo->id],
    ]);
    $this->actingAs($contexto['admin'])->put(route('admin.cotizaciones.update', $cotizacion), $repeticion)->assertRedirect();

    expect($orden->detalles()->where('cotizacion_item_id', $servicio->id)->count())->toBe(1)
        ->and($orden->refacciones()->where('cotizacion_item_id', $itemNuevo->id)->count())->toBe(1)
        ->and($orden->detalles()->whereNull('cotizacion_item_id')->count())->toBe(1);
});

test('cambiar servicio a refaccion y regresar conserva el item y nunca deja ambas lineas', function () {
    $contexto = contextoSincronizacionAceptada();
    $cotizacion = $contexto['cotizacion']->fresh('items');
    $item = $cotizacion->items->firstWhere('tipo', 'servicio');
    $otro = $cotizacion->items->firstWhere('tipo', 'refaccion');

    $aRefaccion = payloadSincronizacionAceptada($contexto, [[
        'id' => $item->id,
        'tipo' => 'refaccion',
        'descripcion' => 'Ahora es refacción',
        'cantidad' => 1,
        'precio_unitario' => 350,
        'costo_unitario' => 125,
    ], [
        'id' => $otro->id,
        'tipo' => $otro->tipo,
        'descripcion' => $otro->descripcion,
        'cantidad' => $otro->cantidad,
        'precio_unitario' => $otro->precio_unitario,
        'costo_unitario' => $otro->costo_unitario,
    ]]);
    $this->actingAs($contexto['admin'])->put(route('admin.cotizaciones.update', $cotizacion), $aRefaccion)->assertRedirect();

    expect($cotizacion->items()->findOrFail($item->id)->tipo)->toBe('refaccion')
        ->and($contexto['orden']->detalles()->where('cotizacion_item_id', $item->id)->exists())->toBeFalse()
        ->and($contexto['orden']->refacciones()->where('cotizacion_item_id', $item->id)->count())->toBe(1);

    $aServicio = $aRefaccion;
    $aServicio['items'][0]['tipo'] = 'servicio';
    $aServicio['items'][0]['descripcion'] = 'Vuelve a servicio';
    $this->actingAs($contexto['admin'])->put(route('admin.cotizaciones.update', $cotizacion), $aServicio)->assertRedirect();

    expect($cotizacion->items()->findOrFail($item->id)->tipo)->toBe('servicio')
        ->and($contexto['orden']->refacciones()->where('cotizacion_item_id', $item->id)->exists())->toBeFalse()
        ->and($contexto['orden']->detalles()->where('cotizacion_item_id', $item->id)->count())->toBe(1);
});

test('cliente equipo y orden de una aceptada quedan congelados en servidor', function () {
    $contexto = contextoSincronizacionAceptada();
    $otroCliente = Cliente::create(['nombre' => 'Cliente ajeno', 'telefono' => '4495', 'tipo_cliente' => 'mantenimiento']);
    $otroEquipo = Equipo::create(['cliente_id' => $otroCliente->id, 'tipo_equipo' => 'Tablet', 'marca' => 'Ajena']);
    $payload = payloadSincronizacionAceptada($contexto, $contexto['cotizacion']->fresh('items')->items->toArray());
    $payload['cliente_id'] = $otroCliente->id;
    $payload['equipo_id'] = $otroEquipo->id;
    $payload['orden_servicio_id'] = 999999;

    $this->actingAs($contexto['admin'])
        ->put(route('admin.cotizaciones.update', $contexto['cotizacion']), $payload)
        ->assertSessionHasErrors('cliente_id');

    expect($contexto['cotizacion']->fresh()->cliente_id)->toBe($contexto['cliente']->id)
        ->and($contexto['cotizacion']->fresh()->equipo_id)->toBe($contexto['equipo']->id)
        ->and($contexto['orden']->fresh()->cotizacion_id)->toBe($contexto['cotizacion']->id);
});

test('socio logistico no puede editar una cotizacion aceptada', function () {
    $contexto = contextoSincronizacionAceptada();
    $contexto['cotizacion']->update(['partner_id' => $contexto['partner']->id]);
    $payload = payloadSincronizacionAceptada($contexto, $contexto['cotizacion']->fresh('items')->items->toArray());

    $this->actingAs($contexto['logistico'])
        ->put(route('logistica.cotizaciones.update', $contexto['cotizacion']), $payload)
        ->assertForbidden();
});

test('rechaza un item id perteneciente a otra cotizacion y revierte todo', function () {
    $contexto = contextoSincronizacionAceptada();
    $otra = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
    ], [[
        'tipo' => 'servicio', 'descripcion' => 'Item ajeno', 'cantidad' => 1, 'precio_unitario' => 1,
    ]], $contexto['admin']);
    $ajeno = $otra->items()->sole();
    $original = $contexto['cotizacion']->fresh('items')->items->first();
    $payload = payloadSincronizacionAceptada($contexto, [[
        'id' => $ajeno->id,
        'tipo' => 'servicio',
        'descripcion' => 'Manipulado',
        'cantidad' => 1,
        'precio_unitario' => 999,
    ]]);

    $this->actingAs($contexto['admin'])
        ->put(route('admin.cotizaciones.update', $contexto['cotizacion']), $payload)
        ->assertSessionHasErrors('items');

    expect($original->fresh()->descripcion)->toBe('Servicio trazable inicial')
        ->and($ajeno->fresh()->descripcion)->toBe('Item ajeno');
});

test('bloquea aceptada cuando la orden deja de ser comercialmente modificable', function (string $caso) {
    $contexto = contextoSincronizacionAceptada();
    $orden = $contexto['orden'];

    if ($caso === 'entregada') {
        $orden->update(['estado' => 'entregado']);
    } elseif ($caso === 'cancelada') {
        $orden->update(['estado' => 'cancelado']);
    } elseif ($caso === 'finanzas') {
        $orden->update(['finanzas_generadas' => true]);
    } else {
        $orden->movimientosFinancieros()->create([
            'cliente_id' => $contexto['cliente']->id,
            'tipo' => 'ingreso',
            'categoria' => 'manual',
            'monto' => 10,
            'descripcion' => 'Movimiento incompatible',
            'fecha' => today(),
        ]);
    }

    $payload = payloadSincronizacionAceptada($contexto, $contexto['cotizacion']->fresh('items')->items->toArray());
    $this->actingAs($contexto['admin'])
        ->put(route('admin.cotizaciones.update', $contexto['cotizacion']), $payload)
        ->assertSessionHasErrors('orden_servicio_id');
    $this->actingAs($contexto['admin'])
        ->get(route('admin.cotizaciones.edit', $contexto['cotizacion']))
        ->assertForbidden();
})->with(['entregada', 'cancelada', 'finanzas', 'movimientos']);

test('rechaza una cotizacion aceptada si perdió su orden vinculada', function () {
    $contexto = contextoSincronizacionAceptada();
    $contexto['orden']->refresh()->update(['cotizacion_id' => null]);
    $payload = payloadSincronizacionAceptada($contexto, $contexto['cotizacion']->fresh('items')->items->toArray());

    $this->actingAs($contexto['admin'])
        ->put(route('admin.cotizaciones.update', $contexto['cotizacion']), $payload)
        ->assertSessionHasErrors('orden_servicio_id');
    $this->actingAs($contexto['admin'])
        ->get(route('admin.cotizaciones.edit', $contexto['cotizacion']))
        ->assertForbidden();
});

test('la interfaz admin habilita aceptada abierta y muestra orden con campos estructurales bloqueados', function () {
    $contexto = contextoSincronizacionAceptada();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.cotizaciones.show', $contexto['cotizacion']))
        ->assertOk()
        ->assertSee('Editar');

    $this->actingAs($contexto['admin'])
        ->get(route('admin.cotizaciones.edit', $contexto['cotizacion']))
        ->assertOk()
        ->assertSee('Esta cotización está aceptada y vinculada a')
        ->assertSee($contexto['orden']->folio)
        ->assertSee('Cliente, equipo y orden permanecen fijos después de aceptar.')
        ->assertSee('readonly', false);
});

test('edicion manual de orden preserva lineas trazables y solo reemplaza manuales', function () {
    $contexto = contextoSincronizacionAceptada();
    $orden = $contexto['orden']->fresh(['detalles', 'refacciones']);
    $idsTrazables = $orden->detalles->whereNotNull('cotizacion_item_id')->pluck('id')
        ->merge($orden->refacciones->whereNotNull('cotizacion_item_id')->pluck('id'));

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.edit', $orden))
        ->assertOk()
        ->assertSee('Conceptos administrados por cotización')
        ->assertSee($contexto['cotizacion']->folio);

    $this->actingAs($contexto['admin'])
        ->put(route('admin.ordenes-servicio.update', $orden), [
            'cliente_id' => $orden->cliente_id,
            'equipo_id' => $orden->equipo_id,
            'tipo_recepcion' => $orden->tipo_recepcion,
            'partner_recepcion_id' => $orden->partner_recepcion_id,
            'partner_tecnico_id' => $orden->partner_tecnico_id,
            'costo_tecnico' => $orden->costo_tecnico,
            'notas' => $orden->notas,
            'servicios' => [[
                'descripcion' => 'Nueva línea manual',
                'cantidad' => 1,
                'precio_unitario' => 75,
                'costo_unitario' => 30,
            ]],
            'refacciones' => [],
        ])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    $orden->refresh();
    expect($orden->detalles()->whereNotNull('cotizacion_item_id')->pluck('id')->merge(
        $orden->refacciones()->whereNotNull('cotizacion_item_id')->pluck('id'),
    )->sort()->values()->all())->toBe($idsTrazables->sort()->values()->all())
        ->and($orden->detalles()->whereNull('cotizacion_item_id')->sole()->descripcion)->toBe('Nueva línea manual')
        ->and($orden->detalles()->whereNotNull('cotizacion_item_id')->count())->toBe(1)
        ->and($orden->refacciones()->whereNotNull('cotizacion_item_id')->count())->toBe(1);

    $otroCliente = Cliente::create(['nombre' => 'Cliente manipulado en orden', 'telefono' => '4496', 'tipo_cliente' => 'mantenimiento']);
    $this->actingAs($contexto['admin'])
        ->put(route('admin.ordenes-servicio.update', $orden), [
            'cliente_id' => $otroCliente->id,
            'equipo_id' => null,
            'tipo_recepcion' => $orden->tipo_recepcion,
            'partner_recepcion_id' => $orden->partner_recepcion_id,
            'partner_tecnico_id' => $orden->partner_tecnico_id,
            'costo_tecnico' => $orden->costo_tecnico,
            'notas' => $orden->notas,
            'servicios' => [[
                'descripcion' => 'Intento manipulado', 'cantidad' => 1, 'precio_unitario' => 1,
            ]],
            'refacciones' => [],
        ])
        ->assertSessionHasErrors('orden');

    expect($orden->fresh()->cliente_id)->toBe($contexto['cliente']->id)
        ->and($orden->fresh()->equipo_id)->toBe($contexto['equipo']->id);
});

test('edicion existente de borrador sigue funcionando sin usar el sincronizador de aceptadas', function () {
    $contexto = contextoSincronizacionAceptada();
    $borrador = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
    ], [[
        'tipo' => 'servicio', 'descripcion' => 'Borrador inicial', 'cantidad' => 1, 'precio_unitario' => 10,
    ]], $contexto['admin']);
    $payload = [
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'tipo_recepcion' => 'en_negocio',
        'items' => [[
            'id' => $borrador->items()->sole()->id,
            'tipo' => 'servicio',
            'descripcion' => 'Borrador actualizado',
            'cantidad' => 2,
            'precio_unitario' => 20,
        ]],
    ];

    $this->actingAs($contexto['admin'])
        ->put(route('admin.cotizaciones.update', $borrador), $payload)
        ->assertRedirect();

    expect($borrador->fresh()->estado)->toBe('borrador')
        ->and($borrador->items()->sole()->descripcion)->toBe('Borrador actualizado')
        ->and((float) $borrador->fresh()->total)->toBe(40.0);
});
