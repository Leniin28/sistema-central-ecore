<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Una orden con cotización vinculada sólo puede entregarse si esa cotización
 * ya está "aceptada". Una orden sin cotización (creada directamente) nunca
 * se bloquea por esto: ver ValidarCotizacionAceptadaParaEntrega.
 */
function contextoCotizacionEntrega(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Cliente cotización entrega', 'telefono' => '4491115500', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Acer']);

    return compact('admin', 'cliente', 'equipo');
}

function ordenConCotizacion(array $contexto, string $estadoCotizacion): OrdenServicio
{
    $cotizacion = Cotizacion::create([
        'folio' => 'COT-ENTREGA-'.fake()->unique()->numerify('#####'),
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'creado_por_user_id' => $contexto['admin']->id,
        'fecha' => today(),
        'estado' => $estadoCotizacion,
        'tipo_recepcion' => 'en_negocio',
        'subtotal' => 1000,
        'descuento' => 0,
        'anticipo' => 0,
        'total' => 1000,
        'saldo' => 1000,
    ]);

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'comision_recepcion' => 0,
        'notas' => 'Problema reportado:\nCon cotización',
    ], [[
        'descripcion' => 'Servicio con cotización',
        'cantidad' => 1,
        'precio_unitario' => 1000,
        'costo_unitario' => 0,
    ]], [], $contexto['admin']);

    $orden->update(['cotizacion_id' => $cotizacion->id]);

    return $orden->fresh();
}

test('no se puede entregar una orden cuya cotización vinculada aún no está aceptada', function () {
    $contexto = contextoCotizacionEntrega();
    $orden = ordenConCotizacion($contexto, 'enviada');

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionHasErrors('estado_nuevo');

    $errors = session('errors');
    expect($errors->get('estado_nuevo')[0])->toBe('No puedes entregar esta orden porque la cotización vinculada todavía no ha sido aceptada.');

    $orden->refresh();
    expect($orden->estado)->toBe('recibido')
        ->and($orden->finanzas_generadas)->toBeFalse()
        ->and(HistorialEstado::where('orden_servicio_id', $orden->id)->where('estado_nuevo', 'entregado')->exists())->toBeFalse()
        ->and(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->exists())->toBeFalse();
});

test('una orden con cotización aceptada sí puede entregarse', function () {
    $contexto = contextoCotizacionEntrega();
    $orden = ordenConCotizacion($contexto, 'aceptada');

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionDoesntHaveErrors();

    $orden->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeTrue();
});

test('una orden sin cotización vinculada no se bloquea por esta regla', function () {
    $contexto = contextoCotizacionEntrega();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'comision_recepcion' => 0,
        'notas' => 'Problema reportado:\nSin cotización',
    ], [[
        'descripcion' => 'Servicio sin cotización',
        'cantidad' => 1,
        'precio_unitario' => 500,
        'costo_unitario' => 0,
    ]], [], $contexto['admin']);

    expect($orden->cotizacion_id)->toBeNull();

    $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.show', $orden))
        ->post(route('admin.ordenes-servicio.estado.store', $orden), ['estado_nuevo' => 'entregado'])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden))
        ->assertSessionDoesntHaveErrors();

    $orden->refresh();
    expect($orden->estado)->toBe('entregado')
        ->and($orden->finanzas_generadas)->toBeTrue();
});
