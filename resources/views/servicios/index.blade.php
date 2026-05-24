<x-layouts::app :title="__('Servicios')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Servicios</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Listado de servicios disponibles para el sistema.</p>
            </div>

            <a href="{{ route('admin.servicios.create') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                Crear servicio
            </a>
        </section>

        @if (session('status'))
            <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ session('error') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                    <thead class="bg-neutral-50 dark:bg-neutral-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Servicio</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Categoría</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Precio base</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Estado</th>
                            <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                        @forelse ($servicios as $servicio)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $servicio->nombre }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $servicio->categoriaServicio?->nombre ?? 'Sin categoría' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($servicio->precio_base, 2) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $servicio->activo ? 'Activo' : 'Inactivo' }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <div class="flex justify-end gap-3">
                                        <a href="{{ route('admin.servicios.show', $servicio) }}" class="font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">Ver</a>
                                        <a href="{{ route('admin.servicios.edit', $servicio) }}" class="font-medium text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100">Editar</a>

                                        <form method="POST" action="{{ route('admin.servicios.destroy', $servicio) }}" onsubmit="return confirm('Eliminar este servicio?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-medium text-red-700 hover:text-red-900 dark:text-red-300 dark:hover:text-red-100">
                                                Eliminar
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                    No hay servicios registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{ $servicios->links() }}
    </div>
</x-layouts::app>
