<x-layouts::app :title="__('Clientes')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Clientes</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Listado de clientes registrados en el sistema.</p>
            </div>

            <a href="{{ route($routePrefix.'.clientes.create') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                Crear cliente
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
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Nombre</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Teléfono</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Correo</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                            <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                        @forelse ($clientes as $cliente)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $cliente->nombre }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $cliente->telefono }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $cliente->correo ?? 'Sin correo' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ ucfirst($cliente->tipo_cliente) }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <div class="flex justify-end gap-3">
                                        <a href="{{ route($routePrefix.'.clientes.show', $cliente) }}" class="font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">Ver</a>

                                        @if (auth()->user()->isAdmin())
                                            <a href="{{ route('admin.clientes.edit', $cliente) }}" class="font-medium text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100">Editar</a>

                                            <form method="POST" action="{{ route('admin.clientes.destroy', $cliente) }}" onsubmit="return confirm('¿Eliminar este cliente?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="font-medium text-red-700 hover:text-red-900 dark:text-red-300 dark:hover:text-red-100">
                                                    Eliminar
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                    No hay clientes registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{ $clientes->links() }}
    </div>
</x-layouts::app>
