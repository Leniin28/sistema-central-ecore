<x-layouts::app :title="__('Panel Administrativo')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Panel Administrativo</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Resumen general para administrar clientes, equipos, servicios, órdenes y finanzas del sistema.</p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.clientes.index') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Gestionar clientes
                </a>

                <a href="{{ route('admin.equipos.index') }}" class="inline-flex w-fit items-center rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Gestionar equipos
                </a>

                <a href="{{ route('admin.categorias-servicio.index') }}" class="inline-flex w-fit items-center rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Gestionar categorías
                </a>

                <a href="{{ route('admin.servicios.index') }}" class="inline-flex w-fit items-center rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Gestionar servicios
                </a>

                <a href="{{ route('admin.ordenes-servicio.index') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Gestionar órdenes
                </a>

                <a href="{{ route('admin.ordenes-servicio.create') }}" class="inline-flex w-fit items-center rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Crear orden
                </a>

                <a href="{{ route('admin.movimientos-financieros.index') }}" class="inline-flex w-fit items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Ver finanzas
                </a>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Órdenes activas</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesActivas) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Órdenes entregadas</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesEntregadas) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Clientes</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalClientes) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Equipos</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEquipos) }}</p>
            </div>

            <div class="rounded-lg border border-green-200 bg-green-50 p-5 dark:border-green-800 dark:bg-green-950/30">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Ingresos</h2>
                <p class="mt-2 text-2xl font-semibold text-green-700 dark:text-green-300">${{ number_format($totalIngresos, 2) }}</p>
            </div>

            <div class="rounded-lg border border-red-200 bg-red-50 p-5 dark:border-red-800 dark:bg-red-950/30">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Egresos</h2>
                <p class="mt-2 text-2xl font-semibold text-red-700 dark:text-red-300">${{ number_format($totalEgresos, 2) }}</p>
            </div>

            <div class="rounded-lg border border-blue-200 bg-blue-50 p-5 dark:border-blue-800 dark:bg-blue-950/30">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Balance</h2>
                <p class="mt-2 text-2xl font-semibold text-blue-700 dark:text-blue-300">${{ number_format($balanceFinanciero, 2) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">En Fixop</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesEnFixop) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Listas para entregar</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalOrdenesListas) }}</p>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <h2 class="font-medium text-neutral-900 dark:text-neutral-100">Últimas órdenes de servicio</h2>
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
                                        <a href="{{ route('admin.ordenes-servicio.show', $orden) }}" class="font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">Ver</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                        No hay órdenes registradas.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <h2 class="font-medium text-neutral-900 dark:text-neutral-100">Últimos movimientos financieros</h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Categoría</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Monto</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Orden/Folio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                            @forelse ($ultimosMovimientos as $movimiento)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->fecha?->format('d/m/Y') ?? 'Sin fecha' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300"><x-financial-label :value="$movimiento->tipo" /></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300"><x-financial-label :value="$movimiento->categoria" /></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">${{ number_format((float) $movimiento->monto, 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->orden_servicio_id ? $movimiento->ordenServicio?->folio : 'Manual' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                        No hay movimientos financieros registrados.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</x-layouts::app>
