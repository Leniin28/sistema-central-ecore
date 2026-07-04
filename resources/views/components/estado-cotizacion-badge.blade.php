@props([
    'estado' => null,
])

@php
    $estadoNormalizado = is_string($estado) ? trim($estado) : null;

    $texto = match ($estadoNormalizado) {
        'borrador' => 'Borrador',
        'enviada' => 'Enviada',
        'aceptada' => 'Aceptada',
        'rechazada' => 'Rechazada',
        'vencida' => 'Vencida',
        null, '' => 'Sin estado',
        default => str($estadoNormalizado)->replace('_', ' ')->headline()->toString(),
    };

    $clasesColor = match ($estadoNormalizado) {
        'borrador' => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-200',
        'enviada' => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-200',
        'aceptada' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200',
        'rechazada' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/60 dark:text-red-200',
        'vencida' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200',
        default => 'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-900/60 dark:text-neutral-200',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium', $clasesColor]) }}>
    {{ $texto }}
</span>
