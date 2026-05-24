@csrf

<div class="grid gap-5">
    <div>
        <label for="cliente_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cliente</label>
        <select
            id="cliente_id"
            name="cliente_id"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
            <option value="">Selecciona un cliente</option>
            @foreach ($clientes as $cliente)
                <option value="{{ $cliente->id }}" @selected((int) old('cliente_id', $equipo->cliente_id) === $cliente->id)>
                    {{ $cliente->nombre }} - {{ $cliente->telefono }}
                </option>
            @endforeach
        </select>
        @error('cliente_id')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-5 md:grid-cols-2">
        <div>
            <label for="tipo_equipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de equipo</label>
            <input
                id="tipo_equipo"
                name="tipo_equipo"
                type="text"
                value="{{ old('tipo_equipo', $equipo->tipo_equipo) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                required
            >
            @error('tipo_equipo')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="marca" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Marca</label>
            <input
                id="marca"
                name="marca"
                type="text"
                value="{{ old('marca', $equipo->marca) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                required
            >
            @error('marca')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-5 md:grid-cols-2">
        <div>
            <label for="modelo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Modelo</label>
            <input
                id="modelo"
                name="modelo"
                type="text"
                value="{{ old('modelo', $equipo->modelo) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @error('modelo')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="numero_serie" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Numero de serie</label>
            <input
                id="numero_serie"
                name="numero_serie"
                type="text"
                value="{{ old('numero_serie', $equipo->numero_serie) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @error('numero_serie')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="password_equipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Password del equipo</label>
        <input
            id="password_equipo"
            name="password_equipo"
            type="text"
            value="{{ old('password_equipo', $equipo->password_equipo) }}"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >
        @error('password_equipo')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="estado_fisico_inicial" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Estado fisico inicial</label>
        <textarea
            id="estado_fisico_inicial"
            name="estado_fisico_inicial"
            rows="4"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >{{ old('estado_fisico_inicial', $equipo->estado_fisico_inicial) }}</textarea>
        @error('estado_fisico_inicial')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="accesorios_recibidos" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Accesorios recibidos</label>
        <textarea
            id="accesorios_recibidos"
            name="accesorios_recibidos"
            rows="4"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >{{ old('accesorios_recibidos', $equipo->accesorios_recibidos) }}</textarea>
        @error('accesorios_recibidos')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
        Guardar equipo
    </button>

    <a href="{{ route($routePrefix.'.equipos.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
        Volver al listado
    </a>
</div>
