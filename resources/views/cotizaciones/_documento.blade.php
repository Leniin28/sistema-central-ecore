{{-- Contenido compartido entre la plantilla imprimible, el PDF y el PNG (dompdf solo soporta CSS simple). --}}
@php
    // Logo incrustado en base64 para que funcione igual en navegador, dompdf y capturas headless (file://).
    $logoRuta = filled($negocio['logo'] ?? null) ? public_path($negocio['logo']) : null;
    $logoBase64 = ($logoRuta && is_file($logoRuta)) ? base64_encode(file_get_contents($logoRuta)) : null;
@endphp
<div style="font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top;">
                @if ($logoBase64)
                    <img src="data:image/png;base64,{{ $logoBase64 }}" alt="{{ $negocio['nombre'] }}" style="height: 58px; width: auto;">
                @else
                    <div style="font-size: 20px; font-weight: bold; color: #111827;">{{ $negocio['nombre'] }}</div>
                    @if (filled($negocio['eslogan'] ?? null))
                        <div style="color: #6b7280;">{{ $negocio['eslogan'] }}</div>
                    @endif
                @endif
                <div style="margin-top: 6px;">
                    @if (filled($negocio['direccion']))
                        <div>{{ $negocio['direccion'] }}</div>
                    @endif
                    @if (filled($negocio['telefono']))
                        <div>Tel: {{ $negocio['telefono'] }}</div>
                    @endif
                    @if (filled($negocio['correo']))
                        <div>{{ $negocio['correo'] }}</div>
                    @endif
                </div>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <div style="font-size: 16px; font-weight: bold; color: #111827;">COTIZACIÓN</div>
                <div style="font-size: 14px; font-weight: bold;">{{ $cotizacion->folio }}</div>
                <div>Fecha: {{ $cotizacion->fecha?->format('d/m/Y') }}</div>
                <div>Vigencia: {{ $cotizacion->vigencia?->format('d/m/Y') ?? 'No especificada' }}</div>
                <div style="margin-top: 4px; text-transform: uppercase; font-weight: bold; color: #6b7280;">{{ $cotizacion->estado }}</div>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 18px;">
        <tr>
            <td style="vertical-align: top; width: 50%; padding-right: 10px;">
                <div style="font-weight: bold; color: #6b7280; text-transform: uppercase; font-size: 10px;">Cliente</div>
                <div style="font-weight: bold;">{{ $cotizacion->cliente?->nombre }}</div>
                @if (filled($cotizacion->cliente?->telefono))
                    <div>Tel: {{ $cotizacion->cliente->telefono }}</div>
                @endif
                @if (filled($cotizacion->cliente?->correo))
                    <div>{{ $cotizacion->cliente->correo }}</div>
                @endif
            </td>
            <td style="vertical-align: top; width: 50%; padding-left: 10px;">
                @if ($cotizacion->equipo)
                    <div style="font-weight: bold; color: #6b7280; text-transform: uppercase; font-size: 10px;">Equipo</div>
                    <div style="font-weight: bold;">{{ $cotizacion->equipo->tipo_equipo }} {{ $cotizacion->equipo->marca }} {{ $cotizacion->equipo->modelo }}</div>
                    @if (filled($cotizacion->equipo->numero_serie))
                        <div>Serie: {{ $cotizacion->equipo->numero_serie }}</div>
                    @endif
                @endif

                <div style="margin-top: 8px; font-weight: bold; color: #6b7280; text-transform: uppercase; font-size: 10px;">Ubicación de recepción</div>
                @if ($cotizacion->esRecogidaADomicilio())
                    <div style="font-weight: bold;">Recogido a domicilio</div>
                    @if (filled($cotizacion->direccion_recepcion))
                        <div>{{ $cotizacion->direccion_recepcion }}</div>
                    @endif
                @else
                    <div style="font-weight: bold;">En negocio</div>
                    @if (filled($cotizacion->partner?->nombre))
                        <div>{{ $cotizacion->partner->nombre }}</div>
                    @endif
                @endif
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 18px;">
        <thead>
            <tr style="background-color: #111827; color: #ffffff;">
                <th style="padding: 6px 8px; text-align: left; font-size: 11px;">Tipo</th>
                <th style="padding: 6px 8px; text-align: left; font-size: 11px;">Descripción</th>
                <th style="padding: 6px 8px; text-align: right; font-size: 11px;">Cantidad</th>
                <th style="padding: 6px 8px; text-align: right; font-size: 11px;">P. unitario</th>
                <th style="padding: 6px 8px; text-align: right; font-size: 11px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cotizacion->items as $item)
                <tr>
                    <td style="padding: 6px 8px; border-bottom: 1px solid #e5e7eb;">{{ ucfirst($item->tipo) }}</td>
                    <td style="padding: 6px 8px; border-bottom: 1px solid #e5e7eb;">{{ $item->descripcion }}</td>
                    <td style="padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: right;">{{ $item->cantidad }}</td>
                    <td style="padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: right;">${{ number_format($item->precio_unitario, 2) }}</td>
                    <td style="padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: right;">${{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width: 40%; border-collapse: collapse; margin-top: 14px; margin-left: 60%;">
        <tr>
            <td style="padding: 4px 8px;">Subtotal</td>
            <td style="padding: 4px 8px; text-align: right;">${{ number_format($cotizacion->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 8px;">Descuento</td>
            <td style="padding: 4px 8px; text-align: right;">-${{ number_format($cotizacion->descuento, 2) }}</td>
        </tr>
        <tr style="font-weight: bold; border-top: 1px solid #111827;">
            <td style="padding: 4px 8px;">Total</td>
            <td style="padding: 4px 8px; text-align: right;">${{ number_format($cotizacion->total, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 8px;">Anticipo</td>
            <td style="padding: 4px 8px; text-align: right;">${{ number_format($cotizacion->anticipo, 2) }}</td>
        </tr>
        <tr style="font-weight: bold;">
            <td style="padding: 4px 8px;">Saldo</td>
            <td style="padding: 4px 8px; text-align: right;">${{ number_format($cotizacion->saldo, 2) }}</td>
        </tr>
    </table>

    @if (filled($cotizacion->notas))
        <div style="margin-top: 18px;">
            <div style="font-weight: bold; color: #6b7280; text-transform: uppercase; font-size: 10px;">Notas</div>
            <div style="white-space: pre-line;">{{ $cotizacion->notas }}</div>
        </div>
    @endif

    @if (filled($negocio['leyenda_cotizacion']))
        <div style="margin-top: 24px; padding-top: 10px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 10px;">
            {{ $negocio['leyenda_cotizacion'] }}
        </div>
    @endif
</div>
