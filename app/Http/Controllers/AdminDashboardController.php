<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /**
     * Display the administrative dashboard with real system metrics.
     */
    public function __invoke(): View
    {
        $ordenesPorEstado = OrdenServicio::query()
            ->select('estado', DB::raw('count(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $movimientosPorTipo = MovimientoFinanciero::query()
            ->select('tipo', DB::raw('sum(monto) as total'))
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $totalIngresos = (float) ($movimientosPorTipo->get('ingreso') ?? 0);
        $totalEgresos = (float) ($movimientosPorTipo->get('egreso') ?? 0);

        $totalOrdenesActivas = $ordenesPorEstado
            ->except(['entregado', 'cancelado'])
            ->sum();

        return view('dashboards.admin', [
            'totalOrdenesActivas' => $totalOrdenesActivas,
            'totalOrdenesEntregadas' => (int) ($ordenesPorEstado->get('entregado') ?? 0),
            'totalClientes' => Cliente::count(),
            'totalEquipos' => Equipo::count(),
            'totalIngresos' => $totalIngresos,
            'totalEgresos' => $totalEgresos,
            'balanceFinanciero' => $totalIngresos - $totalEgresos,
            'totalOrdenesEnFixop' => (int) ($ordenesPorEstado->get('en_fixop') ?? 0),
            'totalOrdenesListas' => (int) ($ordenesPorEstado->get('listo_para_entregar') ?? 0),
            'ultimasOrdenes' => OrdenServicio::with(['cliente', 'equipo', 'partnerRecepcion', 'partnerTecnico'])
                ->latest()
                ->limit(5)
                ->get(),
            'ultimosMovimientos' => MovimientoFinanciero::with(['ordenServicio', 'cliente', 'partner'])
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ]);
    }
}
