<section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Servicios de la orden</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Detalle de servicios incluidos y subtotales calculados.</p>
        </div>

        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
            Total: ${{ number_format($orden->total_cliente, 2) }}
        </p>
    </div>

    <div class="mt-5 overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-900">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Servicio</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Categoría</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Cantidad</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Precio unitario</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Subtotal</th>
                        <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Notas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                    @forelse ($orden->detalles as $detalle)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $detalle->descripcion ?? $detalle->servicio?->nombre ?? 'Sin servicio' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $detalle->servicio?->categoriaServicio?->nombre ?? 'Sin categoría' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $detalle->cantidad }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($detalle->precio_unitario, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($detalle->subtotal, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $detalle->notas ?: 'Sin notas' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                No hay servicios registrados en esta orden.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
