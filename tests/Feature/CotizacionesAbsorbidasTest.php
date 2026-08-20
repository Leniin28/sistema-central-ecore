<?php

use App\Actions\Cotizaciones\ActualizarCotizacion;
use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\ConvertirCotizacionEnOrden;
use App\Actions\Cotizaciones\RegistrarAnticipoCotizacion;
use App\Actions\Cotizaciones\VincularCotizacionAOrden;
use App\Actions\Ordenes\CambiarEstadoOrdenDesdeOpenClaw;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Exceptions\OrdenBloqueadaException;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{admin: User, cliente: Cliente, equipo: Equipo, canonica: Cotizacion, absorbida: Cotizacion} */
function contextoCotizacionesAbsorbidas(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create([
        'nombre' => 'Cliente cotización absorbida',
        'telefono' => '4491002200',
        'tipo_cliente' => 'mantenimiento',
    ]);
    $equipo = Equipo::create([
        'cliente_id' => $cliente->id,
        'tipo_equipo' => 'Laptop',
        'marca' => 'Dell',
    ]);
    $canonica = Cotizacion::create([
        'folio' => 'COT-CANONICA-0001',
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'creado_por_user_id' => $admin->id,
        'fecha' => today(),
        'estado' => 'aceptada',
        'tipo_recepcion' => 'en_negocio',
        'subtotal' => 500,
        'descuento' => 0,
        'anticipo' => 0,
        'total' => 500,
        'saldo' => 500,
    ]);
    $canonica->items()->create([
        'tipo' => 'servicio',
        'descripcion' => 'Servicio canónico',
        'cantidad' => 1,
        'precio_unitario' => 500,
        'costo_unitario' => 100,
        'costo_total' => 100,
        'subtotal' => 500,
    ]);
    $absorbida = Cotizacion::create([
        'cotizacion_canonica_id' => $canonica->id,
        'folio' => 'COT-ABSORBIDA-0002',
        'cliente_id' => $cliente->id,
        'equipo_id' => $equipo->id,
        'creado_por_user_id' => $admin->id,
        'fecha' => today(),
        'estado' => 'aceptada',
        'tipo_recepcion' => 'en_negocio',
        'subtotal' => 120,
        'descuento' => 0,
        'anticipo' => 0,
        'total' => 120,
        'saldo' => 120,
    ]);
    $absorbida->items()->create([
        'tipo' => 'servicio',
        'descripcion' => 'Servicio histórico absorbido',
        'cantidad' => 1,
        'precio_unitario' => 120,
        'costo_unitario' => null,
        'costo_total' => null,
        'subtotal' => 120,
    ]);

    return compact('admin', 'cliente', 'equipo', 'canonica', 'absorbida');
}

function crearOrdenParaCotizacionAbsorbida(array $contexto): OrdenServicio
{
    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'costo_tecnico' => 0,
    ], [], [], $contexto['admin']);
}

test('convertir una cotizacion absorbida se rechaza antes de crear una orden', function () {
    $contexto = contextoCotizacionesAbsorbidas();

    expect(fn () => app(ConvertirCotizacionEnOrden::class)->ejecutar($contexto['absorbida'], [
        'actor' => $contexto['admin'],
        'recepcion' => ['falla_reportada' => 'Intento server-side'],
    ]))->toThrow(ValidationException::class, 'La cotización consolidada es histórica');

    expect(OrdenServicio::count())->toBe(0);
});

test('una cotizacion absorbida no puede cambiar ni reafirmar su estado', function () {
    $contexto = contextoCotizacionesAbsorbidas();
    $accion = app(CambiarEstadoCotizacion::class);

    expect(fn () => $accion->ejecutar($contexto['absorbida'], 'rechazada', $contexto['admin']))
        ->toThrow(ValidationException::class, 'La cotización consolidada es histórica');
    expect(fn () => $accion->ejecutar($contexto['absorbida'], 'aceptada', $contexto['admin']))
        ->toThrow(ValidationException::class, 'La cotización consolidada es histórica');

    expect($contexto['absorbida']->fresh()->estado)->toBe('aceptada')
        ->and(OrdenServicio::count())->toBe(0);
});

test('una invocacion directa no puede actualizar una cotizacion absorbida', function () {
    $contexto = contextoCotizacionesAbsorbidas();

    expect(fn () => app(ActualizarCotizacion::class)->ejecutar(
        $contexto['absorbida'],
        [
            'cliente_id' => $contexto['cliente']->id,
            'equipo_id' => $contexto['equipo']->id,
            'fecha' => today()->format('Y-m-d'),
            'anticipo' => 0,
        ],
        [],
        $contexto['admin'],
    ))->toThrow(ValidationException::class, 'La cotización consolidada es histórica');

    expect((float) $contexto['absorbida']->fresh()->total)->toBe(120.0);
});

test('una cotizacion absorbida no puede vincularse ni desvincularse de una orden', function () {
    $contexto = contextoCotizacionesAbsorbidas();
    $orden = crearOrdenParaCotizacionAbsorbida($contexto);
    $accion = app(VincularCotizacionAOrden::class);

    expect(fn () => $accion->vincular($contexto['absorbida'], $orden->id, $contexto['admin']))
        ->toThrow(ValidationException::class, 'La cotización consolidada es histórica');
    expect(fn () => $accion->vincular($contexto['absorbida'], null, $contexto['admin']))
        ->toThrow(ValidationException::class, 'La cotización consolidada es histórica');
    expect(fn () => $accion->elegibles(
        $contexto['cliente']->id,
        $contexto['equipo']->id,
        $contexto['absorbida'],
    ))->toThrow(ValidationException::class, 'La cotización consolidada es histórica');

    expect($orden->fresh()->cotizacion_id)->toBeNull();
});

test('una cotizacion absorbida no puede registrar anticipos', function () {
    $contexto = contextoCotizacionesAbsorbidas();

    expect(fn () => app(RegistrarAnticipoCotizacion::class)->registrarCambio(
        $contexto['absorbida'],
        0,
        100,
        $contexto['admin'],
    ))->toThrow(ValidationException::class, 'La cotización consolidada es histórica');

    expect(MovimientoFinanciero::count())->toBe(0)
        ->and((float) $contexto['absorbida']->fresh()->anticipo)->toBe(0.0);
});

test('la api interna rechaza manipulacion server-side de una cotizacion absorbida', function () {
    config(['services.openclaw.internal_api_token' => 'token-absorbida']);
    $contexto = contextoCotizacionesAbsorbidas();

    $this->withToken('token-absorbida')
        ->postJson("/api/internal/quotes/{$contexto['absorbida']->id}/convert-to-order", [
            'external_id' => 'intento-absorbida-1',
            'recepcion' => ['falla_reportada' => 'Intento API'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cotizacion');

    expect(OrdenServicio::count())->toBe(0);
});

test('la api no reutiliza una orden por external id para una cotizacion absorbida', function () {
    config(['services.openclaw.internal_api_token' => 'token-absorbida']);
    $contexto = contextoCotizacionesAbsorbidas();
    $existente = crearOrdenParaCotizacionAbsorbida($contexto);
    $existente->update(['external_id' => 'reintento-absorbida']);

    $this->withToken('token-absorbida')
        ->postJson("/api/internal/quotes/{$contexto['absorbida']->id}/convert-to-order", [
            'external_id' => 'reintento-absorbida',
            'recepcion' => ['falla_reportada' => 'Intento API idempotente'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cotizacion');

    expect(OrdenServicio::count())->toBe(1)
        ->and($existente->fresh()->cotizacion_id)->toBeNull();
});

test('la cotizacion canonica continua siendo operativa', function () {
    $contexto = contextoCotizacionesAbsorbidas();

    $resultado = app(ConvertirCotizacionEnOrden::class)->ejecutar($contexto['canonica'], [
        'actor' => $contexto['admin'],
        'recepcion' => ['falla_reportada' => 'Trabajo canónico autorizado'],
    ]);

    expect($contexto['canonica']->esAbsorbida())->toBeFalse()
        ->and($resultado['created'])->toBeTrue()
        ->and($resultado['orden']->cotizacion_id)->toBe($contexto['canonica']->id)
        ->and(OrdenServicio::count())->toBe(1);
});

test('show mantiene visible la absorbida con enlace a la canonica y sin acciones operativas', function () {
    $contexto = contextoCotizacionesAbsorbidas();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.cotizaciones.show', $contexto['absorbida']))
        ->assertOk()
        ->assertSee('Cotización histórica consolidada')
        ->assertSee('Cotización consolidada en')
        ->assertSee($contexto['canonica']->folio)
        ->assertSee(route('admin.cotizaciones.show', $contexto['canonica']), escape: false)
        ->assertDontSee('Editar')
        ->assertDontSee('Cambiar estado')
        ->assertDontSee('Actualizar estado');

    $this->actingAs($contexto['admin'])
        ->get(route('admin.cotizaciones.edit', $contexto['absorbida']))
        ->assertForbidden();
});

test('las ordenes duplicadas consolidadas siguen bloqueadas en panel openclaw y finanzas', function () {
    $contexto = contextoCotizacionesAbsorbidas();
    $canonica = crearOrdenParaCotizacionAbsorbida($contexto);
    $duplicada = crearOrdenParaCotizacionAbsorbida($contexto);
    $duplicada->update([
        'estado' => 'cancelado',
        'orden_canonica_id' => $canonica->id,
    ]);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $duplicada), ['estado_nuevo' => 'entregado'])
        ->assertForbidden();

    expect(fn () => app(CambiarEstadoOrdenDesdeOpenClaw::class)->ejecutar(
        $duplicada,
        'entregado',
        confirmarEntrega: true,
    ))->toThrow(OrdenBloqueadaException::class)
        ->and(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($duplicada))
        ->toThrow(ValidationException::class);

    expect($duplicada->fresh()->estado)->toBe('cancelado')
        ->and($duplicada->movimientosFinancieros()->count())->toBe(0);
});
