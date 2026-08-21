<x-layouts::app :title="__('Órdenes de servicio')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Editar orden {{ $orden->folio }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Actualiza los datos base de la orden. El estado no cambia desde esta pantalla.</p>
        </section>

        <section class="max-w-7xl">
            <form method="POST" action="{{ route('admin.ordenes-servicio.update', $orden) }}" class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                @method('PUT')
                @include('ordenes-servicio._form')
            </form>
        </section>

        @if (! $orden->finanzas_generadas
            && ! in_array($orden->estado, ['entregado', 'cancelado'], true)
            && $orden->orden_canonica_id === null)
            @include('ordenes-servicio._costos-form')
        @endif
    </div>
</x-layouts::app>
