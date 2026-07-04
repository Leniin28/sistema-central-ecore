<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Datos del negocio
    |--------------------------------------------------------------------------
    |
    | Datos que se imprimen en documentos generados por el sistema, como los
    | PDF de cotizaciones. Pueden sobreescribirse desde el archivo .env sin
    | tocar el código.
    |
    */

    'nombre' => env('NEGOCIO_NOMBRE', 'Sistema Central ECore'),
    'telefono' => env('NEGOCIO_TELEFONO', ''),
    'correo' => env('NEGOCIO_CORREO', ''),
    'direccion' => env('NEGOCIO_DIRECCION', ''),
    'leyenda_cotizacion' => env(
        'NEGOCIO_LEYENDA_COTIZACION',
        'Precios en MXN. Esta cotización no es un comprobante fiscal.',
    ),

];
