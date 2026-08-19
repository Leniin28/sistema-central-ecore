@php
    $logoRuta = filled($negocio['logo'] ?? null) ? public_path($negocio['logo']) : null;
    $logoBase64 = ($logoRuta && is_file($logoRuta)) ? base64_encode(file_get_contents($logoRuta)) : null;
    $extractorFalla = app(\App\Services\ExtraerFallaReportada::class);
    $problema = $extractorFalla->extraer($orden->notas);
    $observaciones = $extractorFalla->extraerObservaciones($orden->notas);
@endphp

<style>
    .nota-recepcion-documento { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #1f2937; font-size: 13px; line-height: 1.38; }
    .nota-recepcion-documento table { width: 100%; border-collapse: collapse; }
    .nota-recepcion-documento .cabecera-logo { vertical-align: top; width: 38%; }
    .nota-recepcion-documento .cabecera-logo img { height: 72px; width: auto; max-width: 290px; }
    .nota-recepcion-documento .negocio-nombre { font-size: 23px; font-weight: bold; color: #111827; }
    .nota-recepcion-documento .negocio-eslogan { color: #6b7280; }
    .nota-recepcion-documento .negocio-contacto { margin-top: 5px; color: #4b5563; font-size: 11px; }
    .nota-recepcion-documento .cabecera-folio { vertical-align: top; width: 62%; text-align: right; }
    .nota-recepcion-documento .titulo { font-size: 22px; font-weight: bold; color: #111827; letter-spacing: .5px; }
    .nota-recepcion-documento .folio { margin-top: 3px; font-size: 16px; font-weight: bold; }
    .nota-recepcion-documento .fecha { margin-top: 3px; }
    .nota-recepcion-documento .resumen { margin-top: 14px; }
    .nota-recepcion-documento .resumen td { width: 50%; padding: 9px 12px; vertical-align: top; border: 1px solid #cbd5e1; }
    .nota-recepcion-documento .resumen td + td { border-left: 0; }
    .nota-recepcion-documento .etiqueta { font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
    .nota-recepcion-documento .valor-principal { margin-top: 2px; font-size: 14px; font-weight: bold; color: #111827; }
    .nota-recepcion-documento .detalle { margin-top: 2px; }
    .nota-recepcion-documento .contenido { margin-top: 14px; table-layout: fixed; }
    .nota-recepcion-documento .columna { width: 50%; vertical-align: top; }
    .nota-recepcion-documento .columna:first-child { padding-right: 7px; }
    .nota-recepcion-documento .columna:last-child { padding-left: 7px; }
    .nota-recepcion-documento .bloque + .bloque { margin-top: 11px; }
    .nota-recepcion-documento .caja { margin-top: 4px; min-height: 60px; padding: 8px 10px; border: 1px solid #cbd5e1; white-space: pre-line; overflow-wrap: anywhere; }
    .nota-recepcion-documento .caja-principal { min-height: 78px; }
    .nota-recepcion-documento .firmas { margin-top: 32px; table-layout: fixed; page-break-inside: avoid; }
    .nota-recepcion-documento .firmas td { width: 50%; padding: 28px 54px 0; text-align: center; vertical-align: bottom; }
    .nota-recepcion-documento .linea-firma { border-top: 1px solid #111827; padding-top: 6px; font-weight: bold; }
</style>

<div class="nota-recepcion-documento">
    <table>
        <tr>
            <td class="cabecera-logo">
                @if ($logoBase64)
                    <img src="data:image/png;base64,{{ $logoBase64 }}" alt="{{ $negocio['nombre'] }}">
                @else
                    <div class="negocio-nombre">{{ $negocio['nombre'] }}</div>
                    @if (filled($negocio['eslogan'] ?? null))
                        <div class="negocio-eslogan">{{ $negocio['eslogan'] }}</div>
                    @endif
                @endif
                <div class="negocio-contacto">
                    @if (filled($negocio['direccion']))<div>{{ $negocio['direccion'] }}</div>@endif
                    @if (filled($negocio['telefono']))<div>Tel: {{ $negocio['telefono'] }}</div>@endif
                    @if (filled($negocio['correo']))<div>{{ $negocio['correo'] }}</div>@endif
                </div>
            </td>
            <td class="cabecera-folio">
                <div class="titulo">NOTA DE RECEPCIÓN</div>
                <div class="folio">{{ $orden->folio }}</div>
                <div class="fecha">Fecha de recepción: {{ $orden->fecha_recepcion?->format('d/m/Y H:i') ?? 'Sin fecha' }}</div>
            </td>
        </tr>
    </table>

    <table class="resumen">
        <tr>
            <td>
                <div class="etiqueta">Cliente</div>
                <div class="valor-principal">{{ $orden->cliente?->nombre ?? 'Sin cliente' }}</div>
                <div class="detalle">{{ filled($orden->cliente?->telefono) ? 'Teléfono: '.$orden->cliente->telefono : 'Teléfono: Sin especificar' }}</div>
            </td>
            <td>
                <div class="etiqueta">Equipo</div>
                @if ($orden->equipo)
                    <div class="valor-principal">{{ $orden->equipo->tipo_equipo }}</div>
                    <div class="detalle">
                        Marca: {{ $orden->equipo->marca ?: 'Sin especificar' }}
                        &nbsp; | &nbsp; Modelo: {{ $orden->equipo->modelo ?: 'Sin especificar' }}
                    </div>
                    <div class="detalle">Número de serie: {{ $orden->equipo->numero_serie ?: 'Sin especificar' }}</div>
                @else
                    <div class="valor-principal">Sin equipo asignado</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="contenido">
        <tr>
            <td class="columna">
                <div class="bloque">
                    <div class="etiqueta">Accesorios recibidos</div>
                    <div class="caja">{{ $orden->equipo?->accesorios_recibidos ?: 'Sin especificar' }}</div>
                </div>
                <div class="bloque">
                    <div class="etiqueta">Estado físico inicial</div>
                    <div class="caja caja-principal">{{ $orden->equipo?->estado_fisico_inicial ?: 'Sin especificar' }}</div>
                </div>
            </td>
            <td class="columna">
                <div class="bloque">
                    <div class="etiqueta">Falla / problema reportado</div>
                    <div class="caja caja-principal">{{ $problema ?: 'Sin especificar' }}</div>
                </div>
                <div class="bloque">
                    <div class="etiqueta">Observaciones</div>
                    <div class="caja">{{ $observaciones ?: 'Sin observaciones adicionales' }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="firmas">
        <tr>
            <td><div class="linea-firma">RECIBE</div></td>
            <td><div class="linea-firma">ENTREGA</div></td>
        </tr>
    </table>
</div>
