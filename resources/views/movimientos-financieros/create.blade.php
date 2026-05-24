<x-layouts::app :title="__('Nuevo movimiento financiero')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="space-y-1">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Nuevo movimiento financiero</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Registra un ingreso o egreso manual sin asociarlo a una orden de servicio.</p>
        </section>

        <section class="max-w-3xl">
            <form method="POST" action="{{ route('admin.movimientos-financieros.store') }}" class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
                @csrf

                <div class="grid gap-5">
                    <div>
                        <label for="cliente_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cliente</label>
                        <select id="cliente_id" name="cliente_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                            <option value="">Sin cliente</option>
                            @foreach ($clientes as $cliente)
                                <option value="{{ $cliente->id }}" @selected((int) old('cliente_id') === $cliente->id)>
                                    {{ $cliente->nombre }}
                                </option>
                            @endforeach
                        </select>
                        @error('cliente_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="partner_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Partner</label>
                        <select id="partner_id" name="partner_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
                            <option value="">Sin partner</option>
                            @foreach ($partners as $partner)
                                <option value="{{ $partner->id }}" @selected((int) old('partner_id') === $partner->id)>
                                    {{ $partner->nombre }}
                                </option>
                            @endforeach
                        </select>
                        @error('partner_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label for="tipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo</label>
                            <select id="tipo" name="tipo" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" required>
                                <option value="">Selecciona un tipo</option>
                                @foreach ($tipos as $tipo)
                                    <option value="{{ $tipo }}" @selected(old('tipo') === $tipo)>
                                        <x-financial-label :value="$tipo" />
                                    </option>
                                @endforeach
                            </select>
                            @error('tipo')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="categoria" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Categoria</label>
                            <select id="categoria" name="categoria" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" required>
                                <option value="">Selecciona una categoria</option>
                                @foreach ($categorias as $categoria)
                                    <option value="{{ $categoria }}" @selected(old('categoria') === $categoria)>
                                        <x-financial-label :value="$categoria" />
                                    </option>
                                @endforeach
                            </select>
                            @error('categoria')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label for="monto" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Monto</label>
                            <input id="monto" name="monto" type="number" min="0.01" step="0.01" value="{{ old('monto') }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" required>
                            @error('monto')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="fecha" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha</label>
                            <input id="fecha" name="fecha" type="date" value="{{ old('fecha', today()->toDateString()) }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" required>
                            @error('fecha')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripcion</label>
                        <textarea id="descripcion" name="descripcion" rows="4" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">{{ old('descripcion') }}</textarea>
                        @error('descripcion')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                        Guardar
                    </button>

                    <a href="{{ route('admin.movimientos-financieros.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
                        Volver
                    </a>
                </div>
            </form>
        </section>
    </div>
</x-layouts::app>
