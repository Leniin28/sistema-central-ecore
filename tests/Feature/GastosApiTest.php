<?php

use App\Models\MovimientoFinanciero;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registra un gasto operativo como egreso manual', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/expenses', [
            'descripcion' => 'Compra USB 16GB',
            'monto' => 50,
            'categoria' => 'refaccion',
            'proveedor' => 'Mercado Libre',
            'fecha' => today()->toDateString(),
            'notas' => 'Registrado por Telegram',
            'external_id' => 'telegram-expense-1',
        ]);

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('tipo', 'egreso')
        ->assertJsonPath('categoria', 'refaccion')
        ->assertJsonPath('monto', 50)
        ->assertJsonPath('afecta_corte', true);

    $movimiento = MovimientoFinanciero::first();
    expect($movimiento->orden_servicio_id)->toBeNull()
        ->and($movimiento->descripcion)->toContain('Mercado Libre')
        ->and($movimiento->external_id)->toBe('telegram-expense-1');
});

test('el registro de gastos es idempotente con external_id', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $payload = [
        'descripcion' => 'Gasolina reparto', 'monto' => 200, 'categoria' => 'gasolina',
        'external_id' => 'telegram-expense-dup',
    ];

    $primera = $this->withToken('token-secreto-pruebas')->postJson('/api/internal/expenses', $payload);
    $segunda = $this->withToken('token-secreto-pruebas')->postJson('/api/internal/expenses', $payload);

    $primera->assertCreated()->assertJsonPath('created', true);
    $segunda->assertOk()->assertJsonPath('created', false);

    expect(MovimientoFinanciero::count())->toBe(1);
});

test('mapea las categorías de OpenClaw a las del panel', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/expenses', [
            'descripcion' => 'Envío de refacción', 'monto' => 120, 'categoria' => 'envio',
        ])
        ->assertCreated()
        ->assertJsonPath('categoria', 'transporte')
        ->assertJsonPath('categoria_openclaw', 'envio');
});

test('rechaza categorías no permitidas', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/expenses', [
            'descripcion' => 'Otro gasto', 'monto' => 10, 'categoria' => 'nomina',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['categoria']);
});

test('lista los gastos operativos por fecha sin incluir egresos de órdenes', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    MovimientoFinanciero::create([
        'tipo' => 'egreso', 'categoria' => 'gasolina', 'monto' => 200,
        'descripcion' => 'Gasolina', 'fecha' => today(),
    ]);
    // Egreso ligado a una orden: no debe aparecer en gastos operativos.
    $cliente = \App\Models\Cliente::create(['nombre' => 'Cliente X', 'telefono' => '449', 'tipo_cliente' => 'mantenimiento']);
    $equipo = \App\Models\Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);
    $admin = \App\Models\User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $orden = app(\App\Actions\Ordenes\CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id, 'equipo_id' => $equipo->id, 'tipo_recepcion' => 'directo', 'costo_tecnico' => 0,
    ], [], [], $admin);
    MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id, 'tipo' => 'egreso', 'categoria' => 'refaccion',
        'monto' => 300, 'descripcion' => 'Refacción de orden', 'fecha' => today(),
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/expenses?date='.today()->toDateString());

    $response->assertOk()->assertJsonPath('total', 200);
    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.categoria'))->toBe('gasolina');
});

test('el gasto operativo aparece en los egresos reales del corte', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/expenses', [
            'descripcion' => 'Herramienta nueva', 'monto' => 350, 'categoria' => 'herramienta',
        ])->assertCreated();

    $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/reports/cash-cut?period=daily')
        ->assertOk()
        ->assertJsonPath('egresos', 350);
});

test('los gastos exigen token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->postJson('/api/internal/expenses', ['descripcion' => 'X', 'monto' => 1, 'categoria' => 'otro'])
        ->assertUnauthorized();
    $this->getJson('/api/internal/expenses')->assertUnauthorized();
});
