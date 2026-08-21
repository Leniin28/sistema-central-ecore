<?php

namespace App\Http\Controllers;

use App\Models\AjusteFinancieroOrden;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only, admin-only financial summary. Deliberately keeps two separate
 * perspectives that must never be mixed:
 *
 * - "Flujo financiero" is dated by MovimientoFinanciero.fecha (when money
 *   actually moved) and reports ingresos/egresos/balance -- never "utilidad".
 * - "Operación" is dated by OrdenServicio.fecha_entrega (when an order
 *   closed) and reports counts/ventas/utilidad conocida for delivered,
 *   non-cancelled, non-consolidated orders only.
 *
 * Both use the same calendar range, each against its own date column. No
 * writes happen anywhere in this controller.
 */
class FinanzasResumenController extends Controller
{
    private const PERIODOS = ['hoy', 'semana', 'mes', 'personalizado'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'periodo' => ['nullable', Rule::in(self::PERIODOS)],
            'fecha_desde' => ['nullable', 'date', 'required_if:periodo,personalizado'],
            'fecha_hasta' => ['nullable', 'date', 'required_if:periodo,personalizado', 'after_or_equal:fecha_desde'],
        ]);

        $periodo = $filters['periodo'] ?? 'mes';
        [$desde, $hasta] = $this->resolverRango($periodo, $filters);

        $movimientosQuery = MovimientoFinanciero::query()
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString());

        $ingresosTotal = (float) (clone $movimientosQuery)->where('tipo', 'ingreso')->sum('monto');
        $egresosTotal = (float) (clone $movimientosQuery)->where('tipo', 'egreso')->sum('monto');
        $balance = round($ingresosTotal - $egresosTotal, 2);
        // Reembolsos del periodo (FASE H.3): ya incluidos en egresosTotal/balance
        // como cualquier otro egreso; este total es sólo un resumen dedicado
        // para no tener que leerlo del desglose por categoría.
        $reembolsosTotal = (float) (clone $movimientosQuery)
            ->where('tipo', 'egreso')
            ->where('categoria', AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE)
            ->sum('monto');

        $desglosePorCategoria = (clone $movimientosQuery)
            ->select('tipo', 'categoria', DB::raw('SUM(monto) as total'))
            ->groupBy('tipo', 'categoria')
            ->orderBy('tipo')
            ->orderByDesc('total')
            ->get();

        $movimientos = (clone $movimientosQuery)
            ->with(['ordenServicio', 'cotizacion', 'cliente', 'partner'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'movimientos_page')
            ->withQueryString();

        $ordenesEntregadasQuery = OrdenServicio::query()
            ->whereNull('orden_canonica_id')
            ->where('estado', 'entregado')
            ->whereDate('fecha_entrega', '>=', $desde->toDateString())
            ->whereDate('fecha_entrega', '<=', $hasta->toDateString());

        $ordenesEntregadasCount = (clone $ordenesEntregadasQuery)->count();
        $ventasEntregadas = (float) (clone $ordenesEntregadasQuery)->sum('total_cliente');
        $ticketPromedio = $ordenesEntregadasCount > 0 ? round($ventasEntregadas / $ordenesEntregadasCount, 2) : 0.0;
        $utilidadConocida = (float) (clone $ordenesEntregadasQuery)->where('costos_incompletos', false)->sum('utilidad_neta');
        $ordenesConCostosIncompletos = (clone $ordenesEntregadasQuery)->where('costos_incompletos', true)->count();

        $ordenesEntregadas = (clone $ordenesEntregadasQuery)
            ->orderByDesc('fecha_entrega')
            ->paginate(20, ['*'], 'ordenes_page')
            ->withQueryString();

        return view('finanzas.resumen', [
            'periodo' => $periodo,
            'desde' => $desde,
            'hasta' => $hasta,
            'ingresosTotal' => $ingresosTotal,
            'egresosTotal' => $egresosTotal,
            'balance' => $balance,
            'reembolsosTotal' => $reembolsosTotal,
            'desglosePorCategoria' => $desglosePorCategoria,
            'movimientos' => $movimientos,
            'ordenesEntregadasCount' => $ordenesEntregadasCount,
            'ventasEntregadas' => $ventasEntregadas,
            'ticketPromedio' => $ticketPromedio,
            'utilidadConocida' => $utilidadConocida,
            'ordenesConCostosIncompletos' => $ordenesConCostosIncompletos,
            'ordenesEntregadas' => $ordenesEntregadas,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolverRango(string $periodo, array $filters): array
    {
        $hoy = CarbonImmutable::today();

        return match ($periodo) {
            'hoy' => [$hoy, $hoy],
            'semana' => [$hoy->startOfWeek(), $hoy->endOfWeek()],
            'personalizado' => [
                CarbonImmutable::parse($filters['fecha_desde']),
                CarbonImmutable::parse($filters['fecha_hasta']),
            ],
            default => [$hoy->startOfMonth(), $hoy->endOfMonth()],
        };
    }
}
