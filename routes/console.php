<?php

use App\Actions\Cotizaciones\ReconciliarCotizacionesHistoricas;
use App\Models\CotizacionItem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'cotizaciones:reconciliar-ordenes
        {--dry-run : Fuerza explícitamente el modo de solo diagnóstico}
        {--apply : Aplica exactamente un caso autorizado}
        {--case= : Clave exacta del caso histórico}
        {--fingerprint= : Huella SHA-256 obtenida del dry-run}
        {--actor= : ID del administrador responsable}',
    function (ReconciliarCotizacionesHistoricas $reconciliar): int {
        $aplicar = (bool) $this->option('apply');
        $caseKey = filled($this->option('case')) ? (string) $this->option('case') : null;

        if ($aplicar && $this->option('dry-run')) {
            $this->error('--apply y --dry-run son excluyentes.');

            return self::INVALID;
        }

        if ($aplicar) {
            $fingerprint = (string) $this->option('fingerprint');
            $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
            if ($caseKey === null || $fingerprint === '' || $actorId === false) {
                $this->error('--apply requiere --case, --fingerprint y --actor.');

                return self::INVALID;
            }

            try {
                $resultado = $reconciliar->aplicar($caseKey, $fingerprint, (int) $actorId);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info($resultado['aplicada']
                ? "Caso {$caseKey} aplicado y auditado con registro #{$resultado['id']}."
                : "Caso {$caseKey} ya había sido aplicado; no se realizaron cambios.");

            return self::SUCCESS;
        }

        try {
            $planes = $reconciliar->diagnosticar($caseKey);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->info('Cutoff histórico verificado por Git: '
            .config('historical_quote_reconciliation.cutoff.commit').' @ '
            .config('historical_quote_reconciliation.cutoff.committed_at'));
        $this->table(
            ['Caso', 'Estado', 'Cotización canónica', 'Orden canónica', 'Servicios provisionales', 'Refacciones manuales', 'Total cotización', 'Total orden'],
            $planes->map(fn (array $plan): array => [
                $plan['key'],
                $plan['status'],
                $plan['canonical_quote'],
                $plan['canonical_order'],
                $plan['provisional_services_to_delete'],
                $plan['manual_refactions_preserved'],
                $plan['projected_quote_total'] === null ? '-' : number_format((float) $plan['projected_quote_total'], 2, '.', ''),
                $plan['projected_order_total'] === null ? '-' : number_format((float) $plan['projected_order_total'], 2, '.', ''),
            ])->all(),
        );

        foreach ($planes as $plan) {
            $this->newLine();
            $this->line("Caso {$plan['key']}: {$plan['status']}");
            $this->line('  Cotizaciones origen: '.(implode(', ', $plan['source_quotes']) ?: 'ninguna'));
            $this->line('  Órdenes origen: '.(implode(', ', $plan['source_orders']) ?: 'ninguna'));
            foreach ($plan['reasons'] as $reason) {
                $this->line('  - '.$reason);
            }
            if ($plan['fingerprint'] !== null) {
                $this->line('  Huella: '.$plan['fingerprint']);
            }
        }

        $protegidas = $reconciliar->protegidasPorFinanzas();
        if ($protegidas->isNotEmpty()) {
            $this->newLine();
            $this->warn('Cotizaciones excluidas absolutamente por finanzas:');
            $this->table(['Cotización', 'Orden', 'Finanzas generadas', 'Movimientos'], $protegidas->all());
        }

        $desconocidos = CotizacionItem::query()
            ->whereNull('costo_unitario')
            ->orWhereNull('costo_total')
            ->count();
        $this->newLine();
        $this->comment("Líneas con costo interno desconocido (NULL): {$desconocidos}. No se infieren ni completan.");
        $this->comment('Dry-run por defecto: no se modificaron cotizaciones, órdenes, líneas, estados, auditorías ni movimientos financieros.');

        return self::SUCCESS;
    },
)->purpose('Diagnostica o aplica, por caso y con huella, la reconciliación histórica cotización-orden');
