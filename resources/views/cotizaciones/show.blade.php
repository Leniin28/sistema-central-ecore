<x-layouts::app :title="__('Cotización ').$cotizacion->folio">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $cotizacion->folio }}</h1>
                    <x-estado-cotizacion-badge :estado="$cotizacion->estado" />
                </div>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Cotización para {{ $cotizacion->cliente?->nombre ?? 'cliente sin registro' }}.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route($routePrefix.'.cotizaciones.pdf', $cotizacion) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Descargar PDF
                </a>
                <a href="{{ route($routePrefix.'.cotizaciones.png', $cotizacion) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Descargar PNG
                </a>
                <a href="{{ route($routePrefix.'.cotizaciones.plantilla', $cotizacion) }}" target="_blank" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Ver plantilla
                </a>

                @if ($puedeEditar)
                    <a href="{{ route($routePrefix.'.cotizaciones.edit', $cotizacion) }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                        Editar
                    </a>
                @endif

                <a href="{{ route($routePrefix.'.cotizaciones.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Volver al listado
                </a>
            </div>
        </section>

        @if (session('status'))
            <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700 lg:col-span-2">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Conceptos</h2>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        <thead>
                            <tr>
                                <th scope="col" class="py-2 pr-4 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Tipo</th>
                                <th scope="col" class="py-2 pr-4 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Descripción</th>
                                <th scope="col" class="py-2 pr-4 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Cantidad</th>
                                <th scope="col" class="py-2 pr-4 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">P. unitario</th>
                                <th scope="col" class="py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($cotizacion->items as $item)
                                <tr>
                                    <td class="whitespace-nowrap py-2 pr-4 text-sm text-neutral-700 dark:text-neutral-300">{{ ucfirst($item->tipo) }}</td>
                                    <td class="py-2 pr-4 text-sm text-neutral-900 dark:text-neutral-100">{{ $item->descripcion }}</td>
                                    <td class="whitespace-nowrap py-2 pr-4 text-right text-sm text-neutral-700 dark:text-neutral-300">{{ $item->cantidad }}</td>
                                    <td class="whitespace-nowrap py-2 pr-4 text-right text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($item->precio_unitario, 2) }}</td>
                                    <td class="whitespace-nowrap py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($item->subtotal, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="mt-6 ml-auto grid max-w-xs gap-2 text-sm">
                    <div class="flex justify-between"><dt class="text-neutral-600 dark:text-neutral-400">Subtotal</dt><dd class="font-medium text-neutral-900 dark:text-neutral-100">${{ number_format($cotizacion->subtotal, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-600 dark:text-neutral-400">Descuento</dt><dd class="font-medium text-red-700 dark:text-red-300">-${{ number_format($cotizacion->descuento, 2) }}</dd></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-2 dark:border-neutral-700"><dt class="font-semibold text-neutral-900 dark:text-neutral-100">Total</dt><dd class="font-semibold text-emerald-700 dark:text-emerald-300">${{ number_format($cotizacion->total, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-600 dark:text-neutral-400">Anticipo</dt><dd class="font-medium text-neutral-900 dark:text-neutral-100">${{ number_format($cotizacion->anticipo, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-600 dark:text-neutral-400">Saldo</dt><dd class="font-medium text-neutral-900 dark:text-neutral-100">${{ number_format($cotizacion->saldo, 2) }}</dd></div>
                </dl>
            </section>

            <div class="flex flex-col gap-6">
                <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Datos generales</h2>
                    <dl class="mt-4 grid gap-4 text-sm">
                        <div>
                            <dt class="font-medium text-neutral-500 dark:text-neutral-400">Cliente</dt>
                            <dd class="mt-1 text-neutral-900 dark:text-neutral-100">
                                <a href="{{ route($routePrefix.'.clientes.show', $cotizacion->cliente) }}" class="hover:underline">{{ $cotizacion->cliente?->nombre }}</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-500 dark:text-neutral-400">Equipo</dt>
                            <dd class="mt-1 text-neutral-900 dark:text-neutral-100">
                                {{ $cotizacion->equipo ? $cotizacion->equipo->tipo_equipo.' '.$cotizacion->equipo->marca.' '.$cotizacion->equipo->modelo : 'Sin equipo' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-500 dark:text-neutral-400">Recepción del equipo</dt>
                            <dd class="mt-1 text-neutral-900 dark:text-neutral-100">
                                {{ $cotizacion->etiquetaRecepcion() }}
                                @if ($cotizacion->esRecogidaADomicilio() && filled($cotizacion->direccion_recepcion))
                                    <span class="block text-sm text-neutral-600 dark:text-neutral-400">{{ $cotizacion->direccion_recepcion }}</span>
                                @elseif (! $cotizacion->esRecogidaADomicilio() && filled($cotizacion->partner?->nombre))
                                    <span class="block text-sm text-neutral-600 dark:text-neutral-400">{{ $cotizacion->partner->nombre }}</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-500 dark:text-neutral-400">Fecha</dt>
                            <dd class="mt-1 text-neutral-900 dark:text-neutral-100">{{ $cotizacion->fecha?->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-500 dark:text-neutral-400">Vigencia</dt>
                            <dd class="mt-1 text-neutral-900 dark:text-neutral-100">{{ $cotizacion->vigencia?->format('d/m/Y') ?? 'Sin vigencia definida' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-500 dark:text-neutral-400">Notas</dt>
                            <dd class="mt-1 whitespace-pre-line text-neutral-900 dark:text-neutral-100">{{ $cotizacion->notas ?: 'Sin notas' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Cambiar estado</h2>

                    <form method="POST" action="{{ route($routePrefix.'.cotizaciones.estado.store', $cotizacion) }}" class="mt-4 flex flex-col gap-3">
                        @csrf
                        <select
                            name="estado"
                            class="block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                            @foreach (\App\Models\Cotizacion::ESTADOS as $estado)
                                <option value="{{ $estado }}" @selected($cotizacion->estado === $estado)>{{ ucfirst($estado) }}</option>
                            @endforeach
                        </select>
                        @error('estado')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                            Actualizar estado
                        </button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-layouts::app>
