<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use App\Services\ExportarNotaRecepcionPng;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function datosNotaRecepcion(): array
{
    $partnerLogistico = Partner::create(['nombre' => 'LogÃ­stica nota', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);
    $partnerTecnico = Partner::create(['nombre' => 'TÃ©cnico nota', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'MarÃ­a LÃ³pez', 'telefono' => '4495551234', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Lenovo',
        'modelo' => 'ThinkPad T14',
        'numero_serie' => 'SN-12345',
        'accesorios_recibidos' => 'Cargador y funda',
        'estado_fisico_inicial' => 'Rayones leves en tapa',
    ]);
    $categoria = CategoriaServicio::create(['nombre' => 'DiagnÃ³stico']);
    $servicio = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'DiagnÃ³stico', 'precio_base' => 0, 'activo' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $partnerLogistico->id, 'email_verified_at' => now()]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'partner_id' => $partnerTecnico->id, 'email_verified_at' => now()]);
    $ajeno = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => Partner::create(['nombre' => 'Ajeno', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true])->id, 'email_verified_at' => now()]);

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'partner_recepcion_id' => $partnerLogistico->id,
        'partner_tecnico_id' => $partnerTecnico->id,
        'tipo_recepcion' => 'domicilio',
        'costo_tecnico' => 0,
        'notas' => "Problema reportado:\nNo enciende al conectar el cargador.\n\nNotas internas:\nSe recoge en domicilio.",
    ], [[
        'servicio_id' => $servicio->id,
        'cantidad' => 1,
        'precio_unitario' => 0,
    ]], [], $admin);

    return compact('orden', 'admin', 'logistico', 'tecnico', 'ajeno');
}

test('usuario autorizado puede ver la nota de recepcion con datos de cliente, equipo, falla y firmas', function () {
    $datos = datosNotaRecepcion();

    $this->actingAs($datos['admin'])
        ->get(route('admin.ordenes-servicio.nota-recepcion', $datos['orden']))
        ->assertOk()
        ->assertSee('NOTA DE RECEPCIÃ“N')
        ->assertSee($datos['orden']->folio)
        ->assertSee('MarÃ­a LÃ³pez')
        ->assertSee('4495551234')
        ->assertSee('Laptop')
        ->assertSee('Lenovo')
        ->assertSee('ThinkPad T14')
        ->assertSee('SN-12345')
        ->assertSee('Cargador y funda')
        ->assertSee('Rayones leves en tapa')
        ->assertSee('No enciende al conectar el cargador.')
        ->assertSee('Se recoge en domicilio.')
        ->assertSee('RECIBE')
        ->assertSee('ENTREGA');
});

test('usuarios que pueden consultar la orden pueden acceder a su nota', function () {
    $datos = datosNotaRecepcion();

    $this->actingAs($datos['logistico'])
        ->get(route('logistica.ordenes-servicio.nota-recepcion', $datos['orden']))
        ->assertOk();

    $this->actingAs($datos['tecnico'])
        ->get(route('tecnico.ordenes-servicio.nota-recepcion', $datos['orden']))
        ->assertOk();
});

test('la vista de orden enlaza su nota de recepcion y sus descargas', function () {
    $datos = datosNotaRecepcion();

    $this->actingAs($datos['admin'])
        ->get(route('admin.ordenes-servicio.show', $datos['orden']))
        ->assertOk()
        ->assertSee(route('admin.ordenes-servicio.nota-recepcion', $datos['orden']))
        ->assertSee(route('admin.ordenes-servicio.nota-recepcion.pdf', $datos['orden']))
        ->assertSee(route('admin.ordenes-servicio.nota-recepcion.png', $datos['orden']));
});

test('usuario no autorizado no puede acceder a la nota de una orden ajena', function () {
    $datos = datosNotaRecepcion();

    $this->actingAs($datos['ajeno'])
        ->get(route('logistica.ordenes-servicio.nota-recepcion', $datos['orden']))
        ->assertForbidden();

    $this->actingAs($datos['ajeno'])
        ->get(route('logistica.ordenes-servicio.nota-recepcion.pdf', $datos['orden']))
        ->assertForbidden();
});

test('la ruta de PDF descarga la nota de recepcion', function () {
    $datos = datosNotaRecepcion();

    $response = $this->actingAs($datos['admin'])
        ->get(route('admin.ordenes-servicio.nota-recepcion.pdf', $datos['orden']));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('nota-recepcion-'.$datos['orden']->folio.'.pdf');
});

test('la ruta de PNG descarga la nota de recepcion', function () {
    $datos = datosNotaRecepcion();

    $this->mock(ExportarNotaRecepcionPng::class, function ($mock) use ($datos) {
        $mock->shouldReceive('generar')->once()->andReturn("\x89PNG\r\n\x1a\nfake");
        $mock->shouldReceive('nombreArchivo')->once()->andReturn('nota-recepcion-'.$datos['orden']->folio.'.png');
    });

    $response = $this->actingAs($datos['admin'])
        ->get(route('admin.ordenes-servicio.nota-recepcion.png', $datos['orden']));

    $response->assertOk()->assertHeader('content-type', 'image/png');
    expect($response->headers->get('content-disposition'))->toContain('nota-recepcion-'.$datos['orden']->folio.'.png');
});

test('la exportacion PNG real genera una imagen valida para la nota de recepcion', function () {
    $exportador = app(ExportarNotaRecepcionPng::class);

    if (! $exportador->navegadorDisponible()) {
        $this->markTestSkipped('No hay navegador headless (Edge/Chrome) disponible en este entorno.');
    }

    $datos = datosNotaRecepcion();
    $imagen = $exportador->generar($datos['orden']);

    expect(substr($imagen, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(strlen($imagen))->toBeGreaterThan(10000);
});
