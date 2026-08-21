@if (auth()->user()->isAdmin() && $orden->estado === 'entregado' && $orden->finanzas_generadas)
    <section class="max-w-4xl space-y-4 rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Ajustes financieros</h2>
        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            Correcciones sobre una orden ya entregada. Ninguna acción aquí borra o edita un movimiento original: cada una crea un movimiento compensatorio con motivo y actor registrados.
        </p>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Venta bruta</dt>
                <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->ventaBruta(), 2) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Reembolsado</dt>
                <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->totalReembolsado(), 2) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Venta neta</dt>
                <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->ventaNeta(), 2) }}</dd>
            </div>
        </dl>

        @php
            $estadoReembolso = $orden->estadoReembolso();
            $estadoReembolsoLabel = match ($estadoReembolso) {
                'reembolso_total' => 'Reembolso total',
                'reembolso_parcial' => 'Reembolso parcial',
                default => 'Sin reembolso',
            };
        @endphp
        <p class="text-xs text-neutral-500 dark:text-neutral-400">Estado financiero: <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ $estadoReembolsoLabel }}</span></p>

        @if ($estadoReembolso !== 'reembolso_total')
            <details class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <summary class="cursor-pointer text-sm font-medium text-neutral-700 hover:underline dark:text-neutral-300">Registrar reembolso</summary>
                <form method="POST" action="{{ route('admin.ordenes-servicio.reembolso.store', $orden) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label for="reembolso_monto" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Monto a reembolsar</label>
                        <input id="reembolso_monto" name="monto" type="number" step="0.01" min="0.01" required class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        @error('monto')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="reembolso_fecha" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha real del reembolso</label>
                        <input id="reembolso_fecha" name="fecha" type="date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        @error('fecha')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="reembolso_motivo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Motivo (obligatorio)</label>
                        <textarea id="reembolso_motivo" name="motivo" rows="2" required class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"></textarea>
                        @error('motivo')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                            Confirmar reembolso
                        </button>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Se registrará como egreso en su propia fecha. La venta original no se modifica.</p>
                    </div>
                </form>
            </details>
        @else
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Esta orden ya fue reembolsada en su totalidad.</p>
        @endif

        @if ($orden->detalles->isNotEmpty() || $orden->refacciones->isNotEmpty())
            <details class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <summary class="cursor-pointer text-sm font-medium text-neutral-700 hover:underline dark:text-neutral-300">Corregir costo interno</summary>
                <form method="POST" action="{{ route('admin.ordenes-servicio.costo-interno.store', $orden) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label for="costo_linea" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Línea a corregir</label>
                        <select id="costo_linea" name="linea" required class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" onchange="const [tipo, id] = this.value.split(':'); this.form.linea_tipo.value = tipo; this.form.linea_id.value = id;">
                            <option value="" disabled selected>Selecciona una línea</option>
                            @foreach ($orden->detalles as $detalle)
                                <option value="servicio:{{ $detalle->id }}">Servicio: {{ $detalle->descripcion }} (costo actual: {{ $detalle->costo_unitario === null ? 'sin capturar' : '$'.number_format($detalle->costo_unitario, 2) }})</option>
                            @endforeach
                            @foreach ($orden->refacciones as $refaccion)
                                <option value="refaccion:{{ $refaccion->id }}">Refacción: {{ $refaccion->descripcion }} (costo actual: {{ $refaccion->costo_unitario === null ? 'sin capturar' : '$'.number_format($refaccion->costo_unitario, 2) }})</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="linea_tipo">
                        <input type="hidden" name="linea_id">
                        @error('linea_tipo')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @error('linea_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="costo_nuevo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Costo unitario correcto</label>
                        <input id="costo_nuevo" name="costo_nuevo" type="number" step="0.01" min="0" required class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                        @error('costo_nuevo')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="costo_motivo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Motivo (obligatorio)</label>
                        <textarea id="costo_motivo" name="motivo" rows="2" required class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"></textarea>
                        @error('motivo')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                            Confirmar corrección
                        </button>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">El costo original no se borra: se registra un movimiento compensatorio por la diferencia.</p>
                    </div>
                </form>
            </details>
        @endif

        <details class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
            <summary class="cursor-pointer text-sm font-medium text-neutral-700 hover:underline dark:text-neutral-300">Corregir comisión</summary>
            <form method="POST" action="{{ route('admin.ordenes-servicio.comision.store', $orden) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                @csrf
                <div>
                    <label for="comision_nueva" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                        {{ $orden->usaCostosPorLinea() ? 'Comisión de recepción real' : 'Comisión logística real' }}
                    </label>
                    <input id="comision_nueva" name="comision_nueva" type="number" step="0.01" min="0" required
                        value="{{ old('comision_nueva', $orden->usaCostosPorLinea() ? $orden->comision_recepcion : $orden->comision_logistica) }}"
                        class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                    @error('comision_nueva')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="comision_motivo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Motivo (obligatorio)</label>
                    <textarea id="comision_motivo" name="motivo" rows="2" required class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"></textarea>
                    @error('motivo')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                        Confirmar corrección
                    </button>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">La comisión original no se borra: se registra un movimiento compensatorio por la diferencia.</p>
                </div>
            </form>
        </details>

        @if ($orden->ajustesFinancieros->isNotEmpty())
            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold text-neutral-900 dark:text-neutral-100">Actor</th>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold text-neutral-900 dark:text-neutral-100">Motivo</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold text-neutral-900 dark:text-neutral-100">Impacto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                            @foreach ($orden->ajustesFinancieros as $ajuste)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-neutral-700 dark:text-neutral-300">{{ $ajuste->fecha->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-neutral-700 dark:text-neutral-300">{{ str($ajuste->tipo)->replace('_', ' ')->ucfirst() }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-neutral-700 dark:text-neutral-300">{{ $ajuste->actor?->name ?? 'Sin usuario' }}</td>
                                    <td class="px-3 py-2 text-xs text-neutral-700 dark:text-neutral-300">{{ $ajuste->motivo }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-xs font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ $ajuste->delta === null ? '—' : '$'.number_format($ajuste->delta, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>
@endif
