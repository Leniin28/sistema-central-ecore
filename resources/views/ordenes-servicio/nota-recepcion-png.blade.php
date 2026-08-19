<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nota de recepción {{ $orden->folio }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #ffffff; }
        .lienzo { box-sizing: border-box; width: 100vw; min-height: 100vh; margin: 0; padding: 38px 42px; background: #ffffff; }
    </style>
</head>
<body>
    <div class="lienzo">
        @include('ordenes-servicio._nota-recepcion-documento')
    </div>
</body>
</html>
