<?php

namespace App\Http\Controllers;

use App\Actions\Cotizaciones\ActualizarCotizacion;
use App\Actions\Cotizaciones\CambiarEstadoCotizacion;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Actions\Cotizaciones\VincularCotizacionAOrden;
use App\Http\Requests\StoreCotizacionRequest;
use App\Http\Requests\UpdateCotizacionRequest;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Services\ExportarCotizacionPdf;
use App\Services\ExportarCotizacionPng;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CotizacionController extends Controller
{
    /**
     * Display a listing of quotes.
     */
    public function index(): View
    {
        $cotizaciones = $this->consultaBase()
            ->with(['cliente', 'equipo'])
            ->latest()
            ->paginate(10);

        return view('cotizaciones.index', [
            'cotizaciones' => $cotizaciones,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Show the form for creating a new quote.
     */
    public function create(Request $request, VincularCotizacionAOrden $vincularOrden): View
    {
        $ordenSeleccionada = null;

        if ($request->filled('orden_servicio_id')) {
            abort_unless($request->user()->isAdmin(), 403);
            $ordenSeleccionada = OrdenServicio::query()->findOrFail($request->integer('orden_servicio_id'));
            abort_unless($vincularOrden->esElegibleParaNuevaCotizacion($ordenSeleccionada), 404);
        }

        $cotizacion = new Cotizacion([
            'fecha' => today(),
            'cliente_id' => $ordenSeleccionada?->cliente_id,
            'equipo_id' => $ordenSeleccionada?->equipo_id,
        ]);

        return view('cotizaciones.create', [
            'cotizacion' => $cotizacion,
            ...$this->formData($cotizacion, $ordenSeleccionada),
        ]);
    }

    public function ordenesElegibles(Request $request, VincularCotizacionAOrden $vincularOrden): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'equipo_id' => ['required', 'integer', 'exists:equipos,id'],
            'cotizacion_id' => ['nullable', 'integer', 'exists:cotizaciones,id'],
        ]);
        $cotizacion = filled($data['cotizacion_id'] ?? null)
            ? Cotizacion::query()->findOrFail($data['cotizacion_id'])
            : null;

        $ordenes = $vincularOrden
            ->elegibles((int) $data['cliente_id'], (int) $data['equipo_id'], $cotizacion)
            ->map(fn (OrdenServicio $orden): array => [
                'value' => $orden->id,
                'label' => $orden->folio.' — '.$orden->estadoLabel().' — '.$orden->fecha_recepcion?->format('d/m/Y'),
            ])
            ->values();

        return response()->json(['data' => $ordenes]);
    }

    /**
     * Store a newly created quote.
     */
    public function store(StoreCotizacionRequest $request, CrearCotizacion $accion): RedirectResponse
    {
        $data = $request->validated();
        $cotizacion = $accion->ejecutar($data, $data['items'], $request->user());

        return redirect()
            ->route($this->routePrefix().'.cotizaciones.show', $cotizacion)
            ->with('status', 'Cotización '.$cotizacion->folio.' creada correctamente.');
    }

    /**
     * Display the specified quote.
     */
    public function show(Cotizacion $cotizacion, VincularCotizacionAOrden $vincularOrden): View
    {
        $this->autorizarAcceso($cotizacion);
        $cotizacion->load(['items', 'cliente', 'equipo', 'partner', 'ordenServicio']);
        $puedeEditar = $cotizacion->esEditable()
            || ($cotizacion->estado === 'aceptada'
                && auth()->user()->isAdmin()
                && $cotizacion->ordenServicio
                && $vincularOrden->esModificableParaCotizacion($cotizacion->ordenServicio, $cotizacion));

        return view('cotizaciones.show', [
            'cotizacion' => $cotizacion,
            'routePrefix' => $this->routePrefix(),
            'puedeEditar' => $puedeEditar,
        ]);
    }

    /**
     * Show the form for editing the specified quote.
     */
    public function edit(Cotizacion $cotizacion, VincularCotizacionAOrden $vincularOrden): View
    {
        $this->autorizarAcceso($cotizacion);
        $cotizacion->load(['items', 'ordenServicio']);

        if ($cotizacion->estado === 'aceptada') {
            abort_unless(auth()->user()->isAdmin() && $cotizacion->ordenServicio, 403);
            abort_unless(
                $vincularOrden->esModificableParaCotizacion($cotizacion->ordenServicio, $cotizacion),
                403,
            );
        } else {
            abort_unless($cotizacion->esEditable(), 403);
        }

        return view('cotizaciones.edit', [
            'cotizacion' => $cotizacion,
            ...$this->formData($cotizacion, $cotizacion->ordenServicio),
        ]);
    }

    /**
     * Update the specified quote.
     */
    public function update(
        UpdateCotizacionRequest $request,
        Cotizacion $cotizacion,
        ActualizarCotizacion $accion,
    ): RedirectResponse {
        $this->autorizarAcceso($cotizacion);
        $data = $request->validated();
        $accion->ejecutar($cotizacion, $data, $data['items'], $request->user());

        return redirect()
            ->route($this->routePrefix().'.cotizaciones.show', $cotizacion)
            ->with('status', 'Cotización actualizada correctamente.');
    }

    /**
     * Remove the specified quote.
     */
    public function destroy(Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        try {
            $cotizacion->delete();
        } catch (QueryException) {
            return redirect()
                ->route($this->routePrefix().'.cotizaciones.index')
                ->with('error', 'No se puede eliminar la cotización porque tiene registros relacionados.');
        }

        return redirect()
            ->route($this->routePrefix().'.cotizaciones.index')
            ->with('status', 'Cotización eliminada correctamente.');
    }

    /**
     * Change the status of the specified quote.
     */
    public function cambiarEstado(
        Request $request,
        Cotizacion $cotizacion,
        CambiarEstadoCotizacion $accion,
    ): RedirectResponse {
        $this->autorizarAcceso($cotizacion);
        $validated = $request->validate([
            'estado' => ['required', 'string'],
        ]);

        $accion->ejecutar($cotizacion, $validated['estado'], $request->user());

        return redirect()
            ->route($this->routePrefix().'.cotizaciones.show', $cotizacion)
            ->with('status', 'Estado actualizado correctamente.');
    }

    /**
     * Download the quote as a PDF document.
     */
    public function pdf(Cotizacion $cotizacion, ExportarCotizacionPdf $exportador): Response
    {
        $this->autorizarAcceso($cotizacion);

        return $exportador->generar($cotizacion)->download($exportador->nombreArchivo($cotizacion));
    }

    /**
     * Download the quote as a PNG image (for sharing by chat).
     */
    public function png(Cotizacion $cotizacion, ExportarCotizacionPng $exportador): Response
    {
        $this->autorizarAcceso($cotizacion);

        try {
            $imagen = $exportador->generar($cotizacion);
        } catch (\RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return response($imagen, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$exportador->nombreArchivo($cotizacion).'"',
        ]);
    }

    /**
     * Display the printable template for the quote.
     */
    public function plantilla(Cotizacion $cotizacion): View
    {
        $this->autorizarAcceso($cotizacion);
        $cotizacion->load(['items', 'cliente', 'equipo', 'partner']);

        return view('cotizaciones.plantilla', [
            'cotizacion' => $cotizacion,
            'negocio' => config('negocio'),
        ]);
    }

    /**
     * Scope quotes to the authenticated user's partner when required.
     */
    private function consultaBase()
    {
        $query = Cotizacion::query();

        if (! auth()->user()->isAdmin()) {
            abort_if(auth()->user()->partner_id === null, 403);
            $query->where('partner_id', auth()->user()->partner_id);
        }

        return $query;
    }

    /**
     * Ensure the authenticated user may access the quote.
     */
    private function autorizarAcceso(Cotizacion $cotizacion): void
    {
        $user = auth()->user();

        if (! $user->isAdmin()
            && ($user->partner_id === null || $cotizacion->partner_id !== $user->partner_id)) {
            abort(404);
        }
    }

    /**
     * Shared data for the create/edit forms.
     *
     * @return array<string, mixed>
     */
    private function formData(?Cotizacion $cotizacion = null, ?OrdenServicio $ordenSeleccionada = null): array
    {
        $clienteId = old('cliente_id', $cotizacion?->cliente_id);
        $equipoId = old('equipo_id', $cotizacion?->equipo_id);
        $ordenId = old('orden_servicio_id', $ordenSeleccionada?->id);

        if ($ordenId && (! $ordenSeleccionada || (int) $ordenSeleccionada->id !== (int) $ordenId)) {
            $ordenSeleccionada = OrdenServicio::query()->find($ordenId);
        }

        return [
            'clienteSeleccionado' => $clienteId ? Cliente::find($clienteId) : null,
            'equipoSeleccionado' => $equipoId ? Equipo::find($equipoId) : null,
            'ordenSeleccionada' => $ordenSeleccionada,
            'routePrefix' => $this->routePrefix(),
        ];
    }

    /**
     * Get the route prefix for the authenticated user's role.
     */
    private function routePrefix(): string
    {
        return auth()->user()->isAdmin() ? 'admin' : 'logistica';
    }
}
