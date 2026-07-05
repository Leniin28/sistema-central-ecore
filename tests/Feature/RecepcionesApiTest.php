<?php

use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function payloadRecepcionApi(array $overrides = []): array
{
    return array_replace_recursive([
        'cliente' => ['nombre' => 'José Luis Olvera', 'telefono' => '449 415 6210'],
        'equipo' => [
            'tipo_equipo' => 'Laptop',
            'marca' => 'HP',
            'modelo' => 'Laptop HP',
            'numero_serie' => null,
            'password_equipo' => '884960',
        ],
        'recepcion' => [
            'falla_reportada' => 'Le falla el lector y está lenta',
            'fecha_etiqueta' => '2026-06-29',
            'folio_externo' => '4837',
            'origen' => 'telegram_foto_etiqueta',
            'notas' => 'Datos extraídos de etiqueta física',
        ],
        'servicios' => [
            ['descripcion' => 'Servicio de Optimización', 'precio' => 550],
        ],
        'refacciones' => [
            ['descripcion' => 'SSD 120GB', 'precio' => 680],
        ],
        'notas' => 'Aclaraciones adicionales enviadas por Telegram',
        'external_id' => 'telegram-photo-001',
    ], $overrides);
}

test('la API de recepción rechaza peticiones sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->postJson('/api/internal/receptions', payloadRecepcionApi())
        ->assertUnauthorized();

    expect(OrdenServicio::count())->toBe(0);
});

test('la API de recepción rechaza token incorrecto', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-equivocado')
        ->postJson('/api/internal/receptions', payloadRecepcionApi())
        ->assertUnauthorized();
});

test('la API de recepción responde 403 si el token no está configurado', function () {
    config(['services.openclaw.internal_api_token' => null]);

    $this->withToken('cualquier-token')
        ->postJson('/api/internal/receptions', payloadRecepcionApi())
        ->assertForbidden();
});

test('la API de recepción crea cliente, equipo y orden con datos mínimos', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', [
            'cliente' => ['nombre' => 'Cliente Mínimo'],
            'equipo' => ['tipo_equipo' => 'PC de escritorio'],
            'recepcion' => ['falla_reportada' => 'No enciende'],
            'external_id' => 'telegram-photo-min',
        ]);

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('estado', 'recibido')
        ->assertJsonPath('cliente.nombre', 'Cliente Mínimo')
        ->assertJsonPath('equipo.tipo_equipo', 'PC de escritorio')
        ->assertJsonStructure(['id', 'folio', 'show_url', 'mensaje_resumen', 'warnings']);

    expect($response->json('folio'))->toStartWith('OS-');

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-min');
    expect($orden)->not->toBeNull()
        ->and((float) $orden->total_cliente)->toBe(0.0)
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and(Cliente::where('nombre', 'Cliente Mínimo')->exists())->toBeTrue()
        ->and(Equipo::where('tipo_equipo', 'PC de escritorio')->exists())->toBeTrue()
        // La falla se conserva en las notas de la orden.
        ->and($orden->notas)->toContain('No enciende');
});

test('la API de recepción acepta el payload completo con servicios y refacciones opcionales y devuelve warnings', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi());

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('origen', 'telegram_foto_etiqueta');

    // Servicios/refacciones de la etiqueta se guardan como texto sugerido, no como
    // líneas facturables: total 0 y se avisa por warnings.
    expect($response->json('warnings'))->toBeArray()->not->toBeEmpty();

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-001');
    expect((float) $orden->total_cliente)->toBe(0.0)
        ->and($orden->detalles()->count())->toBe(0)
        ->and($orden->refacciones()->count())->toBe(0)
        ->and($orden->notas)->toContain('Servicio de Optimización')
        ->and($orden->notas)->toContain('SSD 120GB')
        ->and($orden->notas)->toContain('4837');
});

test('la API de recepción es idempotente con el mismo external_id', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $primera = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi());
    $segunda = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi());

    $primera->assertCreated()->assertJsonPath('created', true);
    $segunda->assertOk()->assertJsonPath('created', false);

    expect(OrdenServicio::count())->toBe(1)
        ->and($segunda->json('folio'))->toBe($primera->json('folio'));
});

test('la API de recepción no expone password_equipo en la respuesta', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi());

    $response->assertCreated()
        ->assertJsonPath('equipo.password_equipo_registrada', true)
        ->assertJsonMissingPath('equipo.password_equipo');

    // El valor real nunca aparece en el cuerpo, pero sí quedó guardado en el equipo.
    expect($response->getContent())->not->toContain('884960');

    $equipo = Equipo::firstWhere('modelo', 'Laptop HP');
    expect($equipo->password_equipo)->toBe('884960');
});

test('la API de recepción valida que los precios no sean negativos', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'servicios' => [['descripcion' => 'Servicio raro', 'precio' => -10]],
            'external_id' => 'telegram-photo-neg',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['servicios.0.precio']);

    expect(OrdenServicio::count())->toBe(0);
});

test('la API de recepción exige cliente y equipo identificable', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    // Sin cliente ni cliente_id, y equipo sin tipo ni modelo.
    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', [
            'equipo' => ['marca' => 'HP'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cliente_id', 'cliente', 'equipo.tipo_equipo', 'equipo.modelo']);
});

test('la orden creada por la API interna se atribuye al usuario de sistema y sin partner', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    config(['services.openclaw.system_user_email' => 'openclaw-bot@sistema.local']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi())
        ->assertCreated();

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-001');
    $systemUser = User::firstWhere('email', 'openclaw-bot@sistema.local');

    expect($systemUser)->not->toBeNull()
        ->and($systemUser->role)->toBe('admin')
        ->and($orden->creado_por_user_id)->toBe($systemUser->id)
        // Token de sistema: no pertenece a ningún partner (aislamiento por partner del panel).
        ->and($orden->partner_recepcion_id)->toBeNull()
        ->and($orden->partner_tecnico_id)->toBeNull();

    // Deja rastro en el historial de estados con el mismo usuario de sistema.
    expect($orden->historialEstados()->where('estado_nuevo', 'recibido')->exists())->toBeTrue();
});
