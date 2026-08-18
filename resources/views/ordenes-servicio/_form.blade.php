@csrf

@error('orden')
    <div class="border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{{ $message }}</div>
@enderror

@php
    $servicioRows = old('servicios');

    if ($servicioRows === null) {
        $servicioRows = $orden->exists
            ? $orden->detalles->map(fn ($detalle) => [
                'servicio_id' => $detalle->servicio_id,
                'descripcion' => $detalle->descripcion,
                'cantidad' => $detalle->cantidad,
                'precio_unitario' => $detalle->precio_unitario,
                'costo_unitario' => $detalle->costo_unitario,
                'notas' => $detalle->notas,
            ])->values()->all()
            : [];
    }

    $servicioRows = array_values($servicioRows);

    while (count($servicioRows) < 5) {
        $servicioRows[] = [];
    }

    $refaccionesReadonly = $orden->exists && $orden->finanzas_generadas;
    $refaccionRows = $refaccionesReadonly ? null : old('refacciones');

    if ($refaccionRows === null) {
        $refaccionRows = $orden->exists
            ? $orden->refacciones->map(fn ($refaccion) => [
                'descripcion' => $refaccion->descripcion,
                'cantidad' => $refaccion->cantidad,
                'costo_unitario' => $refaccion->costo_unitario,
                'precio_unitario_cliente' => $refaccion->precio_unitario_cliente,
                'notas' => $refaccion->notas,
            ])->values()->all()
            : [];
    }

    $refaccionRows = array_values($refaccionRows);

    while (count($refaccionRows) < 5) {
        $refaccionRows[] = [];
    }
@endphp

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
                <option value="{{ $cliente->id }}" @selected((int) old('cliente_id', $orden->cliente_id) === $cliente->id)>
                    {{ $cliente->nombre }} - {{ $cliente->telefono }}
                </option>
            @endforeach
        </select>
        @error('cliente_id')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="equipo_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Equipo</label>
        <select
            id="equipo_id"
            name="equipo_id"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >
            <option value="">Sin equipo asignado</option>
            @foreach ($equipos as $equipo)
                <option value="{{ $equipo->id }}" @selected((int) old('equipo_id', $orden->equipo_id) === $equipo->id)>
                    {{ $equipo->cliente?->nombre }} - {{ $equipo->tipo_equipo }} {{ $equipo->marca }} {{ $equipo->modelo }}
                </option>
            @endforeach
        </select>
        @error('equipo_id')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="tipo_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de recepción</label>
        <select
            id="tipo_recepcion"
            name="tipo_recepcion"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            required
        >
            <option value="sucursal" @selected(old('tipo_recepcion', $orden->tipo_recepcion) === 'sucursal')>Sucursal</option>
            <option value="domicilio" @selected(old('tipo_recepcion', $orden->tipo_recepcion) === 'domicilio')>Domicilio</option>
            <option value="directo" @selected(old('tipo_recepcion', $orden->tipo_recepcion) === 'directo')>Directo</option>
        </select>
        @error('tipo_recepcion')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @if (auth()->user()->isAdmin())
        <div>
            <label for="partner_recepcion_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Partner de recepción</label>
            <select
                id="partner_recepcion_id"
                name="partner_recepcion_id"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
                <option value="" @selected(blank(old('partner_recepcion_id', $orden->partner_recepcion_id)))>Sin partner de recepción</option>
                @foreach ($partnersRecepcion as $partner)
                    <option value="{{ $partner->id }}" @selected((int) old('partner_recepcion_id', $orden->partner_recepcion_id) === $partner->id)>
                        {{ $partner->nombre }}
                    </option>
                @endforeach
            </select>
            @error('partner_recepcion_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    @else
        <div class="rounded-md border border-neutral-200 p-4 text-sm text-neutral-700 dark:border-neutral-700 dark:text-neutral-300">
            Partner de recepción: {{ auth()->user()->partner?->nombre ?? 'Sin partner asignado' }}
        </div>
    @endif

    <div>
        <label for="partner_tecnico_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Partner técnico</label>
        <select
            id="partner_tecnico_id"
            name="partner_tecnico_id"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >
            <option value="" @selected(blank(old('partner_tecnico_id', $orden->partner_tecnico_id)))>Sin partner técnico</option>
            @foreach ($partnersTecnicos as $partner)
                <option value="{{ $partner->id }}" @selected((int) old('partner_tecnico_id', $orden->partner_tecnico_id) === $partner->id)>
                    {{ $partner->nombre }}
                </option>
            @endforeach
        </select>
        @error('partner_tecnico_id')
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
        >{{ old('notas', $orden->notas) }}</textarea>
        @error('notas')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @if (auth()->user()->isAdmin() && $orden->exists)
        <div>
            <label for="costo_tecnico" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Costo técnico</label>

            @if ($orden->finanzas_generadas)
                <input
                    id="costo_tecnico"
                    type="number"
                    value="{{ old('costo_tecnico', $orden->costo_tecnico) }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 bg-neutral-100 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                    disabled
                >
                <input type="hidden" name="costo_tecnico" value="{{ $orden->costo_tecnico }}">
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">No puede modificarse porque las finanzas ya fueron generadas.</p>
            @else
                <input
                    id="costo_tecnico"
                    name="costo_tecnico"
                    type="number"
                    min="0"
                    step="0.01"
                    value="{{ old('costo_tecnico', $orden->costo_tecnico) }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                >
            @endif

            @error('costo_tecnico')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    @endif

    <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Servicios de la orden</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Agrega al menos un servicio. El subtotal y total se calculan al guardar.</p>
        </div>

        @error('servicios')
            <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="mt-5 grid gap-4">
            @foreach ($servicioRows as $index => $row)
                <div class="grid gap-3 rounded-md border border-neutral-200 p-4 dark:border-neutral-700 lg:grid-cols-12">
                    <div class="lg:col-span-2">
                        <label for="servicios_{{ $index }}_servicio_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Servicio</label>
                        <select
                            id="servicios_{{ $index }}_servicio_id"
                            name="servicios[{{ $index }}][servicio_id]"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                            <option value="">Servicio del catálogo (opcional)</option>
                            @foreach ($servicios as $servicio)
                                <option value="{{ $servicio->id }}" data-name="{{ $servicio->nombre }}" data-price="{{ $servicio->precio_base }}" @selected((int) ($row['servicio_id'] ?? 0) === $servicio->id)>
                                    {{ $servicio->nombre }} - ${{ number_format($servicio->precio_base, 2) }}
                                </option>
                            @endforeach
                        </select>
                        @error("servicios.$index.servicio_id")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-3">
                        <label for="servicios_{{ $index }}_descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripción vendida</label>
                        <input
                            id="servicios_{{ $index }}_descripcion"
                            name="servicios[{{ $index }}][descripcion]"
                            type="text"
                            maxlength="255"
                            value="{{ $row['descripcion'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                        @error("servicios.$index.descripcion")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-1">
                        <label for="servicios_{{ $index }}_cantidad" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cantidad</label>
                        <input
                            id="servicios_{{ $index }}_cantidad"
                            name="servicios[{{ $index }}][cantidad]"
                            type="number"
                            min="1"
                            value="{{ $row['cantidad'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                        @error("servicios.$index.cantidad")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    @if (auth()->user()->isAdmin())
                        <div class="lg:col-span-2">
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
                            @error("servicios.$index.costo_unitario")
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    <div class="lg:col-span-2">
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
                        @error("servicios.$index.precio_unitario")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-2">
                        <label for="servicios_{{ $index }}_notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas</label>
                        <input
                            id="servicios_{{ $index }}_notas"
                            name="servicios[{{ $index }}][notas]"
                            type="text"
                            value="{{ $row['notas'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                        @error("servicios.$index.notas")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Refacciones</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Registra materiales asociados a la orden. Costo, precio al cliente y utilidad se calculan al guardar.</p>
        </div>

        @if ($refaccionesReadonly)
            <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                No se pueden modificar refacciones porque las finanzas ya fueron generadas.
            </div>
        @endif

        @error('refacciones')
            <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="mt-5 grid gap-5">
            @foreach ($refaccionRows as $index => $row)
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 rounded-md border border-neutral-200 bg-neutral-50/50 p-4 dark:border-neutral-700 dark:bg-neutral-900/30 md:grid-cols-12">
                    <div class="md:col-span-3">
                        <label for="refacciones_{{ $index }}_descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripcion</label>
                        <input
                            id="refacciones_{{ $index }}_descripcion"
                            name="refacciones[{{ $index }}][descripcion]"
                            type="text"
                            maxlength="255"
                            value="{{ $row['descripcion'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                            @disabled($refaccionesReadonly)
                        >
                        @error("refacciones.$index.descripcion")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-1">
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
                        @error("refacciones.$index.cantidad")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label for="refacciones_{{ $index }}_costo_unitario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Costo unitario</label>
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
                        @error("refacciones.$index.costo_unitario")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label for="refacciones_{{ $index }}_precio_unitario_cliente" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Precio cliente</label>
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
                        @error("refacciones.$index.precio_unitario_cliente")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-4">
                        <label for="refacciones_{{ $index }}_notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas</label>
                        <input
                            id="refacciones_{{ $index }}_notas"
                            name="refacciones[{{ $index }}][notas]"
                            type="text"
                            value="{{ $row['notas'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-300"
                            @disabled($refaccionesReadonly)
                        >
                        @error("refacciones.$index.notas")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>

<section class="mt-6 border-y border-neutral-200 py-5 dark:border-neutral-700" aria-label="Resumen estimado">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="text-xs font-medium uppercase text-neutral-500">Servicios</p><p id="order-total-services" class="mt-1 text-lg font-semibold">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Refacciones</p><p id="order-total-parts" class="mt-1 text-lg font-semibold">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Costo refacciones</p><p id="order-cost-parts" class="mt-1 text-lg font-semibold text-red-700 dark:text-red-300">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Total al cliente</p><p id="order-total" class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">$0.00</p></div>
    </div>
    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">Vista previa. Los importes se recalculan en el servidor al guardar.</p>
</section>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
        Guardar orden
    </button>

    <a href="{{ route($routePrefix.'.ordenes-servicio.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
        Volver al listado
    </a>
</div>

<script>
    const orderForm = document.currentScript.closest('form');
    document.addEventListener('DOMContentLoaded', () => {
        const form = orderForm;
        if (!form) return;
        const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
        const calculate = () => {
            let services = 0, parts = 0, costs = 0;
            form.querySelectorAll('input[name^="servicios"][name$="[descripcion]"]').forEach(input => {
                if (!input.value.trim()) return;
                const index = input.name.match(/\[(\d+)\]/)[1];
                const quantity = Number(form.querySelector(`[name="servicios[${index}][cantidad]"]`)?.value) || 0;
                const price = Number(form.querySelector(`[name="servicios[${index}][precio_unitario]"]`)?.value) || 0;
                services += quantity * price;
            });
            form.querySelectorAll('input[name^="refacciones"][name$="[descripcion]"]').forEach(input => {
                if (!input.value.trim()) return;
                const index = input.name.match(/\[(\d+)\]/)[1];
                const quantity = Number(form.querySelector(`[name="refacciones[${index}][cantidad]"]`)?.value) || 0;
                costs += quantity * (Number(form.querySelector(`[name="refacciones[${index}][costo_unitario]"]`)?.value) || 0);
                parts += quantity * (Number(form.querySelector(`[name="refacciones[${index}][precio_unitario_cliente]"]`)?.value) || 0);
            });
            document.getElementById('order-total-services').textContent = money(services);
            document.getElementById('order-total-parts').textContent = money(parts);
            document.getElementById('order-cost-parts').textContent = money(costs);
            document.getElementById('order-total').textContent = money(services + parts);
        };
        form.addEventListener('change', event => {
            if (event.target.matches('select[name^="servicios"][name$="[servicio_id]"]')) {
                const index = event.target.name.match(/\[(\d+)\]/)[1];
                const price = form.querySelector(`[name="servicios[${index}][precio_unitario]"]`);
                if (event.target.value && !price.value) price.value = event.target.selectedOptions[0].dataset.price || '';
                const description = form.querySelector(`[name="servicios[${index}][descripcion]"]`);
                if (event.target.value && description && !description.value) description.value = event.target.selectedOptions[0].dataset.name || '';
            }
            calculate();
        });
        form.addEventListener('input', calculate);
        calculate();
    });
</script>
