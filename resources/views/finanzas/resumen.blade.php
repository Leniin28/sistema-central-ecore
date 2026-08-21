<x-layouts::app :title="__('Resumen financiero')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Resumen financiero</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                Vista de solo lectura para el periodo {{ $desde->format('d/m/Y') }}–{{ $hasta->format('d/m/Y') }}.
            </p>
        </section>

        <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <form method="GET" action="{{ route('admin.finanzas.resumen') }}" class="grid gap-4 lg:grid-cols-5" data-period-form>
                <div>
                    <label for="periodo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Periodo</label>
                    <select id="periodo" name="periodo" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" data-period-select>
                        <option value="hoy" @selected($periodo === 'hoy')>Hoy</option>
                        <option value="semana" @selected($periodo === 'semana')>Esta semana</option>
                        <option value="mes" @selected($periodo === 'mes')>Este mes</option>
                        <option value="personalizado" @selected($periodo === 'personalizado')>Personalizado</option>
                    </select>
                </div>

                <div data-custom-range @class(['opacity-50' => $periodo !== 'personalizado'])>
                    <label for="fecha_desde" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha desde</label>
                    <input id="fecha_desde" name="fecha_desde" type="date" value="{{ request('fecha_desde', $periodo === 'personalizado' ? $desde->format('Y-m-d') : '') }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    @error('fecha_desde')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div data-custom-range @class(['opacity-50' => $periodo !== 'personalizado'])>
                    <label for="fecha_hasta" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha hasta</label>
                    <input id="fecha_hasta" name="fecha_hasta" type="date" value="{{ request('fecha_hasta', $periodo === 'personalizado' ? $hasta->format('Y-m-d') : '') }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    @error('fecha_hasta')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-end">
                    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                        Aplicar
                    </button>
                </div>
            </form>
        </section>

        {{-- Perspectiva A: flujo financiero real, fechado por MovimientoFinanciero.fecha. --}}
        <section class="space-y-4">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Flujo financiero</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Dinero que realmente entró o salió en el periodo, por fecha de movimiento.</p>

            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Ingresos cobrados</h3>
                    <p class="mt-2 text-2xl font-semibold text-emerald-700 dark:text-emerald-300">${{ number_format($ingresosTotal, 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Egresos registrados</h3>
                    <p class="mt-2 text-2xl font-semibold text-red-700 dark:text-red-300">${{ number_format($egresosTotal, 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Balance</h3>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($balance, 2) }}</p>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Ingresos − egresos. No es utilidad.</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Reembolsos del periodo</h3>
                    <p class="mt-2 text-2xl font-semibold text-red-700 dark:text-red-300">${{ number_format($reembolsosTotal, 2) }}</p>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Ya incluidos en egresos, por su propia fecha.</p>
                </div>
            </div>

            @if ($desglosePorCategoria->isNotEmpty())
                <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                                    <th scope="col" class="px-4 py-2 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Categoría</th>
                                    <th scope="col" class="px-4 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                                @foreach ($desglosePorCategoria as $fila)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300"><x-financial-label :value="$fila->tipo" /></td>
                                        <td class="whitespace-nowrap px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300"><x-financial-label :value="$fila->categoria" /></td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">${{ number_format($fila->total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Categoría</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Monto</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Orden/Cotización</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Partner</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Descripción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                            @forelse ($movimientos as $movimiento)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->fecha?->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300"><x-financial-label :value="$movimiento->tipo" /></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300"><x-financial-label :value="$movimiento->categoria" /></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">${{ number_format($movimiento->monto, 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->ordenServicio?->folio ?? $movimiento->cotizacion?->folio ?? 'Manual' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->partner?->nombre ?? 'Sin partner' }}</td>
                                    <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->descripcion ?? 'Sin descripción' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                        No hay movimientos financieros en este periodo.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            {{ $movimientos->links() }}
        </section>

        {{-- Perspectiva B: operación, fechada por OrdenServicio.fecha_entrega. --}}
        <section class="space-y-4 border-t border-neutral-200 pt-6 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Operación — órdenes entregadas</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Órdenes entregadas en el periodo (excluye canceladas, consolidadas y no entregadas), por fecha de entrega.</p>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Órdenes entregadas</h3>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $ordenesEntregadasCount }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Ventas entregadas</h3>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($ventasEntregadas, 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Ticket promedio</h3>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($ticketPromedio, 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Utilidad conocida</h3>
                    <p class="mt-2 text-2xl font-semibold text-emerald-700 dark:text-emerald-300">${{ number_format($utilidadConocida, 2) }}</p>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Solo órdenes sin costos pendientes.</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h3 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Con costos pendientes/incompletos</h3>
                    <p class="mt-2 text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ $ordenesConCostosIncompletos }}</p>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">No incluidas en la utilidad conocida.</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Folio</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha de entrega</th>
                                <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Venta</th>
                                <th scope="col" class="px-4 py-3 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Utilidad neta</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Costos</th>
                                <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Modelo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                            @forelse ($ordenesEntregadas as $orden)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">
                                        <a href="{{ route('admin.ordenes-servicio.show', $orden) }}" class="font-medium text-blue-700 hover:underline dark:text-blue-300">{{ $orden->folio }}</a>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $orden->fecha_entrega?->format('d/m/Y H:i') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->total_cliente, 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->utilidad_neta, 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm">
                                        @if ($orden->costos_incompletos)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">Incompletos</span>
                                        @else
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Completos</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400">{{ $orden->usaCostosPorLinea() ? 'Costos por línea' : 'Legacy' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                        No hay órdenes entregadas en este periodo.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            {{ $ordenesEntregadas->links() }}
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.querySelector('[data-period-select]');
            const ranges = document.querySelectorAll('[data-custom-range]');
            select?.addEventListener('change', () => {
                const isCustom = select.value === 'personalizado';
                ranges.forEach(range => range.classList.toggle('opacity-50', !isCustom));
            });
        });
    </script>
</x-layouts::app>
