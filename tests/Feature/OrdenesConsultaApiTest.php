<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OpenClawOrderChange;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ordenParaConsulta(string $passwordEquipo = '1234', ?string $notas = null): OrdenServicio
{
    $cliente = Cliente::create(['nombre' => 'Román Barrera', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo',
        'modelo' => 'ThinkPad T490', 'password_equipo' => $passwordEquipo,
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'tipo_recepcion' => 'directo',
        'costo_tecnico' => 0,
        'notas' => $notas ?? "Falla reportada:\nNo enciende",
    ], [], [], $admin);
}

function partnerLogisticoConsulta(string $nombre = 'Electrocom Alameda'): Partner
{
    return Partner::create(['nombre' => $nombre, 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);
}

test('consulta una orden por folio sin exponer la contraseña', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta('SECRETO-1111');

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson("/api/internal/service-orders/{$orden->folio}");

    $response->assertOk()
        ->assertJsonPath('folio', $orden->folio)
        ->assertJsonPath('cliente.nombre', 'Román Barrera')
        ->assertJsonPath('equipo.marca', 'Lenovo')
        ->assertJsonPath('equipo.password_registrada', true)
        ->assertJsonPath('estado', 'recibido')
        ->assertJsonPath('estado_label', 'Recibido')
        ->assertJsonPath('falla_reportada', 'No enciende');

    expect($response->getContent())->not->toContain('SECRETO-1111');
});

test('consulta una orden por id numérico', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();

    $this->withToken('token-secreto-pruebas')
        ->getJson("/api/internal/service-orders/{$orden->id}")
        ->assertOk()
        ->assertJsonPath('id', $orden->id)
        ->assertJsonPath('folio', $orden->folio);
});

test('la consulta responde 404 si la orden no existe y 401 sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();

    $this->getJson("/api/internal/service-orders/{$orden->folio}")->assertUnauthorized();

    $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/service-orders/OS-29990101-9999')
        ->assertNotFound();
});

test('corrige nombre y teléfono del cliente relacionado sin duplicarlo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'cliente' => ['nombre' => 'Pedro Medina', 'telefono' => '4499998877'],
            'external_id' => 'telegram-fix-cliente-1',
        ]);

    $response->assertOk()
        ->assertJsonPath('aplicado', true)
        ->assertJsonPath('cliente.nombre', 'Pedro Medina')
        ->assertJsonPath('cliente.telefono', '4499998877');

    expect(Cliente::count())->toBe(1)
        ->and($orden->fresh()->cliente->nombre)->toBe('Pedro Medina')
        ->and($orden->fresh()->notas)->toContain('Corrección vía OpenClaw');
});

test('corrige tipo, marca y modelo del equipo relacionado', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'equipo' => ['tipo_equipo' => 'Desktop', 'marca' => 'Dell', 'modelo' => 'OptiPlex 7080'],
        ])
        ->assertOk()
        ->assertJsonPath('equipo.marca', 'Dell')
        ->assertJsonPath('equipo.modelo', 'OptiPlex 7080');

    $equipo = $orden->fresh()->equipo;
    expect($equipo->tipo_equipo)->toBe('Desktop')
        ->and(Equipo::count())->toBe(1);
});

test('registra password_equipo y solo devuelve password_registrada', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta('');

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'equipo' => ['password_equipo' => 'CLAVE-SECRETA-99'],
        ]);

    $response->assertOk()->assertJsonPath('equipo.password_registrada', true);

    expect($response->getContent())->not->toContain('CLAVE-SECRETA-99')
        ->and($orden->fresh()->equipo->password_equipo)->toBe('CLAVE-SECRETA-99')
        ->and($orden->fresh()->notas)->not->toContain('CLAVE-SECRETA-99');
});

test('corrige la falla reportada y la consulta refleja la corrección', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'recepcion' => ['falla_reportada' => 'Marco dañado', 'folio_externo' => '4946'],
        ])
        ->assertOk()
        ->assertJsonPath('aplicado', true);

    $this->withToken('token-secreto-pruebas')
        ->getJson("/api/internal/service-orders/{$orden->folio}")
        ->assertOk()
        ->assertJsonPath('falla_reportada', 'Marco dañado');
});

test('corrige el partner logístico por nombre aproximado', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $partner = partnerLogisticoConsulta();
    $orden = ordenParaConsulta();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'partner_logistico' => 'alameda',
        ])
        ->assertOk()
        ->assertJsonPath('partner_recepcion', 'Electrocom Alameda');

    expect($orden->fresh()->partner_recepcion_id)->toBe($partner->id);
});

test('devuelve warning y no falla cuando el partner es ambiguo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    partnerLogisticoConsulta('Electrocom Alameda');
    partnerLogisticoConsulta('Electrocom Centro');
    $orden = ordenParaConsulta();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'partner_logistico' => 'Electrocom',
        ]);

    $response->assertOk();
    expect(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'más de un partner')))->toBeTrue()
        ->and($orden->fresh()->partner_recepcion_id)->toBeNull();
});

test('la corrección de perfil es idempotente con external_id', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();

    $payload = [
        'cliente' => ['nombre' => 'Pedro Medina'],
        'external_id' => 'telegram-fix-dup',
    ];

    $primera = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", $payload);
    $segunda = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", $payload);

    $primera->assertOk()->assertJsonPath('aplicado', true);
    $segunda->assertOk()->assertJsonPath('aplicado', false)->assertJsonPath('duplicado', true);

    expect(OpenClawOrderChange::where('external_id', 'telegram-fix-dup')->count())->toBe(1)
        ->and(substr_count((string) $orden->fresh()->notas, 'Corrección vía OpenClaw'))->toBe(1);
});

test('una orden con finanzas cerradas rechaza correcciones de perfil con 409', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $orden = ordenParaConsulta();
    $orden->update(['estado' => 'entregado', 'finanzas_generadas' => true]);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/profile", [
            'cliente' => ['nombre' => 'Pedro Medina'],
        ])
        ->assertStatus(409);

    expect($orden->fresh()->cliente->nombre)->toBe('Román Barrera');
});
