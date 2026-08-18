<?php

use App\Actions\Ordenes\ActualizarOrdenServicio;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function datosOrden(): array
{
    $logistica = Partner::create(['nombre' => 'Electrocom', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $tecnico = Partner::create(['nombre' => 'Fixop', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente prueba', 'telefono' => '555', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo']);
    $categoria = CategoriaServicio::create(['nombre' => 'Diagnóstico']);
    $servicio = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Diagnóstico', 'precio_base' => 300, 'activo' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    return compact('logistica', 'tecnico', 'cliente', 'equipo', 'servicio', 'admin');
}

function crearOrdenPrueba(array $datos): OrdenServicio
{
    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $datos['cliente']->id,
        'equipo_id' => $datos['equipo']->id,
        'partner_recepcion_id' => $datos['logistica']->id,
        'partner_tecnico_id' => $datos['tecnico']->id,
        'tipo_recepcion' => 'sucursal',
        'costo_tecnico' => 100,
        'notas' => 'Prueba',
    ], [[
        'servicio_id' => $datos['servicio']->id,
        'cantidad' => 1,
        'precio_unitario' => 300,
        'notas' => null,
    ]], [[
        'descripcion' => 'Refacción',
        'cantidad' => 1,
        'costo_unitario' => 50,
        'precio_unitario_cliente' => 100,
        'notas' => null,
    ]], $datos['admin']);
}

test('una orden cerrada no puede editar datos economicos', function () {
    $datos = datosOrden();
    $orden = crearOrdenPrueba($datos);
    $orden->update(['estado' => 'entregado', 'finanzas_generadas' => true]);

    expect(fn () => app(ActualizarOrdenServicio::class)->ejecutar($orden, $orden->only([
        'cliente_id', 'equipo_id', 'partner_recepcion_id', 'partner_tecnico_id', 'tipo_recepcion', 'costo_tecnico', 'notas',
    ]), [], [], $datos['admin']))->toThrow(ValidationException::class);
});

test('generar finanzas dos veces no duplica movimientos', function () {
    $orden = crearOrdenPrueba(datosOrden());
    $orden->update(['estado' => 'entregado']);
    $servicio = app(GenerarFinanzasOrdenServicio::class);

    $servicio->generar($orden);
    $servicio->generar($orden->fresh());

    expect(MovimientoFinanciero::where('orden_servicio_id', $orden->id)->count())->toBe(4);
});

test('admin puede crear una recepcion completa', function () {
    $datos = datosOrden();

    $response = $this->actingAs($datos['admin'])->post(route('admin.recepciones.store'), [
        'cliente_modo' => 'nuevo',
        'cliente' => ['nombre' => 'Nuevo cliente', 'telefono' => '123', 'tipo_cliente' => 'mantenimiento'],
        'equipo_modo' => 'nuevo',
        'equipo' => ['tipo_equipo' => 'Laptop', 'marca' => 'Dell'],
        'orden' => [
            'tipo_recepcion' => 'sucursal',
            'partner_recepcion_id' => $datos['logistica']->id,
            'partner_tecnico_id' => $datos['tecnico']->id,
            'problema_reportado' => 'No enciende',
        ],
        'servicios' => [[
            'servicio_id' => $datos['servicio']->id,
            'cantidad' => 1,
            'precio_unitario' => 300,
        ]],
        'refacciones' => [],
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('clientes', ['nombre' => 'Nuevo cliente']);
    $this->assertDatabaseHas('ordenes_servicio', ['total_cliente' => 300]);
});

test('socio tecnico no puede acceder a nueva recepcion', function () {
    $partner = Partner::create(['nombre' => 'Fixop', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'partner_id' => $partner->id, 'email_verified_at' => now()]);

    $this->actingAs($tecnico)->get('/admin/recepciones/create')->assertForbidden();
    $this->actingAs($tecnico)->get('/logistica/recepciones/create')->assertForbidden();
});

test('socio logistico no puede inyectar costo interno en una refaccion', function () {
    $datos = datosOrden();
    $logistico = User::factory()->create([
        'role' => 'socio_logistico',
        'partner_id' => $datos['logistica']->id,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($logistico)
        ->post(route('logistica.ordenes-servicio.store'), [
            'cliente_id' => $datos['cliente']->id,
            'equipo_id' => $datos['equipo']->id,
            'tipo_recepcion' => 'directo',
            'servicios' => [[
                'servicio_id' => $datos['servicio']->id,
                'descripcion' => $datos['servicio']->nombre,
                'cantidad' => 1,
                'precio_unitario' => 300,
            ]],
            'refacciones' => [[
                'descripcion' => 'Refacción con costo inyectado',
                'cantidad' => 1,
                'costo_unitario' => 999999,
                'precio_unitario_cliente' => 100,
            ]],
        ])
        ->assertRedirect();

    $refaccion = OrdenServicio::latest('id')->firstOrFail()->refacciones()->sole();

    expect($refaccion->costo_unitario)->toBeNull()
        ->and($refaccion->costo_total)->toBeNull();
});
