<?php

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contextoFilasDinamicas(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Cliente filas dinámicas', 'telefono' => '4491002000', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo']);
    $categoria = CategoriaServicio::create(['nombre' => 'Categoría filas dinámicas']);
    $servicio = Servicio::create([
        'categoria_servicio_id' => $categoria->id,
        'nombre' => 'Servicio dinámico',
        'precio_base' => 100,
        'activo' => true,
    ]);

    return compact('admin', 'cliente', 'equipo', 'servicio');
}

function serviciosDinamicos(array $contexto, int $cantidad, bool $invalido = false): array
{
    return collect(range(1, $cantidad))->map(fn (int $numero): array => [
        'servicio_id' => $contexto['servicio']->id,
        'descripcion' => "Servicio enviado {$numero}",
        'cantidad' => 1,
        'costo_unitario' => 5,
        'precio_unitario' => $invalido && $numero === $cantidad ? -1 : 99 + $numero,
        'notas' => "Nota servicio {$numero}",
    ])->all();
}

function refaccionesDinamicas(int $cantidad): array
{
    return collect(range(1, $cantidad))->map(fn (int $numero): array => [
        'descripcion' => "Refacción enviada {$numero}",
        'cantidad' => 1,
        'costo_unitario' => 9 + $numero,
        'precio_unitario_cliente' => 49 + $numero,
        'notas' => "Nota refacción {$numero}",
    ])->all();
}

function datosBaseOrdenDinamica(array $contexto): array
{
    return [
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'tipo_recepcion' => 'directo',
        'partner_recepcion_id' => null,
        'partner_tecnico_id' => null,
        'costo_tecnico' => null,
        'notas' => 'Orden con filas dinámicas',
    ];
}

test('una orden nueva muestra una sola fila inicial por tipo y controles para agregar', function () {
    $contexto = contextoFilasDinamicas();

    $response = $this->actingAs($contexto['admin'])->get(route('admin.ordenes-servicio.create'));

    $response->assertOk()
        ->assertSee('+ Agregar servicio')
        ->assertSee('+ Agregar refacción');

    preg_match_all('/data-service-row data-row-index="\d+"/', $response->getContent(), $serviceRows);
    preg_match_all('/data-part-row data-row-index="\d+"/', $response->getContent(), $partRows);

    expect($serviceRows[0])->toHaveCount(1)
        ->and($partRows[0])->toHaveCount(1);
});

test('edición renderiza todos los servicios y refacciones aunque excedan cinco', function () {
    $contexto = contextoFilasDinamicas();
    $orden = app(CrearOrdenServicio::class)->ejecutar(
        datosBaseOrdenDinamica($contexto),
        serviciosDinamicos($contexto, 6),
        refaccionesDinamicas(7),
        $contexto['admin'],
    );

    $response = $this->actingAs($contexto['admin'])->get(route('admin.ordenes-servicio.edit', $orden));

    $response->assertOk()
        ->assertSee('Servicio enviado 6')
        ->assertSee('Refacción enviada 7');
    preg_match_all('/data-service-row data-row-index="\d+"/', $response->getContent(), $serviceRows);
    preg_match_all('/data-part-row data-row-index="\d+"/', $response->getContent(), $partRows);

    expect($serviceRows[0])->toHaveCount(6)
        ->and($partRows[0])->toHaveCount(7);
});

test('creación procesa múltiples filas dinámicas y calcula sus totales en el servidor', function () {
    $contexto = contextoFilasDinamicas();

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.store'), [
            ...datosBaseOrdenDinamica($contexto),
            'servicios' => serviciosDinamicos($contexto, 6),
            'refacciones' => refaccionesDinamicas(6),
        ])
        ->assertRedirect();

    $orden = OrdenServicio::sole();

    expect($orden->detalles()->count())->toBe(6)
        ->and($orden->refacciones()->count())->toBe(6)
        ->and((float) $orden->total_cliente)->toBe(930.0)
        ->and((float) $orden->utilidad_estimada)->toBe(825.0);
});

test('actualización sustituye las colecciones por todas las filas enviadas', function () {
    $contexto = contextoFilasDinamicas();
    $orden = app(CrearOrdenServicio::class)->ejecutar(
        datosBaseOrdenDinamica($contexto),
        serviciosDinamicos($contexto, 1),
        refaccionesDinamicas(1),
        $contexto['admin'],
    );

    $this->actingAs($contexto['admin'])
        ->put(route('admin.ordenes-servicio.update', $orden), [
            ...datosBaseOrdenDinamica($contexto),
            'servicios' => serviciosDinamicos($contexto, 6),
            'refacciones' => refaccionesDinamicas(6),
        ])
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    expect($orden->fresh()->detalles()->count())->toBe(6)
        ->and($orden->fresh()->refacciones()->count())->toBe(6)
        ->and((float) $orden->fresh()->total_cliente)->toBe(930.0);
});

test('error de validación conserva todas las filas y sus valores old', function () {
    $contexto = contextoFilasDinamicas();

    $response = $this->actingAs($contexto['admin'])
        ->from(route('admin.ordenes-servicio.create'))
        ->followingRedirects()
        ->post(route('admin.ordenes-servicio.store'), [
            ...datosBaseOrdenDinamica($contexto),
            'servicios' => serviciosDinamicos($contexto, 7, invalido: true),
            'refacciones' => refaccionesDinamicas(7),
        ]);

    $response->assertOk()
        ->assertSee('Servicio enviado 7')
        ->assertSee('Refacción enviada 7');
    preg_match_all('/data-service-row data-row-index="\d+"/', $response->getContent(), $serviceRows);
    preg_match_all('/data-part-row data-row-index="\d+"/', $response->getContent(), $partRows);

    expect($serviceRows[0])->toHaveCount(7)
        ->and($partRows[0])->toHaveCount(7)
        ->and(OrdenServicio::count())->toBe(0);
});

test('flujo normal conserva un servicio y una refacción', function () {
    $contexto = contextoFilasDinamicas();

    $this->actingAs($contexto['admin'])
        ->post(route('admin.ordenes-servicio.store'), [
            ...datosBaseOrdenDinamica($contexto),
            'servicios' => serviciosDinamicos($contexto, 1),
            'refacciones' => refaccionesDinamicas(1),
        ])
        ->assertRedirect();

    $orden = OrdenServicio::sole();
    expect($orden->detalles()->count())->toBe(1)
        ->and($orden->refacciones()->count())->toBe(1)
        ->and((float) $orden->total_cliente)->toBe(150.0);
});
