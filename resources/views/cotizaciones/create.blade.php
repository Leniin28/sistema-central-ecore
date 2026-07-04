<x-layouts::app :title="__('Nueva cotización')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Nueva cotización</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Registra un presupuesto para un cliente. El folio y los totales se generan en el servidor.</p>
        </section>

        <form method="POST" action="{{ route($routePrefix.'.cotizaciones.store') }}" class="max-w-4xl">
            @include('cotizaciones._form')
        </form>
    </div>
</x-layouts::app>
