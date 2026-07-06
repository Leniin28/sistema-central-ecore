<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function escenarioBusqueda(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $partner = Partner::create(['nombre' => 'Electrocom Alameda', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);

    $roman = Cliente::create(['nombre' => 'Román Barrera', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);
    $pedro = Cliente::create(['nombre' => 'Pedro Medina', 'telefono' => '4497654321', 'tipo_cliente' => 'mantenimiento']);

    $equipoRoman = Equipo::create([
        'cliente_id' => $roman->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo',
        'modelo' => 'ThinkPad T490', 'password_equipo' => 'SECRETO-BUSQUEDA',
    ]);
    $equipoPedro = Equipo::create(['cliente_id' => $pedro->id, 'tipo_equipo' => 'Desktop', 'marca' => 'Dell']);

    $crear = app(CrearOrdenServicio::class);
    $ordenRoman = $crear->ejecutar([
        'cliente_id' => $roman->id, 'equipo_id' => $equipoRoman->id,
        'partner_recepcion_id' => $partner->id, 'tipo_recepcion' => 'sucursal', 'costo_tecnico' => 0,
    ], [], [], $admin);
    $ordenRoman->update(['estado' => 'listo_para_entregar', 'total_cliente' => 1430]);

    $ordenPedro = $crear->ejecutar([
        'cliente_id' => $pedro->id, 'equipo_id' => $equipoPedro->id,
        'tipo_recepcion' => 'directo', 'costo_tecnico' => 0,
    ], [], [], $admin);

    return [$roman, $pedro, $ordenRoman, $ordenPedro, $partner];
}

test('busca por nombre de cliente en todos los tipos', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    [, , $ordenRoman] = escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?q=Barrera&type=all');

    $response->assertOk();
    expect($response->json('clientes'))->toHaveCount(1)
        ->and($response->json('clientes.0.nombre'))->toBe('Román Barrera')
        ->and($response->json('ordenes'))->toHaveCount(1)
        ->and($response->json('ordenes.0.folio'))->toBe($ordenRoman->folio)
        ->and($response->json('ordenes.0.show_url'))->toContain('ordenes-servicio');
});

test('busca por teléfono', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?q=4497654321&type=clients');

    $response->assertOk();
    expect($response->json('clientes'))->toHaveCount(1)
        ->and($response->json('clientes.0.nombre'))->toBe('Pedro Medina');
});

test('busca una orden por folio exacto', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    [, , $ordenRoman] = escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?q='.$ordenRoman->folio.'&type=orders');

    $response->assertOk();
    expect($response->json('ordenes'))->toHaveCount(1)
        ->and($response->json('ordenes.0.total_cliente'))->toBe(1430)
        ->and($response->json('ordenes.0.sucursal'))->toBe('Electrocom Alameda');
});

test('filtra órdenes listas para entregar por estado', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    [, , $ordenRoman] = escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?estado=listo_para_entregar&type=orders');

    $response->assertOk();
    expect($response->json('ordenes'))->toHaveCount(1)
        ->and($response->json('ordenes.0.folio'))->toBe($ordenRoman->folio)
        ->and($response->json('ordenes.0.estado_label'))->toBe('Listo para entregar');
});

test('un estado inexistente devuelve warning y cero órdenes', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?estado=reparando&type=orders');

    $response->assertOk();
    expect($response->json('ordenes'))->toHaveCount(0)
        ->and($response->json('warnings'))->not->toBeEmpty();
});

test('filtra órdenes por sucursal/partner', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    [, , $ordenRoman] = escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?partner=Alameda&type=orders');

    $response->assertOk();
    expect($response->json('ordenes'))->toHaveCount(1)
        ->and($response->json('ordenes.0.folio'))->toBe($ordenRoman->folio);
});

test('la búsqueda nunca expone password_equipo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    escenarioBusqueda();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?q=Barrera&type=all');

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRETO-BUSQUEDA');
});

test('aplica limit y avisa cuando hay más resultados', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    foreach (range(1, 4) as $i) {
        Cliente::create(['nombre' => "Cliente Prueba {$i}", 'telefono' => "44900000{$i}0", 'tipo_cliente' => 'mantenimiento']);
    }

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/search?q=Cliente Prueba&type=clients&limit=2');

    $response->assertOk();
    expect($response->json('clientes'))->toHaveCount(2)
        ->and(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'más de 2 clientes')))->toBeTrue();
});

test('la búsqueda exige token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->getJson('/api/internal/search?q=Roman')->assertUnauthorized();
});
