@csrf

<div class="grid gap-5">
    <div>
        <label for="nombre" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nombre</label>
        <input
            id="nombre"
            name="nombre"
            type="text"
            value="{{ old('nombre', $cliente->nombre) }}"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
        @error('nombre')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="telefono" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Teléfono</label>
        <input
            id="telefono"
            name="telefono"
            type="text"
            value="{{ old('telefono', $cliente->telefono) }}"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
        @error('telefono')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="correo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Correo</label>
        <input
            id="correo"
            name="correo"
            type="email"
            value="{{ old('correo', $cliente->correo) }}"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >
        @error('correo')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="tipo_cliente" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de cliente</label>
        <select
            id="tipo_cliente"
            name="tipo_cliente"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
            <option value="">Selecciona una opción</option>
            <option value="mantenimiento" @selected(old('tipo_cliente', $cliente->tipo_cliente) === 'mantenimiento')>Mantenimiento</option>
            <option value="marketing" @selected(old('tipo_cliente', $cliente->tipo_cliente) === 'marketing')>Marketing</option>
            <option value="ambos" @selected(old('tipo_cliente', $cliente->tipo_cliente) === 'ambos')>Ambos</option>
        </select>
        @error('tipo_cliente')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas</label>
        <textarea
            id="notas"
            name="notas"
            rows="4"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >{{ old('notas', $cliente->notas) }}</textarea>
        @error('notas')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
        Guardar cliente
    </button>

    <a href="{{ route($routePrefix.'.clientes.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
        Volver al listado
    </a>
</div>
