<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota de recepciÃ³n {{ $orden->folio }}</title>
    <style>
        body { margin: 0; background-color: #f3f4f6; }
        .hoja { max-width: 820px; margin: 24px auto; background: #ffffff; padding: 40px; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15); }
        .acciones { max-width: 820px; margin: 16px auto 0; text-align: right; }
        .acciones a, .acciones button { background: #111827; color: #ffffff; border: 0; border-radius: 6px; padding: 8px 16px; font-size: 14px; cursor: pointer; text-decoration: none; }
        @media print { body { background: #ffffff; } .hoja { box-shadow: none; margin: 0; max-width: none; } .acciones { display: none; } }
    </style>
</head>
<body>
    <div class="acciones">
        <a href="{{ route($routePrefix.'.ordenes-servicio.nota-recepcion.pdf', $orden) }}">Descargar PDF</a>
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>
    <div class="hoja">
        @include('ordenes-servicio._nota-recepcion-documento')
    </div>
</body>
</html>
