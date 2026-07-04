<x-layouts::app :title="__('Editar cotización')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Editar cotización {{ $cotizacion->folio }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Solo pueden editarse cotizaciones en borrador o enviadas.</p>
        </section>

        <form method="POST" action="{{ route($routePrefix.'.cotizaciones.update', $cotizacion) }}" class="max-w-4xl">
            @method('PUT')
            @include('cotizaciones._form')
        </form>
    </div>
</x-layouts::app>
