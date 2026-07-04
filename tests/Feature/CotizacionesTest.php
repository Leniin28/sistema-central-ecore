<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

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
