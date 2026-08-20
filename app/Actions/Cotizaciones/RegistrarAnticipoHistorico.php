<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegistrarAnticipoHistorico
{
    public const COTIZACION_AUTORIZADA = 'COT-20260809-0001';

    public const ORDEN_AUTORIZADA = 'OS-20260809-0001';

    public const MONTO_AUTORIZADO = 2030.00;

    public const TOTAL_AUTORIZADO = 4220.00;

    public const FECHA_AUTORIZADA = '2026-08-11';

    public const EXTERNAL_ID = 'anticipo-historico:COT-20260809-0001';

    /** @return array<string, mixed> */
    public function diagnosticar(string $folioCotizacion, string $folioOrden, float $monto, string $fecha): array
    {
        return $this->validarCaso($folioCotizacion, $folioOrden, $monto, $fecha, bloquear: false);
    }

    /** @return array<string, mixed> */
    public function aplicar(
        string $folioCotizacion,
        string $folioOrden,
        float $monto,
        string $fecha,
        int $actorId,
    ): array {
        return DB::transaction(function () use ($folioCotizacion, $folioOrden, $monto, $fecha, $actorId): array {
            $actor = User::query()->find($actorId);
            if (! $actor?->isAdmin()) {
                throw ValidationException::withMessages([
                    'actor' => 'El actor indicado no existe o no es administrador.',
                ]);
            }

            $plan = $this->validarCaso($folioCotizacion, $folioOrden, $monto, $fecha, bloquear: true);
            if ($plan['ya_registrado']) {
                return [...$plan, 'creado' => false];
            }

            $movimiento = MovimientoFinanciero::create([
                'orden_servicio_id' => $plan['orden_id'],
                'cotizacion_id' => $plan['cotizacion_id'],
                'cliente_id' => $plan['cliente_id'],
                'partner_id' => null,
                'tipo' => 'ingreso',
                'categoria' => RegistrarAnticipoCotizacion::CATEGORIA,
                'monto' => self::MONTO_AUTORIZADO,
                'descripcion' => 'Anticipo histórico '.self::COTIZACION_AUTORIZADA.' / '.self::ORDEN_AUTORIZADA,
                'fecha' => self::FECHA_AUTORIZADA,
                'external_id' => self::EXTERNAL_ID,
            ]);

            Log::notice('Anticipo histórico registrado mediante one-shot', [
                'movimiento_financiero_id' => $movimiento->id,
                'cotizacion_id' => $plan['cotizacion_id'],
                'orden_servicio_id' => $plan['orden_id'],
                'actor_user_id' => $actor->id,
                'monto' => self::MONTO_AUTORIZADO,
                'fecha' => self::FECHA_AUTORIZADA,
            ]);

            return [...$plan, 'creado' => true, 'movimiento_id' => $movimiento->id];
        });
    }

    /** @return array<string, mixed> */
    private function validarCaso(
        string $folioCotizacion,
        string $folioOrden,
        float $monto,
        string $fecha,
        bool $bloquear,
    ): array {
        $monto = round($monto, 2);
        $this->validarParametrosAutorizados($folioCotizacion, $folioOrden, $monto, $fecha);

        $cotizacionQuery = Cotizacion::query()->where('folio', $folioCotizacion);
        $ordenQuery = OrdenServicio::query()->where('folio', $folioOrden);
        if ($bloquear) {
            $cotizacionQuery->lockForUpdate();
            $ordenQuery->lockForUpdate();
        }

        $cotizacion = $cotizacionQuery->first();
        $orden = $ordenQuery->first();
        if (! $cotizacion || ! $orden) {
            throw ValidationException::withMessages([
                'caso' => 'La cotización o la orden autorizada no existe. Operación detenida.',
            ]);
        }

        if ((int) $orden->cotizacion_id !== (int) $cotizacion->id
            || ! $cotizacion->ordenServicio()->whereKey($orden->id)->exists()) {
            throw ValidationException::withMessages([
                'caso' => 'La cotización y la orden no están vinculadas estructuralmente entre sí.',
            ]);
        }

        if ((int) $orden->cliente_id !== (int) $cotizacion->cliente_id
            || (int) ($orden->equipo_id ?? 0) !== (int) ($cotizacion->equipo_id ?? 0)) {
            throw ValidationException::withMessages([
                'caso' => 'La cotización y la orden no corresponden al mismo cliente y equipo.',
            ]);
        }

        if ($cotizacion->estado !== 'aceptada') {
            throw ValidationException::withMessages(['caso' => 'La cotización no está aceptada.']);
        }

        if (in_array($orden->estado, ['entregado', 'cancelado'], true)) {
            throw ValidationException::withMessages(['caso' => 'La orden está entregada o cancelada.']);
        }

        if ($orden->finanzas_generadas) {
            throw ValidationException::withMessages(['caso' => 'La orden ya tiene sus finanzas generadas.']);
        }

        if ($orden->tieneMovimientosFinancierosIncompatibles($cotizacion->id)) {
            throw ValidationException::withMessages(['caso' => 'La orden tiene movimientos financieros incompatibles.']);
        }

        if (round((float) $cotizacion->anticipo, 2) !== self::MONTO_AUTORIZADO
            || $monto !== self::MONTO_AUTORIZADO) {
            throw ValidationException::withMessages(['monto' => 'El anticipo almacenado y el monto solicitado deben ser exactamente $2,030.00.']);
        }

        if (round((float) $cotizacion->total, 2) !== self::TOTAL_AUTORIZADO
            || round((float) $orden->total_cliente, 2) !== self::TOTAL_AUTORIZADO
            || $monto > (float) $cotizacion->total) {
            throw ValidationException::withMessages(['monto' => 'El total comercial debe ser exactamente $4,220.00 y cubrir el anticipo solicitado.']);
        }

        if ($fecha !== self::FECHA_AUTORIZADA) {
            throw ValidationException::withMessages(['fecha' => 'La fecha financiera autorizada es exactamente 2026-08-11.']);
        }

        $movimientosQuery = $cotizacion->movimientosFinancieros()
            ->where('categoria', RegistrarAnticipoCotizacion::CATEGORIA);
        if ($bloquear) {
            $movimientosQuery->lockForUpdate();
        }
        $movimientos = $movimientosQuery->get();
        $yaRegistrado = false;
        if ($movimientos->isNotEmpty()) {
            $yaRegistrado = $movimientos->count() === 1
                && $movimientos->first()->tipo === 'ingreso'
                && (int) $movimientos->first()->cotizacion_id === (int) $cotizacion->id
                && (int) $movimientos->first()->orden_servicio_id === (int) $orden->id
                && round((float) $movimientos->first()->monto, 2) === self::MONTO_AUTORIZADO
                && $movimientos->first()->fecha?->format('Y-m-d') === self::FECHA_AUTORIZADA
                && $movimientos->first()->external_id === self::EXTERNAL_ID;

            if (! $yaRegistrado) {
                throw ValidationException::withMessages([
                    'caso' => 'Ya existe un movimiento de anticipo distinto al caso exacto autorizado.',
                ]);
            }
        }

        return [
            'cotizacion_id' => $cotizacion->id,
            'cotizacion_folio' => $cotizacion->folio,
            'orden_id' => $orden->id,
            'orden_folio' => $orden->folio,
            'cliente_id' => $cotizacion->cliente_id,
            'tipo' => 'ingreso',
            'categoria' => RegistrarAnticipoCotizacion::CATEGORIA,
            'monto' => self::MONTO_AUTORIZADO,
            'fecha' => self::FECHA_AUTORIZADA,
            'descripcion' => 'Anticipo histórico '.self::COTIZACION_AUTORIZADA.' / '.self::ORDEN_AUTORIZADA,
            'total_cliente' => (float) $orden->total_cliente,
            'saldo_proyectado_entrega' => round((float) $orden->total_cliente - self::MONTO_AUTORIZADO, 2),
            'ya_registrado' => $yaRegistrado,
        ];
    }

    private function validarParametrosAutorizados(
        string $folioCotizacion,
        string $folioOrden,
        float $monto,
        string $fecha,
    ): void {
        if ($folioCotizacion !== self::COTIZACION_AUTORIZADA
            || $folioOrden !== self::ORDEN_AUTORIZADA
            || $monto !== self::MONTO_AUTORIZADO
            || $fecha !== self::FECHA_AUTORIZADA) {
            throw ValidationException::withMessages([
                'caso' => 'Los parámetros no coinciden exactamente con el único anticipo histórico autorizado.',
            ]);
        }
    }
}
