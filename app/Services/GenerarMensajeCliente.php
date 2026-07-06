<?php

namespace App\Services;

use App\Models\OrdenServicio;

/**
 * Builds ready-to-send WhatsApp/SMS style messages for a service order. ECore
 * only renders deterministic templates with real order data (OpenClaw decides
 * when/how to send). Never includes password_equipo; missing data (phone,
 * branch, total) becomes a warning instead of an invented value.
 */
class GenerarMensajeCliente
{
    public const TONOS = ['amable', 'formal', 'breve'];

    /**
     * @param  array{tipo: string, estado?: string|null, tono?: string|null, incluir_total?: bool, incluir_sucursal?: bool, instruccion?: string|null}  $opciones
     * @return array{message: string, warnings: array<int, string>}
     */
    public function ejecutar(OrdenServicio $orden, array $opciones): array
    {
        $orden->loadMissing(['cliente', 'equipo', 'partnerRecepcion']);

        $warnings = [];
        $nombre = $this->primerNombre($orden->cliente?->nombre);
        $equipo = $this->descripcionEquipo($orden);

        if (blank($orden->cliente?->telefono) || $orden->cliente?->telefono === 'Sin teléfono') {
            $warnings[] = 'El cliente no tiene teléfono registrado; captúralo antes de enviar el mensaje.';
        }

        if (($opciones['tipo'] ?? 'estado') === 'manual') {
            return $this->manual($orden, $opciones, $nombre, $equipo, $warnings);
        }

        $estado = $opciones['estado'] ?? $orden->estado;
        $tono = $opciones['tono'] ?? 'amable';

        $saludo = match ($tono) {
            'formal' => "Estimado(a) {$nombre}:",
            'breve' => "Hola {$nombre}.",
            default => "Hola {$nombre},",
        };

        $cuerpo = $this->cuerpoPorEstado($estado, $equipo, $orden->folio);

        $extras = [];
        if (! empty($opciones['incluir_total'])) {
            $total = (float) $orden->total_cliente;
            if ($total > 0) {
                $extras[] = 'El total de tu servicio es de $'.number_format($total, 2).' MXN.';
            } else {
                $warnings[] = 'La orden aún no tiene total (total_cliente = 0); no se incluyó en el mensaje.';
            }
        }
        if (! empty($opciones['incluir_sucursal'])) {
            if ($orden->partnerRecepcion) {
                $extras[] = "Puedes pasar a la sucursal {$orden->partnerRecepcion->nombre}.";
            } else {
                $warnings[] = 'La orden no tiene sucursal/partner de recepción asignado; no se incluyó en el mensaje.';
            }
        }

        $despedida = match ($tono) {
            'formal' => 'Quedamos atentos a cualquier duda. Saludos cordiales.',
            'breve' => 'Cualquier duda, con gusto.',
            default => '¡Gracias por tu confianza! Cualquier duda estamos para servirte.',
        };

        $mensaje = collect([$saludo, $cuerpo, ...$extras, $despedida])
            ->filter(fn (?string $parte): bool => filled($parte))
            ->implode(' ');

        return ['message' => $mensaje, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $opciones
     * @param  array<int, string>  $warnings
     * @return array{message: string, warnings: array<int, string>}
     */
    private function manual(OrdenServicio $orden, array $opciones, string $nombre, string $equipo, array $warnings): array
    {
        $instruccion = trim((string) ($opciones['instruccion'] ?? ''));

        if ($instruccion === '') {
            $warnings[] = 'No se recibió instruccion para el mensaje manual; se generó un mensaje genérico.';
            $instruccion = "tenemos una actualización sobre tu {$equipo} (orden {$orden->folio}).";
        }

        return [
            'message' => "Hola {$nombre}, sobre tu {$equipo} (orden {$orden->folio}): {$instruccion}",
            'warnings' => $warnings,
        ];
    }

    private function cuerpoPorEstado(string $estado, string $equipo, string $folio): string
    {
        return match ($estado) {
            'recibido' => "Recibimos tu {$equipo} con el folio {$folio}. Te avisaremos en cuanto tengamos el diagnóstico.",
            'en_diagnostico' => "Tu {$equipo} (orden {$folio}) ya está en diagnóstico. En cuanto tengamos resultados te compartimos la cotización.",
            'cotizacion_pendiente' => "Ya tenemos el diagnóstico de tu {$equipo} (orden {$folio}) y estamos preparando tu cotización; te la compartimos en breve.",
            'cotizacion_aprobada' => "Recibimos la aprobación de tu cotización para el {$equipo} (orden {$folio}). Comenzamos con el servicio.",
            'en_proceso', 'en_fixop' => "Tu {$equipo} (orden {$folio}) está en proceso de reparación. Te avisamos en cuanto esté listo.",
            'listo_para_entregar' => "¡Buenas noticias! Tu {$equipo} (orden {$folio}) ya está listo para entrega.",
            'entregado' => "Gracias por recoger tu {$equipo} (orden {$folio}). Fue un gusto atenderte; cualquier detalle posterior estamos a tus órdenes.",
            'cancelado' => "Tu orden {$folio} del {$equipo} fue cancelada. Si fue un error o quieres retomarla, contáctanos.",
            default => "Tenemos una actualización de tu {$equipo} (orden {$folio}).",
        };
    }

    private function primerNombre(?string $nombre): string
    {
        $nombre = trim((string) $nombre);

        return $nombre === '' ? 'cliente' : explode(' ', $nombre)[0];
    }

    private function descripcionEquipo(OrdenServicio $orden): string
    {
        if (! $orden->equipo) {
            return 'equipo';
        }

        return trim(collect([
            $orden->equipo->tipo_equipo,
            $orden->equipo->marca !== 'Sin marca' ? $orden->equipo->marca : null,
            $orden->equipo->modelo,
        ])->filter()->implode(' '));
    }
}
