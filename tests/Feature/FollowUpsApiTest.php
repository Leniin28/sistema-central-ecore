<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ordenParaSeguimiento(array $atributos = []): OrdenServicio
{
    $cliente = Cliente::create(['nombre' => 'Cliente Seguimiento', 'telefono' => '4490003344', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP', 'password_equipo' => 'SECRETO-FUP']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id, 'equipo_id' => $equipo->id,
        'tipo_recepcion' => 'directo', 'costo_tecnico' => 0,
    ], [], [], $admin);

    if ($atributos !== []) {
        $orden->update($atributos);
    }

    return $orden->fresh();
}

test('detecta una orden lista para entregar desde hace días', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaSeguimiento(['estado' => 'listo_para_entregar']);
    $manana = today()->addDays(2)->toDateString();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson("/api/internal/follow-ups?type=orders&date={$manana}");

    $response->assertOk();
    $item = collect($response->json('items'))->first(fn ($i) => str_contains($i['reason'], 'Lista para entregar'));
    expect($item)->not->toBeNull()
        ->and($item['folio'])->toBe($orden->folio)
        ->and($item['suggested_action'])->toContain('mensaje al cliente');
});

test('detecta cotizaciones pendientes sin respuesta', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cliente = Cliente::create(['nombre' => 'Cliente Cot', 'telefono' => '4495556677', 'tipo_cliente' => 'mantenimiento']);
    Cotizacion::create([
        'folio' => 'COT-TEST-0002', 'cliente_id' => $cliente->id, 'fecha' => today()->subDays(5),
        'estado' => 'enviada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => 1350,
        'descuento' => 0, 'anticipo' => 0, 'total' => 1350, 'saldo' => 1350,
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/follow-ups?type=quotes');

    $response->assertOk();
    $item = collect($response->json('items'))->first(fn ($i) => $i['type'] === 'quote');
    expect($item)->not->toBeNull()
        ->and($item['folio'])->toBe('COT-TEST-0002')
        ->and($item['reason'])->toContain('5 días');
});

test('detecta órdenes sin técnico asignado', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaSeguimiento(['estado' => 'en_proceso']);

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/follow-ups?type=orders');

    $response->assertOk();
    $item = collect($response->json('items'))->first(fn ($i) => $i['reason'] === 'Sin técnico asignado');
    expect($item)->not->toBeNull()
        ->and($item['folio'])->toBe($orden->folio);
});

test('detecta refacciones sin costo o precio', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaSeguimiento(['estado' => 'en_proceso']);
    $orden->refacciones()->create([
        'descripcion' => 'Pantalla 14"', 'cantidad' => 1, 'costo_unitario' => 0,
        'precio_unitario_cliente' => 0, 'costo_total' => 0, 'precio_total_cliente' => 0, 'utilidad_refaccion' => 0,
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/follow-ups?type=orders');

    $response->assertOk();
    $item = collect($response->json('items'))->first(fn ($i) => str_contains($i['reason'], 'Refacciones sin costo o precio'));
    expect($item)->not->toBeNull()
        ->and($item['reason'])->toContain('Pantalla 14"');
});

test('los follow-ups no exponen password_equipo y exigen token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    ordenParaSeguimiento(['estado' => 'listo_para_entregar']);

    $this->getJson('/api/internal/follow-ups')->assertUnauthorized();

    $response = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/follow-ups');
    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRETO-FUP');
});

test('overdue_days ajusta el umbral de detección', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    ordenParaSeguimiento(['estado' => 'listo_para_entregar']);

    // Hoy mismo (0 días): con umbral por defecto (1 día) no aparece...
    $sinItems = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/follow-ups?type=orders&estado=listo_para_entregar');
    expect(collect($sinItems->json('items'))->filter(fn ($i) => str_contains($i['reason'], 'Lista para entregar')))->toBeEmpty();

    // ...pero con overdue_days=0 sí.
    $conItems = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/follow-ups?type=orders&estado=listo_para_entregar&overdue_days=0');
    expect(collect($conItems->json('items'))->filter(fn ($i) => str_contains($i['reason'], 'Lista para entregar')))->not->toBeEmpty();
});
