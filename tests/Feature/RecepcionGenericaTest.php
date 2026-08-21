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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function contextoRecepcionGenerica(): array
{
    $partnerA = Partner::create(['nombre' => 'Electrocom Alameda', 'tipo_socio' => 'logistico', 'comision_fija' => 50, 'activo' => true]);
    $partnerB = Partner::create(['nombre' => 'Electrocom Centro', 'tipo_socio' => 'logistico', 'comision_fija' => 70, 'activo' => true]);
    $cliente = Cliente::create(['nombre' => 'Cliente recepción genérica', 'telefono' => '4490000000', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo']);
    $categoria = CategoriaServicio::create(['nombre' => 'Recepción genérica']);
    $servicio = Servicio::create(['categoria_servicio_id' => $categoria->id, 'nombre' => 'Diagnóstico general', 'precio_base' => 300, 'activo' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $logistico = User::factory()->create(['role' => 'socio_logistico', 'partner_id' => $partnerA->id, 'email_verified_at' => now()]);

    return compact('partnerA', 'partnerB', 'cliente', 'equipo', 'servicio', 'admin', 'logistico');
}

function payloadRecepcionGenerica(array $contexto, array $orden = [], array $servicios = []): array
{
    return [
        'cliente_modo' => 'existente',
        'cliente_id' => $contexto['cliente']->id,
        'equipo_modo' => 'existente',
        'equipo_id' => $contexto['equipo']->id,
        'orden' => [
            'tipo_recepcion' => 'sucursal',
            'problema_reportado' => 'No enciende',
            ...$orden,
        ],
        'servicios' => $servicios,
        'refacciones' => [],
    ];
}

function crearOrdenEditableRecepcionGenerica(array $contexto, array $atributos = []): OrdenServicio
{
    return app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'partner_recepcion_id' => $contexto['partnerA']->id,
        'tipo_recepcion' => 'sucursal',
        'costo_tecnico' => 100,
        'notas' => "Problema reportado:\nNo enciende",
        ...$atributos,
    ], [[
        'servicio_id' => $contexto['servicio']->id,
        'descripcion' => $contexto['servicio']->nombre,
        'cantidad' => 1,
        'precio_unitario' => 300,
        'costo_unitario' => 100,
    ]], [], $contexto['admin']);
}

function payloadEdicionRecepcionGenerica(array $contexto, OrdenServicio $orden, array $overrides = []): array
{
    return [
        'cliente_id' => $orden->cliente_id,
        'equipo_id' => $orden->equipo_id,
        'tipo_recepcion' => $orden->tipo_recepcion,
        'partner_recepcion_id' => $orden->partner_recepcion_id,
        'partner_tecnico_id' => $orden->partner_tecnico_id,
        'comision_recepcion' => $orden->comision_recepcion,
        'nota_recepcion' => $orden->nota_recepcion,
        'costo_tecnico' => $orden->costo_tecnico,
        'notas' => $orden->notas,
        'servicios' => [[
            'servicio_id' => $contexto['servicio']->id,
            'descripcion' => $contexto['servicio']->nombre,
            'cantidad' => 1,
            'precio_unitario' => 300,
            'costo_unitario' => 100,
        ]],
        'refacciones' => [],
        ...$overrides,
    ];
}

test('admin crea recepción genérica sin partner ni snapshot y sin servicios', function () {
    $contexto = contextoRecepcionGenerica();

    $this->actingAs($contexto['admin'])
        ->post(route('admin.recepciones.store'), payloadRecepcionGenerica($contexto))
        ->assertRedirect();

    $orden = OrdenServicio::latest('id')->firstOrFail();

    expect($orden->partner_recepcion_id)->toBeNull()
        ->and($orden->comision_recepcion)->toBeNull()
        ->and($orden->nota_recepcion)->toBeNull()
        ->and($orden->modelo_financiero)->toBe(OrdenServicio::MODELO_FINANCIERO_LEGACY)
        ->and($orden->comision_logistica)->toBe('0.00')
        ->and($orden->detalles()->count())->toBe(0)
        ->and($orden->movimientosFinancieros()->count())->toBe(0);
});

test('admin confirma comisión positiva o cero y la nota se recorta', function (string $valor, string $esperado) {
    $contexto = contextoRecepcionGenerica();

    $this->actingAs($contexto['admin'])
        ->post(route('admin.recepciones.store'), payloadRecepcionGenerica($contexto, [
            'partner_recepcion_id' => $contexto['partnerA']->id,
            'comision_recepcion' => $valor,
            'nota_recepcion' => '  Punto Centro  ',
        ]))
        ->assertRedirect();

    $orden = OrdenServicio::latest('id')->firstOrFail();
    expect($orden->comision_recepcion)->toBe($esperado)
        ->and($orden->nota_recepcion)->toBe('Punto Centro')
        ->and($orden->partner_recepcion_id)->toBe($contexto['partnerA']->id);
})->with([
    'positiva' => ['50', '50.00'],
    'cero entero' => ['0', '0.00'],
    'cero decimal' => ['0.00', '0.00'],
]);

test('comisión vacía se conserva como pendiente', function () {
    $contexto = contextoRecepcionGenerica();

    $this->actingAs($contexto['admin'])
        ->post(route('admin.recepciones.store'), payloadRecepcionGenerica($contexto, [
            'comision_recepcion' => '',
            'nota_recepcion' => '   ',
        ]))
        ->assertRedirect();

    $orden = OrdenServicio::latest('id')->firstOrFail();
    expect($orden->comision_recepcion)->toBeNull()
        ->and($orden->nota_recepcion)->toBeNull();
});

test('nota de recepción respeta máximo de 255 caracteres', function () {
    $contexto = contextoRecepcionGenerica();

    $this->actingAs($contexto['admin'])
        ->from(route('admin.recepciones.create'))
        ->post(route('admin.recepciones.store'), payloadRecepcionGenerica($contexto, [
            'nota_recepcion' => str_repeat('a', 256),
        ]))
        ->assertRedirect(route('admin.recepciones.create'))
        ->assertSessionHasErrors('orden.nota_recepcion');

    expect(OrdenServicio::count())->toBe(0);

    $this->actingAs($contexto['admin'])
        ->post(route('admin.recepciones.store'), payloadRecepcionGenerica($contexto, [
            'nota_recepcion' => ['valor no permitido'],
        ]))
        ->assertSessionHasErrors('orden.nota_recepcion');

    expect(OrdenServicio::count())->toBe(0);
});

test('socio logístico conserva su partner y nota pero no puede enviar comisión', function () {
    $contexto = contextoRecepcionGenerica();

    $this->actingAs($contexto['logistico'])
        ->from(route('logistica.recepciones.create'))
        ->post(route('logistica.recepciones.store'), payloadRecepcionGenerica($contexto, [
            'partner_recepcion_id' => $contexto['partnerB']->id,
            'comision_recepcion' => '50',
            'nota_recepcion' => 'Recibido con Juan',
        ]))
        ->assertRedirect(route('logistica.recepciones.create'))
        ->assertSessionHasErrors('orden.comision_recepcion');

    expect(OrdenServicio::count())->toBe(0);

    $this->actingAs($contexto['logistico'])
        ->post(route('logistica.recepciones.store'), payloadRecepcionGenerica($contexto, [
            'partner_recepcion_id' => $contexto['partnerB']->id,
            'nota_recepcion' => '  Recibido con Juan  ',
        ]))
        ->assertRedirect();

    $orden = OrdenServicio::latest('id')->firstOrFail();
    expect($orden->partner_recepcion_id)->toBe($contexto['partnerA']->id)
        ->and($orden->comision_recepcion)->toBeNull()
        ->and($orden->nota_recepcion)->toBe('Recibido con Juan');
});

test('guard de dominio borra comisión manipulada por socio logístico', function () {
    $contexto = contextoRecepcionGenerica();

    $orden = app(CrearOrdenServicio::class)->ejecutar([
        'cliente_id' => $contexto['cliente']->id,
        'equipo_id' => $contexto['equipo']->id,
        'partner_recepcion_id' => $contexto['partnerB']->id,
        'tipo_recepcion' => 'sucursal',
        'comision_recepcion' => 80,
        'nota_recepcion' => 'Punto interno',
    ], [], [], $contexto['logistico']);

    expect($orden->partner_recepcion_id)->toBe($contexto['partnerA']->id)
        ->and($orden->comision_recepcion)->toBeNull()
        ->and($orden->nota_recepcion)->toBe('Punto interno');
});

test('admin edita el snapshot entre pendiente cero y positivo sin recalcular finanzas', function () {
    $contexto = contextoRecepcionGenerica();
    $orden = crearOrdenEditableRecepcionGenerica($contexto, ['comision_recepcion' => null]);
    $utilidadInicial = $orden->utilidad_estimada;

    foreach ([
        ['50', '50.00'],
        ['0', '0.00'],
        ['', null],
    ] as [$valor, $esperado]) {
        $this->actingAs($contexto['admin'])
            ->put(route('admin.ordenes-servicio.update', $orden), payloadEdicionRecepcionGenerica($contexto, $orden->fresh(), [
                'comision_recepcion' => $valor,
                'nota_recepcion' => '  Referencia corregida  ',
            ]))
            ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

        $orden->refresh();
        expect($orden->comision_recepcion)->toBe($esperado)
            ->and($orden->nota_recepcion)->toBe('Referencia corregida')
            ->and($orden->utilidad_estimada)->toBe($utilidadInicial)
            ->and($orden->comision_logistica)->toBe('0.00')
            ->and(MovimientoFinanciero::count())->toBe(0);
    }
});

test('cambiar partner no modifica una comisión ya confirmada', function () {
    $contexto = contextoRecepcionGenerica();
    $orden = crearOrdenEditableRecepcionGenerica($contexto, ['comision_recepcion' => 50]);

    $this->actingAs($contexto['admin'])
        ->put(route('admin.ordenes-servicio.update', $orden), payloadEdicionRecepcionGenerica($contexto, $orden, [
            'partner_recepcion_id' => $contexto['partnerB']->id,
            'comision_recepcion' => '50.00',
        ]))
        ->assertRedirect(route('admin.ordenes-servicio.show', $orden));

    $orden->refresh();
    expect($orden->partner_recepcion_id)->toBe($contexto['partnerB']->id)
        ->and($orden->comision_recepcion)->toBe('50.00');
});

test('detalle interno muestra el snapshot al admin sin exponerlo al socio', function () {
    $contexto = contextoRecepcionGenerica();
    $orden = crearOrdenEditableRecepcionGenerica($contexto, [
        'comision_recepcion' => 0,
        'nota_recepcion' => 'REFERENCIA-INTERNA-SHOW',
    ]);

    $this->actingAs($contexto['admin'])
        ->get(route('admin.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertSee('Comisión de recepción')
        ->assertSee('$0.00')
        ->assertSee('REFERENCIA-INTERNA-SHOW')
        ->assertSee('no es un costo activo de esta orden legacy');

    $this->actingAs($contexto['logistico'])
        ->get(route('logistica.ordenes-servicio.show', $orden))
        ->assertOk()
        ->assertDontSee('Comisión de recepción')
        ->assertDontSee('REFERENCIA-INTERNA-SHOW');
});

test('órdenes entregadas canceladas con finanzas o consolidadas no permiten editar snapshots', function () {
    $contexto = contextoRecepcionGenerica();
    $canonica = crearOrdenEditableRecepcionGenerica($contexto);

    foreach ([
        ['estado' => 'entregado'],
        ['estado' => 'cancelado'],
        ['finanzas_generadas' => true],
        ['orden_canonica_id' => $canonica->id],
    ] as $bloqueo) {
        $orden = crearOrdenEditableRecepcionGenerica($contexto, ['comision_recepcion' => 50, 'nota_recepcion' => 'Original']);
        $orden->update($bloqueo);

        expect(fn () => app(ActualizarOrdenServicio::class)->ejecutar(
            $orden,
            [
                ...$orden->only(['cliente_id', 'equipo_id', 'partner_recepcion_id', 'partner_tecnico_id', 'tipo_recepcion', 'costo_tecnico', 'notas']),
                'comision_recepcion' => 70,
                'nota_recepcion' => 'Alterada',
            ],
            [],
            [],
            $contexto['admin'],
        ))->toThrow(ValidationException::class);

        $orden->refresh();
        expect($orden->comision_recepcion)->toBe('50.00')
            ->and($orden->nota_recepcion)->toBe('Original');
    }
});

test('edición cancelada o consolidada se presenta como solo lectura', function () {
    $contexto = contextoRecepcionGenerica();
    $canonica = crearOrdenEditableRecepcionGenerica($contexto);

    foreach ([
        ['estado' => 'cancelado'],
        ['orden_canonica_id' => $canonica->id],
    ] as $bloqueo) {
        $orden = crearOrdenEditableRecepcionGenerica($contexto);
        $orden->update($bloqueo);

        $this->actingAs($contexto['admin'])
            ->get(route('admin.ordenes-servicio.edit', $orden))
            ->assertOk()
            ->assertSee('solo lectura')
            ->assertDontSee('Guardar orden');
    }
});

test('formularios muestran sugerencia sin activar la comisión en finanzas legacy', function () {
    $contexto = contextoRecepcionGenerica();

    $this->actingAs($contexto['admin'])
        ->get(route('admin.recepciones.create'))
        ->assertOk()
        ->assertSee('Punto / socio de recepción (opcional)')
        ->assertSee('Comisión de recepción')
        ->assertSee('Referencia interna opcional')
        ->assertSee('data-commission="50"', false);
});
