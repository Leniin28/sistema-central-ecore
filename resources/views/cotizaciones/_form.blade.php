@csrf

@php
    $itemRows = old('items');

    if ($itemRows === null) {
        $itemRows = $cotizacion->exists
            ? $cotizacion->items->map(fn ($item) => [
                'id' => $item->id,
                'tipo' => $item->tipo,
                'descripcion' => $item->descripcion,
                'cantidad' => $item->cantidad,
                'precio_unitario' => $item->precio_unitario,
                'costo_unitario' => $item->costo_unitario,
            ])->values()->all()
            : [];
    }

    $itemRows = array_values($itemRows);

    while (count($itemRows) < 6) {
        $itemRows[] = [];
    }
@endphp

<div class="grid gap-5">
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <x-searchable-select
                id="cliente_id"
                name="cliente_id"
                label="Cliente"
                :url="route($routePrefix.'.clientes.buscar')"
                :selected-id="$clienteSeleccionado?->id"
                :selected-label="$clienteSeleccionado ? $clienteSeleccionado->nombre.' · '.$clienteSeleccionado->telefono : null"
                required
            />
            <a href="{{ route($routePrefix.'.clientes.create') }}" class="mt-2 inline-block text-sm text-neutral-600 underline hover:text-neutral-950 dark:text-neutral-400 dark:hover:text-white">Registrar nuevo cliente</a>
            @error('cliente_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-searchable-select
                id="equipo_id"
                name="equipo_id"
                label="Equipo (opcional)"
                :url="str_replace('__CLIENTE__', '{cliente}', route($routePrefix.'.clientes.equipos.buscar', '__CLIENTE__'))"
                :selected-id="$equipoSeleccionado?->id"
                :selected-label="$equipoSeleccionado ? $equipoSeleccionado->tipo_equipo.' · '.$equipoSeleccionado->marca.' '.$equipoSeleccionado->modelo : null"
                depends-on="cliente_id"
            />
            @error('equipo_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="fecha" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Fecha</label>
            <input
                id="fecha"
                name="fecha"
                type="date"
                value="{{ old('fecha', $cotizacion->fecha?->format('Y-m-d')) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                required
            >
            @error('fecha')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="vigencia" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Vigencia (opcional)</label>
            <input
                id="vigencia"
                name="vigencia"
                type="date"
                value="{{ old('vigencia', $cotizacion->vigencia?->format('Y-m-d')) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @error('vigencia')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @if (auth()->user()->isAdmin())
        <section
            class="rounded-lg border border-blue-200 bg-blue-50/50 p-5 dark:border-blue-900 dark:bg-blue-950/20"
            data-orden-activa-selector
            data-url="{{ route('admin.cotizaciones.ordenes-elegibles') }}"
            data-cotizacion-id="{{ $cotizacion->exists ? $cotizacion->id : '' }}"
        >
            <div class="space-y-1">
                <label for="orden_servicio_id" class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Orden activa relacionada</label>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">La vinculación es opcional. Si no eliges una orden, se creará una nueva al aceptar la cotización.</p>
            </div>

            <select
                id="orden_servicio_id"
                name="orden_servicio_id"
                class="mt-4 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
                <option value="">No vincular — crear una nueva orden al aceptar</option>
                @if ($ordenSeleccionada)
                    <option value="{{ $ordenSeleccionada->id }}" selected>
                        {{ $ordenSeleccionada->folio }} — {{ $ordenSeleccionada->estadoLabel() }} — {{ $ordenSeleccionada->fecha_recepcion?->format('d/m/Y') }}
                    </option>
                @endif
            </select>
            <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400" data-orden-activa-estado>
                Selecciona cliente y equipo para consultar órdenes activas elegibles.
            </p>
            @error('orden_servicio_id')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </section>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const container = document.querySelector('[data-orden-activa-selector]');
                if (!container) return;

                const selector = container.querySelector('[name="orden_servicio_id"]');
                const estado = container.querySelector('[data-orden-activa-estado]');
                const cliente = document.querySelector('[name="cliente_id"]');
                const equipo = document.querySelector('[name="equipo_id"]');
                let consultaActual = 0;

                const cargar = async (conservarSeleccion = false) => {
                    const numeroConsulta = ++consultaActual;
                    const seleccionAnterior = conservarSeleccion ? selector.value : '';
                    selector.replaceChildren(new Option('No vincular — crear una nueva orden al aceptar', ''));

                    if (!cliente?.value || !equipo?.value) {
                        estado.textContent = 'Selecciona cliente y equipo para consultar órdenes activas elegibles.';
                        return;
                    }

                    estado.textContent = 'Consultando órdenes activas…';
                    const url = new URL(container.dataset.url, window.location.origin);
                    url.searchParams.set('cliente_id', cliente.value);
                    url.searchParams.set('equipo_id', equipo.value);
                    if (container.dataset.cotizacionId) {
                        url.searchParams.set('cotizacion_id', container.dataset.cotizacionId);
                    }

                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (numeroConsulta !== consultaActual) return;
                    if (!response.ok) {
                        estado.textContent = 'No fue posible consultar las órdenes elegibles.';
                        return;
                    }

                    const ordenes = (await response.json()).data;
                    ordenes.forEach(orden => selector.add(new Option(orden.label, orden.value)));
                    if (seleccionAnterior && ordenes.some(orden => String(orden.value) === String(seleccionAnterior))) {
                        selector.value = seleccionAnterior;
                    }
                    estado.textContent = ordenes.length
                        ? 'Elige explícitamente una orden o conserva la opción de crear una nueva.'
                        : 'No hay órdenes activas elegibles para este cliente y equipo.';
                };

                window.addEventListener('ecore-searchable-select:change', event => {
                    if (!['cliente_id', 'equipo_id'].includes(event.detail.name)) return;
                    selector.value = '';
                    cargar();
                });

                cargar(true);
            });
        </script>
    @endif

    <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Recepción del equipo</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Indica dónde se recibió el equipo; aparecerá en la cotización.</p>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="tipo_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de recepción</label>
                <select
                    id="tipo_recepcion"
                    name="tipo_recepcion"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                >
                    @foreach (['en_negocio' => 'En negocio', 'recogido_a_domicilio' => 'Recogido a domicilio'] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected(old('tipo_recepcion', $cotizacion->tipo_recepcion ?? 'en_negocio') === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                @error('tipo_recepcion')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div id="campo-direccion-recepcion">
                <label for="direccion_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Dirección del cliente (recolección a domicilio)</label>
                <input
                    id="direccion_recepcion"
                    name="direccion_recepcion"
                    type="text"
                    maxlength="500"
                    value="{{ old('direccion_recepcion', $cotizacion->direccion_recepcion) }}"
                    placeholder="Calle, número, colonia, ciudad"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                >
                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Obligatoria solo si el equipo se recogió a domicilio.</p>
                @error('direccion_recepcion')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Conceptos</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">Agrega al menos un concepto. Los importes se recalculan en el servidor al guardar.</p>
        </div>

        @error('items')
            <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="mt-5 grid gap-4">
            @foreach ($itemRows as $index => $row)
                <div class="grid gap-3 rounded-md border border-neutral-200 p-4 dark:border-neutral-700 lg:grid-cols-12">
                    @if (filled($row['id'] ?? null))
                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $row['id'] }}">
                    @endif
                    <div class="lg:col-span-2">
                        <label for="items_{{ $index }}_tipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo</label>
                        <select
                            id="items_{{ $index }}_tipo"
                            name="items[{{ $index }}][tipo]"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                            @foreach (['servicio' => 'Servicio', 'refaccion' => 'Refacción', 'producto' => 'Producto', 'otro' => 'Otro'] as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected(($row['tipo'] ?? 'servicio') === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        @error("items.$index.tipo")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="{{ auth()->user()->isAdmin() ? 'lg:col-span-3' : 'lg:col-span-5' }}">
                        <label for="items_{{ $index }}_descripcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descripción</label>
                        <input
                            id="items_{{ $index }}_descripcion"
                            name="items[{{ $index }}][descripcion]"
                            type="text"
                            maxlength="255"
                            value="{{ $row['descripcion'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                        @error("items.$index.descripcion")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-2">
                        <label for="items_{{ $index }}_cantidad" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cantidad</label>
                        <input
                            id="items_{{ $index }}_cantidad"
                            name="items[{{ $index }}][cantidad]"
                            type="number"
                            min="1"
                            value="{{ $row['cantidad'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                        @error("items.$index.cantidad")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-3">
                        <label for="items_{{ $index }}_precio_unitario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Precio unitario</label>
                        <input
                            id="items_{{ $index }}_precio_unitario"
                            name="items[{{ $index }}][precio_unitario]"
                            type="number"
                            min="0"
                            step="0.01"
                            value="{{ $row['precio_unitario'] ?? '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        >
                        @error("items.$index.precio_unitario")
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    @if (auth()->user()->isAdmin())
                        <div class="lg:col-span-2">
                            <label for="items_{{ $index }}_costo_unitario" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Costo interno unitario</label>
                            <input
                                id="items_{{ $index }}_costo_unitario"
                                name="items[{{ $index }}][costo_unitario]"
                                type="number"
                                min="0"
                                step="0.01"
                                value="{{ $row['costo_unitario'] ?? '' }}"
                                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                            >
                            @error("items.$index.costo_unitario")
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="descuento" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Descuento</label>
            <input
                id="descuento"
                name="descuento"
                type="number"
                min="0"
                step="0.01"
                value="{{ old('descuento', $cotizacion->exists ? $cotizacion->descuento : '0') }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @error('descuento')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="anticipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Anticipo</label>
            <input
                id="anticipo"
                name="anticipo"
                type="number"
                min="0"
                step="0.01"
                value="{{ old('anticipo', $cotizacion->exists ? $cotizacion->anticipo : '0') }}"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            >
            @error('anticipo')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas</label>
        <textarea
            id="notas"
            name="notas"
            rows="4"
            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        >{{ old('notas', $cotizacion->notas) }}</textarea>
        @error('notas')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

<section class="mt-6 border-y border-neutral-200 py-5 dark:border-neutral-700" aria-label="Resumen estimado">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div><p class="text-xs font-medium uppercase text-neutral-500">Subtotal</p><p id="quote-subtotal" class="mt-1 text-lg font-semibold">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Descuento</p><p id="quote-discount" class="mt-1 text-lg font-semibold text-red-700 dark:text-red-300">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Total</p><p id="quote-total" class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Anticipo</p><p id="quote-advance" class="mt-1 text-lg font-semibold">$0.00</p></div>
        <div><p class="text-xs font-medium uppercase text-neutral-500">Saldo</p><p id="quote-balance" class="mt-1 text-lg font-semibold">$0.00</p></div>
    </div>
    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">Vista previa. Los importes se recalculan en el servidor al guardar.</p>
</section>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
        Guardar cotización
    </button>

    <a href="{{ route($routePrefix.'.cotizaciones.index') }}" class="text-sm font-medium text-neutral-700 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100">
        Volver al listado
    </a>
</div>

<script>
    const quoteForm = document.currentScript.closest('form');
    document.addEventListener('DOMContentLoaded', () => {
        const form = quoteForm;
        if (!form) return;
        const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
        const calculate = () => {
            let subtotal = 0;
            form.querySelectorAll('input[name^="items"][name$="[descripcion]"]').forEach(input => {
                if (!input.value.trim()) return;
                const index = input.name.match(/\[(\d+)\]/)[1];
                const quantity = Number(form.querySelector(`[name="items[${index}][cantidad]"]`)?.value) || 0;
                const price = Number(form.querySelector(`[name="items[${index}][precio_unitario]"]`)?.value) || 0;
                subtotal += quantity * price;
            });
            const discount = Number(form.querySelector('[name="descuento"]')?.value) || 0;
            const advance = Number(form.querySelector('[name="anticipo"]')?.value) || 0;
            const total = Math.max(subtotal - discount, 0);
            document.getElementById('quote-subtotal').textContent = money(subtotal);
            document.getElementById('quote-discount').textContent = money(discount);
            document.getElementById('quote-total').textContent = money(total);
            document.getElementById('quote-advance').textContent = money(advance);
            document.getElementById('quote-balance').textContent = money(Math.max(total - advance, 0));
        };
        form.addEventListener('input', calculate);
        form.addEventListener('change', calculate);
        calculate();

        const receptionType = form.querySelector('#tipo_recepcion');
        const addressField = form.querySelector('#campo-direccion-recepcion');
        const toggleAddress = () => {
            if (!receptionType || !addressField) return;
            addressField.style.display = receptionType.value === 'recogido_a_domicilio' ? '' : 'none';
        };
        receptionType?.addEventListener('change', toggleAddress);
        toggleAddress();
    });
</script>
