@php($isTemplate = $isTemplate ?? false)

<div data-part-row data-row-index="{{ $index }}" class="rounded-md border border-neutral-200 bg-neutral-50/50 p-4 dark:border-neutral-700 dark:bg-neutral-900/30">
    <div class="flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">
            Refacción <span data-row-number></span>
        </p>
        @unless ($refaccionesReadonly)
            <button
                type="button"
                data-remove-row="part"
                class="text-sm font-medium text-red-700 hover:text-red-900 disabled:invisible dark:text-red-300 dark:hover:text-red-200"
            >
                Eliminar
            </button>
        @endunless
    </div>

    <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="md:col-span-2 xl:col-span-4">
            <label for="refacciones_{{ $index }}_descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripción</label>
            <input
                id="refacciones_{{ $index }}_descripcion"
                name="refacciones[{{ $index }}][descripcion]"
                type="text"
                maxlength="255"
                value="{{ $row['descripcion'] ?? '' }}"
                data-row-primary
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                @disabled($refaccionesReadonly)
            >
            @unless ($isTemplate)
                @error("refacciones.$index.descripcion")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-1 xl:col-span-1">
            <label for="refacciones_{{ $index }}_cantidad" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cantidad</label>
            <input
                id="refacciones_{{ $index }}_cantidad"
                name="refacciones[{{ $index }}][cantidad]"
                type="number"
                min="1"
                value="{{ $row['cantidad'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                @disabled($refaccionesReadonly)
            >
            @unless ($isTemplate)
                @error("refacciones.$index.cantidad")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-1 xl:col-span-2">
            <label for="refacciones_{{ $index }}_costo_unitario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Costo interno unitario</label>
            <input
                id="refacciones_{{ $index }}_costo_unitario"
                name="refacciones[{{ $index }}][costo_unitario]"
                type="number"
                min="0"
                step="0.01"
                value="{{ $row['costo_unitario'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                @disabled($refaccionesReadonly)
            >
            @unless ($isTemplate)
                @error("refacciones.$index.costo_unitario")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-1 xl:col-span-2">
            <label for="refacciones_{{ $index }}_precio_unitario_cliente" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Precio unitario al cliente</label>
            <input
                id="refacciones_{{ $index }}_precio_unitario_cliente"
                name="refacciones[{{ $index }}][precio_unitario_cliente]"
                type="number"
                min="0"
                step="0.01"
                value="{{ $row['precio_unitario_cliente'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                @disabled($refaccionesReadonly)
            >
            @unless ($isTemplate)
                @error("refacciones.$index.precio_unitario_cliente")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>

        <div class="md:col-span-2 xl:col-span-3">
            <label for="refacciones_{{ $index }}_notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas</label>
            <input
                id="refacciones_{{ $index }}_notas"
                name="refacciones[{{ $index }}][notas]"
                type="text"
                value="{{ $row['notas'] ?? '' }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                @disabled($refaccionesReadonly)
            >
            @unless ($isTemplate)
                @error("refacciones.$index.notas")
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endunless
        </div>
    </div>
</div>
