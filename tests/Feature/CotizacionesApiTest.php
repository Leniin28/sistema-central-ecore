<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Services\ExportarCotizacionPng;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function payloadCotizacionApi(): array
{
    return [
        'cliente' => ['nombre' => 'Cliente OpenClaw', 'telefono' => '5551234567'],
        'items' => [
            ['tipo' => 'servicio', 'descripcion' => 'Formateo e instalación', 'cantidad' => 1, 'precio_unitario' => 400],
            ['tipo' => 'refaccion', 'descripcion' => 'Memoria RAM 8GB', 'cantidad' => 2, 'precio_unitario' => 350],
        ],
        'descuento' => 100,
        'anticipo' => 200,
        'notas' => 'Cotización generada por OpenClaw',
    ];
}

function crearCotizacionApiPrueba(): Cotizacion
{
    return app(CrearCotizacion::class)->ejecutar(
        ['cliente' => ['nombre' => 'Cliente PDF interno', 'telefono' => '5550000000']],
        [['tipo' => 'servicio', 'descripcion' => 'Servicio de prueba', 'cantidad' => 1, 'precio_unitario' => 250]],
        null,
    );
}

test('el endpoint interno rechaza peticiones sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->postJson('/api/internal/quotes', payloadCotizacionApi())
        ->assertUnauthorized();

    expect(Cotizacion::count())->toBe(0);
});

test('el endpoint interno rechaza peticiones con token incorrecto', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-equivocado')
        ->postJson('/api/internal/quotes', payloadCotizacionApi())
        ->assertUnauthorized();
});

test('el endpoint interno responde 403 si el token no esta configurado', function () {
    config(['services.openclaw.internal_api_token' => null]);

    $this->withToken('cualquier-token')
        ->postJson('/api/internal/quotes', payloadCotizacionApi())
        ->assertForbidden();
});

test('el endpoint interno crea una cotizacion con token valido', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', payloadCotizacionApi());

    $response->assertCreated()
        ->assertJsonPath('estado', 'borrador')
        ->assertJsonPath('subtotal', 1100)
        ->assertJsonPath('total', 1000)
        ->assertJsonPath('saldo', 800)
        ->assertJsonStructure(['id', 'folio', 'cliente' => ['id', 'nombre'], 'pdf_url', 'show_url']);

    expect($response->json('folio'))->toStartWith('COT-')
        ->and(Cliente::where('nombre', 'Cliente OpenClaw')->exists())->toBeTrue();
});

test('el endpoint interno acepta cliente existente y valida items', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cliente = Cliente::create(['nombre' => 'Cliente existente', 'telefono' => '555', 'tipo_cliente' => 'mantenimiento']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', [
            'cliente_id' => $cliente->id,
            'items' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);

    $respuesta = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', [
            'cliente_id' => $cliente->id,
            'items' => [['tipo' => 'servicio', 'descripcion' => 'Diagnóstico', 'cantidad' => 1, 'precio_unitario' => 250]],
        ]);

    $respuesta->assertCreated()->assertJsonPath('cliente.id', $cliente->id);
});

test('el endpoint interno es idempotente con external_id y conserva acceso al PDF', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $payload = [...payloadCotizacionApi(), 'external_id' => 'openclaw-msg-001'];

    $primera = $this->withToken('token-secreto-pruebas')->postJson('/api/internal/quotes', $payload);
    $segunda = $this->withToken('token-secreto-pruebas')->postJson('/api/internal/quotes', $payload);

    $primera->assertCreated();
    $segunda->assertCreated();
    expect(Cotizacion::count())->toBe(1)
        ->and($segunda->json('folio'))->toBe($primera->json('folio'))
        ->and($segunda->json('external_id'))->toBe('openclaw-msg-001')
        ->and($segunda->json('internal_pdf_url'))->toBe($primera->json('internal_pdf_url'));

    $this->withToken('token-secreto-pruebas')
        ->get($segunda->json('pdf_download_endpoint'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('el POST interno devuelve la URL interna de descarga del PDF', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', payloadCotizacionApi());

    $response->assertCreated()
        ->assertJsonStructure(['id', 'folio', 'total', 'saldo', 'internal_pdf_url', 'pdf_download_endpoint'])
        ->assertJsonPath('web_urls_require_session', true);

    expect($response->json('internal_pdf_url'))->toContain('/api/internal/quotes/')
        ->and($response->json('internal_pdf_url'))->toContain('/pdf')
        ->and($response->json('pdf_download_endpoint'))->toStartWith('/api/internal/quotes/');
});

test('la descarga interna de PDF rechaza peticiones sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = crearCotizacionApiPrueba();

    $this->getJson("/api/internal/quotes/{$cotizacion->id}/pdf")->assertUnauthorized();
});

test('la descarga interna de PDF rechaza token incorrecto', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = crearCotizacionApiPrueba();

    $this->withToken('token-equivocado')
        ->getJson("/api/internal/quotes/{$cotizacion->id}/pdf")
        ->assertUnauthorized();
});

test('la descarga interna de PDF responde 200 con documento y folio en el nombre', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = crearCotizacionApiPrueba();

    $response = $this->withToken('token-secreto-pruebas')
        ->get("/api/internal/quotes/{$cotizacion->id}/pdf");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))
        ->toContain('cotizacion-'.$cotizacion->folio.'.pdf');
});

test('la descarga interna de PDF responde 404 si la cotizacion no existe', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/quotes/99999/pdf')
        ->assertNotFound();
});

test('el POST interno devuelve la URL interna del PNG', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', payloadCotizacionApi());

    $response->assertCreated()
        ->assertJsonStructure(['internal_png_url', 'png_download_endpoint']);

    expect($response->json('internal_png_url'))->toContain('/api/internal/quotes/')
        ->and($response->json('internal_png_url'))->toContain('/png');
});

test('el endpoint interno guarda la recepcion a domicilio usando la direccion del cliente', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $payload = [...payloadCotizacionApi(), 'tipo_recepcion' => 'recogido_a_domicilio'];
    $payload['cliente']['direccion'] = 'Av. Convención 500, Aguascalientes';

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', $payload);

    $response->assertCreated()
        ->assertJsonPath('tipo_recepcion', 'recogido_a_domicilio')
        ->assertJsonPath('direccion_recepcion', 'Av. Convención 500, Aguascalientes');
});

test('el endpoint interno acepta el payload completo de OpenClaw (cliente rapido, domicilio, direccion, anticipo y external_id)', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    // Equivalente al payload probado desde PowerShell: cliente creado al vuelo,
    // items, recogida a domicilio con dirección explícita, anticipo y external_id.
    $payload = [
        'cliente' => ['nombre' => 'Cliente Telegram', 'telefono' => '5551112233'],
        'tipo_recepcion' => 'recogido_a_domicilio',
        'direccion_recepcion' => 'Calle prueba #123',
        'items' => [
            ['tipo' => 'servicio', 'descripcion' => 'Diagnóstico', 'cantidad' => 1, 'precio_unitario' => 250],
        ],
        'anticipo' => 100,
        'external_id' => 'telegram-test-002',
    ];

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', $payload);

    $response->assertCreated()
        ->assertJsonPath('estado', 'borrador')
        ->assertJsonPath('tipo_recepcion', 'recogido_a_domicilio')
        ->assertJsonPath('direccion_recepcion', 'Calle prueba #123')
        ->assertJsonPath('external_id', 'telegram-test-002')
        ->assertJsonPath('total', 250)
        ->assertJsonPath('anticipo', 100)
        ->assertJsonPath('saldo', 150);

    expect(Cliente::where('nombre', 'Cliente Telegram')->exists())->toBeTrue();
});

test('el endpoint interno rechaza recepcion a domicilio sin direccion', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cliente = Cliente::create(['nombre' => 'Cliente sin direccion', 'telefono' => '555', 'tipo_cliente' => 'mantenimiento']);

    $this->withToken('token-secreto-pruebas')
        ->postJson('/api/internal/quotes', [
            'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'recogido_a_domicilio',
            'items' => [['tipo' => 'servicio', 'descripcion' => 'Diagnóstico', 'cantidad' => 1, 'precio_unitario' => 250]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['direccion_recepcion']);
});

test('la descarga interna de PNG rechaza peticiones sin token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = crearCotizacionApiPrueba();

    $this->getJson("/api/internal/quotes/{$cotizacion->id}/png")->assertUnauthorized();
});

test('la descarga interna de PNG responde 200 con imagen y folio en el nombre', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = crearCotizacionApiPrueba();

    $this->mock(ExportarCotizacionPng::class, function ($mock) use ($cotizacion) {
        $mock->shouldReceive('generar')->once()->andReturn("\x89PNG\r\n\x1a\nfake");
        $mock->shouldReceive('nombreArchivo')->andReturn('cotizacion-'.$cotizacion->folio.'.png');
    });

    $response = $this->withToken('token-secreto-pruebas')
        ->get("/api/internal/quotes/{$cotizacion->id}/png");

    $response->assertOk()->assertHeader('content-type', 'image/png');

    expect($response->headers->get('content-disposition'))
        ->toContain('cotizacion-'.$cotizacion->folio.'.png');
});
