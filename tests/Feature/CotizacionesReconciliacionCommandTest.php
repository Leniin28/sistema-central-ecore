<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('historical reconciliation dry-run reports unknown internal costs without writing data', function () {
    $cliente = Cliente::create([
        'nombre' => 'Cliente historico',
        'telefono' => '4490000000',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $cotizacion = Cotizacion::create([
        'folio' => 'COT-HIST-0001',
        'cliente_id' => $cliente->id,
        'fecha' => today(),
        'estado' => 'aceptada',
        'tipo_recepcion' => 'en_negocio',
        'subtotal' => 500,
        'descuento' => 0,
        'anticipo' => 0,
        'total' => 500,
        'saldo' => 500,
    ]);
    $cotizacion->items()->create([
        'tipo' => 'servicio',
        'descripcion' => 'Servicio historico sin costo',
        'cantidad' => 1,
        'precio_unitario' => 500,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 500,
    ]);

    $this->artisan('cotizaciones:reconciliar-ordenes --dry-run')
        ->expectsOutputToContain('costo interno desconocido (NULL)')
        ->expectsOutputToContain('Dry-run: no se modificaron cotizaciones')
        ->assertExitCode(0);

    expect($cotizacion->fresh()->ordenServicio)->toBeNull()
        ->and($cotizacion->items()->firstOrFail()->costo_unitario)->toBeNull()
        ->and($cotizacion->items()->firstOrFail()->costo_total)->toBeNull();
});

test('historical reconciliation refuses execution without the explicit dry-run flag', function () {
    $this->artisan('cotizaciones:reconciliar-ordenes')
        ->expectsOutputToContain('requiere --dry-run')
        ->assertExitCode(2);
});

test('historical reconciliation classifies A B C and D without writing data', function () {
    $cliente = Cliente::create(['nombre' => 'Cliente clasificacion', 'telefono' => '4491111111', 'tipo_cliente' => 'mantenimiento']);
    $crearCotizacion = function (string $folio, string $estado) use ($cliente): Cotizacion {
        return Cotizacion::create([
            'folio' => $folio,
            'cliente_id' => $cliente->id,
            'fecha' => today(),
            'estado' => $estado,
            'tipo_recepcion' => 'en_negocio',
            'subtotal' => 0,
            'descuento' => 0,
            'anticipo' => 0,
            'total' => 0,
            'saldo' => 0,
        ]);
    };
    $aceptadaConOrden = $crearCotizacion('COT-CLAS-A', 'aceptada');
    $aceptadaSinOrden = $crearCotizacion('COT-CLAS-B', 'aceptada');
    $noAceptada = $crearCotizacion('COT-CLAS-C', 'enviada');
    $ambigua = $crearCotizacion('COT-CLAS-D', 'aceptada');
    $admin = \App\Models\User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $crearOrdenLegacy = function (Cotizacion $cotizacion) use ($admin): \App\Models\OrdenServicio {
        return app(\App\Actions\Ordenes\CrearOrdenServicio::class)->ejecutar([
            'cliente_id' => $cotizacion->cliente_id,
            'tipo_recepcion' => 'directo',
            'costo_tecnico' => 0,
            'origen' => 'openclaw-cotizacion',
            'notas' => 'Convertida desde la cotizacion '.$cotizacion->folio,
        ], [], [], $admin);
    };
    $ordenA = $crearOrdenLegacy($aceptadaConOrden);
    $ordenD1 = $crearOrdenLegacy($ambigua);
    $ordenD2 = $crearOrdenLegacy($ambigua);

    expect(\App\Models\OrdenServicio::query()
        ->where('origen', 'openclaw-cotizacion')
        ->where('notas', 'like', '%'.$ambigua->folio.'%')
        ->count())->toBe(2);

    $this->artisan('cotizaciones:reconciliar-ordenes --dry-run')
        ->expectsOutputToContain('A: aceptada con orden')
        ->expectsOutputToContain('B: aceptada sin orden')
        ->expectsOutputToContain('C: no aceptada')
        ->expectsOutputToContain('D: relación ambigua')
        ->expectsOutputToContain('Caso D detectado: 1')
        ->expectsOutputToContain($ambigua->folio.': candidatos #'.$ordenD1->id.', #'.$ordenD2->id)
        ->assertExitCode(0);

    expect(\App\Models\OrdenServicio::count())->toBe(3)
        ->and($aceptadaConOrden->fresh()->ordenServicio)->toBeNull()
        ->and($aceptadaSinOrden->fresh()->ordenServicio)->toBeNull()
        ->and($noAceptada->fresh()->ordenServicio)->toBeNull()
        ->and($ambigua->fresh()->ordenServicio)->toBeNull()
        ->and($ordenA->fresh()->cotizacion_id)->toBeNull();
});
