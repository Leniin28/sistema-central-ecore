<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Display a listing of service orders.
     */
    public function index(): View
    {
        $ordenes = $this->ordenesQuery()
            ->latest()
            ->paginate(10);

        return view('ordenes-servicio.index', [
            'ordenes' => $ordenes,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Show the form for creating a new service order.
     */
    public function create(): View
    {
        abort_if(auth()->user()->hasRole('socio_tecnico'), 403);

        return view('ordenes-servicio.create', [
            'orden' => new OrdenServicio(['tipo_recepcion' => 'sucursal']),
            ...$this->formData(),
        ]);
    }

    /**
     * Store a newly created service order.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(auth()->user()->hasRole('socio_tecnico'), 403);

        $data = $this->validatedData($request);
        $detalles = $this->validatedDetalles($request);
        $refacciones = $this->validatedRefacciones($request);
        $this->validateEquipoBelongsToCliente($data);
        $data = $this->applyRoleAssignments($data);
        $data['costo_tecnico'] = 0;

        $orden = DB::transaction(function () use ($data, $detalles, $refacciones): OrdenServicio {
            $orden = OrdenServicio::create([
                ...$data,
                'folio' => $this->generarFolio(),
                'estado' => 'recibido',
                'fecha_recepcion' => now(),
                'creado_por_user_id' => auth()->id(),
            ]);

            $orden->historialEstados()->create([
                'user_id' => auth()->id(),
                'estado_anterior' => null,
                'estado_nuevo' => 'recibido',
                'comentario' => 'Orden creada',
            ]);

            $this->guardarDetalles($orden, $detalles);
            $this->guardarRefacciones($orden, $refacciones);
            $this->recalcularTotalCliente($orden);

            return $orden;
        });

        return redirect()
            ->route($this->routePrefix().'.ordenes-servicio.show', $orden)
            ->with('status', 'Orden creada correctamente.');
    }

    /**
     * Display the specified service order.
     */
    public function show(OrdenServicio $ordenServicio): View
    {
        $this->authorizeView($ordenServicio);

        $ordenServicio->load([
            'cliente',
            'equipo',
            'partnerRecepcion',
            'partnerTecnico',
            'creadoPor',
            'detalles.servicio.categoriaServicio',
            'refacciones' => fn ($query) => $query->orderBy('id'),
            'historialEstados.user',
        ]);

        return view('ordenes-servicio.show', [
            'orden' => $ordenServicio,
            'routePrefix' => $this->routePrefix(),
            'estadosDisponibles' => $this->estadosDisponibles($ordenServicio),
        ]);
    }

    /**
     * Show the form for editing the specified service order.
     */
    public function edit(OrdenServicio $ordenServicio): View
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $ordenServicio->load([
            'detalles',
            'refacciones' => fn ($query) => $query->orderBy('id'),
        ]);

        return view('ordenes-servicio.edit', [
            'orden' => $ordenServicio,
            ...$this->formData($ordenServicio),
        ]);
    }

    /**
     * Update base data for the specified service order.
     */
    public function update(Request $request, OrdenServicio $ordenServicio): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $data = $this->validatedData($request);
        $detalles = $this->validatedDetalles($request);
        $refacciones = $ordenServicio->finanzas_generadas
            ? []
            : $this->validatedRefacciones($request);
        $this->validateEquipoBelongsToCliente($data);
        $this->validateCostoTecnicoEditable($request, $ordenServicio);
        $this->validateRefaccionesEditable($request, $ordenServicio);

        DB::transaction(function () use ($ordenServicio, $data, $detalles, $refacciones): void {
            $ordenServicio->update($data);

            $ordenServicio->detalles()->delete();
            $this->guardarDetalles($ordenServicio, $detalles);

            if (! $ordenServicio->finanzas_generadas) {
                $ordenServicio->refacciones()->delete();
                $this->guardarRefacciones($ordenServicio, $refacciones);
            }

            $this->recalcularTotalCliente($ordenServicio);
        });

        return redirect()
            ->route('admin.ordenes-servicio.show', $ordenServicio)
            ->with('status', 'Orden actualizada correctamente.');
    }

    /**
     * Remove the specified service order.
     */
    public function destroy(OrdenServicio $ordenServicio): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        try {
            DB::transaction(function () use ($ordenServicio): void {
                $ordenServicio->movimientosFinancieros()->delete();
                $ordenServicio->delete();
            });
        } catch (QueryException) {
            return redirect()
                ->route('admin.ordenes-servicio.index')
                ->with('error', 'No se puede eliminar la orden porque tiene registros relacionados.');
        }

        return redirect()
            ->route('admin.ordenes-servicio.index')
            ->with('status', 'Orden eliminada correctamente.');
    }

    /**
     * Get the filtered service order query for the current role.
     */
    private function ordenesQuery()
    {
        $query = OrdenServicio::with(['cliente', 'equipo', 'partnerRecepcion', 'partnerTecnico']);
        $user = auth()->user();

        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->hasRole('socio_logistico')) {
            return $query->where('partner_recepcion_id', $user->partner_id);
        }

        if ($user->hasRole('socio_tecnico')) {
            return $query->where('partner_tecnico_id', $user->partner_id);
        }

        abort(403);
    }

    /**
     * Validate base service order form data.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'equipo_id' => ['nullable', 'exists:equipos,id'],
            'tipo_recepcion' => ['required', 'in:sucursal,domicilio,directo'],
            'partner_recepcion_id' => ['nullable', 'exists:partners,id'],
            'partner_tecnico_id' => ['nullable', 'exists:partners,id'],
            'costo_tecnico' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
        ]);

        $data['equipo_id'] = $data['equipo_id'] ?? null;
        $data['partner_recepcion_id'] = $data['partner_recepcion_id'] ?? null;
        $data['partner_tecnico_id'] = $data['partner_tecnico_id'] ?? null;
        $data['costo_tecnico'] = $data['costo_tecnico'] ?? 0;

        return $data;
    }

    /**
     * Ensure technical cost is not changed after finances are generated.
     */
    private function validateCostoTecnicoEditable(Request $request, OrdenServicio $orden): void
    {
        if (! $orden->finanzas_generadas) {
            return;
        }

        $nuevoCostoTecnico = (float) ($request->input('costo_tecnico') ?? 0);
        $costoTecnicoActual = (float) $orden->costo_tecnico;

        if ($nuevoCostoTecnico !== $costoTecnicoActual) {
            throw ValidationException::withMessages([
                'costo_tecnico' => 'No se puede cambiar el costo técnico porque las finanzas ya fueron generadas.',
            ]);
        }
    }

    /**
     * Validate service order detail form data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validatedDetalles(Request $request): array
    {
        $servicios = collect($request->input('servicios', []))
            ->filter(fn (array $servicio): bool => filled($servicio['servicio_id'] ?? null))
            ->all();

        if ($servicios === []) {
            throw ValidationException::withMessages([
                'servicios' => 'Agrega al menos un servicio a la orden.',
            ]);
        }

        $validated = validator(
            ['servicios' => $servicios],
            [
                'servicios' => ['required', 'array', 'min:1'],
                'servicios.*.servicio_id' => ['required', 'exists:servicios,id'],
                'servicios.*.cantidad' => ['required', 'integer', 'min:1'],
                'servicios.*.precio_unitario' => ['required', 'numeric', 'min:0'],
                'servicios.*.notas' => ['nullable', 'string'],
            ],
        )->validate();

        return $validated['servicios'];
    }

    /**
     * Validate service order parts form data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validatedRefacciones(Request $request): array
    {
        $refacciones = $this->refaccionesFromRequest($request);

        if ($refacciones === []) {
            return [];
        }

        $validated = validator(
            ['refacciones' => $refacciones],
            [
                'refacciones' => ['array'],
                'refacciones.*.descripcion' => ['required', 'string', 'max:255'],
                'refacciones.*.cantidad' => ['required', 'integer', 'min:1'],
                'refacciones.*.costo_unitario' => ['required', 'numeric', 'min:0'],
                'refacciones.*.precio_unitario_cliente' => ['required', 'numeric', 'min:0'],
                'refacciones.*.notas' => ['nullable', 'string'],
            ],
        )->validate();

        return $validated['refacciones'];
    }

    /**
     * Get non-empty part rows from the request.
     *
     * @return array<int, array<string, mixed>>
     */
    private function refaccionesFromRequest(Request $request): array
    {
        return collect($request->input('refacciones', []))
            ->filter(function ($refaccion): bool {
                if (! is_array($refaccion)) {
                    return false;
                }

                return filled($refaccion['descripcion'] ?? null)
                    || filled($refaccion['cantidad'] ?? null)
                    || filled($refaccion['costo_unitario'] ?? null)
                    || filled($refaccion['precio_unitario_cliente'] ?? null)
                    || filled($refaccion['notas'] ?? null);
            })
            ->values()
            ->all();
    }

    /**
     * Ensure parts are not changed after finances are generated.
     */
    private function validateRefaccionesEditable(Request $request, OrdenServicio $orden): void
    {
        if (! $orden->finanzas_generadas || ! $request->has('refacciones')) {
            return;
        }

        $nuevasRefacciones = collect($this->validatedRefacciones($request))
            ->map(fn (array $refaccion): array => [
                'descripcion' => (string) $refaccion['descripcion'],
                'cantidad' => (int) $refaccion['cantidad'],
                'costo_unitario' => number_format((float) $refaccion['costo_unitario'], 2, '.', ''),
                'precio_unitario_cliente' => number_format((float) $refaccion['precio_unitario_cliente'], 2, '.', ''),
                'notas' => $refaccion['notas'] ?? null,
            ])
            ->values()
            ->all();

        $refaccionesActuales = $orden->refacciones()
            ->orderBy('id')
            ->get()
            ->map(fn ($refaccion): array => [
                'descripcion' => (string) $refaccion->descripcion,
                'cantidad' => (int) $refaccion->cantidad,
                'costo_unitario' => number_format((float) $refaccion->costo_unitario, 2, '.', ''),
                'precio_unitario_cliente' => number_format((float) $refaccion->precio_unitario_cliente, 2, '.', ''),
                'notas' => $refaccion->notas,
            ])
            ->values()
            ->all();

        if ($nuevasRefacciones !== $refaccionesActuales) {
            throw ValidationException::withMessages([
                'refacciones' => 'No se pueden cambiar las refacciones porque las finanzas ya fueron generadas.',
            ]);
        }
    }

    /**
     * Store order details.
     *
     * @param  array<int, array<string, mixed>>  $detalles
     */
    private function guardarDetalles(OrdenServicio $orden, array $detalles): void
    {
        foreach ($detalles as $detalle) {
            $cantidad = (int) $detalle['cantidad'];
            $precioUnitario = (float) $detalle['precio_unitario'];
            $subtotal = $cantidad * $precioUnitario;

            $orden->detalles()->create([
                'servicio_id' => $detalle['servicio_id'],
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'subtotal' => $subtotal,
                'notas' => $detalle['notas'] ?? null,
            ]);
        }
    }

    /**
     * Store order parts with backend-calculated totals.
     *
     * @param  array<int, array<string, mixed>>  $refacciones
     */
    private function guardarRefacciones(OrdenServicio $orden, array $refacciones): void
    {
        foreach ($refacciones as $refaccion) {
            $cantidad = (int) $refaccion['cantidad'];
            $costoUnitario = (float) $refaccion['costo_unitario'];
            $precioUnitarioCliente = (float) $refaccion['precio_unitario_cliente'];
            $costoTotal = $cantidad * $costoUnitario;
            $precioTotalCliente = $cantidad * $precioUnitarioCliente;

            $orden->refacciones()->create([
                'descripcion' => $refaccion['descripcion'],
                'cantidad' => $cantidad,
                'costo_unitario' => $costoUnitario,
                'precio_unitario_cliente' => $precioUnitarioCliente,
                'costo_total' => $costoTotal,
                'precio_total_cliente' => $precioTotalCliente,
                'utilidad_refaccion' => $precioTotalCliente - $costoTotal,
                'notas' => $refaccion['notas'] ?? null,
            ]);
        }
    }

    /**
     * Recalculate the customer total from services and parts.
     */
    private function recalcularTotalCliente(OrdenServicio $orden): void
    {
        $totalServicios = (float) $orden->detalles()->sum('subtotal');
        $totalRefacciones = (float) $orden->refacciones()->sum('precio_total_cliente');

        $orden->update([
            'total_cliente' => $totalServicios + $totalRefacciones,
        ]);
    }

    /**
     * Ensure the selected equipment belongs to the selected client.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateEquipoBelongsToCliente(array $data): void
    {
        if (empty($data['equipo_id'])) {
            return;
        }

        $equipoBelongsToCliente = Equipo::where('id', $data['equipo_id'])
            ->where('cliente_id', $data['cliente_id'])
            ->exists();

        if (! $equipoBelongsToCliente) {
            throw ValidationException::withMessages([
                'equipo_id' => 'El equipo seleccionado no pertenece al cliente seleccionado.',
            ]);
        }
    }

    /**
     * Apply role-specific partner assignment rules.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyRoleAssignments(array $data): array
    {
        $user = auth()->user();

        if ($user->hasRole('socio_logistico')) {
            abort_if($user->partner_id === null, 403);

            $data['partner_recepcion_id'] = $user->partner_id;
        }

        return $data;
    }

    /**
     * Authorize the current user to view the order.
     */
    private function authorizeView(OrdenServicio $orden): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->hasRole('socio_logistico') && $orden->partner_recepcion_id === $user->partner_id) {
            return;
        }

        if ($user->hasRole('socio_tecnico') && $orden->partner_tecnico_id === $user->partner_id) {
            return;
        }

        abort(403);
    }

    /**
     * Get available statuses for the current user and order state.
     *
     * @return array<int, string>
     */
    private function estadosDisponibles(OrdenServicio $orden): array
    {
        if ($orden->estado === 'entregado' && $orden->finanzas_generadas) {
            return [];
        }

        $user = auth()->user();

        if ($user->isAdmin()) {
            return array_values(array_filter(
                self::ESTADOS,
                fn (string $estado): bool => $estado !== $orden->estado,
            ));
        }

        if ($user->hasRole('socio_logistico')) {
            return $orden->estado === 'listo_para_entregar'
                ? ['entregado']
                : [];
        }

        if ($user->hasRole('socio_tecnico')) {
            return match ($orden->estado) {
                'recibido' => ['en_diagnostico'],
                'en_diagnostico' => ['cotizacion_pendiente'],
                'cotizacion_aprobada' => ['en_proceso'],
                'en_proceso' => ['listo_para_entregar'],
                'en_fixop' => ['listo_para_entregar'],
                default => [],
            };
        }

        return [];
    }

    /**
     * Get common form data.
     *
     * @return array<string, mixed>
     */
    private function formData(?OrdenServicio $orden = null): array
    {
        return [
            'clientes' => Cliente::orderBy('nombre')->get(),
            'equipos' => Equipo::with('cliente')->orderBy('marca')->orderBy('modelo')->get(),
            'servicios' => $this->serviciosForForm($orden),
            'partnersRecepcion' => Partner::where('activo', true)
                ->where('tipo_socio', 'logistico')
                ->orderBy('nombre')
                ->get(),
            'partnersTecnicos' => Partner::where('activo', true)
                ->where('tipo_socio', 'tecnico')
                ->orderBy('nombre')
                ->get(),
            'routePrefix' => $this->routePrefix(),
        ];
    }

    /**
     * Get active services, plus existing inactive services used by the order.
     */
    private function serviciosForForm(?OrdenServicio $orden = null)
    {
        $query = Servicio::query()
            ->with('categoriaServicio')
            ->where('activo', true);

        if ($orden && $orden->exists) {
            $servicioIds = $orden->detalles()
                ->pluck('servicio_id')
                ->all();

            if ($servicioIds !== []) {
                $query->orWhereIn('id', $servicioIds);
            }
        }

        return $query->orderBy('nombre')->get();
    }

    /**
     * Generate a unique service order folio.
     */
    private function generarFolio(): string
    {
        $fecha = now()->format('Ymd');
        $ultimoFolio = OrdenServicio::where('folio', 'like', "OS-{$fecha}-%")
            ->orderByDesc('folio')
            ->value('folio');

        $consecutivo = $ultimoFolio
            ? ((int) substr($ultimoFolio, -4)) + 1
            : 1;

        return 'OS-'.$fecha.'-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the route prefix for the authenticated user's role.
     */
    private function routePrefix(): string
    {
        $user = auth()->user();

        return match ($user->role) {
            'admin' => 'admin',
            'socio_logistico' => 'logistica',
            'socio_tecnico' => 'tecnico',
            default => abort(403),
        };
    }
}
