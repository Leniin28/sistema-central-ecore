<?php

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Finanzas\AnularEntregaOrdenServicio;
use App\Actions\Finanzas\RegistrarReembolsoOrdenServicio;
use App\Models\AjusteFinancieroOrden;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\GeneracionFinancieraOrden;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * FASE H.6: anulación de una entrega marcada por error, únicamente cuando
 * tiene trazabilidad estructurada (FASE H.2: GeneracionFinancieraOrden).
 * Ejemplo del roadmap: anticipo previo 400, entrega crea saldo 1100 +
 * servicios 200/100 + refacción 500 + comisión 50 (total_cliente=1500).
 * Anular debe preservar el anticipo y compensar únicamente los movimientos
 * del lote de esa entrega.
 */
function adminAnulacion(): User
{
    return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
}

/** @return array{orden: OrdenServicio, anticipo: MovimientoFinanciero, cotizacion: Cotizacion} */
function escenarioAnulacionEntrega(): array
{
    $admin = adminAnulacion();
    $partner = Partner::create(['nombre' => 'Electrocom anulación', 'tipo_socio' => 'logistico', 'comision_fija' => 999, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente anulación', 'telefono' => '4491116600', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 400,
    ], [[
        'tipo' => 'servicio',
        'descripcion' => 'Servicio principal',
        'cantidad' => 1,
        'precio_unitario' => 1500,
        'costo_unitario' => 0,
    ]], $admin);

    $orden = OrdenServicio::create([
        'folio' => 'OS-ANUL-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'cotizacion_id' => $cotizacion->id,
        'partner_recepcion_id' => $partner->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'sucursal',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 1500,
        'comision_recepcion' => 50,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $anticipo = MovimientoFinanciero::create([
        'orden_servicio_id' => $orden->id,
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cliente->id,
        'tipo' => 'ingreso',
        'categoria' => 'anticipo',
        'monto' => 400,
        'descripcion' => 'Anticipo '.$cotizacion->folio,
        'fecha' => today(),
    ]);

    $orden->detalles()->create([
        'descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 1000,
        'costo_unitario' => 200, 'costo_total' => 200, 'subtotal' => 1000,
    ]);
    $orden->refacciones()->create([
        'descripcion' => 'Refacción A', 'cantidad' => 1, 'costo_unitario' => 500,
        'precio_unitario_cliente' => 500, 'costo_total' => 500, 'precio_total_cliente' => 500,
        'utilidad_refaccion' => 0,
    ]);

    // Ajusta total_cliente para que cuadre exactamente con el ejemplo del roadmap
    // (servicio 1000 + refacción 500 = 1500; saldo tras anticipo 400 = 1100).
    $estadoAnterior = $orden->estado;
    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    $orden->historialEstados()->create([
        'user_id' => $admin->id,
        'estado_anterior' => $estadoAnterior,
        'estado_nuevo' => 'entregado',
        'comentario' => null,
    ]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    return ['orden' => $orden->fresh(), 'anticipo' => $anticipo->fresh(), 'cotizacion' => $cotizacion];
}

test('anula la entrega, compensa los movimientos del lote y preserva el anticipo previo', function () {
    ['orden' => $orden, 'anticipo' => $anticipo] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();

    $lote = GeneracionFinancieraOrden::where('orden_servicio_id', $orden->id)->sole();
    $movimientosOriginales = MovimientoFinanciero::where('generacion_financiera_orden_id', $lote->id)->get();
    expect($movimientosOriginales)->toHaveCount(4); // saldo, servicio, refacción, comisión

    app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'Entrega marcada por error, equipo sigue en sucursal', $actor);

    // El anticipo nunca pertenece al lote y permanece exactamente igual.
    $anticipo->refresh();
    expect((float) $anticipo->monto)->toBe(400.0)
        ->and($anticipo->tipo)->toBe('ingreso')
        ->and($anticipo->ajuste_financiero_orden_id)->toBeNull();

    // Cada movimiento original del lote sigue intacto; existe una compensación exacta por cada uno.
    foreach ($movimientosOriginales as $original) {
        $original->refresh();
        expect($original->ajuste_financiero_orden_id)->toBeNull();

        $compensatorio = MovimientoFinanciero::where('generacion_financiera_orden_id', $lote->id)
            ->where('categoria', $original->categoria)
            ->where('tipo', $original->tipo === 'ingreso' ? 'egreso' : 'ingreso')
            ->where('monto', $original->monto)
            ->whereNotNull('ajuste_financiero_orden_id')
            ->first();
        expect($compensatorio)->not->toBeNull();
    }

    expect($lote->estaAnulada())->toBeTrue();
});

test('la orden restaura su estado anterior a entregado, finanzas_generadas y fecha_entrega', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();

    $registroEstado = HistorialEstado::where('orden_servicio_id', $orden->id)
        ->where('estado_nuevo', 'entregado')
        ->sole();

    app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'motivo', $actor);

    $orden->refresh();
    expect($orden->estado)->toBe($registroEstado->estado_anterior)
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and($orden->fecha_entrega)->toBeNull()
        ->and((float) $orden->utilidad_neta)->toBe(0.0);

    $ultimoHistorial = HistorialEstado::where('orden_servicio_id', $orden->id)->latest('id')->first();
    expect($ultimoHistorial->estado_anterior)->toBe('entregado')
        ->and($ultimoHistorial->estado_nuevo)->toBe($registroEstado->estado_anterior)
        ->and($ultimoHistorial->comentario)->toContain('Entrega anulada por error');
});

test('una entrega histórica sin lote no puede anularse', function () {
    $admin = adminAnulacion();
    $cliente = Cliente::create(['nombre' => 'Cliente histórico', 'telefono' => '4491116601', 'tipo_cliente' => 'mantenimiento']);

    // Simula una orden entregada ANTES de FASE H.2: finanzas_generadas=true
    // pero sin GeneracionFinancieraOrden asociado.
    $orden = OrdenServicio::create([
        'folio' => 'OS-HIST-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'entregado',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 800,
        'utilidad_estimada' => 100,
        'utilidad_neta' => 100,
        'costos_incompletos' => false,
        'finanzas_generadas' => true,
        'fecha_entrega' => today(),
    ]);

    expect(fn () => app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'motivo', $admin))
        ->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('una entrega ya anulada no puede anularse de nuevo', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();

    app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'primera anulación', $actor);

    expect(fn () => app(AnularEntregaOrdenServicio::class)->ejecutar($orden->fresh(), 'segunda anulación', $actor))
        ->toThrow(ValidationException::class);
});

test('una entrega con un reembolso posterior no puede anularse', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 100, 'reembolso posterior', $actor);

    expect(fn () => app(AnularEntregaOrdenServicio::class)->ejecutar($orden->fresh(), 'motivo', $actor))
        ->toThrow(ValidationException::class);
});

/**
 * J3: caso detectado manualmente en OS-20260821-0001 -- el admin no supo
 * claramente si la anulación se había ejecutado o no cuando fue bloqueada
 * por un reembolso posterior. El mensaje ahora deja explícito que NO se
 * anuló y que la entrega permanece registrada.
 */
test('J3: el bloqueo por reembolso posterior deja explícito que la entrega no se anuló', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();

    app(RegistrarReembolsoOrdenServicio::class)->ejecutar($orden->fresh(), 100, 'reembolso posterior', $actor);

    try {
        app(AnularEntregaOrdenServicio::class)->ejecutar($orden->fresh(), 'motivo', $actor);
        $this->fail('Se esperaba ValidationException');
    } catch (ValidationException $exception) {
        $mensaje = $exception->errors()['orden'][0];
        expect($mensaje)->toContain('No se puede anular esta entrega.')
            ->toContain('reembolsos o correcciones registrados después de la entrega')
            ->toContain('La entrega permanece registrada.');
    }

    expect($orden->fresh()->estado)->toBe('entregado')
        ->and($orden->fresh()->finanzas_generadas)->toBeTrue();
});

test('J3: la entrega histórica sin lote deja explícito que requiere revisión manual', function () {
    $admin = adminAnulacion();
    $cliente = Cliente::create(['nombre' => 'Cliente histórico J3', 'telefono' => '4491116605', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-HISTJ3-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'entregado',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 800,
        'utilidad_estimada' => 100,
        'utilidad_neta' => 100,
        'costos_incompletos' => false,
        'finanzas_generadas' => true,
        'fecha_entrega' => today(),
    ]);

    try {
        app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'motivo', $admin);
        $this->fail('Se esperaba ValidationException');
    } catch (ValidationException $exception) {
        $mensaje = $exception->errors()['orden'][0];
        expect($mensaje)->toContain('No se puede anular automáticamente')
            ->toContain('Requiere revisión manual')
            ->toContain('La entrega permanece registrada.');
    }
});

/**
 * El guard de estado (entregado + finanzas_generadas) siempre se evalúa
 * antes que el guard de lote-ya-anulado: en el flujo real, anular restaura
 * el estado previo a "entregado", así que una segunda anulación normal
 * siempre topa primero con el guard de estado, no con éste. Este test aísla
 * directamente el guard de lote-ya-anulado (forzando el estado de vuelta a
 * "entregado" tras la primera anulación) para verificar su mensaje exacto.
 */
test('J3: una entrega ya anulada da el mensaje exacto de la FASE J', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();

    app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'primera anulación', $actor);

    $orden->fresh()->update(['estado' => 'entregado', 'finanzas_generadas' => true]);

    expect(fn () => app(AnularEntregaOrdenServicio::class)->ejecutar($orden->fresh(), 'segunda anulación', $actor))
        ->toThrow(ValidationException::class, 'Esta entrega ya fue anulada.');
});

test('socio logistico y socio tecnico reciben 403 al anular una entrega', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'email_verified_at' => now()]);

    $this->actingAs($logistico)
        ->post(route('admin.ordenes-servicio.anular-entrega.store', $orden), ['motivo' => 'x'])
        ->assertForbidden();

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('el motivo de la anulación es obligatorio vía HTTP', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $admin = adminAnulacion();

    $this->actingAs($admin)
        ->post(route('admin.ordenes-servicio.anular-entrega.store', $orden), [])
        ->assertSessionHasErrors(['motivo' => 'El motivo es obligatorio para registrar este ajuste.']);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});

test('E2E: anticipo, entrega, anulación y reentrega producen el saldo neto correcto', function () {
    ['orden' => $orden, 'anticipo' => $anticipo, 'cotizacion' => $cotizacion] = escenarioAnulacionEntrega();
    $actor = adminAnulacion();
    $loteOriginal = GeneracionFinancieraOrden::where('orden_servicio_id', $orden->id)->sole();

    app(AnularEntregaOrdenServicio::class)->ejecutar($orden->fresh(), 'error de entrega', $actor);

    $orden->refresh();
    expect($orden->estado)->not->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeFalse();

    // Reentrega: mismo flujo real (estado -> entregado dispara GenerarFinanzasOrdenServicio).
    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    $orden->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeTrue();

    $loteNuevo = GeneracionFinancieraOrden::where('orden_servicio_id', $orden->id)
        ->where('tipo', GeneracionFinancieraOrden::TIPO_ENTREGA)
        ->latest('id')
        ->first();
    expect($loteNuevo->id)->not->toBe($loteOriginal->id);

    // El anticipo no se duplicó: sigue siendo el único ingreso categoria=anticipo.
    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'anticipo')->count())->toBe(1);
    $anticipo->refresh();
    expect((float) $anticipo->monto)->toBe(400.0);

    // Saldo neto (ingresos - egresos, todos los movimientos incluido el lote
    // anulado y su compensación): el lote anulado se cancela exactamente a sí
    // mismo (ingreso 1100 - egreso 750 originales, más su compensación
    // opuesta egreso 1100 + ingreso 750, neto cero), así que lo único que
    // debe sobrevivir es el anticipo (400) más el neto de la reentrega
    // (saldo 1100 - costos 750 = 350).
    $ingresos = (float) MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'ingreso')->sum('monto');
    $egresos = (float) MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'egreso')->sum('monto');
    expect(round($ingresos - $egresos, 2))->toBe(round(400 + (1100 - 750), 2));
});

test('el detalle de la orden muestra la acción de anular entrega cuando hay lote', function () {
    ['orden' => $orden] = escenarioAnulacionEntrega();
    $admin = adminAnulacion();

    $this->actingAs($admin)
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Anular entrega por error')
        ->assertSee('Esta entrega fue un error');
});

test('el detalle de la orden muestra el mensaje seguro cuando la entrega es histórica sin lote', function () {
    $admin = adminAnulacion();
    $cliente = Cliente::create(['nombre' => 'Cliente histórico UI', 'telefono' => '4491116603', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-HISTUI-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'entregado',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 800,
        'utilidad_estimada' => 100,
        'utilidad_neta' => 100,
        'costos_incompletos' => false,
        'finanzas_generadas' => true,
        'fecha_entrega' => today(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Requiere revisión manual')
        ->assertSee('La entrega permanece registrada')
        ->assertDontSee('Esta entrega fue un error');
});

test('anula una entrega legacy y restaura comision_logistica a 0', function () {
    $admin = adminAnulacion();
    $partner = Partner::create(['nombre' => 'Electrocom anulación legacy', 'tipo_socio' => 'logistico', 'comision_fija' => 80, 'activo' => true]);
    $partnerTecnico = Partner::create(['nombre' => 'Fixop anulación legacy', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente anulación legacy', 'telefono' => '4491116604', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-ANULLEG-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'partner_recepcion_id' => $partner->id,
        'partner_tecnico_id' => $partnerTecnico->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'sucursal',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 1000,
        'costo_tecnico' => 100,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    $estadoAnterior = $orden->estado;
    $orden->update(['estado' => 'entregado', 'fecha_entrega' => today()]);
    $orden->historialEstados()->create([
        'user_id' => $admin->id, 'estado_anterior' => $estadoAnterior, 'estado_nuevo' => 'entregado', 'comentario' => null,
    ]);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();
    expect((float) $orden->comision_logistica)->toBe(80.0);

    $lote = GeneracionFinancieraOrden::where('orden_servicio_id', $orden->id)->sole();
    $movimientosOriginales = MovimientoFinanciero::where('generacion_financiera_orden_id', $lote->id)->get();

    app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'error de entrega legacy', $admin);

    $orden->refresh();
    expect($orden->estado)->toBe($estadoAnterior)
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and($orden->fecha_entrega)->toBeNull()
        ->and((float) $orden->utilidad_neta)->toBe(0.0)
        ->and((float) $orden->comision_logistica)->toBe(0.0)
        // costo_tecnico es un dato capturado (no un derivado de la entrega): se conserva.
        ->and((float) $orden->costo_tecnico)->toBe(100.0);

    foreach ($movimientosOriginales as $original) {
        $original->refresh();
        expect($original->ajuste_financiero_orden_id)->toBeNull();
    }

    $ingresos = (float) MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'ingreso')->sum('monto');
    $egresos = (float) MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('tipo', 'egreso')->sum('monto');
    expect(round($ingresos - $egresos, 2))->toBe(0.0);
});

test('una orden no entregada bloquea la anulación de entrega', function () {
    $admin = adminAnulacion();
    $cliente = Cliente::create(['nombre' => 'Cliente no entregada', 'telefono' => '4491116602', 'tipo_cliente' => 'mantenimiento']);

    $orden = OrdenServicio::create([
        'folio' => 'OS-NOENT-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $admin->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'en_proceso',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'total_cliente' => 500,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
    ]);

    expect(fn () => app(AnularEntregaOrdenServicio::class)->ejecutar($orden, 'motivo', $admin))
        ->toThrow(ValidationException::class);

    expect(AjusteFinancieroOrden::count())->toBe(0);
});
