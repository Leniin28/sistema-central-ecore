<x-layouts::app :title="__('Panel Técnico')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Panel Técnico</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Vista de trabajo para revisar órdenes asignadas, diagnóstico, reparación y entrega técnica.</p>
            </div>

            <a href="{{ route('tecnico.ordenes-servicio.index') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                Gestionar órdenes
            </a>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Órdenes asignadas</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesAsignadas) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">En diagnóstico</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesDiagnostico) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">En proceso</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesProceso) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Listas para devolver</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesListas) }}</p>
            </div>
        </section>

        <section class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                <h2 class="font-medium text-neutral-900 dark:text-neutral-100">Últimas órdenes técnicas</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                    <thead class="bg-neutral-50 dark:bg-neutral-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Folio</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Cliente</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Estado</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Total</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                            <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                        @forelse ($ultimasOrdenes as $orden)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $orden->folio }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $orden->cliente?->nombre ?? 'Sin cliente' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">
                                    <x-estado-orden-badge :estado="$orden->estado" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format((float) $orden->total_cliente, 2) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $orden->fecha_recepcion?->format('d/m/Y H:i') ?? 'Sin fecha' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                    <a href="{{ route('tecnico.ordenes-servicio.show', $orden) }}" class="font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">Ver</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                    No hay órdenes técnicas asignadas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>
