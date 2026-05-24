<section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Historial de estados</h2>

    <div class="mt-5 overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-900">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Fecha</th>
                    <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Anterior</th>
                    <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Nuevo</th>
                    <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Usuario</th>
                    <th scope="col" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">Comentario</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-800">
                @forelse ($orden->historialEstados as $historial)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $historial->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">
                            @if ($historial->estado_anterior)
                                <x-estado-orden-badge :estado="$historial->estado_anterior" />
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">Sin estado anterior</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            <x-estado-orden-badge :estado="$historial->estado_nuevo" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $historial->user?->name ?? 'Sin usuario' }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300">{{ $historial->comentario ?? 'Sin comentario' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-400">
                            No hay historial registrado.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
