<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use App\Services\ExportarCotizacionPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('customer quote documents do not expose internal costs', function () {
    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio con costo interno',
        'cantidad' => 1,
        'precio_unitario' => 500,
        'costo_unitario' => 47.11,
    ]], $datos['admin']);

    $documento = view('cotizaciones._documento', [
        'cotizacion' => $cotizacion->fresh(['items', 'cliente', 'equipo', 'partner']),
        'negocio' => config('negocio'),
    ])->render();

    expect($documento)->toContain('$500.00')
        ->not->toContain('47.11')
        ->not->toContain('Costo interno');
});

function datosCotizacion(): array
{
    $cliente = Cliente::create(['nombre' => 'Cliente cotizado', 'telefono' => '555', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return compact('cliente', 'equipo', 'admin');
}

function itemsCotizacion(): array
{
    return [
        ['tipo' => 'servicio', 'descripcion' => 'Mantenimiento preventivo', 'cantidad' => 2, 'precio_unitario' => 100],
        ['tipo' => 'refaccion', 'descripcion' => 'Disco SSD 480GB', 'cantidad' => 1, 'precio_unitario' => 300],
    ];
}

test('usuario autorizado puede ver el listado de cotizaciones', function () {
    $datos = datosCotizacion();

    $this->actingAs($datos['admin'])
        ->get(route('admin.cotizaciones.index'))
        ->assertOk()
        ->assertSee('Cotizaciones');
});

test('admin puede crear una cotizacion con varios items', function () {
    $datos = datosCotizacion();

    $response = $this->actingAs($datos['admin'])->post(route('admin.cotizaciones.store'), [
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'descuento' => 50,
        'anticipo' => 150,
        'items' => itemsCotizacion(),
    ]);

    $response->assertRedirect();
    $cotizacion = Cotizacion::firstOrFail();
    expect($cotizacion->items)->toHaveCount(2)
        ->and($cotizacion->folio)->toStartWith('COT-')
        ->and($cotizacion->cliente_id)->toBe($datos['cliente']->id)
        ->and($cotizacion->equipo_id)->toBe($datos['equipo']->id);
});

test('cliente Fernanda recien creado puede vincularse a una cotizacion', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $fernanda = Cliente::create([
        'nombre' => 'Fernanda',
        'telefono' => '4491239876',
        'tipo_cliente' => 'mantenimiento',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.cotizaciones.store'), [
            'cliente_id' => $fernanda->id,
            'fecha' => today()->format('Y-m-d'),
            'items' => itemsCotizacion(),
        ])
        ->assertRedirect();

    expect(Cotizacion::sole()->cliente_id)->toBe($fernanda->id);
});

test('texto de cliente sin seleccion muestra un mensaje entendible', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $this->actingAs($admin)
        ->from(route('admin.cotizaciones.create'))
        ->post(route('admin.cotizaciones.store'), [
            'fecha' => today()->format('Y-m-d'),
            'items' => itemsCotizacion(),
        ])
        ->assertRedirect(route('admin.cotizaciones.create'))
        ->assertSessionHasErrors([
            'cliente_id' => 'Selecciona un cliente de los resultados de búsqueda.',
        ]);

    expect(Cotizacion::count())->toBe(0);
});

test('un equipo no puede permanecer vinculado al cambiar a otro cliente', function () {
    $datos = datosCotizacion();
    $otroCliente = Cliente::create([
        'nombre' => 'Cliente distinto',
        'telefono' => '4495554444',
        'tipo_cliente' => 'mantenimiento',
    ]);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), [
            'cliente_id' => $otroCliente->id,
            'equipo_id' => $datos['equipo']->id,
            'fecha' => today()->format('Y-m-d'),
            'items' => itemsCotizacion(),
        ])
        ->assertSessionHasErrors('equipo_id');

    expect(Cotizacion::count())->toBe(0);
});

test('los totales se calculan en el servidor', function () {
    $datos = datosCotizacion();

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'descuento' => 50,
        'anticipo' => 150,
    ], itemsCotizacion(), $datos['admin']);

    expect((float) $cotizacion->subtotal)->toBe(500.0)
        ->and((float) $cotizacion->descuento)->toBe(50.0)
        ->and((float) $cotizacion->total)->toBe(450.0)
        ->and((float) $cotizacion->anticipo)->toBe(150.0)
        ->and((float) $cotizacion->saldo)->toBe(300.0);
});

test('el anticipo no puede ser mayor al total', function () {
    $datos = datosCotizacion();

    expect(fn () => app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'anticipo' => 9999,
    ], itemsCotizacion(), $datos['admin']))->toThrow(ValidationException::class);
});

test('los items con cantidades o precios negativos se rechazan', function () {
    $datos = datosCotizacion();

    expect(fn () => app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], [['tipo' => 'servicio', 'descripcion' => 'Inválido', 'cantidad' => -1, 'precio_unitario' => 100]], $datos['admin']))
        ->toThrow(ValidationException::class);
});

test('la cotizacion queda en el historial del cliente', function () {
    $datos = datosCotizacion();

    app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], itemsCotizacion(), $datos['admin']);

    expect($datos['cliente']->cotizaciones()->count())->toBe(1);

    $this->actingAs($datos['admin'])
        ->get(route('admin.clientes.show', $datos['cliente']))
        ->assertOk()
        ->assertSee('COT-');
});

test('la ruta de PDF responde con un documento', function () {
    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], itemsCotizacion(), $datos['admin']);

    $this->actingAs($datos['admin'])
        ->get(route('admin.cotizaciones.pdf', $cotizacion))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('no se puede editar una cotizacion aceptada', function () {
    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], itemsCotizacion(), $datos['admin']);
    $cotizacion->update(['estado' => 'aceptada']);

    $this->actingAs($datos['admin'])
        ->get(route('admin.cotizaciones.edit', $cotizacion))
        ->assertForbidden();
});

test('aceptar desde el panel crea una orden con las lineas de la cotizacion', function () {
    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
    ], itemsCotizacion(), $datos['admin']);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect(route('admin.cotizaciones.show', $cotizacion));

    expect($cotizacion->fresh()->estado)->toBe('aceptada')
        ->and(OrdenServicio::count())->toBe(1);
});

test('aceptar desde el panel traslada snapshots, costos y lineas sin duplicarlos', function () {
    $datos = datosCotizacion();
    $categoria = CategoriaServicio::create(['nombre' => 'Mantenimiento']);
    $servicioExacto = Servicio::create([
        'categoria_servicio_id' => $categoria->id,
        'nombre' => 'Servicio de mantenimiento',
        'precio_base' => 300,
        'activo' => true,
    ]);
    Servicio::create([
        'categoria_servicio_id' => $categoria->id,
        'nombre' => 'Mantenimiento',
        'precio_base' => 500,
        'activo' => true,
    ]);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
    ], [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio de mantenimiento', 'cantidad' => 2, 'precio_unitario' => 300, 'costo_unitario' => 60],
        ['tipo' => 'servicio', 'descripcion' => 'Mantenimiento laptop', 'cantidad' => 1, 'precio_unitario' => 500, 'costo_unitario' => 100],
        ['tipo' => 'refaccion', 'descripcion' => 'SSD 1TB', 'cantidad' => 1, 'precio_unitario' => 1000, 'costo_unitario' => 650],
    ], $datos['admin']);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect();
    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect();

    $orden = $cotizacion->fresh('ordenServicio')->ordenServicio;
    expect($orden)->not->toBeNull()
        ->and(OrdenServicio::count())->toBe(1)
        ->and($orden->detalles)->toHaveCount(2)
        ->and($orden->refacciones)->toHaveCount(1)
        ->and((float) $orden->total_cliente)->toBe(2100.0)
        ->and((float) $orden->utilidad_estimada)->toBe(1230.0);

    $detalleExacto = $orden->detalles()->where('descripcion', 'Servicio de mantenimiento')->firstOrFail();
    $detalleAdHoc = $orden->detalles()->where('descripcion', 'Mantenimiento laptop')->firstOrFail();
    $refaccion = $orden->refacciones()->firstOrFail();

    expect($detalleExacto->servicio_id)->toBe($servicioExacto->id)
        ->and((float) $detalleExacto->costo_unitario)->toBe(60.0)
        ->and($detalleAdHoc->servicio_id)->toBeNull()
        ->and($detalleAdHoc->descripcion)->toBe('Mantenimiento laptop')
        ->and((float) $refaccion->costo_unitario)->toBe(650.0)
        ->and($detalleExacto->cotizacion_item_id)->not->toBeNull()
        ->and($refaccion->cotizacion_item_id)->not->toBeNull();
});

test('socio logistico no puede reprocesar una cotizacion ya aceptada', function () {
    $datos = datosCotizacion();
    $partner = Partner::create(['nombre' => 'Logística protegida', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $partner->id, 'email_verified_at' => now()]);
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
    ], itemsCotizacion(), $logistico);
    $cotizacion->update(['estado' => 'aceptada']);

    $this->actingAs($logistico)
        ->post(route('logistica.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertSessionHasErrors('estado');

    expect(OrdenServicio::count())->toBe(0);
});

test('socio tecnico no puede acceder a cotizaciones', function () {
    $partner = Partner::create(['nombre' => 'Fixop', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'partner_id' => $partner->id, 'email_verified_at' => now()]);

    $this->actingAs($tecnico)->get('/admin/cotizaciones')->assertForbidden();
    $this->actingAs($tecnico)->get('/logistica/cotizaciones')->assertForbidden();
});

test('socio logistico solo ve cotizaciones de su partner', function () {
    $datos = datosCotizacion();
    $partner = Partner::create(['nombre' => 'Electrocom', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $partner->id, 'email_verified_at' => now()]);

    $ajena = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], itemsCotizacion(), $datos['admin']);

    $this->actingAs($logistico)
        ->get(route('logistica.cotizaciones.index'))
        ->assertOk()
        ->assertDontSee($ajena->folio);

    $this->actingAs($logistico)
        ->get(route('logistica.cotizaciones.show', $ajena))
        ->assertNotFound();
});

test('la cotizacion guarda la recepcion a domicilio con direccion', function () {
    $datos = datosCotizacion();

    $this->actingAs($datos['admin'])->post(route('admin.cotizaciones.store'), [
        'cliente_id' => $datos['cliente']->id,
        'fecha' => today()->format('Y-m-d'),
        'tipo_recepcion' => 'recogido_a_domicilio',
        'direccion_recepcion' => 'Av. Aguascalientes 123, Col. Centro',
        'items' => itemsCotizacion(),
    ])->assertRedirect();

    $cotizacion = Cotizacion::firstOrFail();
    expect($cotizacion->tipo_recepcion)->toBe('recogido_a_domicilio')
        ->and($cotizacion->direccion_recepcion)->toBe('Av. Aguascalientes 123, Col. Centro')
        ->and($cotizacion->esRecogidaADomicilio())->toBeTrue();
});

test('la recepcion a domicilio requiere direccion', function () {
    $datos = datosCotizacion();

    $this->actingAs($datos['admin'])->post(route('admin.cotizaciones.store'), [
        'cliente_id' => $datos['cliente']->id,
        'fecha' => today()->format('Y-m-d'),
        'tipo_recepcion' => 'recogido_a_domicilio',
        'items' => itemsCotizacion(),
    ])->assertSessionHasErrors('direccion_recepcion');

    expect(Cotizacion::count())->toBe(0);
});

test('sin tipo de recepcion la cotizacion queda como en negocio', function () {
    $datos = datosCotizacion();

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], itemsCotizacion(), $datos['admin']);

    expect($cotizacion->tipo_recepcion)->toBe('en_negocio')
        ->and($cotizacion->direccion_recepcion)->toBeNull()
        ->and($cotizacion->etiquetaRecepcion())->toBe('En negocio');
});

test('la plantilla muestra la ubicacion de recepcion y los datos del negocio', function () {
    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'tipo_recepcion' => 'recogido_a_domicilio',
        'direccion_recepcion' => 'Calle Ficticia 45, Aguascalientes',
    ], itemsCotizacion(), $datos['admin']);

    $this->actingAs($datos['admin'])
        ->get(route('admin.cotizaciones.plantilla', $cotizacion))
        ->assertOk()
        ->assertSee('Recogido a domicilio')
        ->assertSee('Calle Ficticia 45, Aguascalientes')
        ->assertSee(config('negocio.telefono'));
});

test('la ruta de PNG descarga una imagen', function () {
    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
    ], itemsCotizacion(), $datos['admin']);

    $this->mock(ExportarCotizacionPng::class, function ($mock) use ($cotizacion) {
        $mock->shouldReceive('generar')->once()->andReturn("\x89PNG\r\n\x1a\nfake");
        $mock->shouldReceive('nombreArchivo')->andReturn('cotizacion-'.$cotizacion->folio.'.png');
    });

    $response = $this->actingAs($datos['admin'])
        ->get(route('admin.cotizaciones.png', $cotizacion));

    $response->assertOk()->assertHeader('content-type', 'image/png');
    expect($response->headers->get('content-disposition'))->toContain('.png');
});

test('la exportacion PNG real genera una imagen valida', function () {
    $exportador = app(ExportarCotizacionPng::class);

    if (! $exportador->navegadorDisponible()) {
        $this->markTestSkipped('No hay navegador headless (Edge/Chrome) disponible en este entorno.');
    }

    $datos = datosCotizacion();
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'tipo_recepcion' => 'recogido_a_domicilio',
        'direccion_recepcion' => 'Av. Prueba 1, Aguascalientes',
    ], itemsCotizacion(), $datos['admin']);

    $imagen = $exportador->generar($cotizacion);

    expect(substr($imagen, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(strlen($imagen))->toBeGreaterThan(10000);
});
