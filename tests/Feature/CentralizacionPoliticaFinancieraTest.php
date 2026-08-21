<?php

use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE D.1: centraliza la política financiera (utilidad + readiness) detrás
 * de PoliticaCostosOrdenServicio y prueba, por ruta HTTP real, que
 * costos_por_linea entrega correctamente. Cierra los hallazgos IMPORTANTE
 * #1, #2 y #3 de la revisión de FASE D.
 */
function contextoEntregaRealCPL(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logistico = Partner::create(['nombre' => 'Electrocom entrega real', 'tipo_socio' => 'logistico', 'comision_fija' => 999, 'activo' => true]);
    $tecnico = Partner::create(['nombre' => 'Fixop entrega real', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente entrega real CPL', 'telefono' => '4491234567', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);

    return compact('admin', 'logistico', 'tecnico', 'cliente', 'equipo');
}

/**
 * Crea la orden por el camino real de negocio (cotización -> aceptación ->
 * ConvertirCotizacionEnOrden, igual que hace el panel), y luego activa
 * costos_por_linea sobre esa orden real — igual que hará la activación de
 * FASE E. total_cliente=1500 (servicios 700+400, refacción 400), costos
 * internos 200+100+500=800, comision_recepcion=50 => utilidad 650.
 */
function crearOrdenListaParaEntregaCPL(array $contexto, float $anticipo = 400, float $comisionRecepcion = 50): OrdenServicio
{
    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => $anticipo,
    ], [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio A entrega real', 'cantidad' => 1, 'precio_unitario' => 700, 'costo_unitario' => 200],
        ['tipo' => 'servicio', 'descripcion' => 'Servicio B entrega real', 'cantidad' => 1, 'precio_unitario' => 400, 'costo_unitario' => 100],
        ['tipo' => 'refaccion', 'descripcion' => 'Refacción entrega real', 'cantidad' => 1, 'precio_unitario' => 400, 'costo_unitario' => 500],
    ], $contexto['admin']);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $contexto['admin']);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();

    $orden->update([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => $comisionRecepcion,
        'partner_recepcion_id' => $contexto['logistico']->id,
        'partner_tecnico_id' => $contexto['tecnico']->id,
        'costo_tecnico' => 700,
    ]);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    return $orden->fresh();
}

// --- 9: Una sola fórmula de utilidad para los 3 caminos --------------------

test('legacy: Calcular, Recalcular y Generar producen la misma utilidad', function () {
    // venta 1500, servicio costo 100, costo_tecnico 200, comision_logistica 50 => 1150
    $tecnico = Partner::create(['nombre' => 'Fixop consistencia legacy', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $logistico = Partner::create(['nombre' => 'Electrocom consistencia legacy', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente consistencia legacy', 'telefono' => '4499990001', 'tipo_cliente' => 'mantenimiento']);
    $usuario = User::factory()->create(['role' => 'admin']);

    $resumen = app(CalcularTotalesOrdenServicio::class)->resumen(
        detalles: [['descripcion' => 'Servicio consistencia', 'cantidad' => 1, 'precio_unitario' => 1500, 'costo_unitario' => 100]],
        refacciones: [],
        costoTecnico: 200,
        comision: 50,
        tienePartnerTecnico: true,
        modeloFinanciero: OrdenServicio::MODELO_FINANCIERO_LEGACY,
        comisionRecepcion: null,
    );
    expect($resumen['utilidad_estimada'])->toBe(1150.0);

    $orden = OrdenServicio::create([
        'folio' => 'OS-CONSIST-LEGACY-1',
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $usuario->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'partner_recepcion_id' => $logistico->id,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 200,
        // comision_logistica se sincroniza con el partner solo al generar
        // finanzas (GenerarFinanzasOrdenServicio::generarLegacy); se fija
        // aquí para que Calcular/Recalcular/Generar partan del mismo dato,
        // que es justo lo que este test quiere probar.
        'comision_logistica' => 50,
        'total_cliente' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);
    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio consistencia', 'cantidad' => 1, 'precio_unitario' => 1500, 'costo_unitario' => 100,
    ]));

    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    expect((float) $orden->fresh()->utilidad_estimada)->toBe(1150.0);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    expect((float) $orden->fresh()->utilidad_neta)->toBe(1150.0);
});

test('costos_por_linea: Calcular, Recalcular y Generar producen la misma utilidad 650', function () {
    $contexto = contextoEntregaRealCPL();

    $resumen = app(CalcularTotalesOrdenServicio::class)->resumen(
        detalles: [
            ['descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 700, 'costo_unitario' => 200],
            ['descripcion' => 'Servicio B', 'cantidad' => 1, 'precio_unitario' => 400, 'costo_unitario' => 100],
        ],
        refacciones: [
            ['descripcion' => 'Refacción', 'cantidad' => 1, 'precio_unitario_cliente' => 400, 'costo_unitario' => 500],
        ],
        costoTecnico: 700,
        comision: 999,
        tienePartnerTecnico: true,
        modeloFinanciero: OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        comisionRecepcion: 50,
    );
    expect($resumen['utilidad_estimada'])->toBe(650.0);

    $orden = crearOrdenListaParaEntregaCPL($contexto);
    expect((float) $orden->utilidad_estimada)->toBe(650.0);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    expect((float) $orden->fresh()->utilidad_neta)->toBe(650.0)
        ->and((float) $orden->fresh()->utilidad_estimada)->toBe(650.0);
});

// --- 10: El guard no confía ciegamente en costos_incompletos persistido ----

test('costos_incompletos persistido en false no evita el bloqueo si la comision real es NULL', function () {
    $contexto = contextoEntregaRealCPL();
    $orden = crearOrdenListaParaEntregaCPL($contexto);

    // Simula un snapshot stale: el booleano dice "completo" pero el dato
    // real de comisión es NULL (por ejemplo, una edición posterior que no
    // disparó el recálculo). El único movimiento tolerado antes de entregar
    // es el anticipo ya reconciliado con la cotización.
    $orden->update(['comision_recepcion' => null, 'costos_incompletos' => false]);
    $conteoAntes = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count();

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe($conteoAntes);
});

test('costos_incompletos persistido en false no evita el bloqueo si una linea real es NULL', function () {
    $contexto = contextoEntregaRealCPL();
    $orden = crearOrdenListaParaEntregaCPL($contexto);

    $orden->detalles()->first()->update(['costo_total' => null]);
    $orden->update(['costos_incompletos' => false]);
    $conteoAntes = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count();

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe($conteoAntes);
});

// --- 11-12: Feature test E2E — ruta real de entrega -------------------------

test('E2E ruta real: POST admin.ordenes-servicio.estado.store entrega una orden costos_por_linea', function () {
    $contexto = contextoEntregaRealCPL();
    $orden = crearOrdenListaParaEntregaCPL($contexto);

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionDoesntHaveErrors();

    $orden->refresh();

    expect($orden->estado)->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeTrue()
        ->and($orden->fecha_entrega)->not->toBeNull()
        ->and((float) $orden->utilidad_neta)->toBe(650.0)
        ->and((float) $orden->utilidad_estimada)->toBe(650.0);

    $ingresos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'ingreso')->get();
    expect((float) $ingresos->where('categoria', 'anticipo')->sum('monto'))->toBe(400.0)
        ->and((float) $ingresos->where('categoria', 'reparacion')->sum('monto'))->toBe(1100.0);

    $egresos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'egreso')->get();
    expect($egresos->where('categoria', 'servicio')->pluck('monto')->map(fn ($m): float => (float) $m)->sort()->values()->all())
        ->toBe([100.0, 200.0])
        ->and((float) $egresos->where('categoria', 'refaccion')->sum('monto'))->toBe(500.0)
        ->and((float) $egresos->where('categoria', 'comision_recepcion')->sum('monto'))->toBe(50.0)
        ->and($egresos->where('categoria', 'pago_socio_tecnico'))->toHaveCount(0)
        ->and($egresos->where('categoria', 'pago_socio_logistico'))->toHaveCount(0)
        ->and($egresos->contains(fn (MovimientoFinanciero $m): bool => (float) $m->monto === 700.0))->toBeFalse()
        ->and($egresos->contains(fn (MovimientoFinanciero $m): bool => (float) $m->monto === 999.0))->toBeFalse();

    $historial = HistorialEstado::where('orden_servicio_id', $orden->id)->where('estado_nuevo', 'entregado')->first();
    expect($historial)->not->toBeNull()
        ->and($historial->user_id)->toBe($contexto['admin']->id);
});

// --- 13: E2E con comisión pendiente rechaza por ruta real -------------------

test('E2E ruta real: comision_recepcion NULL con costos_incompletos stale en false se rechaza al entregar', function () {
    $contexto = contextoEntregaRealCPL();
    $orden = crearOrdenListaParaEntregaCPL($contexto);
    $orden->update(['comision_recepcion' => null, 'costos_incompletos' => false]);

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionHasErrors('estado_nuevo');

    $orden->refresh();

    expect($orden->estado)->not->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and($orden->fecha_entrega)->toBeNull()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', '!=', 'anticipo')->count())->toBe(0)
        ->and(HistorialEstado::where('orden_servicio_id', $orden->id)->where('estado_nuevo', 'entregado')->exists())->toBeFalse();
});

// --- 14: Idempotencia por ruta real ------------------------------------------

test('E2E ruta real: un segundo intento de entrega no duplica movimientos', function () {
    $contexto = contextoEntregaRealCPL();
    $orden = crearOrdenListaParaEntregaCPL($contexto);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    $conteoTrasPrimeraEntrega = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count();

    // El controller ya bloquea reintentos sobre una orden entregada
    // (abort_if estado === 'entregado' || finanzas_generadas), así que el
    // segundo intento debe fallar sin tocar finanzas — no hace falta
    // infraestructura de concurrencia real para probarlo.
    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertForbidden();

    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe($conteoTrasPrimeraEntrega);
});
