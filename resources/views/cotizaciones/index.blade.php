<x-layouts::app :title="__('Cotizaciones')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Cotizaciones</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Presupuestos de servicios y refacciones para clientes.</p>
            </div>

            <a href="{{ route($routePrefix.'.cotizaciones.create') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                Nueva cotización
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
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Folio</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Cliente</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Equipo</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Estado</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                            <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Total</th>
                            <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Saldo</th>
                            <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                        @forelse ($cotizaciones as $cotizacion)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $cotizacion->folio }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $cotizacion->cliente?->nombre ?? 'Sin cliente' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">
                                    {{ $cotizacion->equipo ? $cotizacion->equipo->tipo_equipo.' '.$cotizacion->equipo->marca : 'Sin equipo' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    <x-estado-cotizacion-badge :estado="$cotizacion->estado" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $cotizacion->fecha?->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($cotizacion->total, 2) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($cotizacion->saldo, 2) }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <div class="flex justify-end gap-3">
                                        <a href="{{ route($routePrefix.'.cotizaciones.show', $cotizacion) }}" class="font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">Ver</a>

                                        @if ($cotizacion->esEditable())
                                            <a href="{{ route($routePrefix.'.cotizaciones.edit', $cotizacion) }}" class="font-medium text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100">Editar</a>
                                        @endif

                                        @if (auth()->user()->isAdmin())
                                            <form method="POST" action="{{ route('admin.cotizaciones.destroy', $cotizacion) }}" onsubmit="return confirm('¿Eliminar esta cotización?');">
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
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                    No hay cotizaciones registradas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{ $cotizaciones->links() }}
    </div>
</x-layouts::app>
