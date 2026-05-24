<x-layouts::app :title="__('Clientes')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $cliente->nombre }}</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Detalle del cliente registrado.</p>
            </div>

            <div class="flex gap-3">
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.clientes.edit', $cliente) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                        Editar
                    </a>
                @endif

                <a href="{{ route($routePrefix.'.clientes.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Volver al listado
                </a>
            </div>
        </section>

        <section class="max-w-3xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <dl class="grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Nombre</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $cliente->nombre }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Teléfono</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $cliente->telefono }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Correo</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $cliente->correo ?? 'Sin correo' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Tipo de cliente</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ ucfirst($cliente->tipo_cliente) }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Creado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $cliente->created_at?->format('d/m/Y H:i') }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Actualizado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $cliente->updated_at?->format('d/m/Y H:i') }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Notas</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm text-neutral-900 dark:text-neutral-100">{{ $cliente->notas ?: 'Sin notas' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</x-layouts::app>
