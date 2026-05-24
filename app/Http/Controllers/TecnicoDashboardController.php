<?php

namespace App\Http\Controllers;

use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TecnicoDashboardController extends Controller
{
    /**
     * Display the technical partner dashboard with scoped metrics.
     */
    public function __invoke(): View
    {
        $user = auth()->user();

        abort_if($user->partner_id === null, 403);

        $ordenesPorEstado = OrdenServicio::query()
            ->where('partner_tecnico_id', $user->partner_id)
            ->select('estado', DB::raw('count(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('dashboards.tecnico', [
            'totalOrdenesAsignadas' => $ordenesPorEstado->sum(),
            'totalOrdenesDiagnostico' => (int) ($ordenesPorEstado->get('en_diagnostico') ?? 0),
            'totalOrdenesProceso' => (int) ($ordenesPorEstado->get('en_proceso') ?? 0),
            'totalOrdenesListas' => (int) ($ordenesPorEstado->get('listo_para_entregar') ?? 0),
            'ultimasOrdenes' => OrdenServicio::with(['cliente', 'equipo', 'partnerRecepcion'])
                ->where('partner_tecnico_id', $user->partner_id)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
