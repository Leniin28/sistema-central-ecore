<?php

use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin searches clients by name or phone and receives recent results', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $nombre = Cliente::create(['nombre' => 'Rosa Hernández', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);
    $telefono = Cliente::create(['nombre' => 'Cliente distinto', 'telefono' => '4499990000', 'tipo_cliente' => 'mantenimiento']);

    $this->actingAs($admin)
        ->getJson(route('admin.clientes.buscar', ['q' => 'Rosa']))
        ->assertOk()
        ->assertJsonPath('data.0.value', (string) $nombre->id);

    $this->actingAs($admin)
        ->getJson(route('admin.clientes.buscar', ['q' => '9990000']))
        ->assertOk()
        ->assertJsonPath('data.0.value', (string) $telefono->id);
});

test('a newly created Fernanda is searchable by name phone and recent clients', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $fernanda = Cliente::create([
        'nombre' => 'Fernanda',
        'telefono' => '4491239876',
        'tipo_cliente' => 'mantenimiento',
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.clientes.buscar', ['q' => 'Fernanda']))
        ->assertOk()
        ->assertJsonPath('data.0.value', (string) $fernanda->id)
        ->assertJsonPath('data.0.label', 'Fernanda · 4491239876');

    $this->actingAs($admin)
        ->getJson(route('admin.clientes.buscar', ['q' => '1239876']))
        ->assertOk()
        ->assertJsonPath('data.0.value', (string) $fernanda->id);

    $this->actingAs($admin)
        ->getJson(route('admin.clientes.buscar'))
        ->assertOk()
        ->assertJsonPath('data.0.value', (string) $fernanda->id);
});

test('equipment search only returns equipment owned by the selected client', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Rosa Hernández', 'telefono' => '4491112233', 'tipo_cliente' => 'mantenimiento']);
    $otroCliente = Cliente::create(['nombre' => 'Otro cliente', 'telefono' => '4492223344', 'tipo_cliente' => 'mantenimiento']);
    $equipo = Equipo::create(['cliente_id' => $cliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Lenovo', 'modelo' => 'ThinkPad']);
    Equipo::create(['cliente_id' => $otroCliente->id, 'tipo_equipo' => 'Laptop', 'marca' => 'Dell', 'modelo' => 'Latitude']);

    $this->actingAs($admin)
        ->getJson(route('admin.clientes.equipos.buscar', ['cliente' => $cliente, 'q' => 'Laptop']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.value', (string) $equipo->id);
});

test('operational forms render searchable selectors instead of all client records', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $cliente = Cliente::create(['nombre' => 'Cliente no precargado', 'telefono' => '4493334455', 'tipo_cliente' => 'mantenimiento']);

    $this->actingAs($admin)
        ->get(route('admin.recepciones.create'))
        ->assertOk()
        ->assertSee('Escribe para buscar')
        ->assertDontSee($cliente->nombre);

    $this->actingAs($admin)
        ->get(route('admin.cotizaciones.create'))
        ->assertOk()
        ->assertSee('Escribe para buscar')
        ->assertDontSee($cliente->nombre);
});

test('quote client search results can become visible', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.cotizaciones.create'))
        ->assertOk()
        ->assertSee('id="cliente_id_results" class="mt-1 max-h-52', false)
        ->assertDontSee('id="cliente_id_results" class="mt-1 hidden', false)
        ->assertSee('if (hadSelection) notifyChange', false);
});

test('technical partners cannot use operational search endpoints', function () {
    $partner = Partner::create(['nombre' => 'Fixop', 'tipo_socio' => 'tecnico', 'comision_fija' => 0, 'activo' => true]);
    $tecnico = User::factory()->create(['role' => 'socio_tecnico', 'partner_id' => $partner->id, 'email_verified_at' => now()]);

    $this->actingAs($tecnico)
        ->get('/admin/clientes/buscar')
        ->assertForbidden();
});
