<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoFinanciero;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MovimientoFinancieroController extends Controller
{
    private const CATEGORIAS = [
        'reparacion',
        'mantenimiento',
        'marketing',
        'fb_ads',
        'pago_socio_logistico',
        'pago_socio_tecnico',
        'inversion_publicitaria',
        'refaccion',
        'gasto_operativo',
        'gasolina',
        'transporte',
        'herramientas',
        'insumos',
        'renta',
        'internet',
        'otro',
    ];

    private const TIPOS = [
        'ingreso',
        'egreso',
    ];

    /**
     * Display a read-only listing of financial movements.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'tipo' => ['nullable', 'in:ingreso,egreso'],
            'categoria' => ['nullable', 'string'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
        ]);

        $query = MovimientoFinanciero::query()
            ->with(['ordenServicio', 'cotizacion', 'cliente', 'partner']);

        $this->applyFilters($query, $filters);

        $totalIngresos = (clone $query)->where('tipo', 'ingreso')->sum('monto');
        $totalEgresos = (clone $query)->where('tipo', 'egreso')->sum('monto');
        $balance = $totalIngresos - $totalEgresos;

        $movimientos = $query
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('movimientos-financieros.index', [
            'movimientos' => $movimientos,
            'categorias' => [...self::CATEGORIAS, 'anticipo'],
            'filters' => $filters,
            'totalIngresos' => $totalIngresos,
            'totalEgresos' => $totalEgresos,
            'balance' => $balance,
        ]);
    }

    /**
     * Show the form for creating a manual financial movement.
     */
    public function create(): View
    {
        return view('movimientos-financieros.create', [
            'clientes' => Cliente::query()->orderBy('nombre')->get(),
            'partners' => Partner::query()->where('activo', true)->orderBy('nombre')->get(),
            'categorias' => self::CATEGORIAS,
            'tipos' => self::TIPOS,
        ]);
    }

    /**
     * Store a manually created financial movement.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cliente_id' => ['nullable', 'exists:clientes,id'],
            'partner_id' => ['nullable', 'exists:partners,id'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'categoria' => ['required', Rule::in(self::CATEGORIAS)],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'fecha' => ['required', 'date'],
        ]);

        MovimientoFinanciero::create([
            'orden_servicio_id' => null,
            'cliente_id' => $data['cliente_id'] ?? null,
            'partner_id' => $data['partner_id'] ?? null,
            'tipo' => $data['tipo'],
            'categoria' => $data['categoria'],
            'monto' => $data['monto'],
            'descripcion' => $data['descripcion'] ?? null,
            'fecha' => $data['fecha'],
        ]);

        return redirect()
            ->route('admin.movimientos-financieros.index')
            ->with('status', 'Movimiento financiero registrado correctamente.');
    }

    /**
     * Apply filters to the financial movements query.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        $query
            ->when($filters['tipo'] ?? null, fn ($query, string $tipo) => $query->where('tipo', $tipo))
            ->when($filters['categoria'] ?? null, fn ($query, string $categoria) => $query->where('categoria', $categoria))
            ->when($filters['fecha_desde'] ?? null, fn ($query, string $fecha) => $query->whereDate('fecha', '>=', $fecha))
            ->when($filters['fecha_hasta'] ?? null, fn ($query, string $fecha) => $query->whereDate('fecha', '<=', $fecha));
    }
}
