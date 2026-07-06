<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function servicioParaCatalogo(string $nombre, float $precio, bool $activo = true, string $categoria = 'General'): Servicio
{
    $categoriaModelo = CategoriaServicio::firstOrCreate(['nombre' => $categoria]);

    return Servicio::create([
        'categoria_servicio_id' => $categoriaModelo->id,
        'nombre' => $nombre,
        'precio_base' => $precio,
        'activo' => $activo,
    ]);
}

function ordenParaCatalogo(): OrdenServicio
{
    $cliente = Cliente::create(['nombre' => 'Cliente Catálogo', 'telefono' => '4497770011', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id, 'equipo_id' => $equipo->id,
        'tipo_recepcion' => 'directo', 'costo_tecnico' => 0,
    ], [], [], $admin);
}

test('lista solo servicios activos con precio, categoría y aliases', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Servicio de Optimización', 550);
    servicioParaCatalogo('Cambio de bisagras', 700, activo: false);

    $response = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/services');

    $response->assertOk()->assertJsonPath('total', 1);
    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.nombre'))->toBe('Servicio de Optimización')
        ->and($response->json('items.0.precio_base'))->toBe(550)
        ->and($response->json('items.0.categoria'))->toBe('General')
        ->and($response->json('items.0.activo'))->toBeTrue()
        ->and($response->json('items.0.aliases'))->toContain('optimizacion');
});

test('active=false lista los servicios inactivos', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Servicio de Optimización', 550);
    servicioParaCatalogo('Cambio de bisagras', 700, activo: false);

    $response = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/services?active=false');

    $response->assertOk();
    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.nombre'))->toBe('Cambio de bisagras')
        ->and($response->json('items.0.activo'))->toBeFalse();
});

test('la búsqueda q es tolerante a mayúsculas y acentos', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Servicio de Optimización', 550);
    servicioParaCatalogo('Formateo e instalación', 450);

    $response = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/services?q=OPTIMIZACION');

    $response->assertOk();
    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.nombre'))->toBe('Servicio de Optimización');
});

test('q sin coincidencias devuelve lista vacía y warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Servicio de Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/services?q=bisagras');

    $response->assertOk();
    expect($response->json('items'))->toHaveCount(0)
        ->and($response->json('warnings'))->not->toBeEmpty();
});

test('filtra por categoría y aplica limit con warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Servicio de Optimización', 550, categoria: 'Mantenimiento');
    servicioParaCatalogo('Limpieza profunda', 350, categoria: 'Mantenimiento');
    servicioParaCatalogo('Diseño de logotipo', 900, categoria: 'Marketing');

    $porCategoria = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/services?category=mantenimiento');
    expect($porCategoria->json('items'))->toHaveCount(2);

    $conLimite = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/services?limit=1');
    expect($conLimite->json('items'))->toHaveCount(1)
        ->and($conLimite->json('warnings'))->not->toBeEmpty();
});

test('match devuelve confidence high con texto largo y candidato claro', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $servicio = servicioParaCatalogo('Servicio de Optimización', 550);
    servicioParaCatalogo('Formateo e instalación', 450);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/services/match', [
            'text' => 'optimización completa limpieza pasta termica sistema optimizado',
        ]);

    $response->assertOk()
        ->assertJsonPath('confidence', 'high')
        ->assertJsonPath('match.id', $servicio->id)
        ->assertJsonPath('match.precio_base', 550);

    expect($response->json('candidates'))->not->toBeEmpty();
});

test('match con varios candidatos similares es ambiguous y no elige', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Limpieza profunda laptop', 350);
    servicioParaCatalogo('Limpieza profunda desktop', 400);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/services/match', ['text' => 'limpieza profunda']);

    $response->assertOk()
        ->assertJsonPath('confidence', 'ambiguous')
        ->assertJsonPath('match', null);

    expect(count($response->json('candidates')))->toBeGreaterThanOrEqual(2)
        ->and($response->json('warnings'))->not->toBeEmpty();
});

test('match sin coincidencias devuelve null con warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Servicio de Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/services/match', ['text' => 'cambio de bisagras']);

    $response->assertOk()
        ->assertJsonPath('confidence', 'none')
        ->assertJsonPath('match', null);

    expect($response->json('warnings'))->not->toBeEmpty();
});

test('match ignora servicios desactivados', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioParaCatalogo('Cambio de bisagras', 700, activo: false);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/services/match', ['text' => 'cambio de bisagras']);

    $response->assertOk()->assertJsonPath('confidence', 'none')->assertJsonPath('match', null);
});

test('un servicio desactivado no se agrega a una orden vía changes y deja warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $inactivo = servicioParaCatalogo('Cambio de bisagras', 700, activo: false);
    $orden = ordenParaCatalogo();

    $porDescripcion = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'servicios' => [['descripcion' => 'Cambio de bisagras']],
        ]);
    $porId = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'servicios' => [['servicio_id' => $inactivo->id, 'descripcion' => 'Cambio de bisagras']],
        ]);

    $porDescripcion->assertOk();
    $porId->assertOk();
    expect($porDescripcion->json('servicios_agregados'))->toHaveCount(0)
        ->and($porId->json('servicios_agregados'))->toHaveCount(0)
        ->and($porId->json('warnings'))->not->toBeEmpty()
        ->and($orden->fresh()->detalles()->count())->toBe(0);
});

test('el catálogo exige token y match valida text', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->getJson('/api/internal/services')->assertUnauthorized();
    $this->postJson('/api/internal/services/match', ['text' => 'x'])->assertUnauthorized();

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/services/match', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['text']);
});
