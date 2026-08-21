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

    'nombre' => env('NEGOCIO_NOMBRE', 'E-Core'),
    'eslogan' => env('NEGOCIO_ESLOGAN', 'Mantenimiento de Software'),
    'telefono' => env('NEGOCIO_TELEFONO', '4494226522'),
    'correo' => env('NEGOCIO_CORREO', ''),
    'direccion' => env('NEGOCIO_DIRECCION', ''),

    // Ruta del logotipo dentro de public/. Se incrusta en base64 en la
    // plantilla, el PDF y el PNG; si el archivo no existe se omite.
    'logo' => env('NEGOCIO_LOGO', 'images/logo-ecore.png'),
    'leyenda_cotizacion' => env(
        'NEGOCIO_LEYENDA_COTIZACION',
        'Precios en MXN. Esta cotización no es un comprobante fiscal.',
    ),

    /*
    |--------------------------------------------------------------------------
    | Modelo financiero para órdenes nuevas
    |--------------------------------------------------------------------------
    |
    | Decide, exclusivamente en el servidor, qué modelo_financiero recibe toda
    | orden NUEVA (recepción web, creación directa, cotización -> orden nueva,
    | OpenClaw). Ningún request puede elegirlo. Valores válidos: "legacy" o
    | "costos_por_linea". Una orden ya existente conserva siempre el modelo
    | con el que nació, sin importar este valor.
    |
    */

    'modelo_financiero_nuevas_ordenes' => env('ECORE_MODELO_FINANCIERO_NUEVAS_ORDENES', 'legacy'),

];
