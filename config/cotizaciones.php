<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exportación PNG de cotizaciones
    |--------------------------------------------------------------------------
    |
    | El PNG se genera con un navegador en modo headless (Edge o Chrome).
    | En Windows con Laravel Herd normalmente no hay que configurar nada:
    | se detecta Microsoft Edge automáticamente. Si el navegador está en
    | otra ruta, definir COTIZACIONES_BROWSER_BIN en el .env.
    |
    */

    'browser_bin' => env('COTIZACIONES_BROWSER_BIN'),

    // Ancho lógico de la imagen en píxeles CSS. El screenshot se toma a
    // escala 2x, por lo que el PNG final mide el doble de ancho.
    'png_ancho' => (int) env('COTIZACIONES_PNG_ANCHO', 880),

    // Segundos máximos de espera para que el navegador genere la captura.
    'png_timeout' => (int) env('COTIZACIONES_PNG_TIMEOUT', 60),

];
