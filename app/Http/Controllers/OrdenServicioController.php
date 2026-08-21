<?php

namespace App\Http\Controllers;

use App\Actions\Cotizaciones\VincularCotizacionAOrden;
use App\Actions\Ordenes\ActualizarCostosInternosOrdenServicio;
use App\Actions\Ordenes\ActualizarOrdenServicio;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\GeneracionFinancieraOrden;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Services\ExportarNotaRecepcionPdf;
use App\Services\ExportarNotaRecepcionPng;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrdenServicioController extends Controller
{
    private const ESTADOS = [
        'recibido',
        'en_diagnostico',
        'cotizacion_pendiente',
        'cotizacion_aprobada',
        'en_proceso',
        'en_fixop',
        'listo_para_entregar',
        'entregado',
        'cancelado',
    ];

    public function index(): View
    {
        return view('ordenes-servicio.index', [
            'ordenes' => $this->ordenesQuery()->latest()->paginate(10),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function create(): View
    {
        abort_if(auth()->user()->hasRole('socio_tecnico'), 403);

        return view('ordenes-servicio.create', [
            'orden' => new OrdenServicio(['tipo_recepcion' => 'sucursal']),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request, CrearOrdenServicio $crearOrden): RedirectResponse
    {
        abort_if(auth()->user()->hasRole('socio_tecnico'), 403);
        $data = $this->validatedData($request);
        $orden = $crearOrden->ejecutar(
            $data,
            $this->validatedDetalles($request),
            $this->validatedRefacciones($request),
            $request->user(),
        );

        return redirect()
            ->route($this->routePrefix().'.ordenes-servicio.show', $orden)
            ->with('status', 'Orden creada correctamente.');
    }

    public function show(OrdenServicio $ordenServicio, VincularCotizacionAOrden $vincularOrden): View
    {
        $this->authorizeView($ordenServicio);
        $ordenServicio->load([
            'cliente', 'equipo', 'partnerRecepcion', 'partnerTecnico', 'creadoPor', 'cotizacion',
            'detalles.servicio.categoriaServicio',
            'refacciones' => fn ($query) => $query->orderBy('id'),
            'historialEstados.user',
            'ajustesFinancieros' => fn ($query) => $query->with('actor')->latest('id'),
        ]);

        return view('ordenes-servicio.show', [
            'orden' => $ordenServicio,
            'routePrefix' => $this->routePrefix(),
            'estadosDisponibles' => $this->estadosDisponibles($ordenServicio),
            'puedeCrearCotizacion' => auth()->user()->isAdmin()
                && $vincularOrden->esElegibleParaNuevaCotizacion($ordenServicio),
            'tieneLoteEntrega' => $ordenServicio->generacionesFinancieras()
                ->where('tipo', GeneracionFinancieraOrden::TIPO_ENTREGA)
                ->exists(),
        ]);
    }

    public function notaRecepcion(OrdenServicio $ordenServicio): View
    {
        $this->authorizeView($ordenServicio);
        $ordenServicio->loadMissing(['cliente', 'equipo']);

        return view('ordenes-servicio.nota-recepcion', [
            'orden' => $ordenServicio,
            'negocio' => config('negocio'),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function notaRecepcionPdf(OrdenServicio $ordenServicio, ExportarNotaRecepcionPdf $exportador): Response
    {
        $this->authorizeView($ordenServicio);

        return $exportador->generar($ordenServicio)->download($exportador->nombreArchivo($ordenServicio));
    }

    public function notaRecepcionPng(OrdenServicio $ordenServicio, ExportarNotaRecepcionPng $exportador): Response
    {
        $this->authorizeView($ordenServicio);

        try {
            $imagen = $exportador->generar($ordenServicio);
        } catch (\RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return response($imagen, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$exportador->nombreArchivo($ordenServicio).'"',
        ]);
    }

    public function edit(OrdenServicio $ordenServicio): View|RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $ordenServicio->load(['cotizacion', 'detalles', 'refacciones' => fn ($query) => $query->orderBy('id')]);

        if ($ordenServicio->finanzas_generadas
            || $ordenServicio->estado === 'entregado'
            || ($ordenServicio->cotizacion?->estado === 'aceptada'
                && ($ordenServicio->estado === 'cancelado' || $ordenServicio->tieneMovimientosFinancierosIncompatibles()))) {
            return redirect()
                ->route('admin.ordenes-servicio.show', $ordenServicio)
                ->with('error', 'La orden está cerrada o tiene movimientos financieros y no puede editarse.');
        }

        return view('ordenes-servicio.edit', [
            'orden' => $ordenServicio,
            ...$this->formData($ordenServicio),
        ]);
    }

    public function update(
        Request $request,
        OrdenServicio $ordenServicio,
        ActualizarOrdenServicio $actualizarOrden,
    ): RedirectResponse {
        abort_unless(auth()->user()->isAdmin(), 403);
        $orden = $actualizarOrden->ejecutar(
            $ordenServicio,
            $this->validatedData($request),
            $this->validatedDetalles($request, $ordenServicio),
            $this->validatedRefacciones($request),
            $request->user(),
        );

        return redirect()
            ->route('admin.ordenes-servicio.show', $orden)
            ->with('status', 'Orden actualizada correctamente.');
    }

    public function actualizarCostos(
        Request $request,
        OrdenServicio $ordenServicio,
        ActualizarCostosInternosOrdenServicio $actualizarCostos,
    ): RedirectResponse {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'costos_servicios' => ['sometimes', 'array'],
            'costos_servicios.*.id' => ['required', 'integer', 'distinct'],
            'costos_servicios.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'costos_refacciones' => ['sometimes', 'array'],
            'costos_refacciones.*.id' => ['required', 'integer', 'distinct'],
            'costos_refacciones.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
        ]);
        $orden = $actualizarCostos->ejecutar(
            $ordenServicio,
            $data['costos_servicios'] ?? [],
            $data['costos_refacciones'] ?? [],
            $request->user(),
        );

        return redirect()
            ->route('admin.ordenes-servicio.show', $orden)
            ->with('status', 'Costos internos actualizados correctamente.');
    }

    public function destroy(OrdenServicio $ordenServicio): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if (
            $ordenServicio->finanzas_generadas
            || $ordenServicio->estado === 'entregado'
            || $ordenServicio->movimientosFinancieros()->exists()
        ) {
            return redirect()
                ->route('admin.ordenes-servicio.index')
                ->with('error', 'No se puede eliminar una orden cerrada o con movimientos financieros asociados.');
        }

        try {
            $ordenServicio->delete();
        } catch (QueryException) {
            return redirect()
                ->route('admin.ordenes-servicio.index')
                ->with('error', 'No se puede eliminar la orden porque tiene registros relacionados.');
        }

        return redirect()
            ->route('admin.ordenes-servicio.index')
            ->with('status', 'Orden eliminada correctamente.');
    }

    private function ordenesQuery()
    {
        $query = OrdenServicio::with(['cliente', 'equipo', 'partnerRecepcion', 'partnerTecnico']);
        $user = auth()->user();

        return match ($user->role) {
            'admin' => $query,
            'socio_logistico' => $query->where('partner_recepcion_id', $user->partner_id),
            'socio_tecnico' => $query->where('partner_tecnico_id', $user->partner_id),
            default => abort(403),
        };
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'equipo_id' => ['nullable', 'exists:equipos,id'],
            'tipo_recepcion' => ['required', Rule::in(['sucursal', 'domicilio', 'directo'])],
            'partner_recepcion_id' => ['nullable', Rule::exists('partners', 'id')->where(fn ($query) => $query->where('tipo_socio', 'logistico')->where('activo', true))],
            'partner_tecnico_id' => ['nullable', Rule::exists('partners', 'id')->where(fn ($query) => $query->where('tipo_socio', 'tecnico')->where('activo', true))],
            'comision_recepcion' => $request->user()->isAdmin()
                ? ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99']
                : ['prohibited'],
            'nota_recepcion' => ['nullable', 'string', 'max:255'],
            'costo_tecnico' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
        ]);

        foreach (['equipo_id', 'partner_recepcion_id', 'partner_tecnico_id'] as $campo) {
            $data[$campo] = $data[$campo] ?? null;
        }
        $data['costo_tecnico'] = isset($data['costo_tecnico']) && $data['costo_tecnico'] !== ''
            ? $data['costo_tecnico']
            : null;
        $data['comision_recepcion'] = array_key_exists('comision_recepcion', $data)
            && $data['comision_recepcion'] !== ''
                ? $data['comision_recepcion']
                : null;
        $notaRecepcion = trim((string) ($data['nota_recepcion'] ?? ''));
        $data['nota_recepcion'] = $notaRecepcion !== '' ? $notaRecepcion : null;

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    private function validatedDetalles(Request $request, ?OrdenServicio $ordenServicio = null): array
    {
        $servicios = collect($request->input('servicios', []))
            ->filter(fn ($row): bool => is_array($row) && (filled($row['servicio_id'] ?? null) || filled($row['descripcion'] ?? null)))
            ->values()->all();

        if ($servicios === []) {
            if ($ordenServicio?->cotizacion_id !== null) {
                return [];
            }

            throw ValidationException::withMessages(['servicios' => 'Agrega al menos un servicio a la orden.']);
        }

        $validados = validator(['servicios' => $servicios], [
            'servicios' => ['required', 'array', 'min:1'],
            'servicios.*.servicio_id' => ['nullable', 'exists:servicios,id'],
            'servicios.*.descripcion' => ['required', 'string', 'max:255'],
            'servicios.*.cantidad' => ['required', 'integer', 'min:1'],
            'servicios.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'servicios.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.notas' => ['nullable', 'string'],
        ])->validate()['servicios'];

        if ($request->user()->isAdmin()) {
            return $validados;
        }

        return array_map(function (array $servicio): array {
            $servicio['costo_unitario'] = null;

            return $servicio;
        }, $validados);
    }

    /** @return array<int, array<string, mixed>> */
    private function validatedRefacciones(Request $request): array
    {
        $refacciones = collect($request->input('refacciones', []))
            ->filter(fn ($row): bool => is_array($row) && collect($row)->contains(fn ($value) => filled($value)))
            ->values()->all();

        if ($refacciones === []) {
            return [];
        }

        $validadas = validator(['refacciones' => $refacciones], [
            'refacciones' => ['array'],
            'refacciones.*.descripcion' => ['required', 'string', 'max:255'],
            'refacciones.*.cantidad' => ['required', 'integer', 'min:1'],
            'refacciones.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.precio_unitario_cliente' => ['required', 'numeric', 'min:0'],
            'refacciones.*.notas' => ['nullable', 'string'],
        ])->validate()['refacciones'];

        if ($request->user()->isAdmin()) {
            return $validadas;
        }

        return array_map(function (array $refaccion): array {
            $refaccion['costo_unitario'] = null;

            return $refaccion;
        }, $validadas);
    }

    private function authorizeView(OrdenServicio $orden): void
    {
        $user = auth()->user();
        $autorizado = $user->isAdmin()
            || ($user->hasRole('socio_logistico') && $orden->partner_recepcion_id === $user->partner_id)
            || ($user->hasRole('socio_tecnico') && $orden->partner_tecnico_id === $user->partner_id);
        abort_unless($autorizado, 403);
    }

    /** @return array<int, string> */
    private function estadosDisponibles(OrdenServicio $orden): array
    {
        if ($orden->estado === 'entregado' || $orden->finanzas_generadas) {
            return [];
        }
        $user = auth()->user();

        if ($user->isAdmin()) {
            return array_values(array_filter(self::ESTADOS, fn (string $estado): bool => $estado !== $orden->estado));
        }
        if ($user->hasRole('socio_logistico')) {
            return $orden->estado === 'listo_para_entregar' ? ['entregado'] : [];
        }

        return $user->hasRole('socio_tecnico') ? match ($orden->estado) {
            'recibido' => ['en_diagnostico'],
            'en_diagnostico' => ['cotizacion_pendiente'],
            'cotizacion_aprobada' => ['en_proceso'],
            'en_proceso', 'en_fixop' => ['listo_para_entregar'],
            default => [],
        } : [];
    }

    /** @return array<string, mixed> */
    private function formData(?OrdenServicio $orden = null): array
    {
        $servicios = Servicio::query()->with('categoriaServicio')->where('activo', true);
        if ($orden?->exists) {
            $servicios->orWhereIn('id', $orden->detalles()->pluck('servicio_id'));
        }

        return [
            'clientes' => Cliente::orderBy('nombre')->get(),
            'equipos' => Equipo::with('cliente')->orderBy('marca')->orderBy('modelo')->get(),
            'servicios' => $servicios->orderBy('nombre')->get(),
            'partnersRecepcion' => Partner::where('activo', true)->where('tipo_socio', 'logistico')->orderBy('nombre')->get(),
            'partnersTecnicos' => Partner::where('activo', true)->where('tipo_socio', 'tecnico')->orderBy('nombre')->get(),
            'routePrefix' => $this->routePrefix(),
        ];
    }

    private function routePrefix(): string
    {
        return match (auth()->user()->role) {
            'admin' => 'admin',
            'socio_logistico' => 'logistica',
            'socio_tecnico' => 'tecnico',
            default => abort(403),
        };
    }
}
