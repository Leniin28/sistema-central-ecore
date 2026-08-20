<x-layouts::app :title="__('Editar cotización')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Editar cotización {{ $cotizacion->folio }}</h1>
            @if ($cotizacion->estado === 'aceptada')
                <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                    Esta cotización está aceptada y vinculada a
                    <a href="{{ route('admin.ordenes-servicio.show', $cotizacion->ordenServicio) }}" class="font-semibold underline">{{ $cotizacion->ordenServicio->folio }}</a>.
                    Los cambios en sus conceptos actualizarán esa orden.
                </div>
            @else
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Solo pueden editarse cotizaciones en borrador o enviadas.</p>
            @endif
        </section>

        <form method="POST" action="{{ route($routePrefix.'.cotizaciones.update', $cotizacion) }}" class="max-w-4xl">
            @method('PUT')
            @include('cotizaciones._form')
        </form>
    </div>
</x-layouts::app>
