<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('conversion rejects a quote line already traced as the opposite order-line type', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir([
        ['tipo' => 'servicio', 'descripcion' => 'Diagnostico cruzado', 'cantidad' => 1, 'precio_unitario' => 550, 'subtotal' => 550],
    ]);
    $usuario = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $ordenPrevia = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cotizacion->cliente_id,
        'tipo_recepcion' => 'directo',
        'costo_tecnico' => 0,
    ], [], [], $usuario);
    $ordenPrevia->refacciones()->create([
        'cotizacion_item_id' => $cotizacion->items->first()->id,
        'descripcion' => 'Traza incorrecta previa',
        'cantidad' => 1,
        'precio_unitario_cliente' => 0,
        'precio_total_cliente' => 0,
        'utilidad_refaccion' => 0,
    ]);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-cross-type',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cotizacion']);

    expect(OrdenServicio::count())->toBe(1)
        ->and($ordenPrevia->detalles()->count())->toBe(0)
        ->and($ordenPrevia->refacciones()->count())->toBe(1);
});

test('conversion transfers internal costs and creates traceable financial movements', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cliente = Cliente::create(['nombre' => 'Cliente costos', 'telefono' => '4490000000', 'tipo_cliente' => 'mantenimiento']);
    $cotizacion = Cotizacion::create([
        'folio' => 'COT-COSTOS-1001', 'cliente_id' => $cliente->id, 'fecha' => today(),
        'estado' => 'enviada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => 1800,
        'descuento' => 0, 'anticipo' => 0, 'total' => 1800, 'saldo' => 1800,
    ]);
    $cotizacion->items()->createMany([
        ['tipo' => 'servicio', 'descripcion' => 'Diagnostico especializado', 'cantidad' => 2, 'precio_unitario' => 500, 'costo_unitario' => 120, 'costo_total' => 240, 'subtotal' => 1000],
        ['tipo' => 'refaccion', 'descripcion' => 'SSD 480GB', 'cantidad' => 1, 'precio_unitario' => 800, 'costo_unitario' => 450, 'costo_total' => 450, 'subtotal' => 800],
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-costos-internos',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ]);

    $response->assertCreated()->assertJsonPath('total_cliente', 1800);
    $orden = OrdenServicio::findOrFail($response->json('id'));

    expect((float) $orden->detalles()->first()->costo_total)->toBe(240.0)
        ->and((float) $orden->refacciones()->first()->costo_total)->toBe(450.0)
        ->and($orden->costo_tecnico)->toBeNull()
        ->and((float) $orden->fresh()->utilidad_estimada)->toBe(1110.0);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden);

    expect((float) $orden->fresh()->utilidad_neta)->toBe(1110.0)
        ->and($orden->movimientosFinancieros()->where('categoria', 'servicio')->count())->toBe(1)
        ->and($orden->movimientosFinancieros()->where('categoria', 'refaccion')->count())->toBe(1);
});

test('conversion preserves unknown costs and does not create their expense movements', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-costo-desconocido',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ]);

    $response->assertCreated();
    $orden = OrdenServicio::findOrFail($response->json('id'));

    expect($orden->detalles()->first()->costo_unitario)->toBeNull()
        ->and($orden->detalles()->first()->costo_total)->toBeNull()
        ->and($orden->refacciones()->first()->costo_unitario)->toBeNull()
        ->and($orden->refacciones()->first()->costo_total)->toBeNull()
        ->and($orden->costos_incompletos)->toBeTrue();

    app(GenerarFinanzasOrdenServicio::class)->generar($orden);

    expect($orden->movimientosFinancieros()->whereIn('categoria', ['servicio', 'refaccion'])->count())->toBe(0)
        ->and($orden->movimientosFinancieros()->where('categoria', 'reparacion')->count())->toBe(1)
        ->and($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('linked historical quote copies only its missing lines during reconciliation', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();
    $cotizacion->update(['estado' => 'aceptada']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cotizacion->cliente_id,
        'tipo_recepcion' => 'directo',
        'costo_tecnico' => 0,
    ], [], [], $admin);
    $servicio = $cotizacion->items()->where('tipo', 'servicio')->firstOrFail();
    $orden->detalles()->create([
        'cotizacion_item_id' => $servicio->id,
        'descripcion' => $servicio->descripcion,
        'cantidad' => $servicio->cantidad,
        'precio_unitario' => $servicio->precio_unitario,
        'costo_unitario' => $servicio->costo_unitario,
        'costo_total' => $servicio->costo_total,
        'subtotal' => $servicio->subtotal,
    ]);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'historica-parcial-1',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ])
        ->assertOk()
        ->assertJsonPath('created', false);

    expect($orden->fresh()->detalles()->count())->toBe(1)
        ->and($orden->fresh()->refacciones()->count())->toBe(1)
        ->and(OrdenServicio::count())->toBe(1);
});

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
            'recepcion' => ['falla_reportada' => 'Servicio autorizado desde cotización'],
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

    $payload = [
        'external_id' => 'telegram-quote-convert-dup',
        'recepcion' => ['falla_reportada' => 'Servicio autorizado desde cotización'],
        'equipo' => ['tipo_equipo' => 'Laptop'],
    ];

    $primera = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", $payload);
    $segunda = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", $payload);

    $primera->assertCreated()->assertJsonPath('created', true);
    $segunda->assertOk()->assertJsonPath('created', false);

    expect(OrdenServicio::count())->toBe(1)
        ->and($segunda->json('id'))->toBe($primera->json('id'));
});

test('items de servicio ad-hoc se conservan sin servicio del catálogo al convertir', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir([
        ['tipo' => 'servicio', 'descripcion' => 'Reparación imposible de clasificar', 'cantidad' => 1, 'precio_unitario' => 999, 'subtotal' => 999],
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-nomatch',
            'recepcion' => ['falla_reportada' => 'Servicio autorizado desde cotización'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ]);

    $response->assertCreated()->assertJsonPath('total_cliente', 999);

    $orden = OrdenServicio::find($response->json('id'));
    expect($orden->detalles()->count())->toBe(1)
        ->and($orden->detalles->first()->servicio_id)->toBeNull()
        ->and($orden->detalles->first()->descripcion)->toBe('Reparación imposible de clasificar')
        ->and((float) $orden->total_cliente)->toBe(999.0);
});

test('un POST vacío devuelve 422 y no crea orden ni cambia la cotización', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioCatalogoConversion();
    $cotizacion = cotizacionParaConvertir();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external_id', 'recepcion']);

    expect(OrdenServicio::count())->toBe(0)
        ->and($cotizacion->fresh()->estado)->toBe('enviada')
        ->and($cotizacion->fresh()->notas)->toBeNull();
});

test('sin external_id devuelve 422 y no crea orden', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external_id']);

    expect(OrdenServicio::count())->toBe(0);
});

test('la cotización sin equipo y payload sin equipo suficiente devuelve 422', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioCatalogoConversion();
    $cotizacion = cotizacionParaConvertir();

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-sin-equipo',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['marca' => 'Lenovo'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equipo.tipo_equipo']);

    expect(OrdenServicio::count())->toBe(0)
        ->and($cotizacion->fresh()->estado)->toBe('enviada');
});

test('una cotización cancelada devuelve 409 y no crea orden', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();
    $cotizacion->update(['estado' => 'cancelada']);

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-cancelada',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ]);

    $response->assertStatus(409);
    expect($response->json('message'))->toContain('cancelada')
        ->and(OrdenServicio::count())->toBe(0)
        ->and($cotizacion->fresh()->estado)->toBe('cancelada');
});

test('una cotización rechazada o vencida devuelve 409 y no crea orden', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();
    $cotizacion->update(['estado' => 'rechazada']);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-rechazada',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
        ])
        ->assertStatus(409);

    expect(OrdenServicio::count())->toBe(0);
});

test('una cotización ya convertida no se duplica aunque cambie el external_id', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    servicioCatalogoConversion();
    $cotizacion = cotizacionParaConvertir();

    $base = [
        'recepcion' => ['falla_reportada' => 'Autorizado'],
        'equipo' => ['tipo_equipo' => 'Laptop'],
    ];

    $primera = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", $base + ['external_id' => 'telegram-quote-convert-a']);
    $segunda = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", $base + ['external_id' => 'telegram-quote-convert-b']);

    $primera->assertCreated();
    $segunda->assertOk()->assertJsonPath('created', false);

    expect(OrdenServicio::count())->toBe(1)
        ->and($segunda->json('id'))->toBe($primera->json('id'))
        ->and(collect($segunda->json('warnings'))->contains(fn ($w) => str_contains($w, 'ya fue convertida')))->toBeTrue();
});

test('la conversión nunca expone password_equipo', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $cotizacion = cotizacionParaConvertir();

    $response = $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/quotes/{$cotizacion->id}/convert-to-order", [
            'external_id' => 'telegram-quote-convert-pass',
            'recepcion' => ['falla_reportada' => 'Autorizado'],
            'equipo' => ['tipo_equipo' => 'Laptop', 'password_equipo' => 'SECRETO-CONV-77'],
        ]);

    $response->assertCreated()->assertJsonPath('equipo.password_registrada', true);
    expect($response->getContent())->not->toContain('SECRETO-CONV-77');
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
