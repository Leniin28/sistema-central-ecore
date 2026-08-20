<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrarAnticipoCotizacion
{
    public const CATEGORIA = 'anticipo';

    public function registrarCambio(
        Cotizacion $cotizacion,
        float $anticipoAnterior,
        float $anticipoNuevo,
        ?User $actor,
    ): void {
        DB::transaction(function () use ($cotizacion, $anticipoAnterior, $anticipoNuevo, $actor): void {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $cotizacion->asegurarOperativa('anticipo');
            $anticipoAnterior = round($anticipoAnterior, 2);
            $anticipoNuevo = round($anticipoNuevo, 2);

            if ($anticipoNuevo === $anticipoAnterior) {
                return;
            }

            if (! $actor?->isAdmin()) {
                throw ValidationException::withMessages([
                    'anticipo' => 'Solo un administrador puede registrar o modificar anticipos.',
                ]);
            }

            $registrado = $this->totalRegistrado($cotizacion, bloquear: true);

            if ($anticipoNuevo < $registrado) {
                throw ValidationException::withMessages([
                    'anticipo' => 'El anticipo no puede quedar por debajo de los $'.number_format($registrado, 2).' ya registrados. Se requiere una devolución o reversión financiera explícita.',
                ]);
            }

            $orden = $cotizacion->ordenServicio()->lockForUpdate()->first();
            if ($orden && ($orden->estado === 'entregado' || $orden->estado === 'cancelado' || $orden->finanzas_generadas)) {
                throw ValidationException::withMessages([
                    'anticipo' => 'El anticipo no puede modificarse después de cerrar o cancelar la orden. Se requiere un ajuste financiero explícito.',
                ]);
            }

            $incremento = round($anticipoNuevo - $anticipoAnterior, 2);
            if ($incremento <= 0) {
                return;
            }

            MovimientoFinanciero::create([
                'orden_servicio_id' => $orden?->id,
                'cotizacion_id' => $cotizacion->id,
                'cliente_id' => $cotizacion->cliente_id,
                'partner_id' => null,
                'tipo' => 'ingreso',
                'categoria' => self::CATEGORIA,
                'monto' => $incremento,
                'descripcion' => 'Anticipo '.$cotizacion->folio.($orden ? ' / '.$orden->folio : ''),
                'fecha' => today(),
            ]);
        });
    }

    public function totalRegistrado(Cotizacion $cotizacion, bool $bloquear = false): float
    {
        $query = $cotizacion->movimientosFinancieros()
            ->where('tipo', 'ingreso')
            ->where('categoria', self::CATEGORIA);

        if ($bloquear) {
            $query->lockForUpdate();
        }

        return round((float) $query->get(['monto'])->sum('monto'), 2);
    }

    public function vincularAOrden(Cotizacion $cotizacion, ?OrdenServicio $orden): void
    {
        DB::transaction(function () use ($cotizacion, $orden): void {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $cotizacion->asegurarOperativa('anticipo');
            $movimientos = $cotizacion->movimientosFinancieros()
                ->where('tipo', 'ingreso')
                ->where('categoria', self::CATEGORIA);

            $movimientos->update([
                'orden_servicio_id' => $orden?->id,
                'descripcion' => 'Anticipo '.$cotizacion->folio.($orden ? ' / '.$orden->folio : ''),
            ]);
        });
    }
}
