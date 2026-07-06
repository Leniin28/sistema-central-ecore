<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MovimientoFinanciero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Operational expenses registered from OpenClaw (Telegram). They are stored as
 * manual MovimientoFinanciero egresos (orden_servicio_id = null), exactly like
 * the panel's manual movement form, so they DO show up in the cash cut's real
 * expenses. Idempotent by external_id (new nullable unique column).
 */
class InternalExpenseController extends Controller
{
    /**
     * OpenClaw categories → panel categories (MovimientoFinancieroController::CATEGORIAS).
     *
     * @var array<string, string>
     */
    private const CATEGORIAS = [
        'refaccion' => 'refaccion',
        'herramienta' => 'herramientas',
        'gasolina' => 'gasolina',
        'envio' => 'transporte',
        'comida' => 'gasto_operativo',
        'otro' => 'otro',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'descripcion' => ['required', 'string', 'max:1000'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'categoria' => ['required', Rule::in(array_keys(self::CATEGORIAS))],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'fecha' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        $resultado = DB::transaction(function () use ($data): array {
            if (! empty($data['external_id'])) {
                $existente = MovimientoFinanciero::query()
                    ->where('external_id', $data['external_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existente) {
                    return ['movimiento' => $existente, 'created' => false];
                }
            }

            $descripcion = collect([
                trim($data['descripcion']),
                filled($data['proveedor'] ?? null) ? 'Proveedor: '.trim((string) $data['proveedor']) : null,
                filled($data['notas'] ?? null) ? trim((string) $data['notas']) : null,
                'Registrado por OpenClaw (Telegram).',
            ])->filter()->implode(' | ');

            $movimiento = MovimientoFinanciero::create([
                'orden_servicio_id' => null,
                'cliente_id' => null,
                'partner_id' => null,
                'tipo' => 'egreso',
                'categoria' => self::CATEGORIAS[$data['categoria']],
                'monto' => $data['monto'],
                'descripcion' => $descripcion,
                'fecha' => $data['fecha'] ?? today()->toDateString(),
                'external_id' => $data['external_id'] ?? null,
            ]);

            return ['movimiento' => $movimiento, 'created' => true];
        });

        $movimiento = $resultado['movimiento'];

        Log::info('API interna: gasto operativo registrado desde OpenClaw.', [
            'movimiento_id' => $movimiento->id,
            'categoria' => $movimiento->categoria,
            'monto' => (float) $movimiento->monto,
            'created' => $resultado['created'],
        ]);

        return response()->json([
            'created' => $resultado['created'],
            'id' => $movimiento->id,
            'tipo' => $movimiento->tipo,
            'categoria' => $movimiento->categoria,
            'categoria_openclaw' => $data['categoria'],
            'monto' => (float) $movimiento->monto,
            'descripcion' => $movimiento->descripcion,
            'fecha' => $movimiento->fecha?->toDateString(),
            'external_id' => $movimiento->external_id,
            // Este gasto entra a los egresos reales del corte (cash-cut) de su fecha.
            'afecta_corte' => true,
        ], $resultado['created'] ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'categoria' => ['nullable', Rule::in(array_keys(self::CATEGORIAS))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limite = (int) ($filtros['limit'] ?? 50);

        // Solo gastos manuales/operativos: los egresos generados por órdenes
        // tienen orden_servicio_id y se consultan vía reportes.
        $gastos = MovimientoFinanciero::query()
            ->where('tipo', 'egreso')
            ->whereNull('orden_servicio_id')
            ->when($filtros['date'] ?? null, fn ($query, string $fecha) => $query->whereDate('fecha', $fecha))
            ->when($filtros['date_from'] ?? null, fn ($query, string $desde) => $query->whereDate('fecha', '>=', $desde))
            ->when($filtros['date_to'] ?? null, fn ($query, string $hasta) => $query->whereDate('fecha', '<=', $hasta))
            ->when($filtros['categoria'] ?? null, fn ($query, string $categoria) => $query->where('categoria', self::CATEGORIAS[$categoria]))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        return response()->json([
            'items' => $gastos->map(fn (MovimientoFinanciero $gasto): array => [
                'id' => $gasto->id,
                'categoria' => $gasto->categoria,
                'monto' => (float) $gasto->monto,
                'descripcion' => $gasto->descripcion,
                'fecha' => $gasto->fecha?->toDateString(),
                'external_id' => $gasto->external_id,
            ])->values()->all(),
            'total' => (float) $gastos->sum('monto'),
            'warnings' => [],
        ]);
    }
}
