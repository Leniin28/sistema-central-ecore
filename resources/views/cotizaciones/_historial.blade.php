{{-- Tabla compacta de cotizaciones para mostrarse en cliente/equipo. Espera $cotizaciones y $routePrefix. --}}
@if ($cotizaciones->isEmpty())
    <p class="mt-4 text-sm text-neutral-600 dark:text-neutral-400">Sin cotizaciones registradas.</p>
@else
    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead>
                <tr>
                    <th scope="col" class="py-2 pr-4 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Folio</th>
                    <th scope="col" class="py-2 pr-4 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                    <th scope="col" class="py-2 pr-4 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Estado</th>
                    <th scope="col" class="py-2 pr-4 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Total</th>
                    <th scope="col" class="py-2 pr-4 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Saldo</th>
                    <th scope="col" class="py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($cotizaciones as $cotizacion)
                    <tr>
                        <td class="whitespace-nowrap py-2 pr-4 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $cotizacion->folio }}</td>
                        <td class="whitespace-nowrap py-2 pr-4 text-sm text-neutral-700 dark:text-neutral-300">{{ $cotizacion->fecha?->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap py-2 pr-4 text-sm"><x-estado-cotizacion-badge :estado="$cotizacion->estado" /></td>
                        <td class="whitespace-nowrap py-2 pr-4 text-right text-sm text-neutral-900 dark:text-neutral-100">${{ number_format($cotizacion->total, 2) }}</td>
                        <td class="whitespace-nowrap py-2 pr-4 text-right text-sm text-neutral-700 dark:text-neutral-300">${{ number_format($cotizacion->saldo, 2) }}</td>
                        <td class="whitespace-nowrap py-2 text-right text-sm">
                            <a href="{{ route($routePrefix.'.cotizaciones.show', $cotizacion) }}" class="font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">Ver</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
