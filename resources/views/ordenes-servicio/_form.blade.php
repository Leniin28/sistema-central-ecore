@csrf

@error('orden')
    <div class="border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{{ $message }}</div>
@enderror

@php
    $detallesCotizacion = $orden->exists ? $orden->detalles->whereNotNull('cotizacion_item_id')->values() : collect();
    $refaccionesCotizacion = $orden->exists ? $orden->refacciones->whereNotNull('cotizacion_item_id')->values() : collect();
    $trabajoVinculadoBloqueado = $orden->exists && $orden->cotizacion?->estado === 'aceptada';
    $servicioRows = old('servicios');

    if ($servicioRows === null) {
        $servicioRows = $orden->exists
            ? $orden->detalles->whereNull('cotizacion_item_id')->map(fn ($detalle) => [
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

    if ($servicioRows === []) {
        $servicioRows[] = [];
    }

    $refaccionesReadonly = $orden->exists && $orden->finanzas_generadas;
    $refaccionRows = $refaccionesReadonly ? null : old('refacciones');

    if ($refaccionRows === null) {
        $refaccionRows = $orden->exists
            ? $orden->refacciones->whereNull('cotizacion_item_id')->map(fn ($refaccion) => [
                'descripcion' => $refaccion->descripcion,
                'cantidad' => $refaccion->cantidad,
                'costo_unitario' => $refaccion->costo_unitario,
                'precio_unitario_cliente' => $refaccion->precio_unitario_cliente,
                'notas' => $refaccion->notas,
            ])->values()->all()
            : [];
    }

    $refaccionRows = array_values($refaccionRows);

    if ($refaccionRows === []) {
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
            @disabled($trabajoVinculadoBloqueado)
        >
            <option value="">Selecciona un cliente</option>
            @foreach ($clientes as $cliente)
                <option value="{{ $cliente->id }}" @selected((int) old('cliente_id', $orden->cliente_id) === $cliente->id)>
                    {{ $cliente->nombre }} - {{ $cliente->telefono }}
                </option>
            @endforeach
        </select>
        @if ($trabajoVinculadoBloqueado)<input type="hidden" name="cliente_id" value="{{ $orden->cliente_id }}">@endif
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
            @disabled($trabajoVinculadoBloqueado)
        >
            <option value="">Sin equipo asignado</option>
            @foreach ($equipos as $equipo)
                <option value="{{ $equipo->id }}" @selected((int) old('equipo_id', $orden->equipo_id) === $equipo->id)>
                    {{ $equipo->cliente?->nombre }} - {{ $equipo->tipo_equipo }} {{ $equipo->marca }} {{ $equipo->modelo }}
                </option>
            @endforeach
        </select>
        @if ($trabajoVinculadoBloqueado)<input type="hidden" name="equipo_id" value="{{ $orden->equipo_id }}">@endif
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

        <div>
            <label for="comision_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Comisión de recepción</label>
            <input id="comision_recepcion" name="comision_recepcion" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('comision_recepcion', $orden->comision_recepcion) }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Vacío significa pendiente. En esta fase el dato no participa en las finanzas legacy.</p>
            @error('comision_recepcion')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    @else
        <div class="rounded-md border border-neutral-200 p-4 text-sm text-neutral-700 dark:border-neutral-700 dark:text-neutral-300">
            Partner de recepción: {{ auth()->user()->partner?->nombre ?? 'Sin partner asignado' }}
        </div>
    @endif

    <div>
        <label for="nota_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nota de recepción</label>
        <input id="nota_recepcion" name="nota_recepcion" maxlength="255" value="{{ old('nota_recepcion', $orden->nota_recepcion) }}" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">
        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Referencia interna opcional, por ejemplo sucursal o persona que recibió el equipo.</p>
        @error('nota_recepcion')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

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
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    Déjalo vacío mientras el costo esté pendiente. Escribe 0 únicamente si Fixop confirmó que no cobrará el trabajo.
                </p>
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

        <div class="mt-5 grid gap-4" data-service-rows>
            @foreach ($servicioRows as $index => $row)
                @include('ordenes-servicio._servicio-row', ['isTemplate' => false])
            @endforeach
        </div>

        <button type="button" data-add-row="service" class="mt-4 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800">
            + Agregar servicio
        </button>

        <template data-row-template="service">
            @include('ordenes-servicio._servicio-row', ['index' => '__INDEX__', 'row' => [], 'isTemplate' => true])
        </template>
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

        <div class="mt-5 grid gap-4" data-part-rows>
            @foreach ($refaccionRows as $index => $row)
                @include('ordenes-servicio._refaccion-row', ['isTemplate' => false])
            @endforeach
        </div>

        @unless ($refaccionesReadonly)
            <button type="button" data-add-row="part" class="mt-4 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800">
                + Agregar refacción
            </button>

            <template data-row-template="part">
                @include('ordenes-servicio._refaccion-row', ['index' => '__INDEX__', 'row' => [], 'isTemplate' => true])
            </template>
        @endunless
    </section>
</div>

@if ($detallesCotizacion->isNotEmpty() || $refaccionesCotizacion->isNotEmpty())
    <section class="mt-5 rounded-lg border border-blue-200 bg-blue-50/50 p-5 dark:border-blue-900 dark:bg-blue-950/20">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Conceptos administrados por cotización</h2>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">Se muestran sólo como referencia. Edítalos desde la cotización para conservar su trazabilidad.</p>
            </div>
            @if ($orden->cotizacion)
                <a href="{{ route('admin.cotizaciones.show', $orden->cotizacion) }}" class="text-sm font-semibold text-blue-700 underline dark:text-blue-300">Ver cotización {{ $orden->cotizacion->folio }}</a>
            @endif
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-neutral-600 dark:text-neutral-400"><th class="py-2 pr-4">Tipo</th><th class="py-2 pr-4">Descripción</th><th class="py-2 pr-4 text-right">Cantidad</th><th class="py-2 text-right">Precio</th></tr></thead>
                <tbody class="divide-y divide-blue-100 dark:divide-blue-900">
                    @foreach ($detallesCotizacion as $linea)
                        <tr><td class="py-2 pr-4">Servicio</td><td class="py-2 pr-4">{{ $linea->descripcion }}</td><td class="py-2 pr-4 text-right">{{ $linea->cantidad }}</td><td class="py-2 text-right">${{ number_format($linea->precio_unitario, 2) }}</td></tr>
                    @endforeach
                    @foreach ($refaccionesCotizacion as $linea)
                        <tr><td class="py-2 pr-4">Refacción</td><td class="py-2 pr-4">{{ $linea->descripcion }}</td><td class="py-2 pr-4 text-right">{{ $linea->cantidad }}</td><td class="py-2 text-right">${{ number_format($linea->precio_unitario_cliente, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

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
        const tracedServices = {{ Illuminate\Support\Js::from((float) $detallesCotizacion->sum('subtotal')) }};
        const tracedParts = {{ Illuminate\Support\Js::from((float) $refaccionesCotizacion->sum('precio_total_cliente')) }};
        const tracedPartCosts = {{ Illuminate\Support\Js::from((float) $refaccionesCotizacion->sum('costo_total')) }};
        const rowTypes = {
            service: {
                list: form.querySelector('[data-service-rows]'),
                template: form.querySelector('[data-row-template="service"]'),
                rowSelector: '[data-service-row]',
            },
            part: {
                list: form.querySelector('[data-part-rows]'),
                template: form.querySelector('[data-row-template="part"]'),
                rowSelector: '[data-part-row]',
            },
        };

        const nextIndex = config => Math.max(
            -1,
            ...Array.from(config.list.querySelectorAll(config.rowSelector))
                .map(row => Number(row.dataset.rowIndex))
                .filter(Number.isFinite),
        ) + 1;

        const syncRows = config => {
            const rows = Array.from(config.list.querySelectorAll(config.rowSelector));
            rows.forEach((row, index) => {
                const number = row.querySelector('[data-row-number]');
                if (number) number.textContent = index + 1;
                const remove = row.querySelector('[data-remove-row]');
                if (remove) remove.disabled = rows.length === 1;
            });
        };

        const addRow = type => {
            const config = rowTypes[type];
            if (!config?.template) return;
            const index = nextIndex(config);
            config.list.insertAdjacentHTML('beforeend', config.template.innerHTML.replaceAll('__INDEX__', String(index)));
            syncRows(config);
            config.list.querySelector(`${config.rowSelector}:last-child [data-row-primary]`)?.focus();
        };

        const calculate = () => {
            let services = tracedServices, parts = tracedParts, costs = tracedPartCosts;
            rowTypes.service.list.querySelectorAll(rowTypes.service.rowSelector).forEach(row => {
                if (!row.querySelector('[name$="[descripcion]"]')?.value.trim()) return;
                const quantity = Number(row.querySelector('[name$="[cantidad]"]')?.value) || 0;
                const price = Number(row.querySelector('[name$="[precio_unitario]"]')?.value) || 0;
                services += quantity * price;
            });
            rowTypes.part.list.querySelectorAll(rowTypes.part.rowSelector).forEach(row => {
                if (!row.querySelector('[name$="[descripcion]"]')?.value.trim()) return;
                const quantity = Number(row.querySelector('[name$="[cantidad]"]')?.value) || 0;
                costs += quantity * (Number(row.querySelector('[name$="[costo_unitario]"]')?.value) || 0);
                parts += quantity * (Number(row.querySelector('[name$="[precio_unitario_cliente]"]')?.value) || 0);
            });
            document.getElementById('order-total-services').textContent = money(services);
            document.getElementById('order-total-parts').textContent = money(parts);
            document.getElementById('order-cost-parts').textContent = money(costs);
            document.getElementById('order-total').textContent = money(services + parts);
        };
        form.addEventListener('click', event => {
            if (!(event.target instanceof Element)) return;
            const addButton = event.target.closest('[data-add-row]');
            if (addButton) {
                addRow(addButton.dataset.addRow);
                return;
            }

            const removeButton = event.target.closest('[data-remove-row]');
            if (!removeButton || removeButton.disabled) return;
            const config = rowTypes[removeButton.dataset.removeRow];
            removeButton.closest(config.rowSelector)?.remove();
            syncRows(config);
            calculate();
        });
        form.addEventListener('change', event => {
            if (event.target.matches('select[name^="servicios"][name$="[servicio_id]"]')) {
                const row = event.target.closest('[data-service-row]');
                const price = row.querySelector('[name$="[precio_unitario]"]');
                if (event.target.value && !price.value) price.value = event.target.selectedOptions[0].dataset.price || '';
                const description = row.querySelector('[name$="[descripcion]"]');
                if (event.target.value && description && !description.value) description.value = event.target.selectedOptions[0].dataset.name || '';
            }
            calculate();
        });
        form.addEventListener('input', calculate);
        Object.values(rowTypes).forEach(syncRows);
        calculate();
    });
</script>
