<?php

use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Actions\Ordenes\ResolverModeloFinancieroNuevaOrden;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE E.1: modelo_financiero de una orden NUEVA se resuelve exclusivamente
 * server-side desde config('negocio.modelo_financiero_nuevas_ordenes').
 * Ningún canal ni request puede elegirlo, y una orden existente conserva
 * siempre el modelo con el que nació.
 */
function contextoModeloFinancieroNuevasOrdenes(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logistico = Partner::create(['nombre' => 'Electrocom modelo nuevo', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente modelo nuevo', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);
    $categoria = CategoriaServicio::create(['nombre' => 'Modelo nuevo']);
    $servicio = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Diagnóstico', 'precio_base' => 300, 'activo' => true]);

    return compact('admin', 'logistico', 'cliente', 'equipo', 'servicio');
}

// --- A/B: config gobierna la recepción web -----------------------------

test('config legacy: nueva recepción web nace legacy', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nPrueba',
    ], [], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);
});

test('config costos_por_linea: nueva recepción web nace costos_por_linea', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'comision_recepcion' => 50,
        'notas' => 'Problema reportado:\nPrueba',
    ], [], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA);
});

// --- C: creación directa sigue config -----------------------------------

test('creación directa de orden sigue el modelo configurado', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nPrueba directa',
    ], [
        ['servicio_id' => $contexto['servicio']->id, 'descripcion' => 'Diagnóstico', 'cantidad' => 1, 'precio_unitario' => 300, 'costo_unitario' => 100],
    ], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA)
        // El costo del servicio (100) debe descontarse una sola vez vía la
        // política centralizada, no quedar calculado como legacy por error.
        ->and((float) $orden->utilidad_estimada)->toBe(200.0);
});

// --- D/E: cotización nueva sigue config, existente conserva su modelo ---

test('cotización que crea una orden nueva sigue el modelo configurado', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 0,
    ], [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio cotización nueva', 'cantidad' => 1, 'precio_unitario' => 500],
    ], $contexto['admin']);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $contexto['admin']);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA);
});

test('cotización que reutiliza una orden legacy existente no cambia su modelo aunque config sea costos_por_linea', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $ordenLegacy = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nOrden previa legacy',
    ], [], [], $contexto['admin']);

    expect($ordenLegacy->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'orden_servicio_id' => $ordenLegacy->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 0,
    ], [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio para orden existente', 'cantidad' => 1, 'precio_unitario' => 500],
    ], $contexto['admin']);

    // Activa config nueva DESPUÉS de crear la orden legacy: si la vinculación
    // reevaluara el modelo, esta orden cambiaría — no debe hacerlo.
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $contexto['admin']);

    expect($ordenLegacy->fresh()->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);
});

// --- F: OpenClaw sigue config server-side --------------------------------

test('OpenClaw crea nueva recepción respetando el modelo configurado', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);
    config(['services.openclaw.internal_api_token' => 'token-modelo-nuevo']);

    $response = $this->withToken('token-modelo-nuevo')
        ->postJson('/api/internal/receptions', [
            'cliente' => ['nombre' => 'Cliente OpenClaw modelo nuevo'],
            'equipo' => ['tipo_equipo' => 'Laptop'],
            'recepcion' => ['falla_reportada' => 'No enciende'],
            'external_id' => 'openclaw-modelo-nuevo-1',
        ]);

    $response->assertCreated();

    $orden = OrdenServicio::firstWhere('external_id', 'openclaw-modelo-nuevo-1');
    expect($orden)->not->toBeNull()
        ->and($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA)
        // OpenClaw nunca confirma comisión: debe quedar NULL/pendiente.
        ->and($orden->comision_recepcion)->toBeNull();
});

// --- G: request manipulado no controla el resultado ----------------------

test('un modelo_financiero enviado en el request no controla el resultado', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'notas' => 'Problema reportado:\nIntento de manipulación',
    ], [], [], $contexto['admin']);

    expect($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);
});

// --- H: config inválida falla cerrado, antes de persistir ----------------

test('config inválida falla cerrado y no persiste ninguna orden', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'valor_invalido']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    expect(fn () => app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nDebe fallar',
    ], [], [], $contexto['admin']))->toThrow(RuntimeException::class);

    expect(OrdenServicio::count())->toBe(0);
});

test('resolver falla cerrado también cuando config es null', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => null]);

    expect(fn () => app(ResolverModeloFinancieroNuevaOrden::class)->ejecutar())->toThrow(RuntimeException::class);
});

// --- I: ninguna orden real existente cambia -------------------------------

test('activar costos_por_linea en config no altera órdenes ya existentes', function () {
    config(['negocio.modelo_financiero_nuevas_ordenes' => 'legacy']);
    $contexto = contextoModeloFinancieroNuevasOrdenes();

    $ordenPrevia = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'notas' => 'Problema reportado:\nOrden previa',
    ], [], [], $contexto['admin']);

    config(['negocio.modelo_financiero_nuevas_ordenes' => 'costos_por_linea']);

    expect($ordenPrevia->fresh()->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY);
});
