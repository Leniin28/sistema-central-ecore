<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearOrdenReporte(?Cliente $cliente = null): OrdenServicio
{
    $cliente ??= Cliente::create(['nombre' => 'Cliente Reporte', 'telefono' => '4490001122', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP', 'password_equipo' => 'SECRETO-REPORTE']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $cliente->id, 'equipo_id' => $equipo->id,
        'tipo_recepcion' => 'directo', 'costo_tecnico' => 0,
    ], [], [], $admin);
}

test('el resumen diario cuenta órdenes, cotizaciones y finanzas reales del día', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $ordenLista = crearOrdenReporte();
    $ordenLista->update(['estado' => 'listo_para_entregar', 'total_cliente' => 500]);

    $ordenEntregada = crearOrdenReporte();
    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$ordenEntregada->folio}/changes", [
            'refacciones' => [['descripcion' => 'USB', 'costo_unitario' => 50, 'precio_cliente' => 80]],
        ])->assertOk();
    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$ordenEntregada->folio}/status", [
            'estado' => 'entregado', 'confirm_final_delivery' => true,
        ])->assertOk();

    Cotizacion::create([
        'folio' => 'COT-TEST-0001', 'cliente_id' => $ordenLista->cliente_id, 'fecha' => today(),
        'estado' => 'enviada', 'tipo_recepcion' => 'en_negocio', 'subtotal' => 900,
        'descuento' => 0, 'anticipo' => 0, 'total' => 900, 'saldo' => 900,
    ]);

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson('/api/internal/reports/daily?include_details=true');

    $response->assertOk()
        ->assertJsonPath('date', today()->toDateString())
        ->assertJsonPath('ordenes.creadas', 2)
        ->assertJsonPath('ordenes.entregadas', 1)
        ->assertJsonPath('ordenes.listas_para_entregar', 1)
        ->assertJsonPath('cotizaciones.pendientes', 1)
        ->assertJsonPath('cotizaciones.total_pendiente', 900)
        ->assertJsonPath('finanzas.ingresos', 80)
        ->assertJsonPath('finanzas.egresos', 50)
        ->assertJsonPath('finanzas.utilidad', 30)
        ->assertJsonPath('finanzas.pendiente_por_cobrar_estimado', 500);

    expect($response->json('alertas'))->not->toBeEmpty()
        ->and($response->json('detalles.ordenes_listas'))->toHaveCount(1)
        ->and($response->getContent())->not->toContain('SECRETO-REPORTE');
});

test('el resumen semanal acumula el rango de la semana', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    crearOrdenReporte();

    $inicio = today()->startOfWeek()->toDateString();

    $response = $this->withToken('token-secreto-pruebas')
        ->getJson("/api/internal/reports/weekly?week_start={$inicio}");

    $response->assertOk()
        ->assertJsonPath('week_start', $inicio)
        ->assertJsonPath('ordenes.creadas', 1);
});

test('los reportes exigen token', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);

    $this->getJson('/api/internal/reports/daily')->assertUnauthorized();
});
