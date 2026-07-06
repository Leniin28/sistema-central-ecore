<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ordenParaMensaje(array $atributos = [], ?string $telefono = '4491112233'): OrdenServicio
{
    $cliente = Cliente::create(['nombre' => 'Román Barrera', 'telefono' => $telefono ?? 'Sin teléfono', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo',
        'modelo' => 'ThinkPad T490', 'password_equipo' => 'SECRETO-MSG',
    ]);
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

test('genera plantilla listo_para_entregar con total y sucursal', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $partner = Partner::create(['nombre' => 'Electrocom Alameda', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);
    $orden = ordenParaMensaje([
        'estado' => 'listo_para_entregar', 'total_cliente' => 1430, 'partner_recepcion_id' => $partner->id,
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/message-template", [
            'tipo' => 'estado', 'estado' => 'listo_para_entregar',
            'tono' => 'amable', 'incluir_total' => true, 'incluir_sucursal' => true,
        ]);

    $response->assertOk();
    $mensaje = $response->json('message');
    expect($mensaje)->toContain('Hola Román')
        ->toContain('Laptop Lenovo ThinkPad T490')
        ->toContain('listo para entrega')
        ->toContain('1,430.00')
        ->toContain('Electrocom Alameda');
    expect($response->json('warnings'))->toBeEmpty();
});

test('genera plantilla de estado recibido', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaMensaje();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/message-template", [
            'tipo' => 'estado', 'estado' => 'recibido',
        ]);

    $response->assertOk();
    expect($response->json('message'))->toContain('Recibimos tu Laptop')
        ->toContain($orden->folio);
});

test('genera mensaje manual con la instrucción tal cual', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaMensaje();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/message-template", [
            'tipo' => 'manual',
            'instruccion' => 'ya quedó lista y puedes pasar por ella hoy antes de las 7',
        ]);

    $response->assertOk();
    expect($response->json('message'))->toContain('ya quedó lista y puedes pasar por ella hoy antes de las 7')
        ->toContain($orden->folio);
});

test('el mensaje nunca incluye password_equipo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaMensaje();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/message-template", [
            'tipo' => 'estado', 'estado' => 'en_proceso',
        ]);

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRETO-MSG');
});

test('avisa cuando faltan teléfono, total o sucursal', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaMensaje(['estado' => 'listo_para_entregar'], null);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/message-template", [
            'tipo' => 'estado', 'estado' => 'listo_para_entregar',
            'incluir_total' => true, 'incluir_sucursal' => true,
        ]);

    $response->assertOk();
    $warnings = collect($response->json('warnings'));
    expect($warnings->contains(fn ($w) => str_contains($w, 'teléfono')))->toBeTrue()
        ->and($warnings->contains(fn ($w) => str_contains($w, 'total')))->toBeTrue()
        ->and($warnings->contains(fn ($w) => str_contains($w, 'sucursal')))->toBeTrue();
});

test('mensaje manual sin instruccion es 422', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaMensaje();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/message-template", ['tipo' => 'manual'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['instruccion']);
});

test('la plantilla responde 404 para orden inexistente y 401 sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaMensaje();

    $this->postJson("/api/internal/service-orders/{$orden->folio}/message-template", ['tipo' => 'estado'])
        ->assertUnauthorized();

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/service-orders/OS-29990101-9999/message-template', ['tipo' => 'estado'])
        ->assertNotFound();
});
