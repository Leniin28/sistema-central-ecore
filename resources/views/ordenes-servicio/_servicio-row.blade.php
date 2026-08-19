@php($isTemplate = $isTemplate ?? false)

<div data-service-row data-row-index="{{ $index }}" class="rounded-md border border-neutral-200 bg-neutral-50/50 p-4 dark:border-neutral-700 dark:bg-neutral-900/30">
    <div class="flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">
            Servicio <span data-row-number></span>
        </p>
        <button
            type="button"
            data-remove-row="service"
            class="text-sm font-medium text-red-700 hover:text-red-900 disabled:invisible dark:text-red-300 dark:hover:text-red-200"
        >
            Eliminar
        </button>
    </div>

    <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="md:col-span-1 xl:col-span-3">
            <label for="servicios_{{ $index }}_servicio_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Servicio</label>
            <select
                id="servicios_{{ $index }}_servicio_id"
                name="servicios[{{ $index }}][servicio_id]"
                data-row-primary
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
                <option value="">Servicio del catálogo (opcional)</option>
                @foreach ($servicios as $servicio)
                    <option value="{{ $servicio->id }}" data-name="{{ $servicio->nombre }}" data-price="{{ $servicio->precio_base }}" @selected((int) ($row['servicio_id'] ?? 0) === $servicio->id)>
                        {{ $servicio->nombre }} - ${{ number_format($servicio->precio_base, 2) }}
                    </option>
                @endforeach
            </select>
            @unless ($isTemplate)
                @error("servicios.$index.servicio_id")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-2 xl:col-span-4">
            <label for="servicios_{{ $index }}_descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripción vendida</label>
            <input
                id="servicios_{{ $index }}_descripcion"
                name="servicios[{{ $index }}][descripcion]"
                type="text"
                maxlength="255"
                value="{{ $row['descripcion'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @unless ($isTemplate)
                @error("servicios.$index.descripcion")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-1 xl:col-span-1">
            <label for="servicios_{{ $index }}_cantidad" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cantidad</label>
            <input
                id="servicios_{{ $index }}_cantidad"
                name="servicios[{{ $index }}][cantidad]"
                type="number"
                min="1"
                value="{{ $row['cantidad'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @unless ($isTemplate)
                @error("servicios.$index.cantidad")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        @if (auth()->user()->isAdmin())
            <div class="md:col-span-1 xl:col-span-2">
                <label for="servicios_{{ $index }}_costo_unitario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Costo interno unitario</label>
                <input
                    id="servicios_{{ $index }}_costo_unitario"
                    name="servicios[{{ $index }}][costo_unitario]"
                    type="number"
                    min="0"
                    step="0.01"
                    value="{{ $row['costo_unitario'] ?? '' }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                >
                @unless ($isTemplate)
                    @error("servicios.$index.costo_unitario")
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                @endunless
            </div>
        @endif

        <div class="md:col-span-1 xl:col-span-2">
            <label for="servicios_{{ $index }}_precio_unitario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Precio unitario</label>
            <input
                id="servicios_{{ $index }}_precio_unitario"
                name="servicios[{{ $index }}][precio_unitario]"
                type="number"
                min="0"
                step="0.01"
                value="{{ $row['precio_unitario'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @unless ($isTemplate)
                @error("servicios.$index.precio_unitario")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-2 xl:col-span-12">
            <label for="servicios_{{ $index }}_notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas</label>
            <input
                id="servicios_{{ $index }}_notas"
                name="servicios[{{ $index }}][notas]"
                type="text"
                value="{{ $row['notas'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @unless ($isTemplate)
                @error("servicios.$index.notas")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>
    </div>
</div>
