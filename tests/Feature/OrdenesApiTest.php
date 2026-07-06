<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OpenClawOrderChange;
use App\Models\OrdenServicio;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ordenParaCambios(string $passwordEquipo = '9999'): OrdenServicio
{
    $cliente = Cliente::create(['nombre' => 'Román Barrera', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP', 'password_equipo' => $passwordEquipo,
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'tipo_recepcion' => 'directo',
        'costo_tecnico' => 0,
        'notas' => 'Orden base',
    ], [], [], $admin);
}

function servicioCatalogo(string $nombre, float $precio): Servicio
{
    $categoria = CategoriaServicio::firstOrCreate(['nombre' => 'General']);

    return Servicio::create([
        'categoria_servicio_id' => $categoria->id, 'nombre' => $nombre, 'precio_base' => $precio, 'activo' => true,
    ]);
}

test('el endpoint de cambios rechaza peticiones sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $this->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
        'refacciones' => [['descripcion' => 'USB 16GB', 'precio_cliente' => 80]],
    ])->assertUnauthorized();
});

test('el endpoint de cambios responde 404 si la orden no existe', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/service-orders/OS-29990101-9999/changes', [
            'refacciones' => [['descripcion' => 'USB 16GB', 'precio_cliente' => 80]],
        ])
        ->assertNotFound();
});

test('agrega una refacción real USB 16GB identificando la orden por folio', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'refacciones' => [[
                'descripcion' => 'USB 16GB', 'cantidad' => 1, 'costo_unitario' => 50, 'precio_cliente' => 80,
                'notas' => 'Solicitado por Telegram',
            ]],
            'notas' => 'Agregar USB de 16GB',
            'external_id' => 'telegram-edit-usb-1',
        ]);

    $response->assertOk()
        ->assertJsonPath('aplicado', true)
        ->assertJsonPath('folio', $orden->folio)
        ->assertJsonPath('total_cliente', 80);

    expect($response->json('refacciones_agregadas'))->toHaveCount(1);

    $orden->refresh();
    expect($orden->refacciones()->count())->toBe(1)
        ->and((float) $orden->total_cliente)->toBe(80.0)
        ->and($orden->notas)->toContain('Agregar USB de 16GB');
});

test('agrega un servicio real por servicio_id identificando la orden por id', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();
    $servicio = servicioCatalogo('Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->id}/changes", [
            'servicios' => [['servicio_id' => $servicio->id, 'cantidad' => 1]],
            'external_id' => 'telegram-edit-serv-id',
        ]);

    $response->assertOk()->assertJsonPath('aplicado', true);
    expect($response->json('servicios_agregados'))->toHaveCount(1);

    $orden->refresh();
    expect($orden->detalles()->count())->toBe(1)
        ->and((float) $orden->total_cliente)->toBe(550.0);
});

test('agrega un servicio real por nombre cuando el match es claro', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();
    servicioCatalogo('Servicio de Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'servicios' => [['descripcion' => 'optimización', 'precio_cliente' => 550]],
            'external_id' => 'telegram-edit-serv-nombre',
        ]);

    $response->assertOk();
    expect($response->json('servicios_agregados'))->toHaveCount(1);
    expect($orden->fresh()->detalles()->count())->toBe(1);
});

test('un servicio sin match no crea línea falsa y devuelve warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();
    servicioCatalogo('Servicio de Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'servicios' => [['descripcion' => 'Reparación de placa madre nivel avanzado']],
            'external_id' => 'telegram-edit-nomatch',
        ]);

    $response->assertOk();
    expect($response->json('servicios_agregados'))->toHaveCount(0)
        ->and(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'No se encontró en el catálogo')))->toBeTrue()
        ->and($orden->fresh()->detalles()->count())->toBe(0);
});

test('no duplica la operación si se repite el external_id', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $payload = [
        'refacciones' => [['descripcion' => 'USB 16GB', 'costo_unitario' => 50, 'precio_cliente' => 80]],
        'external_id' => 'telegram-edit-dup',
    ];

    $primera = $this->withToken('token-secreto-pruebas')->postJson("/api/internal/service-orders/{$orden->folio}/changes", $payload);
    $segunda = $this->withToken('token-secreto-pruebas')->postJson("/api/internal/service-orders/{$orden->folio}/changes", $payload);

    $primera->assertOk()->assertJsonPath('aplicado', true);
    $segunda->assertOk()->assertJsonPath('aplicado', false)->assertJsonPath('duplicado', true);

    expect($orden->fresh()->refacciones()->count())->toBe(1)
        ->and(OpenClawOrderChange::where('external_id', 'telegram-edit-dup')->count())->toBe(1);
});

test('el endpoint de cambios no expone password_equipo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios('SECRETO-4242');

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'refacciones' => [['descripcion' => 'USB 16GB', 'precio_cliente' => 80]],
        ]);

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRETO-4242');
});

test('el endpoint de cambios acepta el wrapper agregar.servicios y agregar.refacciones', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();
    $servicio = servicioCatalogo('Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'agregar' => [
                'servicios' => [['servicio_id' => $servicio->id]],
                'refacciones' => [['descripcion' => 'USB 16GB', 'costo_unitario' => 50, 'precio_cliente' => 80]],
            ],
            'external_id' => 'telegram-edit-wrapper',
        ]);

    $response->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('total_cliente', 630);
    expect($response->json('servicios_agregados'))->toHaveCount(1)
        ->and($response->json('refacciones_agregadas'))->toHaveCount(1);
});

test('cambia el estado por folio con token válido y registra historial con el usuario de sistema', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    config(['services.openclaw.system_user_email' => 'openclaw-bot@sistema.local']);
    $orden = ordenParaCambios();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", [
            'estado' => 'en_proceso',
            'notas' => 'Cambio solicitado por Telegram',
            'external_id' => 'telegram-status-1',
        ]);

    $response->assertOk()
        ->assertJsonPath('cambiado', true)
        ->assertJsonPath('estado_anterior', 'recibido')
        ->assertJsonPath('estado_actual', 'en_proceso')
        ->assertJsonPath('finanzas_generadas', false);

    $historial = HistorialEstado::where('orden_servicio_id', $orden->id)
        ->where('estado_nuevo', 'en_proceso')->first();
    $sistema = User::firstWhere('email', 'openclaw-bot@sistema.local');

    expect($historial)->not->toBeNull()
        ->and($historial->user_id)->toBe($sistema->id)
        ->and($historial->comentario)->toBe('Cambio solicitado por Telegram');
});

test('el endpoint de estado rechaza peticiones sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $this->postJson("/api/internal/service-orders/{$orden->folio}/status", ['estado' => 'en_proceso'])
        ->assertUnauthorized();

    expect($orden->fresh()->estado)->toBe('recibido');
});

test('el endpoint de estado rechaza un estado inválido con 422', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", ['estado' => 'en_reparacion'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);

    expect($orden->fresh()->estado)->toBe('recibido');
});

test('rechaza entregado sin confirm_final_delivery con 409 y no genera finanzas', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", [
            'estado' => 'entregado',
            'external_id' => 'telegram-status-noconfirm',
        ]);

    $response->assertStatus(409)->assertJsonPath('requires_confirmation', true);

    $orden->refresh();
    expect($orden->estado)->toBe('recibido')
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0)
        // El intento fallido no consume el external_id: el reintento con confirm debe poder usarlo.
        ->and(OpenClawOrderChange::where('external_id', 'telegram-status-noconfirm')->exists())->toBeFalse();
});

test('permite entregado con confirm_final_delivery=true y genera finanzas con el flujo existente', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    // Refacción real primero (total 80, costo 50) para que las finanzas tengan montos.
    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'refacciones' => [['descripcion' => 'USB 16GB', 'costo_unitario' => 50, 'precio_cliente' => 80]],
        ])->assertOk();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", [
            'estado' => 'entregado',
            'notas' => 'Entrega confirmada por Telegram',
            'external_id' => 'telegram-status-deliver',
            'confirm_final_delivery' => true,
        ]);

    $response->assertOk()
        ->assertJsonPath('cambiado', true)
        ->assertJsonPath('estado_actual', 'entregado')
        ->assertJsonPath('finanzas_generadas', true)
        ->assertJsonPath('total_cliente', 80);

    $orden->refresh();
    expect($orden->finanzas_generadas)->toBeTrue()
        ->and($orden->fecha_entrega)->not->toBeNull()
        // Flujo existente de finanzas: ingreso (80) + egreso refacción (50).
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(2)
        ->and((float) $orden->utilidad_neta)->toBe(30.0);
});

test('el cambio de estado es idempotente con external_id y no duplica historial ni finanzas', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $payload = [
        'estado' => 'entregado',
        'external_id' => 'telegram-status-retry',
        'confirm_final_delivery' => true,
    ];

    $primera = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", $payload);
    $segunda = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", $payload);

    $primera->assertOk()->assertJsonPath('cambiado', true);
    $segunda->assertOk()->assertJsonPath('cambiado', false)->assertJsonPath('duplicado', true);

    expect(HistorialEstado::where('orden_servicio_id', $orden->id)->where('estado_nuevo', 'entregado')->count())->toBe(1)
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())
        ->toBe(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count()); // estable entre llamadas

    // Conteo absoluto: solo el ingreso de la primera entrega (total 0, sin refacciones ni partners).
    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(1);
});

test('una orden ya entregada rechaza otro cambio de estado con 409', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();
    $orden->update(['estado' => 'entregado', 'finanzas_generadas' => true]);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", [
            'estado' => 'en_proceso',
        ])
        ->assertStatus(409)
        ->assertJsonPath('requires_confirmation', false);
});

test('repetir el mismo estado responde sin cambio y con warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", ['estado' => 'recibido']);

    $response->assertOk()->assertJsonPath('cambiado', false)->assertJsonPath('duplicado', false);
    expect($response->json('warnings'))->not->toBeEmpty();
});

test('el endpoint de estado no expone password_equipo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios('SECRETO-7777');

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", ['estado' => 'en_diagnostico']);

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRETO-7777');
});

test('una orden con finanzas cerradas rechaza cambios con 409', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaCambios();
    $orden->update(['estado' => 'entregado', 'finanzas_generadas' => true]);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/changes", [
            'refacciones' => [['descripcion' => 'USB 16GB', 'precio_cliente' => 80]],
        ])
        ->assertStatus(409);

    expect($orden->fresh()->refacciones()->count())->toBe(0);
});
