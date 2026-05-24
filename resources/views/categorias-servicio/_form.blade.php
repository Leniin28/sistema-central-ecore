@csrf

<div class="grid gap-5">
    <div>
        <label for="nombre" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nombre</label>
        <input
            id="nombre"
            name="nombre"
            type="text"
            value="{{ old('nombre', $categoria->nombre) }}"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
        @error('nombre')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripción</label>
        <textarea
            id="descripcion"
            name="descripcion"
            rows="4"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >{{ old('descripcion', $categoria->descripcion) }}</textarea>
        @error('descripcion')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
        Guardar categoría
    </button>

    <a href="{{ route('admin.categorias-servicio.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
        Volver al listado
    </a>
</div>
