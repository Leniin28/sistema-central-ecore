<?php

use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function ordenCostosPorLinea(array $atributos = []): OrdenServicio
{
    $usuario = User::factory()->create(['role' => 'admin']);
    $cliente = Cliente::create([
        'nombre' => 'Cliente costos por linea',
        'telefono' => '4499990000',
        'tipo_cliente' => 'mantenimiento',
    ]);

    return OrdenServicio::create([
        'folio' => 'OS-CPL-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $cliente->id,
        'creado_por_user_id' => $usuario->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'total_cliente' => 0,
        'comision_recepcion' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'costos_incompletos' => false,
        'finanzas_generadas' => false,
        ...$atributos,
    ]);
}

function agregarDetalleCPL(OrdenServicio $orden, float $precioUnitario, ?float $costoUnitario, int $cantidad = 1): void
{
    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio costos por linea',
        'cantidad' => $cantidad,
        'precio_unitario' => $precioUnitario,
        'costo_unitario' => $costoUnitario,
    ]));
}

function agregarRefaccionCPL(OrdenServicio $orden, float $precioUnitarioCliente, ?float $costoUnitario, int $cantidad = 1): void
{
    $orden->refacciones()->create(app(CalcularTotalesOrdenServicio::class)->refaccion([
        'descripcion' => 'Refacción costos por linea',
        'cantidad' => $cantidad,
        'costo_unitario' => $costoUnitario,
        'precio_unitario_cliente' => $precioUnitarioCliente,
    ]));
}

function partnerRecepcionCPL(): Partner
{
    return Partner::create(['nombre' => 'Electrocom CPL', 'tipo_socio' => 'logistico', 'comision_fija' => 999, 'activo' => true]);
}

function partnerTecnicoCPL(): Partner
{
    return Partner::create(['nombre' => 'Fixop CPL', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
}

function generarFinanzasOrden(OrdenServicio $orden): void
{
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
}

/**
 * Los anticipos solo se reconocen como ingresos cuando están estructurados a
 * través de una cotización real (la orden aislada sin cotizacion_id nunca
 * puede tener movimientos de categoría anticipo: ver GenerarFinanzasOrdenServicio).
 * Este helper crea la cotización, la acepta (generando la orden legacy) y luego
 * la convierte a costos_por_linea, igual que hará la activación real de FASE E.
 *
 * @param  array<int, array<string, mixed>>  $items
 */
function crearOrdenCPLConCotizacion(float $anticipo, array $items, float $comisionRecepcion = 0): OrdenServicio
{
    $cliente = Cliente::create(['nombre' => 'Cliente CPL cotizacion', 'telefono' => '449'.fake()->unique()->numerify('#######'), 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => $anticipo,
    ], $items, $admin);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $admin);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();
    $orden->update([
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        'comision_recepcion' => $comisionRecepcion,
    ]);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    return $orden->fresh();
}

// --- 19: Ejemplo financiero obligatorio -------------------------------------

test('ejemplo obligatorio: venta 1500, anticipo 400, servicios+refaccion+comision, utilidad 650', function () {
    $tecnico = partnerTecnicoCPL();
    $logistico = partnerRecepcionCPL();
    $orden = crearOrdenCPLConCotizacion(anticipo: 400, comisionRecepcion: 50, items: [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 800, 'costo_unitario' => 200],
        ['tipo' => 'servicio', 'descripcion' => 'Servicio B', 'cantidad' => 1, 'precio_unitario' => 200, 'costo_unitario' => 100],
        ['tipo' => 'refaccion', 'descripcion' => 'Refacción', 'cantidad' => 1, 'precio_unitario' => 500, 'costo_unitario' => 500],
    ]);
    $orden->update([
        'partner_recepcion_id' => $logistico->id,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 700,
        'comision_logistica' => 999,
    ]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect((float) $orden->total_cliente)->toBe(1500.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'anticipo')->sum('monto'))->toBe(400.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'reparacion')->sum('monto'))->toBe(1100.0)
        ->and((float) $orden->movimientosFinancieros()->where('tipo', 'ingreso')->sum('monto'))->toBe(1500.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'servicio')->sum('monto'))->toBe(300.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'refaccion')->sum('monto'))->toBe(500.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->sum('monto'))->toBe(50.0)
        ->and($orden->movimientosFinancieros()->where('categoria', 'pago_socio_tecnico')->exists())->toBeFalse()
        ->and($orden->movimientosFinancieros()->where('categoria', 'pago_socio_logistico')->exists())->toBeFalse()
        ->and((float) $orden->movimientosFinancieros()->where('tipo', 'egreso')->sum('monto'))->toBe(850.0)
        ->and((float) $orden->utilidad_neta)->toBe(650.0)
        ->and((float) $orden->utilidad_estimada)->toBe(650.0)
        ->and($orden->finanzas_generadas)->toBeTrue();

    // 20: idempotencia — segunda ejecución no duplica nada.
    $cantidadAntes = $orden->movimientosFinancieros()->count();
    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect($orden->movimientosFinancieros()->count())->toBe($cantidadAntes)
        ->and((float) $orden->fresh()->utilidad_neta)->toBe(650.0);
});

// --- 1-3: Ingreso / anticipo / saldo -----------------------------------------

test('sin anticipo genera saldo igual al total', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 100);

    generarFinanzasOrden($orden);

    expect((float) $orden->fresh()->movimientosFinancieros()->where('categoria', 'reparacion')->sum('monto'))->toBe(300.0)
        ->and($orden->movimientosFinancieros()->where('categoria', 'anticipo')->exists())->toBeFalse();
});

test('con anticipo parcial genera solo el saldo restante', function () {
    $orden = crearOrdenCPLConCotizacion(anticipo: 100, items: [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 300, 'costo_unitario' => 100],
    ]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect((float) $orden->movimientosFinancieros()->where('categoria', 'reparacion')->sum('monto'))->toBe(200.0)
        ->and((float) $orden->movimientosFinancieros()->where('tipo', 'ingreso')->sum('monto'))->toBe(300.0);
});

test('anticipo total no crea un ingreso de saldo en cero', function () {
    $orden = crearOrdenCPLConCotizacion(anticipo: 300, items: [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 300, 'costo_unitario' => 100],
    ]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect($orden->movimientosFinancieros()->where('categoria', 'reparacion')->count())->toBe(0)
        ->and((float) $orden->movimientosFinancieros()->where('tipo', 'ingreso')->sum('monto'))->toBe(300.0)
        ->and($orden->fresh()->finanzas_generadas)->toBeTrue();
});

// --- 4-7: Servicios y refacciones --------------------------------------------

test('servicio con costo positivo crea egreso de servicio', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 120);

    generarFinanzasOrden($orden);

    $movimiento = $orden->movimientosFinancieros()->where('categoria', 'servicio')->sole();
    expect((float) $movimiento->monto)->toBe(120.0)
        ->and($movimiento->tipo)->toBe('egreso')
        ->and($movimiento->partner_id)->toBeNull();
});

test('servicio con costo cero no crea movimiento', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 0);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('categoria', 'servicio')->exists())->toBeFalse();
});

test('refaccion con costo positivo crea egreso de refaccion', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);
    agregarRefaccionCPL($orden, precioUnitarioCliente: 500, costoUnitario: 350);

    generarFinanzasOrden($orden);

    $movimiento = $orden->movimientosFinancieros()->where('categoria', 'refaccion')->sole();
    expect((float) $movimiento->monto)->toBe(350.0)
        ->and($movimiento->tipo)->toBe('egreso');
});

test('refaccion con costo cero no crea movimiento', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);
    agregarRefaccionCPL($orden, precioUnitarioCliente: 500, costoUnitario: 0);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('categoria', 'refaccion')->exists())->toBeFalse();
});

// --- 8-11: Comisión de recepción ----------------------------------------------

test('comision positiva sin partner de recepcion crea egreso sin partner_id', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50, 'partner_recepcion_id' => null]);

    generarFinanzasOrden($orden);

    $movimiento = $orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->sole();
    expect((float) $movimiento->monto)->toBe(50.0)
        ->and($movimiento->partner_id)->toBeNull();
});

test('comision positiva con partner de recepcion usa ese partner como beneficiario', function () {
    $logistico = partnerRecepcionCPL();
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50, 'partner_recepcion_id' => $logistico->id]);

    generarFinanzasOrden($orden);

    $movimiento = $orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->sole();
    expect($movimiento->partner_id)->toBe($logistico->id);
});

test('comision cero confirmada no crea movimiento', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->exists())->toBeFalse()
        ->and($orden->fresh()->finanzas_generadas)->toBeTrue();
});

test('comision NULL bloquea antes de escribir ningun movimiento', function () {
    $orden = ordenCostosPorLinea(['tipo_recepcion' => 'sucursal', 'comision_recepcion' => null]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 100);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0);
});

// --- 12-13: Servicio/refacción NULL bloquean --------------------------------

test('servicio con costo NULL bloquea antes de escribir', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: null);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0);
});

test('refaccion con costo NULL bloquea antes de escribir', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50]);
    agregarRefaccionCPL($orden, precioUnitarioCliente: 500, costoUnitario: null);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0);
});

// --- 14-17: Ignora costo_tecnico y comision_logistica ------------------------

test('ignora costo_tecnico positivo por completo', function () {
    $tecnico = partnerTecnicoCPL();
    $orden = ordenCostosPorLinea([
        'comision_recepcion' => 0,
        'partner_tecnico_id' => $tecnico->id,
        'costo_tecnico' => 700,
    ]);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('tipo', 'egreso')->exists())->toBeFalse();
});

test('ignora comision_logistica positiva por completo', function () {
    $logistico = partnerRecepcionCPL();
    $orden = ordenCostosPorLinea([
        'comision_recepcion' => 50,
        'partner_recepcion_id' => $logistico->id,
        'comision_logistica' => 999,
    ]);

    generarFinanzasOrden($orden);

    expect((float) $orden->movimientosFinancieros()->where('tipo', 'egreso')->sum('monto'))->toBe(50.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->sole()->monto)->toBe(50.0);
});

test('nunca genera pago_socio_tecnico', function () {
    $tecnico = partnerTecnicoCPL();
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0, 'partner_tecnico_id' => $tecnico->id, 'costo_tecnico' => 700]);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('categoria', 'pago_socio_tecnico')->exists())->toBeFalse();
});

test('nunca genera pago_socio_logistico', function () {
    $logistico = partnerRecepcionCPL();
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0, 'partner_recepcion_id' => $logistico->id]);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('categoria', 'pago_socio_logistico')->exists())->toBeFalse();
});

// --- 18-19: Utilidad ----------------------------------------------------------

test('utilidad del ejemplo obligatorio es 650', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50]);
    agregarDetalleCPL($orden, precioUnitario: 800, costoUnitario: 200);
    agregarDetalleCPL($orden, precioUnitario: 200, costoUnitario: 100);
    agregarRefaccionCPL($orden, precioUnitarioCliente: 500, costoUnitario: 500);

    generarFinanzasOrden($orden);

    expect((float) $orden->fresh()->utilidad_neta)->toBe(650.0);
});

test('el anticipo no altera la utilidad', function () {
    $orden = crearOrdenCPLConCotizacion(anticipo: 400, comisionRecepcion: 50, items: [
        ['tipo' => 'servicio', 'descripcion' => 'Servicio A', 'cantidad' => 1, 'precio_unitario' => 800, 'costo_unitario' => 200],
        ['tipo' => 'servicio', 'descripcion' => 'Servicio B', 'cantidad' => 1, 'precio_unitario' => 200, 'costo_unitario' => 100],
        ['tipo' => 'refaccion', 'descripcion' => 'Refacción', 'cantidad' => 1, 'precio_unitario' => 500, 'costo_unitario' => 500],
    ]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect((float) $orden->fresh()->utilidad_neta)->toBe(650.0);
});

// --- 20: Idempotencia (cubierta también en el ejemplo obligatorio) ----------

test('segunda ejecucion no duplica movimientos ni cambia la utilidad', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 100);

    generarFinanzasOrden($orden);
    $cantidad = $orden->movimientosFinancieros()->count();
    $utilidad = (float) $orden->fresh()->utilidad_neta;

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());

    expect($orden->movimientosFinancieros()->count())->toBe($cantidad)
        ->and((float) $orden->fresh()->utilidad_neta)->toBe($utilidad);
});

// --- 21-22: Orden sin líneas / total cero ------------------------------------

test('orden sin lineas y comision cero es valida y no exige servicio ficticio', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);

    generarFinanzasOrden($orden);

    expect($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and($orden->fresh()->costos_incompletos)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0)
        ->and((float) $orden->fresh()->utilidad_neta)->toBe(0.0);
});

test('total cero no crea ingreso cero', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 0]);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('tipo', 'ingreso')->exists())->toBeFalse();
});

// --- 23: Legacy sigue igual ---------------------------------------------------

test('legacy sigue generando finanzas exactamente como antes junto a costos_por_linea', function () {
    $tecnico = partnerTecnicoCPL();
    $logistico = partnerRecepcionCPL();
    $orden = OrdenServicio::create([
        'folio' => 'OS-LEGACY-'.fake()->unique()->numerify('#####'),
        'cliente_id' => Cliente::create(['nombre' => 'Cliente legacy', 'telefono' => '4491112222', 'tipo_cliente' => 'mantenimiento'])->id,
        'creado_por_user_id' => User::factory()->create(['role' => 'admin'])->id,
        'tipo_recepcion' => 'directo',
        'estado' => 'recibido',
        'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
        'partner_tecnico_id' => $tecnico->id,
        'partner_recepcion_id' => $logistico->id,
        'costo_tecnico' => 200,
        'total_cliente' => 0,
        'utilidad_estimada' => 0,
        'utilidad_neta' => 0,
        'finanzas_generadas' => false,
    ]);
    $orden->detalles()->create(app(CalcularTotalesOrdenServicio::class)->detalle([
        'descripcion' => 'Servicio legacy', 'cantidad' => 1, 'precio_unitario' => 1500, 'costo_unitario' => 100,
    ]));
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect($orden->finanzas_generadas)->toBeTrue()
        ->and((float) $orden->comision_logistica)->toBe(999.0)
        ->and((float) $orden->utilidad_estimada)->toBe(201.0)
        ->and($orden->movimientosFinancieros()->where('categoria', 'pago_socio_logistico')->exists())->toBeTrue()
        ->and($orden->movimientosFinancieros()->where('categoria', 'pago_socio_tecnico')->exists())->toBeTrue()
        ->and($orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->exists())->toBeFalse();
});

// --- 24-25: Transacción / finanzas_generadas solo tras éxito ----------------

test('rollback transaccional: comision NULL detectada a mitad dejando la orden intacta', function () {
    $orden = ordenCostosPorLinea(['tipo_recepcion' => 'sucursal', 'comision_recepcion' => null]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 100);
    agregarRefaccionCPL($orden, precioUnitarioCliente: 200, costoUnitario: 50);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect(MovimientoFinanciero::count())->toBe(0)
        ->and($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and((float) $orden->fresh()->utilidad_neta)->toBe(0.0);
});

test('finanzas_generadas solo se activa despues de un exito completo', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50]);
    agregarDetalleCPL($orden, precioUnitario: 300, costoUnitario: 100);

    expect($orden->finanzas_generadas)->toBeFalse();

    generarFinanzasOrden($orden);

    expect($orden->fresh()->finanzas_generadas)->toBeTrue();
});

// --- 26: Snapshot de comisión no lee Partner::comision_fija -----------------

test('la comision usa el snapshot de la orden y no Partner::comision_fija', function () {
    $logistico = partnerRecepcionCPL();
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50, 'partner_recepcion_id' => $logistico->id]);

    $logistico->update(['comision_fija' => 12345]);

    generarFinanzasOrden($orden);

    $movimiento = $orden->movimientosFinancieros()->where('categoria', 'comision_recepcion')->sole();
    expect((float) $movimiento->monto)->toBe(50.0);
});

// --- 27: Categoría comision_recepcion representada correctamente -----------

test('la categoria comision_recepcion queda registrada tal cual en el movimiento', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50]);

    generarFinanzasOrden($orden);

    expect($orden->movimientosFinancieros()->where('categoria', GenerarFinanzasOrdenServicio::CATEGORIA_COMISION_RECEPCION)->exists())->toBeTrue();
});

// --- 28: Guards de estado ya existentes siguen aplicando --------------------

test('orden consolidada (orden_canonica_id) sigue bloqueada para costos_por_linea', function () {
    $canonica = ordenCostosPorLinea(['comision_recepcion' => 0]);
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50, 'orden_canonica_id' => $canonica->id]);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden))
        ->toThrow(ValidationException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0);
});

test('finanzas_generadas ya activo es idempotente y no vuelve a ejecutar', function () {
    $orden = ordenCostosPorLinea(['comision_recepcion' => 50, 'finanzas_generadas' => true]);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden);

    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(0);
});

// --- 15: Cotización aceptada con anticipo estructurado ----------------------

test('cotizacion aceptada con costos_por_linea reconcilia anticipo y usa lineas sincronizadas', function () {
    $cliente = Cliente::create(['nombre' => 'Cliente CPL cotizacion', 'telefono' => '4493334444', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
        'anticipo' => 400,
    ], [[
        'tipo' => 'servicio', 'descripcion' => 'Servicio CPL', 'cantidad' => 1, 'precio_unitario' => 1000, 'costo_unitario' => 300,
    ]], $admin);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $admin);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();
    $orden->update(['modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA, 'comision_recepcion' => 0]);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh());
    $orden->refresh();

    expect((float) $orden->movimientosFinancieros()->where('categoria', 'anticipo')->sum('monto'))->toBe(400.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'reparacion')->sum('monto'))->toBe(600.0)
        ->and((float) $orden->movimientosFinancieros()->where('categoria', 'servicio')->sum('monto'))->toBe(300.0)
        ->and((float) $orden->utilidad_neta)->toBe(700.0)
        ->and($orden->finanzas_generadas)->toBeTrue();
});

test('cotizacion con anticipo historico sin movimiento estructurado bloquea entrega en costos_por_linea', function () {
    $cliente = Cliente::create(['nombre' => 'Cliente CPL historico', 'telefono' => '4495556666', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'HP']);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $cotizacion = app(CrearCotizacion::class)->ejecutar([
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'fecha' => today()->format('Y-m-d'),
    ], [[
        'tipo' => 'servicio', 'descripcion' => 'Servicio CPL historico', 'cantidad' => 1, 'precio_unitario' => 1000, 'costo_unitario' => 300,
    ]], $admin);
    $cotizacion->update(['anticipo' => 400, 'saldo' => 600]);

    app(CambiarEstadoCotizacion::class)->ejecutar($cotizacion, 'aceptada', $admin);
    $orden = $cotizacion->fresh()->ordenServicio()->firstOrFail();
    $orden->update(['modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA, 'comision_recepcion' => 0]);
    app(RecalcularTotalesOrdenServicio::class)->ejecutar($orden);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden->fresh()))
        ->toThrow(ValidationException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and($orden->movimientosFinancieros()->count())->toBe(0);
});
