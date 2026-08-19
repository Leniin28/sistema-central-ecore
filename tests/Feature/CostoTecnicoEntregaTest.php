<?php

use App\Actions\Ordenes\ActualizarOrdenServicio;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function contextoCostoTecnico(): array
{
    $logistica = Partner::create(['nombre' => 'Electrocom costos', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $tecnico = Partner::create(['nombre' => 'Fixop costos', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente costos técnicos', 'telefono' => '5551000', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo']);
    $categoria = CategoriaServicio::create(['nombre' => 'Categoría costos técnicos']);
    $servicio = Servicio::create([
        'categoria_servicio_id' => $categoria->id,
        'nombre' => 'Servicio costos técnicos',
        'precio_base' => 300,
        'activo' => true,
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logistico = User::factory()->create([
        'role' => 'socio_logistico',
        'partner_id' => $logistica->id,
        'email_verified_at' => now(),
    ]);

    return compact('logistica', 'tecnico', 'cliente', 'equipo', 'servicio', 'admin', 'logistico');
}

function crearOrdenCostoTecnico(array $contexto, ?float $costoTecnico, bool $asignarTecnico = true): OrdenServicio
{
    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'partner_recepcion_id' => $contexto['logistica']->id,
        'partner_tecnico_id' => $asignarTecnico ? $contexto['tecnico']->id : null,
        'tipo_recepcion' => 'sucursal',
        'costo_tecnico' => $costoTecnico,
    ], [[
        'servicio_id' => $contexto['servicio']->id,
        'descripcion' => $contexto['servicio']->nombre,
        'cantidad' => 1,
        'precio_unitario' => 300,
        'costo_unitario' => 0,
    ]], [], $contexto['admin']);
}

function payloadEdicionCostoTecnico(array $contexto, OrdenServicio $orden, mixed $costoTecnico, ?int $partnerTecnicoId = null): array
{
    return [
        'cliente_id' => $orden->cliente_id,
        'equipo_id' => $orden->equipo_id,
        'tipo_recepcion' => $orden->tipo_recepcion,
        'partner_recepcion_id' => $orden->partner_recepcion_id,
        'partner_tecnico_id' => $partnerTecnicoId ?? $orden->partner_tecnico_id,
        'costo_tecnico' => $costoTecnico,
        'notas' => $orden->notas,
        'servicios' => [[
            'servicio_id' => $contexto['servicio']->id,
            'descripcion' => $contexto['servicio']->nombre,
            'cantidad' => 1,
            'precio_unitario' => 300,
            'costo_unitario' => 0,
        ]],
        'refacciones' => [],
    ];
}

test('la creación directa desde el panel deja pendiente el costo técnico', function () {
    $contexto = contextoCostoTecnico();

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.store'), [
            ...payloadEdicionCostoTecnico($contexto, new OrdenServicio([
                'cliente_id' => $contexto['cliente']->id,
                'equipo_id' => $contexto['equipo']->id,
                'tipo_recepcion' => 'sucursal',
                'partner_recepcion_id' => $contexto['logistica']->id,
                'partner_tecnico_id' => $contexto['tecnico']->id,
            ]), null),
        ])
        ->assertRedirect();

    $orden = OrdenServicio::latest('id')->firstOrFail();

    expect($orden->costo_tecnico)->toBeNull()
        ->and($orden->costos_incompletos)->toBeTrue();
});

test('socio logístico no puede inyectar un costo técnico al crear', function () {
    $contexto = contextoCostoTecnico();

    $this->actingAs($contexto['logistico'])
        ->post(route('logistica.ordenes-servicio.store'), [
            ...payloadEdicionCostoTecnico($contexto, new OrdenServicio([
                'cliente_id' => $contexto['cliente']->id,
                'equipo_id' => $contexto['equipo']->id,
                'tipo_recepcion' => 'sucursal',
                'partner_recepcion_id' => $contexto['logistica']->id,
                'partner_tecnico_id' => $contexto['tecnico']->id,
            ]), 999),
        ])
        ->assertRedirect();

    expect(OrdenServicio::latest('id')->firstOrFail()->costo_tecnico)->toBeNull();
});

test('la edición admin distingue costo técnico vacío de cero explícito', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, 125);

    $this->actingAs($contexto['admin'])
        ->put(route('admin.ordenes-servicio.update', $orden), payloadEdicionCostoTecnico($contexto, $orden, ''))
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    expect($orden->fresh()->costo_tecnico)->toBeNull()
        ->and($orden->fresh()->costos_incompletos)->toBeTrue();

    $this->actingAs($contexto['admin'])
        ->put(route('admin.ordenes-servicio.update', $orden), payloadEdicionCostoTecnico($contexto, $orden, 0))
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    expect((float) $orden->fresh()->costo_tecnico)->toBe(0.0)
        ->and($orden->fresh()->costos_incompletos)->toBeFalse();
});

test('cambiar el partner técnico reinicia el costo confirmado', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, 125);
    $otroTecnico = Partner::create(['nombre' => 'Otro técnico', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);

    app(ActualizarOrdenServicio::class)->ejecutar(
        $orden,
        collect(payloadEdicionCostoTecnico($contexto, $orden, 125, $otroTecnico->id))->except(['servicios', 'refacciones'])->all(),
        payloadEdicionCostoTecnico($contexto, $orden, 125)['servicios'],
        [],
        $contexto['admin'],
    );

    expect($orden->fresh()->partner_tecnico_id)->toBe($otroTecnico->id)
        ->and($orden->fresh()->costo_tecnico)->toBeNull()
        ->and($orden->fresh()->costos_incompletos)->toBeTrue();
});

test('admin no puede entregar con Fixop asignado y costo pendiente', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, null);

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionHasErrors('estado_nuevo');

    expect($orden->fresh()->estado)->toBe('recibido')
        ->and($orden->fresh()->fecha_entrega)->toBeNull()
        ->and($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(HistorialEstado::where('orden_servicio_id', $orden->id)->where('estado_nuevo', 'entregado')->exists())->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->exists())->toBeFalse();
});

test('socio logístico tampoco puede entregar con costo técnico pendiente', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, null);
    $orden->update(['estado' => 'listo_para_entregar']);

    $this->actingAs($contexto['logistico'])
        ->from(route('logistica.ordenes-servicio.show', $orden))
        ->post(route('logistica.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('logistica.ordenes-servicio.show', $orden))
        ->assertSessionHasErrors('estado_nuevo');

    expect($orden->fresh()->estado)->toBe('listo_para_entregar')
        ->and($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->exists())->toBeFalse();
});

test('la API devuelve 409 sin cambios parciales cuando el costo técnico está pendiente', function () {
    config(['services.openclaw.internal_api_token' => 'token-secreto-pruebas']);
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, null);

    $this->withToken('token-secreto-pruebas')
        ->postJson("/api/internal/service-orders/{$orden->folio}/status", [
            'estado' => 'entregado',
            'external_id' => 'costo-tecnico-pendiente-api',
            'confirm_final_delivery' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('requires_confirmation', false);

    expect($orden->fresh()->estado)->toBe('recibido')
        ->and($orden->fresh()->fecha_entrega)->toBeNull()
        ->and($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->exists())->toBeFalse();
});

test('el servicio financiero rechaza defensivamente un costo técnico pendiente', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, null);

    expect(fn () => app(GenerarFinanzasOrdenServicio::class)->generar($orden))
        ->toThrow(CostoTecnicoPendienteException::class);

    expect($orden->fresh()->finanzas_generadas)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->exists())->toBeFalse();
});

test('una orden interna sin partner técnico puede entregarse con costo nulo', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, null, false);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    expect($orden->fresh()->estado)->toBe('entregado')
        ->and($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and($orden->fresh()->costos_incompletos)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'pago_socio_tecnico')->exists())->toBeFalse();
});

test('costo técnico cero permite entregar sin crear pago técnico', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, 0);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    expect($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and($orden->fresh()->costos_incompletos)->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'pago_socio_tecnico')->exists())->toBeFalse();
});

test('costo técnico positivo crea exactamente un pago para el partner asignado', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, 125.50);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    $movimientos = MovimientoFinanciero::where('orden_servicio_id', $orden->id)->where('categoria', 'pago_socio_tecnico')->get();

    expect($orden->fresh()->finanzas_generadas)->toBeTrue()
        ->and($movimientos)->toHaveCount(1)
        ->and($movimientos->sole()->partner_id)->toBe($contexto['tecnico']->id)
        ->and((float) $movimientos->sole()->monto)->toBe(125.5);
});

test('la migración final permite null y conserva un cero explícito', function () {
    $contexto = contextoCostoTecnico();
    $orden = crearOrdenCostoTecnico($contexto, 0);
    $columna = collect(Schema::getColumns('ordenes_servicio'))->firstWhere('name', 'costo_tecnico');

    expect($columna['nullable'])->toBeTrue()
        ->and((float) $orden->fresh()->costo_tecnico)->toBe(0.0);
});
