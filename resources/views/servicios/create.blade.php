<x-layouts::app :title="__('Servicios')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Crear servicio</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Registra un servicio disponible para futuras órdenes.</p>
        </section>

        <section class="max-w-2xl">
            <form method="POST" action="{{ route('admin.servicios.store') }}" class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                @include('servicios._form')
            </form>
        </section>
    </div>
</x-layouts::app>
