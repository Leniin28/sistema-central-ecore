<?php

namespace App\Http\Controllers;

use App\Actions\Finanzas\AnularEntregaOrdenServicio;
use App\Actions\Finanzas\CorregirComisionOrdenEntregada;
use App\Actions\Finanzas\CorregirCostoInternoOrdenEntregada;
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

    public function costoInterno(
        Request $request,
        OrdenServicio $ordenServicio,
        CorregirCostoInternoOrdenEntregada $accion,
    ): RedirectResponse {
        $data = $request->validate([
            'linea_tipo' => ['required', 'in:servicio,refaccion'],
            'linea_id' => ['required', 'integer', 'min:1'],
            'costo_nuevo' => ['required', 'numeric', 'min:0'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $accion->ejecutar(
                $ordenServicio,
                $data['linea_tipo'],
                (int) $data['linea_id'],
                (float) $data['costo_nuevo'],
                $data['motivo'],
                $request->user(),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('admin.ordenes-servicio.show', $ordenServicio)
            ->with('status', 'Costo interno corregido correctamente.');
    }

    public function comision(
        Request $request,
        OrdenServicio $ordenServicio,
        CorregirComisionOrdenEntregada $accion,
    ): RedirectResponse {
        $data = $request->validate([
            'comision_nueva' => ['required', 'numeric', 'min:0'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $accion->ejecutar(
                $ordenServicio,
                (float) $data['comision_nueva'],
                $data['motivo'],
                $request->user(),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('admin.ordenes-servicio.show', $ordenServicio)
            ->with('status', 'Comisión corregida correctamente.');
    }

    public function anularEntrega(
        Request $request,
        OrdenServicio $ordenServicio,
        AnularEntregaOrdenServicio $accion,
    ): RedirectResponse {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $accion->ejecutar($ordenServicio, $data['motivo'], $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('admin.ordenes-servicio.show', $ordenServicio)
            ->with('status', 'Entrega anulada correctamente. La orden volvió a su estado anterior.');
    }
}
