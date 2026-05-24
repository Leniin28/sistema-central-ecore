@props([
    'estado' => null,
])

@php
    $estadoNormalizado = is_string($estado) ? trim($estado) : null;

    $texto = match ($estadoNormalizado) {
        'recibido' => 'Recibido',
        'en_diagnostico' => 'En diagnóstico',
        'cotizacion_pendiente' => 'Cotización pendiente',
        'cotizacion_aprobada' => 'Cotización aprobada',
        'en_proceso' => 'En proceso',
        'en_fixop' => 'En Fixop',
        'listo_para_entregar' => 'Listo para entregar',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
        null, '' => 'Sin estado',
        default => str($estadoNormalizado)->replace('_', ' ')->headline()->toString(),
    };

    $clasesColor = match ($estadoNormalizado) {
        'recibido' => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-200',
        'en_diagnostico' => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-200',
        'cotizacion_pendiente' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200',
        'cotizacion_aprobada' => 'border-lime-200 bg-lime-50 text-lime-700 dark:border-lime-800 dark:bg-lime-950/60 dark:text-lime-200',
        'en_proceso' => 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-200',
        'en_fixop' => 'border-purple-200 bg-purple-50 text-purple-700 dark:border-purple-800 dark:bg-purple-950/60 dark:text-purple-200',
        'listo_para_entregar' => 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/60 dark:text-green-200',
        'entregado' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200',
        'cancelado' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/60 dark:text-red-200',
        default => 'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-900/60 dark:text-neutral-200',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium', $clasesColor]) }}>
    {{ $texto }}
</span>
