<?php

namespace App\Http\Controllers;

use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LogisticaDashboardController extends Controller
{
    /**
     * Display the logistics partner dashboard with scoped metrics.
     */
    public function __invoke(): View
    {
        $user = auth()->user();

        abort_if($user->partner_id === null, 403);

        $ordenesPorEstado = OrdenServicio::query()
            ->where('partner_recepcion_id', $user->partner_id)
            ->select('estado', DB::raw('count(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('dashboards.logistica', [
            'totalOrdenesRecibidas' => $ordenesPorEstado->sum(),
            'totalOrdenesActivas' => $ordenesPorEstado
                ->except(['entregado', 'cancelado'])
                ->sum(),
            'totalOrdenesListas' => (int) ($ordenesPorEstado->get('listo_para_entregar') ?? 0),
            'totalOrdenesEntregadas' => (int) ($ordenesPorEstado->get('entregado') ?? 0),
            'ultimasOrdenes' => OrdenServicio::with(['cliente', 'equipo', 'partnerTecnico'])
                ->where('partner_recepcion_id', $user->partner_id)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
