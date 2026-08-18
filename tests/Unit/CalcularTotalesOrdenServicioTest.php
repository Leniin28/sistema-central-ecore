<?php

use App\Actions\Ordenes\CalcularTotalesOrdenServicio;

test('calcula servicios refacciones y utilidad estimada', function () {
    $calculadora = new CalcularTotalesOrdenServicio;

    $resumen = $calculadora->resumen(
        [[
            'servicio_id' => 1,
            'descripcion' => 'Servicio de prueba',
            'cantidad' => 2,
            'precio_unitario' => 300,
            'notas' => null,
        ]],
        [[
            'descripcion' => 'Memoria RAM',
            'cantidad' => 2,
            'costo_unitario' => 100,
            'precio_unitario_cliente' => 150,
            'notas' => null,
        ]],
    );

    expect($resumen)->toMatchArray([
        'total_servicios' => 600.0,
        'total_refacciones' => 300.0,
        'costo_refacciones' => 200.0,
        'total_cliente' => 900.0,
        'utilidad_estimada' => 700.0,
    ]);
});

test('calcula los importes de una refaccion en el servidor', function () {
    $calculadora = new CalcularTotalesOrdenServicio;

    expect($calculadora->refaccion([
        'descripcion' => 'SSD',
        'cantidad' => 2,
        'costo_unitario' => 450.25,
        'precio_unitario_cliente' => 700,
        'notas' => 'Incluye instalación',
    ]))->toMatchArray([
        'costo_total' => 900.5,
        'precio_total_cliente' => 1400.0,
        'utilidad_refaccion' => 499.5,
    ]);
});

test('preserva costo desconocido y señala utilidad incompleta', function () {
    $calculadora = new CalcularTotalesOrdenServicio;

    $resumen = $calculadora->resumen(
        [[
            'descripcion' => 'Diagnóstico',
            'cantidad' => 1,
            'precio_unitario' => 500,
            'costo_unitario' => null,
        ]],
        [],
    );

    expect($calculadora->detalle([
        'descripcion' => 'Diagnóstico',
        'cantidad' => 1,
        'precio_unitario' => 500,
        'costo_unitario' => null,
    ]))->toMatchArray([
        'costo_unitario' => null,
        'costo_total' => null,
    ])->and($resumen['utilidad_estimada'])->toBe(500.0)
        ->and($resumen['costos_incompletos'])->toBeTrue();
});
