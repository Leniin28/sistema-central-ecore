<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unified search for OpenClaw (Telegram): clients, service orders and quotes by
 * free text (name, phone, folio, equipment, branch) plus optional filters. Read
 * only, safe payloads (never password_equipo), always with panel show_url.
 */
class InternalSearchController extends Controller
{
    private const LIMIT_DEFAULT = 10;

    private const LIMIT_MAX = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['all', 'clients', 'orders', 'quotes'])],
            'estado' => ['nullable', 'string', 'max:50'],
            'partner' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIMIT_MAX],
        ]);

        $tipo = $filtros['type'] ?? 'all';
        $q = trim((string) ($filtros['q'] ?? ''));
        $limite = (int) ($filtros['limit'] ?? self::LIMIT_DEFAULT);
        $warnings = [];

        if ($q === '' && blank($filtros['estado'] ?? null) && blank($filtros['partner'] ?? null)
            && blank($filtros['date_from'] ?? null) && blank($filtros['date_to'] ?? null)) {
            $warnings[] = 'Búsqueda sin criterios: envía q, estado, partner o rango de fechas para acotar.';
        }

        $clientes = in_array($tipo, ['all', 'clients'], true)
            ? $this->buscarClientes($q, $limite, $warnings)
            : [];
        $ordenes = in_array($tipo, ['all', 'orders'], true)
            ? $this->buscarOrdenes($q, $filtros, $limite, $warnings)
            : [];
        $cotizaciones = in_array($tipo, ['all', 'quotes'], true)
            ? $this->buscarCotizaciones($q, $filtros, $limite, $warnings)
            : [];

        return response()->json([
            'clientes' => $clientes,
            'ordenes' => $ordenes,
            'cotizaciones' => $cotizaciones,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function buscarClientes(string $q, int $limite, array &$warnings): array
    {
        if ($q === '') {
            return [];
        }

        $query = Cliente::query()
            ->where(function ($builder) use ($q): void {
                $builder->where('nombre', 'like', "%{$q}%")
                    ->orWhere('telefono', 'like', "%{$q}%")
                    ->orWhere('correo', 'like', "%{$q}%");
            })
            ->orderBy('nombre');

        $clientes = $query->limit($limite + 1)->get();
        $this->avisarTruncado($clientes, $limite, 'clientes', $warnings);

        return $clientes->take($limite)->map(fn (Cliente $cliente): array => [
            'id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'telefono' => $cliente->telefono,
            'correo' => $cliente->correo,
            'tipo_cliente' => $cliente->tipo_cliente,
            'ordenes_activas' => $cliente->ordenesServicio()
                ->whereNotIn('estado', ['entregado', 'cancelado'])->count(),
            'show_url' => route('admin.clientes.show', $cliente),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function buscarOrdenes(string $q, array $filtros, int $limite, array &$warnings): array
    {
        if (filled($filtros['estado'] ?? null) && ! in_array($filtros['estado'], OrdenServicio::ESTADOS, true)) {
            $warnings[] = "El estado \"{$filtros['estado']}\" no existe; los válidos son: ".implode(', ', OrdenServicio::ESTADOS).'.';

            return [];
        }

        $query = OrdenServicio::query()
            ->with(['cliente', 'equipo', 'partnerRecepcion'])
            ->when($q !== '', function ($builder) use ($q): void {
                $builder->where(function ($subquery) use ($q): void {
                    $subquery->where('folio', 'like', "%{$q}%")
                        ->orWhereHas('cliente', function ($cliente) use ($q): void {
                            $cliente->where('nombre', 'like', "%{$q}%")->orWhere('telefono', 'like', "%{$q}%");
                        })
                        ->orWhereHas('equipo', function ($equipo) use ($q): void {
                            $equipo->where('tipo_equipo', 'like', "%{$q}%")
                                ->orWhere('marca', 'like', "%{$q}%")
                                ->orWhere('modelo', 'like', "%{$q}%")
                                ->orWhere('numero_serie', 'like', "%{$q}%");
                        })
                        ->orWhereHas('partnerRecepcion', fn ($partner) => $partner->where('nombre', 'like', "%{$q}%"));
                });
            })
            ->when($filtros['estado'] ?? null, fn ($builder, string $estado) => $builder->where('estado', $estado))
            ->when($filtros['partner'] ?? null, function ($builder, string $partner): void {
                $builder->whereHas('partnerRecepcion', fn ($sub) => $sub->where('nombre', 'like', "%{$partner}%"));
            })
            ->when($filtros['date_from'] ?? null, fn ($builder, string $desde) => $builder->whereDate('fecha_recepcion', '>=', $desde))
            ->when($filtros['date_to'] ?? null, fn ($builder, string $hasta) => $builder->whereDate('fecha_recepcion', '<=', $hasta))
            ->orderByDesc('fecha_recepcion');

        $ordenes = $query->limit($limite + 1)->get();
        $this->avisarTruncado($ordenes, $limite, 'órdenes', $warnings);

        return $ordenes->take($limite)->map(fn (OrdenServicio $orden): array => [
            'id' => $orden->id,
            'folio' => $orden->folio,
            'estado' => $orden->estado,
            'estado_label' => $orden->estadoLabel(),
            'cliente' => $orden->cliente?->nombre,
            'telefono' => $orden->cliente?->telefono,
            'equipo' => $orden->equipo
                ? trim("{$orden->equipo->tipo_equipo} {$orden->equipo->marca} ".(string) $orden->equipo->modelo)
                : null,
            'sucursal' => $orden->partnerRecepcion?->nombre,
            'total_cliente' => (float) $orden->total_cliente,
            'fecha_recepcion' => $orden->fecha_recepcion?->toDateString(),
            'fecha_entrega' => $orden->fecha_entrega?->toDateString(),
            'show_url' => route('admin.ordenes-servicio.show', $orden),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function buscarCotizaciones(string $q, array $filtros, int $limite, array &$warnings): array
    {
        $estado = $filtros['estado'] ?? null;
        if (filled($estado) && ! in_array($estado, Cotizacion::ESTADOS, true)) {
            // El estado puede ser de órdenes (búsqueda type=all); no es error, solo no aplica aquí.
            return [];
        }

        $query = Cotizacion::query()
            ->with(['cliente', 'equipo'])
            ->when($q !== '', function ($builder) use ($q): void {
                $builder->where(function ($subquery) use ($q): void {
                    $subquery->where('folio', 'like', "%{$q}%")
                        ->orWhereHas('cliente', function ($cliente) use ($q): void {
                            $cliente->where('nombre', 'like', "%{$q}%")->orWhere('telefono', 'like', "%{$q}%");
                        });
                });
            })
            ->when($estado, fn ($builder, string $valor) => $builder->where('estado', $valor))
            ->when($filtros['date_from'] ?? null, fn ($builder, string $desde) => $builder->whereDate('fecha', '>=', $desde))
            ->when($filtros['date_to'] ?? null, fn ($builder, string $hasta) => $builder->whereDate('fecha', '<=', $hasta))
            ->orderByDesc('fecha');

        $cotizaciones = $query->limit($limite + 1)->get();
        $this->avisarTruncado($cotizaciones, $limite, 'cotizaciones', $warnings);

        return $cotizaciones->take($limite)->map(fn (Cotizacion $cotizacion): array => [
            'id' => $cotizacion->id,
            'folio' => $cotizacion->folio,
            'estado' => $cotizacion->estado,
            'cliente' => $cotizacion->cliente?->nombre,
            'telefono' => $cotizacion->cliente?->telefono,
            'total' => (float) $cotizacion->total,
            'saldo' => (float) $cotizacion->saldo,
            'fecha' => $cotizacion->fecha?->toDateString(),
            'vigencia' => $cotizacion->vigencia?->toDateString(),
            'show_url' => route('admin.cotizaciones.show', $cotizacion),
        ])->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $resultados
     * @param  array<int, string>  $warnings
     */
    private function avisarTruncado($resultados, int $limite, string $etiqueta, array &$warnings): void
    {
        if ($resultados->count() > $limite) {
            $warnings[] = "Hay más de {$limite} {$etiqueta} que coinciden; se devolvieron los primeros {$limite}. Acota la búsqueda o sube limit.";
        }
    }
}
