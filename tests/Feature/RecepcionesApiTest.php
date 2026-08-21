<?php

use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
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
        ->and($orden->costo_tecnico)->toBeNull()
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and(Cliente::where('nombre', 'Cliente Mínimo')->exists())->toBeTrue()
        ->and(Equipo::where('tipo_equipo', 'PC de escritorio')->exists())->toBeTrue()
        // La falla se conserva en las notas de la orden.
        ->and($orden->notas)->toContain('No enciende');
});

test('la API de recepción agrega refacción real y deja el servicio sin catálogo como warning', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    // Sin catálogo sembrado: "Servicio de Optimización" no matchea → warning (no línea falsa).
    // La refacción "SSD 120GB" (texto libre, precio 680) sí se agrega como línea real.
    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi());

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('origen', 'telegram_foto_etiqueta');

    expect(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'Servicio de Optimización')))->toBeTrue()
        ->and($response->json('refacciones_agregadas'))->toHaveCount(1)
        ->and($response->json('servicios_agregados'))->toHaveCount(0);

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-001');
    expect($orden->detalles()->count())->toBe(0)          // servicio sin catálogo: no crea línea
        ->and($orden->refacciones()->count())->toBe(1)     // refacción real
        ->and((float) $orden->total_cliente)->toBe(680.0)  // total = precio de la refacción
        ->and($orden->notas)->toContain('4837');
});

test('la API de recepción agrega un servicio real cuando matchea el catálogo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearServicioCatalogo('Servicio de Optimización', 550);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', [
            'cliente' => ['nombre' => 'Cliente Match'],
            'equipo' => ['tipo_equipo' => 'Laptop', 'marca' => 'HP'],
            'recepcion' => ['falla_reportada' => 'Lenta'],
            'servicios' => [['descripcion' => 'optimización', 'precio' => 550]],
            'external_id' => 'telegram-photo-servicio-match',
        ]);

    $response->assertCreated();
    expect($response->json('servicios_agregados'))->toHaveCount(1);

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-servicio-match');
    expect($orden->detalles()->count())->toBe(1)
        ->and((float) $orden->total_cliente)->toBe(550.0);
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

function crearServicioCatalogo(string $nombre, float $precio): Servicio
{
    $categoria = CategoriaServicio::firstOrCreate(['nombre' => 'General']);

    return Servicio::create([
        'categoria_servicio_id' => $categoria->id,
        'nombre' => $nombre,
        'precio_base' => $precio,
        'activo' => true,
    ]);
}

function crearPartnersLogisticos(): void
{
    Partner::create(['nombre' => 'Electrocom Alameda', 'tipo_socio' => 'logistico', 'activo' => true]);
    Partner::create(['nombre' => 'Electrocom Rodolfo', 'tipo_socio' => 'logistico', 'activo' => true]);
}

test('la API de recepción asigna el partner logístico por nombre exacto de sucursal', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();
    $alameda = Partner::where('nombre', 'Electrocom Alameda')->first();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'recepcion' => ['partner_logistico' => 'Electrocom Alameda'],
            'external_id' => 'telegram-photo-alameda',
        ]));

    $response->assertCreated()
        ->assertJsonPath('partner_recepcion.id', $alameda->id)
        ->assertJsonPath('partner_recepcion.nombre', 'Electrocom Alameda');

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-alameda');
    expect($orden->partner_recepcion_id)->toBe($alameda->id);
});

test('la API de recepción resuelve variantes cortas de sucursal como "Alameda"', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();
    $alameda = Partner::where('nombre', 'Electrocom Alameda')->first();

    // Forma simple top-level y variante corta.
    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'partner_logistico' => 'Alameda',
            'external_id' => 'telegram-photo-alameda-corta',
        ]));

    $response->assertCreated()->assertJsonPath('partner_recepcion.id', $alameda->id);
    expect($response->json('warnings'))->not->toContain('No se encontró un partner logístico');
});

test('la API de recepción acepta partner_recepcion_id explícito y válido', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();
    $rodolfo = Partner::where('nombre', 'Electrocom Rodolfo')->first();

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'recepcion' => ['partner_recepcion_id' => $rodolfo->id],
            'external_id' => 'telegram-photo-rodolfo-id',
        ]))
        ->assertCreated()
        ->assertJsonPath('partner_recepcion.id', $rodolfo->id);
});

test('la API de recepción rechaza un partner_recepcion_id que no es logístico activo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $tecnico = Partner::create(['nombre' => 'Taller Técnico', 'tipo_socio' => 'tecnico', 'activo' => true]);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'recepcion' => ['partner_recepcion_id' => $tecnico->id],
            'external_id' => 'telegram-photo-bad-partner',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recepcion.partner_recepcion_id']);

    expect(OrdenServicio::count())->toBe(0);
});

test('la API de recepción no falla si la sucursal no existe: crea la orden con warning y nota de recepción', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'recepcion' => ['partner_logistico' => 'Sucursal Inexistente'],
            'external_id' => 'telegram-photo-sin-sucursal',
        ]));

    $response->assertCreated()
        ->assertJsonPath('partner_recepcion', null);

    expect(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'Sucursal Inexistente')))->toBeTrue();

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-sin-sucursal');
    expect($orden->partner_recepcion_id)->toBeNull()
        ->and($orden->nota_recepcion)->toBe('Sucursal Inexistente')
        ->and($orden->notas)->not->toContain('Sucursal Inexistente');
});

test('la API interna persiste nota explícita sin inferir partner y devuelve el snapshot interno', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'nota_recepcion' => '  Sucursal Alameda  ',
            'external_id' => 'telegram-photo-nota-independiente',
        ]));

    $response->assertCreated()
        ->assertJsonPath('partner_recepcion', null)
        ->assertJsonPath('nota_recepcion', 'Sucursal Alameda')
        ->assertJsonPath('comision_recepcion', null);

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-nota-independiente');
    expect($orden->partner_recepcion_id)->toBeNull()
        ->and($orden->nota_recepcion)->toBe('Sucursal Alameda')
        ->and($orden->comision_recepcion)->toBeNull()
        ->and($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);
});

test('la API interna rechaza confirmar comisión de recepción', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'comision_recepcion' => 50,
            'external_id' => 'telegram-photo-comision-prohibida',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('comision_recepcion');

    expect(OrdenServicio::count())->toBe(0);
});

test('la idempotencia conserva nota y comisión pendiente de la recepción original', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $payload = payloadRecepcionApi([
        'nota_recepcion' => 'Punto inicial',
        'external_id' => 'telegram-photo-snapshot-idempotente',
    ]);

    $primera = $this->withToken('token-secreto-pruebas')->postJson('/api/internal/receptions', $payload);
    $segunda = $this->withToken('token-secreto-pruebas')->postJson('/api/internal/receptions', [
        ...$payload,
        'nota_recepcion' => 'Punto alterado',
    ]);

    $primera->assertCreated()->assertJsonPath('nota_recepcion', 'Punto inicial');
    $segunda->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('nota_recepcion', 'Punto inicial')
        ->assertJsonPath('comision_recepcion', null);

    expect(OrdenServicio::count())->toBe(1)
        ->and(OrdenServicio::sole()->nota_recepcion)->toBe('Punto inicial');
});

test('la API de recepción no adivina cuando la sucursal es ambigua', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', payloadRecepcionApi([
            'recepcion' => ['partner_logistico' => 'Electrocom'],
            'external_id' => 'telegram-photo-ambigua',
        ]));

    $response->assertCreated()->assertJsonPath('partner_recepcion', null);
    expect(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'más de un partner')))->toBeTrue();
});

test('la recepción mínima sigue funcionando sin partner', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearPartnersLogisticos();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/receptions', [
            'cliente' => ['nombre' => 'Cliente Sin Sucursal'],
            'equipo' => ['tipo_equipo' => 'Impresora'],
            'external_id' => 'telegram-photo-sin-partner',
        ]);

    $response->assertCreated()->assertJsonPath('partner_recepcion', null);

    $orden = OrdenServicio::firstWhere('external_id', 'telegram-photo-sin-partner');
    expect($orden->partner_recepcion_id)->toBeNull();
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
