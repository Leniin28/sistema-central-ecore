<section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Cambiar estado</h2>

    <form method="POST" action="{{ route($routePrefix.'.ordenes-servicio.estado.store', $orden) }}" class="mt-5 grid gap-4">
        @csrf

        <div>
            <label for="estado_nuevo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nuevo estado</label>
            <select
                id="estado_nuevo"
                name="estado_nuevo"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                required
            >
                <option value="">Selecciona un estado</option>
                @foreach ($estadosDisponibles as $estado)
                    <option value="{{ $estado }}" @selected(old('estado_nuevo') === $estado)>
                        {{ str_replace('_', ' ', ucfirst($estado)) }}
                    </option>
                @endforeach
            </select>
            @error('estado_nuevo')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="comentario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Comentario</label>
            <textarea
                id="comentario"
                name="comentario"
                rows="3"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >{{ old('comentario') }}</textarea>
            @error('comentario')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                Cambiar estado
            </button>
        </div>
    </form>
</section>
