<x-layouts::app :title="__('Órdenes de servicio')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Orden {{ $orden->folio }}</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Detalle general de la orden de servicio.</p>
            </div>

            <div class="flex gap-3">
                @if (auth()->user()->isAdmin() && $orden->cotizacion)
                    <a href="{{ route('admin.cotizaciones.show', $orden->cotizacion) }}" class="rounded-md border border-blue-300 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-950/40">
                        Ver cotización
                    </a>
                @elseif ($puedeCrearCotizacion)
                    <a href="{{ route('admin.cotizaciones.create', ['orden_servicio_id' => $orden->id]) }}" class="rounded-md border border-blue-300 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-950/40">
                        Crear cotización
                    </a>
                @endif

                <a href="{{ route($routePrefix.'.ordenes-servicio.nota-recepcion', $orden) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                    Nota de recepción
                </a>

                <a href="{{ route($routePrefix.'.ordenes-servicio.nota-recepcion.pdf', $orden) }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    PDF
                </a>

                <a href="{{ route($routePrefix.'.ordenes-servicio.nota-recepcion.png', $orden) }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    PNG
                </a>

                @if (auth()->user()->isAdmin() && ! $orden->finanzas_generadas && $orden->estado !== 'entregado')
                    <a href="{{ route('admin.ordenes-servicio.edit', $orden) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                        Editar
                    </a>
                @endif

                <a href="{{ route($routePrefix.'.ordenes-servicio.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Volver al listado
                </a>
            </div>
        </section>

        @if (session('error'))
            <div class="border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{{ session('error') }}</div>
        @endif

        @if ($orden->finanzas_generadas)
            <div class="border-l-4 border-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                <p class="font-semibold">Orden cerrada financieramente</p>
                <p class="mt-1">Los servicios, refacciones, costos y totales ya no pueden modificarse.</p>
            </div>
        @endif

        <section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Datos generales</h2>

            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Folio</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->folio }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Estado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                        <x-estado-orden-badge :estado="$orden->estado" />
                    </dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Cliente</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->cliente?->nombre ?? 'Sin cliente' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Equipo</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                        {{ $orden->equipo ? $orden->equipo->tipo_equipo.' '.$orden->equipo->marca.' '.$orden->equipo->modelo : 'Sin equipo asignado' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Tipo de recepción</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ ucfirst($orden->tipo_recepcion) }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Fecha de recepción</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->fecha_recepcion?->format('d/m/Y H:i') ?? 'Sin fecha' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Fecha de entrega</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->fecha_entrega?->format('d/m/Y H:i') ?? 'Sin entrega registrada' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Partner de recepción</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->partnerRecepcion?->nombre ?? 'Sin partner' }}</dd>
                </div>

                @if (auth()->user()->isAdmin())
                    @php
                        $comisionEsRequisitoReal = \App\Actions\Ordenes\PoliticaCostosOrdenServicio::requiereComisionRecepcion($orden->modelo_financiero, $orden->tipo_recepcion);
                    @endphp
                    <div>
                        <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Comisión de recepción</dt>
                        <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                            @if ($orden->comision_recepcion !== null)
                                ${{ number_format($orden->comision_recepcion, 2) }}
                            @elseif ($comisionEsRequisitoReal)
                                Pendiente
                            @else
                                No aplica
                            @endif
                        </dd>
                        @if ($comisionEsRequisitoReal)
                            <p class="mt-1 text-xs {{ $orden->comision_recepcion === null ? 'text-amber-700 dark:text-amber-300' : 'text-neutral-500 dark:text-neutral-400' }}">
                                {{ $orden->comision_recepcion === null ? 'Pendiente: requisito financiero real para poder entregar esta orden.' : 'Costo activo de esta orden.' }}
                            </p>
                        @elseif ($orden->usaCostosPorLinea())
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">No aplica: la comisión de recepción sólo se confirma cuando el equipo se recibió en sucursal.</p>
                        @else
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Dato preparado; no es un costo activo de esta orden legacy.</p>
                        @endif
                    </div>

                    @if ($orden->nota_recepcion)
                        <div>
                            <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Nota de recepción</dt>
                            <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->nota_recepcion }}</dd>
                        </div>
                    @endif
                @endif

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Partner técnico</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->partnerTecnico?->nombre ?? 'Sin partner' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Creada por</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->creadoPor?->name ?? 'Sin usuario' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Total cliente</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->total_cliente, 2) }}</dd>
                </div>

                @if ($orden->usaModeloFinancieroLegacy())
                    <div>
                        <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Costo técnico</dt>
                        <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $orden->costo_tecnico === null ? 'Pendiente' : '$'.number_format($orden->costo_tecnico, 2) }}
                        </dd>
                    </div>
                @endif

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Comisión logística</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->comision_logistica, 2) }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Utilidad neta</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($orden->utilidad_neta, 2) }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Finanzas generadas</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->finanzas_generadas ? 'Sí' : 'No' }}</dd>
                </div>

                @if (auth()->user()->isAdmin())
                    <div>
                        <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Modelo financiero</dt>
                        <dd class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ $orden->usaCostosPorLinea() ? 'Costos por línea' : 'Legacy' }}</dd>
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Notas</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm text-neutral-900 dark:text-neutral-100">{{ $orden->notas ?: 'Sin notas' }}</dd>
                </div>
            </dl>
        </section>

        @if ($estadosDisponibles !== [])
            @include('ordenes-servicio._estado-form')
        @endif

        @include('ordenes-servicio._detalles')

        @include('ordenes-servicio._refacciones')

        @include('ordenes-servicio._ajustes-financieros')

        @include('ordenes-servicio._historial')
    </div>
</x-layouts::app>
