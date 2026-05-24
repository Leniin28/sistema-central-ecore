<x-layouts::app :title="__('Servicios')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $servicio->nombre }}</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Detalle del servicio registrado.</p>
            </div>

            <div class="flex gap-3">
                <a href="{{ route('admin.servicios.edit', $servicio) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Editar
                </a>

                <a href="{{ route('admin.servicios.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Volver al listado
                </a>
            </div>
        </section>

        <section class="max-w-3xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <dl class="grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Nombre</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $servicio->nombre }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Categoría</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $servicio->categoriaServicio?->nombre ?? 'Sin categoría' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Precio base</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($servicio->precio_base, 2) }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Estado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $servicio->activo ? 'Activo' : 'Inactivo' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Creado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $servicio->created_at?->format('d/m/Y H:i') }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Actualizado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $servicio->updated_at?->format('d/m/Y H:i') }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Descripción</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm text-neutral-900 dark:text-neutral-100">{{ $servicio->descripcion ?: 'Sin descripción' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</x-layouts::app>
