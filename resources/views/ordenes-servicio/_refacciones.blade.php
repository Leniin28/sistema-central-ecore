@php
    $totalCostoRefacciones = $orden->refacciones->sum('costo_total');
    $totalVendidoRefacciones = $orden->refacciones->sum('precio_total_cliente');
    $utilidadTotalRefacciones = $orden->refacciones->sum('utilidad_refaccion');
@endphp

<section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Refacciones</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Materiales asociados a la orden y utilidad calculada.</p>
        </div>

        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
            Vendido: ${{ number_format($totalVendidoRefacciones, 2) }}
        </p>
    </div>

    <div class="mt-5 overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-900">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Refaccion</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Cantidad</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Costo unitario</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Precio unitario cliente</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Costo total</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Precio al cliente</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Utilidad</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Notas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                    @forelse ($orden->refacciones as $refaccion)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $refaccion->descripcion }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $refaccion->cantidad }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($refaccion->costo_unitario, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($refaccion->precio_unitario_cliente, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($refaccion->costo_total, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($refaccion->precio_total_cliente, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($refaccion->utilidad_refaccion, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $refaccion->notas ?: 'Sin notas' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                No hay refacciones registradas en esta orden.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <dl class="mt-5 grid gap-4 sm:grid-cols-3">
        <div class="rounded-md border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Total costo refacciones</dt>
            <dd class="mt-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($totalCostoRefacciones, 2) }}</dd>
        </div>

        <div class="rounded-md border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Total vendido en refacciones</dt>
            <dd class="mt-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($totalVendidoRefacciones, 2) }}</dd>
        </div>

        <div class="rounded-md border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Utilidad total en refacciones</dt>
            <dd class="mt-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($utilidadTotalRefacciones, 2) }}</dd>
        </div>
    </dl>
</section>
