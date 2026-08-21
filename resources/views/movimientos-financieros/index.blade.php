<x-layouts::app :title="__('Movimientos financieros')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Movimientos financieros</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Consulta de ingresos, egresos y balance del sistema.</p>
            </div>

            <a href="{{ route('admin.movimientos-financieros.create') }}" class="inline-flex items-center justify-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                Nuevo movimiento
            </a>
        </section>

        @if (session('status'))
            <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                {{ session('status') }}
            </div>
        @endif

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Total ingresos</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($totalIngresos, 2) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Total egresos</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($totalEgresos, 2) }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Balance</h2>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">${{ number_format($balance, 2) }}</p>
            </div>
        </section>

        <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <form method="GET" action="{{ route('admin.movimientos-financieros.index') }}" class="grid gap-4 lg:grid-cols-5">
                <div>
                    <label for="tipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo</label>
                    <select id="tipo" name="tipo" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        <option value="">Todos</option>
                        <option value="ingreso" @selected(($filters['tipo'] ?? '') === 'ingreso')>Ingreso</option>
                        <option value="egreso" @selected(($filters['tipo'] ?? '') === 'egreso')>Egreso</option>
                    </select>
                </div>

                <div>
                    <label for="categoria" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Categoría</label>
                    <select id="categoria" name="categoria" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        <option value="">Todas</option>
                        @foreach ($categorias as $categoria)
                            <option value="{{ $categoria }}" @selected(($filters['categoria'] ?? '') === $categoria)>
                                <x-financial-label :value="$categoria" />
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="fecha_desde" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha desde</label>
                    <input id="fecha_desde" name="fecha_desde" type="date" value="{{ $filters['fecha_desde'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    @error('fecha_desde')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="fecha_hasta" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha hasta</label>
                    <input id="fecha_hasta" name="fecha_hasta" type="date" value="{{ $filters['fecha_hasta'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    @error('fecha_hasta')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-end gap-3">
                    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                        Filtrar
                    </button>

                    <a href="{{ route('admin.movimientos-financieros.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                        Limpiar filtros
                    </a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                    <thead class="bg-neutral-50 dark:bg-neutral-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Categoría</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Monto</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Orden/Folio</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Cliente</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Partner</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Descripción</th>
                            <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Reversión</th>
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
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->cliente?->nombre ?? 'Sin cliente' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->partner?->nombre ?? 'Sin partner' }}</td>
                                <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $movimiento->descripcion ?? 'Sin descripción' }}</td>
                                <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">
                                    @if ($movimiento->esReversion())
                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                            Reversión de #{{ $movimiento->movimiento_original_id }}
                                        </span>
                                    @elseif ($movimiento->movimientoReversion)
                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                            Revertido (#{{ $movimiento->movimientoReversion->id }})
                                        </span>
                                    @elseif (! $movimiento->esEstructurado())
                                        <details class="text-xs">
                                            <summary class="cursor-pointer font-medium text-neutral-700 hover:underline dark:text-neutral-300">Revertir</summary>
                                            <form method="POST" action="{{ route('admin.movimientos-financieros.revertir', $movimiento) }}" class="mt-2 flex min-w-[220px] flex-col gap-2">
                                                @csrf
                                                <p class="text-neutral-600 dark:text-neutral-400">
                                                    La reversión no elimina el movimiento original. Se registrará un movimiento compensatorio.
                                                </p>
                                                <label for="motivo_reversion_{{ $movimiento->id }}" class="font-medium text-neutral-700 dark:text-neutral-300">Motivo (obligatorio)</label>
                                                <textarea id="motivo_reversion_{{ $movimiento->id }}" name="motivo_reversion" required rows="2" class="rounded-md border-neutral-300 text-xs shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"></textarea>
                                                <button type="submit" class="self-start rounded-md border border-neutral-300 px-3 py-1 font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                                                    Confirmar reversión
                                                </button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                                    No hay movimientos financieros registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{ $movimientos->links() }}
    </div>
</x-layouts::app>
