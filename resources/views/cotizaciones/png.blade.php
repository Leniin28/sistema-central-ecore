<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->folio }}</title>
    <style>
        {{-- Lienzo de ancho fijo para la captura headless; el blanco sobrante inferior se recorta después. --}}
        html, body { margin: 0; padding: 0; background: #ffffff; }
        .lienzo { width: 800px; margin: 0 auto; padding: 36px 40px; background: #ffffff; }
    </style>
</head>
<body>
    <div class="lienzo">
        @include('cotizaciones._documento')
    </div>
</body>
</html>
