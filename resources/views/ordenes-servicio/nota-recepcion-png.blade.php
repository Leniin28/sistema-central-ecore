<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Nota de recepciÃ³n {{ $orden->folio }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #ffffff; }
        .lienzo { width: 800px; margin: 0 auto; padding: 36px 40px; background: #ffffff; }
    </style>
</head>
<body>
    <div class="lienzo">
        @include('ordenes-servicio._nota-recepcion-documento')
    </div>
</body>
</html>
