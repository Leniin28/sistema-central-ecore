<?php

use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\OrdenServicio;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cotizaciones:reconciliar-ordenes {--dry-run : Reporta el plan sin modificar datos}', function (): int {
    if (! $this->option('dry-run')) {
        $this->error('Por seguridad este comando requiere --dry-run; todavía no existe un modo de escritura.');

        return self::INVALID;
    }

    $tieneTrazabilidad = Schema::hasColumn('ordenes_servicio', 'cotizacion_id');
    $tieneCostos = Schema::hasColumns('cotizacion_items', ['costo_unitario', 'costo_total']);
    $clasificadas = Cotizacion::query()->orderBy('id')->get(['id', 'folio', 'estado'])
        ->map(function (Cotizacion $cotizacion) use ($tieneTrazabilidad): array {
            $candidatos = collect();
            if ($tieneTrazabilidad) {
                $candidatos = $candidatos->merge(
                    OrdenServicio::query()->where('cotizacion_id', $cotizacion->id)->pluck('id'),
                );
            }

            // Legacy notes are diagnostic evidence only. They never authorize
            // an automatic write, and multiple candidates are always Case D.
            $candidatos = $candidatos->merge(
                OrdenServicio::query()
                    ->where('origen', 'openclaw-cotizacion')
                    ->where('notas', 'like', '%'.$cotizacion->folio.'%')
                    ->pluck('id'),
            )->unique()->values();

            $clasificacion = $cotizacion->estado !== 'aceptada'
                ? 'C'
                : match ($candidatos->count()) {
                    0 => 'B',
                    1 => 'A',
                    default => 'D',
                };

            return [
                'clasificacion' => $clasificacion,
                'folio' => $cotizacion->folio,
                'candidatos' => $candidatos,
            ];
        });

    $conteos = $clasificadas->countBy('clasificacion');
    $desconocidos = $tieneCostos
        ? CotizacionItem::query()->whereNull('costo_unitario')->orWhereNull('costo_total')->count()
        : CotizacionItem::query()->count();

    $this->table(['Clasificación', 'Total', 'Cambio propuesto'], [
        ['Cotizaciones encontradas', $clasificadas->count(), 'Solo diagnóstico'],
        ['A: aceptada con orden inequívoca', $conteos->get('A', 0), 'Vincular y copiar solo líneas faltantes'],
        ['B: aceptada sin orden', $conteos->get('B', 0), 'Crear orden con la conversión segura'],
        ['C: no aceptada', $conteos->get('C', 0), 'No modificar'],
        ['D: relación ambigua', $conteos->get('D', 0), 'No modificar; resolver manualmente'],
        [$tieneCostos ? 'Líneas con costo interno desconocido (NULL)' : 'Líneas sin columnas de costo (desconocidas)', $desconocidos, 'No inventar ni completar costos'],
    ]);
    $this->comment('Caso D detectado: '.$conteos->get('D', 0));

    $ambiguas = $clasificadas->filter(
        fn (array $fila): bool => $fila['clasificacion'] === 'D',
    )->values();
    if ($ambiguas->isNotEmpty()) {
        $this->newLine();
        $this->warn('Pendientes de reconciliación manual (Caso D):');
        $this->table(['Cotización', 'Órdenes candidatas', 'Motivo'], $ambiguas->map(fn (array $fila): array => [
            $fila['folio'],
            implode(', ', $fila['candidatos']->map(fn (int $id): string => '#'.$id)->all()),
            'Múltiples órdenes con evidencia estructurada o histórica.',
        ])->all());
        foreach ($ambiguas as $fila) {
            $this->comment($fila['folio'].': candidatos '
                .implode(', ', $fila['candidatos']->map(fn (int $id): string => '#'.$id)->all())
                .'; motivo: múltiples órdenes con evidencia estructurada o histórica.');
        }
    }

    $this->comment('Dry-run: no se modificaron cotizaciones, órdenes, líneas ni movimientos financieros.');

    return self::SUCCESS;
})->purpose('Clasifica cotizaciones históricas y propone una reconciliación sin escribir datos');
