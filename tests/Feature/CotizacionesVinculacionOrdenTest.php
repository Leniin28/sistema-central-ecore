<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Actions\Recepciones\CrearRecepcion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contextoVinculacionCotizacion(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create([
        'nombre' => 'Cliente vínculo explícito',
        'telefono' => '4491002000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Lenovo',
        'modelo' => 'T14',
        'accesorios_recibidos' => 'Cargador original',
        'estado_fisico_inicial' => 'Golpe leve en tapa',
    ]);

    return compact('admin', 'cliente', 'equipo');
}

function ordenRecepcionParaVinculo(array $datos): OrdenServicio
{
    return app(CrearRecepcion::class)->ejecutar([
        'cliente_modo' => 'existente',
        'cliente_id' => $datos['cliente']->id,
        'equipo_modo' => 'existente',
        'equipo_id' => $datos['equipo']->id,
        'orden' => [
            'tipo_recepcion' => 'directo',
            'problema_reportado' => 'No enciende después de una descarga',
            'notas' => 'Conservar cargador y datos de recepción',
        ],
        'servicios' => [],
        'refacciones' => [],
    ], $datos['admin']);
}

function payloadCotizacionVinculada(array $datos, ?int $ordenId = null): array
{
    return [
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
        'orden_servicio_id' => $ordenId,
        'fecha' => today()->format('Y-m-d'),
        'items' => [[
            'tipo' => 'servicio',
            'descripcion' => 'Diagnóstico eléctrico',
            'cantidad' => 1,
            'precio_unitario' => 650,
            'costo_unitario' => 120,
        ]],
    ];
}

test('recepción y cotización vinculada reutilizan una sola orden preservando líneas manuales estado y recepción', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $notasOriginales = $orden->notas;
    $orden->update(['estado' => 'en_diagnostico']);
    $lineaManual = $orden->detalles()->create([
        'cotizacion_item_id' => null,
        'descripcion' => 'Revisión manual inicial',
        'cantidad' => 1,
        'precio_unitario' => 100,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 100,
    ]);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), payloadCotizacionVinculada($datos, $orden->id))
        ->assertRedirect();

    $cotizacion = Cotizacion::sole();
    expect($orden->fresh()->cotizacion_id)->toBe($cotizacion->id)
        ->and(OrdenServicio::count())->toBe(1);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect();
    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect();

    $orden->refresh();
    expect(OrdenServicio::count())->toBe(1)
        ->and($orden->folio)->not->toBeEmpty()
        ->and($orden->estado)->toBe('en_diagnostico')
        ->and($orden->notas)->toBe($notasOriginales)
        ->and($orden->detalles()->whereKey($lineaManual->id)->whereNull('cotizacion_item_id')->exists())->toBeTrue()
        ->and($orden->detalles()->whereNotNull('cotizacion_item_id')->count())->toBe(1)
        ->and($orden->detalles()->count())->toBe(2)
        ->and($datos['equipo']->fresh()->accesorios_recibidos)->toBe('Cargador original')
        ->and($datos['equipo']->fresh()->estado_fisico_inicial)->toBe('Golpe leve en tapa');
});

test('sin vínculo explícito no hay autodetección y la aceptación crea otra orden', function () {
    $datos = contextoVinculacionCotizacion();
    ordenRecepcionParaVinculo($datos);
    $cotizacion = app(CrearCotizacion::class)->ejecutar(
        collect(payloadCotizacionVinculada($datos))->except('orden_servicio_id')->all(),
        payloadCotizacionVinculada($datos)['items'],
        $datos['admin'],
    );

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertRedirect();

    expect(OrdenServicio::count())->toBe(2);
});

test('rechaza vincular una orden de otro cliente', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $otro = Cliente::create(['nombre' => 'Otro cliente', 'telefono' => '4492', 'tipo_cliente' => 'mantenimiento']);
    $otroEquipo = Equipo::create(['cliente_id' => $otro->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);
    $payload = payloadCotizacionVinculada(['cliente' => $otro, 'equipo' => $otroEquipo], $orden->id);

    $this->actingAs($datos['admin'])
        ->from(route('admin.cotizaciones.create'))
        ->post(route('admin.cotizaciones.store'), $payload)
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0)->and($orden->fresh()->cotizacion_id)->toBeNull();
});

test('rechaza vincular una orden del mismo cliente pero otro equipo', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $otroEquipo = Equipo::create(['cliente_id' => $datos['cliente']->id, 'tipo_equipo' => 'Tablet', 'marca' => 'Apple']);
    $payload = payloadCotizacionVinculada([...$datos, 'equipo' => $otroEquipo], $orden->id);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), $payload)
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0)->and($orden->fresh()->cotizacion_id)->toBeNull();
});

test('rechaza órdenes cerradas aunque se manipule el formulario', function (string $estado) {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $orden->update(['estado' => $estado]);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), payloadCotizacionVinculada($datos, $orden->id))
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0);
})->with(['entregado', 'cancelado']);

test('rechaza una orden con finanzas generadas', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $orden->update(['finanzas_generadas' => true]);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), payloadCotizacionVinculada($datos, $orden->id))
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0);
});

test('rechaza una orden con movimientos financieros aunque el flag esté apagado', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $orden->movimientosFinancieros()->create([
        'cliente_id' => $datos['cliente']->id,
        'tipo' => 'ingreso',
        'categoria' => 'manual',
        'monto' => 10,
        'descripcion' => 'Movimiento previo de prueba',
        'fecha' => today(),
    ]);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), payloadCotizacionVinculada($datos, $orden->id))
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0)->and($orden->fresh()->finanzas_generadas)->toBeFalse();
});

test('una orden ocupada no puede vincular otra cotización', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $primera = app(CrearCotizacion::class)->ejecutar(
        payloadCotizacionVinculada($datos, $orden->id),
        payloadCotizacionVinculada($datos)['items'],
        $datos['admin'],
    );

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), payloadCotizacionVinculada($datos, $orden->id))
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(1)
        ->and($orden->fresh()->cotizacion_id)->toBe($primera->id);
});

test('revalida la orden al aceptar si dejó de ser elegible después de vincularla', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $cotizacion = app(CrearCotizacion::class)->ejecutar(
        payloadCotizacionVinculada($datos, $orden->id),
        payloadCotizacionVinculada($datos)['items'],
        $datos['admin'],
    );
    $orden->update(['estado' => 'cancelado']);

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.estado.store', $cotizacion), ['estado' => 'aceptada'])
        ->assertSessionHasErrors('orden_servicio_id');

    expect($cotizacion->fresh()->estado)->toBe('borrador')
        ->and($orden->detalles()->whereNotNull('cotizacion_item_id')->count())->toBe(0)
        ->and(OrdenServicio::count())->toBe(1);
});

test('endpoint admin lista sólo órdenes elegibles del cliente y equipo exactos', function () {
    $datos = contextoVinculacionCotizacion();
    $elegible = ordenRecepcionParaVinculo($datos);
    $elegible->update(['estado' => 'listo_para_entregar']);
    $cerrada = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
        'tipo_recepcion' => 'directo',
    ], [], [], $datos['admin']);
    $cerrada->update(['estado' => 'cancelado']);

    $this->actingAs($datos['admin'])
        ->getJson(route('admin.cotizaciones.ordenes-elegibles', [
            'cliente_id' => $datos['cliente']->id,
            'equipo_id' => $datos['equipo']->id,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.value', $elegible->id)
        ->assertJsonPath('data.0.label', $elegible->folio.' — Listo para entregar — '.$elegible->fecha_recepcion->format('d/m/Y'));
});

test('socio logístico no puede vincular una orden mediante un parámetro manipulado', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $partner = Partner::create(['nombre' => 'Logística', 'tipo_socio' => 'logistico', 'comision_fija' => 0, 'activo' => true]);
    $logistico = User::factory()->create([
        'role' => 'socio_logistico',
        'partner_id' => $partner->id,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($logistico)
        ->post(route('logistica.cotizaciones.store'), payloadCotizacionVinculada($datos, $orden->id))
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0)->and($orden->fresh()->cotizacion_id)->toBeNull();
});

test('rechaza un ID de orden inexistente enviado manualmente', function () {
    $datos = contextoVinculacionCotizacion();

    $this->actingAs($datos['admin'])
        ->post(route('admin.cotizaciones.store'), payloadCotizacionVinculada($datos, 999999))
        ->assertSessionHasErrors('orden_servicio_id');

    expect(Cotizacion::count())->toBe(0);
});

test('show de orden ofrece crear con precarga y después muestra ver cotización', function () {
    $datos = contextoVinculacionCotizacion();
    $orden = ordenRecepcionParaVinculo($datos);
    $crearUrl = route('admin.cotizaciones.create', ['orden_servicio_id' => $orden->id]);

    $this->actingAs($datos['admin'])
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Crear cotización')
        ->assertSee($crearUrl);

    $formulario = $this->actingAs($datos['admin'])->get($crearUrl);
    $formulario->assertOk()->assertSee($orden->folio);
    $documento = new DOMDocument;
    @$documento->loadHTML($formulario->getContent());
    $xpath = new DOMXPath($documento);
    expect($xpath->query('//*[@id="cliente_id"]')->item(0)?->getAttribute('value'))->toBe((string) $datos['cliente']->id)
        ->and($xpath->query('//*[@id="equipo_id"]')->item(0)?->getAttribute('value'))->toBe((string) $datos['equipo']->id)
        ->and($xpath->query('//*[@id="orden_servicio_id"]/option[@selected]')->item(0)?->getAttribute('value'))->toBe((string) $orden->id);

    $cotizacion = app(CrearCotizacion::class)->ejecutar(
        payloadCotizacionVinculada($datos, $orden->id),
        payloadCotizacionVinculada($datos)['items'],
        $datos['admin'],
    );

    $this->actingAs($datos['admin'])
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Ver cotización')
        ->assertSee(route('admin.cotizaciones.show', $cotizacion))
        ->assertDontSee('Crear otra cotización');
});
