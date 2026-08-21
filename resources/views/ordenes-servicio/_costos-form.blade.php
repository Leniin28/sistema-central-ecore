<section class="max-w-7xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
    <div class="space-y-1">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Costos internos de líneas existentes</h2>
        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            Vacío significa costo pendiente. Escribe 0 únicamente cuando el costo real conocido sea cero.
            Los datos comerciales y la trazabilidad no se modifican desde aquí.
        </p>
    </div>

    @error('costos')
        <div class="mt-4 border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{{ $message }}</div>
    @enderror
    @error('costos_servicios')
        <div class="mt-4 border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{{ $message }}</div>
    @enderror
    @error('costos_refacciones')
        <div class="mt-4 border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('admin.ordenes-servicio.costos.update', $orden) }}" class="mt-5 space-y-6">
        @csrf
        @method('PATCH')

        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Tipo</th>
                        <th class="px-4 py-3 text-left font-semibold">Descripción</th>
                        <th class="px-4 py-3 text-right font-semibold">Cantidad</th>
                        <th class="px-4 py-3 text-right font-semibold">Precio comercial</th>
                        <th class="px-4 py-3 text-left font-semibold">Costo interno unitario</th>
                        <th class="px-4 py-3 text-left font-semibold">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($orden->detalles as $index => $linea)
                        <tr>
                            <td class="px-4 py-3">Servicio{{ $linea->cotizacion_item_id ? ' cotizado' : ' manual' }}</td>
                            <td class="px-4 py-3">{{ $linea->descripcion }}</td>
                            <td class="px-4 py-3 text-right">{{ $linea->cantidad }}</td>
                            <td class="px-4 py-3 text-right">${{ number_format($linea->precio_unitario, 2) }}</td>
                            <td class="px-4 py-3">
                                <input type="hidden" name="costos_servicios[{{ $index }}][id]" value="{{ $linea->id }}">
                                <input
                                    aria-label="Costo interno de {{ $linea->descripcion }}"
                                    name="costos_servicios[{{ $index }}][costo_unitario]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value="{{ old("costos_servicios.$index.costo_unitario", $linea->costo_unitario) }}"
                                    class="w-36 rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                >
                            </td>
                            <td class="px-4 py-3">
                                @if ($linea->costo_unitario === null)
                                    <span class="font-medium text-amber-700 dark:text-amber-300">Costo pendiente</span>
                                @else
                                    <span class="font-medium text-emerald-700 dark:text-emerald-300">${{ number_format($linea->costo_unitario, 2) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($orden->refacciones as $index => $linea)
                        <tr>
                            <td class="px-4 py-3">Refacción{{ $linea->cotizacion_item_id ? ' cotizada' : ' manual' }}</td>
                            <td class="px-4 py-3">{{ $linea->descripcion }}</td>
                            <td class="px-4 py-3 text-right">{{ $linea->cantidad }}</td>
                            <td class="px-4 py-3 text-right">${{ number_format($linea->precio_unitario_cliente, 2) }}</td>
                            <td class="px-4 py-3">
                                <input type="hidden" name="costos_refacciones[{{ $index }}][id]" value="{{ $linea->id }}">
                                <input
                                    aria-label="Costo interno de {{ $linea->descripcion }}"
                                    name="costos_refacciones[{{ $index }}][costo_unitario]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value="{{ old("costos_refacciones.$index.costo_unitario", $linea->costo_unitario) }}"
                                    class="w-36 rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                >
                            </td>
                            <td class="px-4 py-3">
                                @if ($linea->costo_unitario === null)
                                    <span class="font-medium text-amber-700 dark:text-amber-300">Costo pendiente</span>
                                @else
                                    <span class="font-medium text-emerald-700 dark:text-emerald-300">${{ number_format($linea->costo_unitario, 2) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($orden->detalles->isEmpty() && $orden->refacciones->isEmpty())
                        <tr><td colspan="6" class="px-4 py-8 text-center text-neutral-600 dark:text-neutral-400">Esta orden no tiene líneas registradas.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>

        <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
            Guardar costos internos
        </button>
    </form>
</section>
