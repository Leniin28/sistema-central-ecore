@props([
    'value',
])

@php
    $labels = [
        'ingreso' => 'Ingreso',
        'egreso' => 'Egreso',
        'reparacion' => 'Reparación',
        'mantenimiento' => 'Mantenimiento',
        'marketing' => 'Marketing',
        'fb_ads' => 'FB Ads',
        'pago_socio_logistico' => 'Pago socio logístico',
        'pago_socio_tecnico' => 'Pago socio técnico',
        'inversion_publicitaria' => 'Inversión publicitaria',
        'refaccion' => 'Refacción',
        'servicio' => 'Costo de servicio',
        'comision_recepcion' => 'Comisión de recepción',
        'gasto_operativo' => 'Gasto operativo',
        'gasolina' => 'Gasolina',
        'transporte' => 'Transporte',
        'herramientas' => 'Herramientas',
        'insumos' => 'Insumos',
        'renta' => 'Renta',
        'internet' => 'Internet',
        'otro' => 'Otro',
    ];

    $normalizedValue = filled($value ?? null) ? (string) $value : null;
@endphp

{{ $normalizedValue ? ($labels[$normalizedValue] ?? str($normalizedValue)->replace('_', ' ')->title()) : 'Sin categoría' }}
