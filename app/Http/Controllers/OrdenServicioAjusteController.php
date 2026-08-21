<?php

namespace App\Http\Controllers;

use App\Actions\Finanzas\RegistrarReembolsoOrdenServicio;
use App\Models\OrdenServicio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * FASE H.3+: ajustes financieros admin-only sobre órdenes ya entregadas
 * (reembolsos, correcciones de costo/comisión, anulación de entrega). Cada
 * acción es un método/ruta/formulario separado a propósito -- ver
 * docs/FASE H.7: no se combinan en un botón genérico "Corregir".
 */
class OrdenServicioAjusteController extends Controller
{
    public function reembolso(
        Request $request,
        OrdenServicio $ordenServicio,
        RegistrarReembolsoOrdenServicio $accion,
    ): RedirectResponse {
        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:1000'],
            'fecha' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            $accion->ejecutar(
                $ordenServicio,
                (float) $data['monto'],
                $data['motivo'],
                $request->user(),
                isset($data['fecha']) ? CarbonImmutable::parse($data['fecha']) : null,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('admin.ordenes-servicio.show', $ordenServicio)
            ->with('status', 'Reembolso registrado correctamente. La venta original no se modificó.');
    }
}
