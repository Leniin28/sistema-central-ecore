@csrf

<div class="grid gap-5">
    <div>
        <label for="categoria_servicio_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Categoría</label>
        <select
            id="categoria_servicio_id"
            name="categoria_servicio_id"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
            <option value="">Selecciona una categoría</option>
            @foreach ($categorias as $categoria)
                <option value="{{ $categoria->id }}" @selected((int) old('categoria_servicio_id', $servicio->categoria_servicio_id) === $categoria->id)>
                    {{ $categoria->nombre }}
                </option>
            @endforeach
        </select>
        @error('categoria_servicio_id')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="nombre" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nombre</label>
        <input
            id="nombre"
            name="nombre"
            type="text"
            value="{{ old('nombre', $servicio->nombre) }}"
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
        >{{ old('descripcion', $servicio->descripcion) }}</textarea>
        @error('descripcion')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="precio_base" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Precio base</label>
        <input
            id="precio_base"
            name="precio_base"
            type="number"
            step="0.01"
            min="0"
            value="{{ old('precio_base', $servicio->precio_base) }}"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
        @error('precio_base')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <label class="flex items-center gap-3">
        <input
            name="activo"
            type="checkbox"
            value="1"
            @checked(old('activo', $servicio->activo))
            class="rounded border-neutral-300 text-neutral-900 shadow-sm dark:border-neutral-700"
        >
        <span class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Servicio activo</span>
    </label>
    @error('activo')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
        Guardar servicio
    </button>

    <a href="{{ route('admin.servicios.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
        Volver al listado
    </a>
</div>
