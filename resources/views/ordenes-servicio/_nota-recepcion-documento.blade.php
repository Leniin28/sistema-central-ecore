@php
    $logoRuta = filled($negocio['logo'] ?? null) ? public_path($negocio['logo']) : null;
    $logoBase64 = ($logoRuta && is_file($logoRuta)) ? base64_encode(file_get_contents($logoRuta)) : null;
    $notas = trim((string) $orden->notas);
    $problema = $notas;
    $observaciones = null;

    if (preg_match('/^Problema reportado:\s*(.*?)(?:\R\RNotas internas:\s*(.*))?$/s', $notas, $coincidencias)) {
        $problema = trim($coincidencias[1]);
        $observaciones = trim($coincidencias[2] ?? '');
    }
@endphp
<div style="font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top; width: 58%;">
                @if ($logoBase64)
                    <img src="data:image/png;base64,{{ $logoBase64 }}" alt="{{ $negocio['nombre'] }}" style="height: 54px; width: auto;">
                @else
                    <div style="font-size: 20px; font-weight: bold; color: #111827;">{{ $negocio['nombre'] }}</div>
                    @if (filled($negocio['eslogan'] ?? null))
                        <div style="color: #6b7280;">{{ $negocio['eslogan'] }}</div>
                    @endif
                @endif
                <div style="margin-top: 6px; color: #4b5563;">
                    @if (filled($negocio['direccion']))<div>{{ $negocio['direccion'] }}</div>@endif
                    @if (filled($negocio['telefono']))<div>Tel: {{ $negocio['telefono'] }}</div>@endif
                    @if (filled($negocio['correo']))<div>{{ $negocio['correo'] }}</div>@endif
                </div>
            </td>
            <td style="vertical-align: top; width: 42%; text-align: right;">
                <div style="font-size: 17px; font-weight: bold; color: #111827;">NOTA DE RECEPCIÃ“N</div>
                <div style="font-size: 14px; font-weight: bold;">{{ $orden->folio }}</div>
                <div>Fecha de recepciÃ³n: {{ $orden->fecha_recepcion?->format('d/m/Y H:i') ?? 'Sin fecha' }}</div>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <tr>
            <td style="width: 50%; padding: 10px; vertical-align: top; border: 1px solid #d1d5db;">
                <div style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Cliente</div>
                <div style="font-size: 13px; font-weight: bold;">{{ $orden->cliente?->nombre ?? 'Sin cliente' }}</div>
                <div>{{ filled($orden->cliente?->telefono) ? 'Tel: '.$orden->cliente->telefono : 'Tel: Sin especificar' }}</div>
            </td>
            <td style="width: 50%; padding: 10px; vertical-align: top; border: 1px solid #d1d5db; border-left: 0;">
                <div style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Equipo</div>
                @if ($orden->equipo)
                    <div style="font-size: 13px; font-weight: bold;">{{ $orden->equipo->tipo_equipo }}</div>
                    <div>Marca: {{ $orden->equipo->marca ?: 'Sin especificar' }}</div>
                    <div>Modelo: {{ $orden->equipo->modelo ?: 'Sin especificar' }}</div>
                    @if (filled($orden->equipo->numero_serie))<div>No. de serie: {{ $orden->equipo->numero_serie }}</div>@endif
                @else
                    <div>Sin equipo asignado</div>
                @endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 18px;">
        <div style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Accesorios recibidos</div>
        <div style="min-height: 32px; padding: 7px 9px; border: 1px solid #d1d5db; white-space: pre-line;">{{ $orden->equipo?->accesorios_recibidos ?: 'Sin especificar' }}</div>
    </div>

    <div style="margin-top: 14px;">
        <div style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Estado fÃ­sico inicial</div>
        <div style="min-height: 32px; padding: 7px 9px; border: 1px solid #d1d5db; white-space: pre-line;">{{ $orden->equipo?->estado_fisico_inicial ?: 'Sin especificar' }}</div>
    </div>

    <div style="margin-top: 14px;">
        <div style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Falla / problema reportado</div>
        <div style="min-height: 48px; padding: 7px 9px; border: 1px solid #d1d5db; white-space: pre-line;">{{ $problema ?: 'Sin especificar' }}</div>
    </div>

    <div style="margin-top: 14px;">
        <div style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Observaciones</div>
        <div style="min-height: 48px; padding: 7px 9px; border: 1px solid #d1d5db; white-space: pre-line;">{{ $observaciones ?: 'Sin observaciones adicionales' }}</div>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 48px;">
        <tr>
            <td style="width: 50%; padding-right: 34px; text-align: center; vertical-align: bottom;">
                <div style="border-top: 1px solid #111827; padding-top: 6px; font-weight: bold;">RECIBE</div>
            </td>
            <td style="width: 50%; padding-left: 34px; text-align: center; vertical-align: bottom;">
                <div style="border-top: 1px solid #111827; padding-top: 6px; font-weight: bold;">ENTREGA</div>
            </td>
        </tr>
    </table>
</div>
