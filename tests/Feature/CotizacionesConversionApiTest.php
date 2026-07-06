<?php

use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cotizacionParaConvertir(array $items = []): Cotizacion
{
    $cliente = Cliente::create(['nombre' => 'Román Barrera', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);

    $items = $items !== [] ? $items : [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio de Optimización', 'cantidad' => 1, 'precio_unitario' => 550, 'subtotal' => 550],
        ['tipo' => 'refaccion', 'descripcion' => 'SSD 480GB', 'cantidad' => 1, 'precio_unitario' => 800, 'subtotal' => 800],
    ];
    $total = collect($items)->sum('subtotal');

    $cotizacion = Cotizacion::create([
        'folio' => 'COT-TEST-1001', 'cliente_id' => $cliente->id, 'fecha' => today(),
        'estado' => 'enviada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => $total,
        'descuento' => 0, 'anticipo' => 0, 'total' => $total, 'saldo' => $total,
    ]);

    foreach ($items as $item) {
        $cotizacion->items()->create($item);
    }

    return $cotizacion->fresh(['items', 'cliente']);
}

function servicioCatalogoConversion(string $nombre = 'Servicio de Optimización', float $precio = 550): Servicio
{
    $categoria = CategoriaServicio::firstOrCreate(['nombre' => 'General']);

    return Servicio::create([
        'categoria_servicio_id' => $categoria->id, 'nombre' => $nombre, 'precio_base' => $precio, 'activo' => true,
    ]);
}

test('convierte una cotización en orden con servicios del catálogo y refacciones', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioCatalogoConversion();
    $cotizacion = cotizacionParaConvertir();
    $partner = Partner::create(['nombre' => 'Electrocom Alameda', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-1',
            'partner_logistico' => 'Alameda',
            'equipo' => ['tipo_equipo' => 'Laptop', 'marca' => 'Lenovo', 'modelo' => 'ThinkPad T490'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('cotizacion.estado', 'aceptada')
        ->assertJsonPath('partner_recepcion', 'Electrocom Alameda')
        ->assertJsonPath('total_cliente', 1350);

    expect($response->json('show_url'))->toContain('ordenes-servicio');

    $orden = OrdenServicio::find($response->json('id'));
    expect($orden->detalles()->count())->toBe(1)
        ->and($orden->refacciones()->count())->toBe(1)
        ->and($orden->origen)->toBe('openclaw-cotizacion')
        ->and($orden->notas)->toContain($cotizacion->folio)
        ->and($cotizacion->fresh()->notas)->toContain($orden->folio);
});

test('la conversión es idempotente con external_id y no duplica órdenes', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioCatalogoConversion();
    $cotizacion = cotizacionParaConvertir();

    $payload = ['external_id' => 'telegram-quote-convert-dup'];

    $primera = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", $payload);
    $segunda = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", $payload);

    $primera->assertCreated()->assertJsonPath('created', true);
    $segunda->assertOk()->assertJsonPath('created', false);

    expect(OrdenServicio::count())->toBe(1)
        ->and($segunda->json('id'))->toBe($primera->json('id'));
});

test('items de servicio sin match del catálogo generan warning y no líneas falsas', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir([
        ['tipo' => 'servicio', 'descripcion' => 'Reparación imposible de clasificar', 'cantidad' => 1, 'precio_unitario' => 999, 'subtotal' => 999],
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-nomatch',
        ]);

    $response->assertCreated();
    expect(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'No se encontró en el catálogo')))->toBeTrue();

    $orden = OrdenServicio::find($response->json('id'));
    expect($orden->detalles()->count())->toBe(0)
        ->and((float) $orden->total_cliente)->toBe(0.0)
        ->and($orden->notas)->toContain('Reparación imposible de clasificar');
});

test('la cotización sin equipo genera warning si no se envía equipo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioCatalogoConversion();
    $cotizacion = cotizacionParaConvertir();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", []);

    $response->assertCreated();
    expect(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w, 'sin equipo')))->toBeTrue();
});

test('lista cotizaciones pendientes con enlaces de pdf/png y días pendientes', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cliente = Cliente::create(['nombre' => 'Pedro Medina', 'telefono' => '4497654321', 'tipo_cliente' => 'mantenimiento']);
    Cotizacion::create([
        'folio' => 'COT-TEST-2001', 'cliente_id' => $cliente->id, 'fecha' => today()->subDays(3),
        'estado' => 'enviada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => 1350,
        'descuento' => 0, 'anticipo' => 0, 'total' => 1350, 'saldo' => 1350,
    ]);
    Cotizacion::create([
        'folio' => 'COT-TEST-2002', 'cliente_id' => $cliente->id, 'fecha' => today(),
        'estado' => 'aceptada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => 500,
        'descuento' => 0, 'anticipo' => 0, 'total' => 500, 'saldo' => 500,
    ]);

    $response = $this->withToken('token-secreto-pruebas')->getJson('/api/internal/quotes/pending');

    $response->assertOk();
    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.folio'))->toBe('COT-TEST-2001')
        ->and($response->json('items.0.dias_pendiente'))->toBe(3)
        ->and($response->json('items.0.pdf_url'))->toContain('/api/internal/quotes/')
        ->and($response->json('items.0.png_url'))->toContain('/png');
});

test('older_than_days filtra las cotizaciones pendientes', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cliente = Cliente::create(['nombre' => 'Pedro Medina', 'telefono' => '4497654321', 'tipo_cliente' => 'mantenimiento']);
    Cotizacion::create([
        'folio' => 'COT-TEST-3001', 'cliente_id' => $cliente->id, 'fecha' => today(),
        'estado' => 'enviada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => 100,
        'descuento' => 0, 'anticipo' => 0, 'total' => 100, 'saldo' => 100,
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/quotes/pending?older_than_days=2');

    $response->assertOk();
    expect($response->json('items'))->toHaveCount(0);
});

test('la conversión y pendientes exigen token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();

    $this->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [])->assertUnauthorized();
    $this->getJson('/api/internal/quotes/pending')->assertUnauthorized();
});
