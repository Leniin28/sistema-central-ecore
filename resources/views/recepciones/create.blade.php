<x-layouts::app :title="__('Nueva recepción')">
    @php
        $servicioRows = array_values(old('servicios', [[]]));
        $refaccionRows = array_values(old('refacciones', [[]]));
        while (count($servicioRows) < 5) { $servicioRows[] = []; }
        while (count($refaccionRows) < 5) { $refaccionRows[] = []; }
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 px-1 pb-10">
        <header class="flex flex-col gap-4 border-b border-neutral-200 pb-5 dark:border-neutral-700 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-medium text-blue-700 dark:text-blue-300">Recepción operativa</p>
                <h1 class="mt-1 text-2xl font-semibold text-neutral-950 dark:text-white">Nueva recepción</h1>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">Registra cliente, equipo y orden en un solo flujo.</p>
            </div>
            <a href="{{ route($routePrefix.'.ordenes-servicio.index') }}" class="text-sm font-medium text-neutral-600 hover:text-neutral-950 dark:text-neutral-300 dark:hover:text-white">Volver a órdenes</a>
        </header>

        @if ($errors->any())
            <div class="border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200" role="alert">
                <p class="font-semibold">Revisa los datos marcados antes de guardar.</p>
                <p class="mt-1">{{ $errors->first() }}</p>
            </div>
        @endif

        <form id="recepcion-form" method="POST" action="{{ route($routePrefix.'.recepciones.store') }}" class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_19rem]">
            @csrf

            <div class="min-w-0 space-y-10">
                <section aria-labelledby="cliente-heading">
                    <div class="flex items-center gap-3 border-b border-neutral-200 pb-3 dark:border-neutral-700">
                        <span class="flex size-7 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">1</span>
                        <div>
                            <h2 id="cliente-heading" class="font-semibold text-neutral-950 dark:text-white">Cliente</h2>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">Selecciona un registro o captura uno nuevo.</p>
                        </div>
                    </div>

                    <div class="mt-5 inline-flex rounded-md border border-neutral-300 p-1 dark:border-neutral-700" data-mode-group="cliente">
                        <label class="cursor-pointer rounded px-3 py-1.5 text-sm font-medium has-[:checked]:bg-neutral-900 has-[:checked]:text-white dark:has-[:checked]:bg-white dark:has-[:checked]:text-neutral-900">
                            <input class="sr-only" type="radio" name="cliente_modo" value="existente" @checked(old('cliente_modo', 'existente') === 'existente')>
                            Existente
                        </label>
                        <label class="cursor-pointer rounded px-3 py-1.5 text-sm font-medium has-[:checked]:bg-neutral-900 has-[:checked]:text-white dark:has-[:checked]:bg-white dark:has-[:checked]:text-neutral-900">
                            <input class="sr-only" type="radio" name="cliente_modo" value="nuevo" @checked(old('cliente_modo') === 'nuevo')>
                            Nuevo cliente
                        </label>
                    </div>

                    <div class="mt-5" data-mode-panel="cliente-existente">
                        <x-searchable-select
                            id="cliente_id"
                            name="cliente_id"
                            label="Cliente registrado"
                            :url="route($routePrefix.'.clientes.buscar')"
                            :selected-id="$clienteSeleccionado?->id"
                            :selected-label="$clienteSeleccionado ? $clienteSeleccionado->nombre.' · '.$clienteSeleccionado->telefono : null"
                            required
                        />
                        @error('cliente_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2" data-mode-panel="cliente-nuevo">
                        <div>
                            <label for="cliente_nombre" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nombre completo</label>
                            <input id="cliente_nombre" name="cliente[nombre]" value="{{ old('cliente.nombre') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">
                            @error('cliente.nombre') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="cliente_telefono" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Teléfono</label>
                            <input id="cliente_telefono" name="cliente[telefono]" value="{{ old('cliente.telefono') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">
                            @error('cliente.telefono') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="cliente_correo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Correo electrónico</label>
                            <input id="cliente_correo" type="email" name="cliente[correo]" value="{{ old('cliente.correo') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">
                        </div>
                        <div>
                            <label for="cliente_tipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de cliente</label>
                            <select id="cliente_tipo" name="cliente[tipo_cliente]" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="mantenimiento" @selected(old('cliente.tipo_cliente', 'mantenimiento') === 'mantenimiento')>Mantenimiento</option>
                                <option value="marketing" @selected(old('cliente.tipo_cliente') === 'marketing')>Marketing</option>
                                <option value="ambos" @selected(old('cliente.tipo_cliente') === 'ambos')>Ambos</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="cliente_notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas del cliente</label>
                            <textarea id="cliente_notas" name="cliente[notas]" rows="2" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">{{ old('cliente.notas') }}</textarea>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="equipo-heading">
                    <div class="flex items-center gap-3 border-b border-neutral-200 pb-3 dark:border-neutral-700">
                        <span class="flex size-7 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">2</span>
                        <div><h2 id="equipo-heading" class="font-semibold text-neutral-950 dark:text-white">Equipo</h2><p class="text-sm text-neutral-500 dark:text-neutral-400">Asocia un equipo del cliente o registra uno nuevo.</p></div>
                    </div>

                    <div class="mt-5 inline-flex rounded-md border border-neutral-300 p-1 dark:border-neutral-700" data-mode-group="equipo">
                        <label class="cursor-pointer rounded px-3 py-1.5 text-sm font-medium has-[:checked]:bg-neutral-900 has-[:checked]:text-white dark:has-[:checked]:bg-white dark:has-[:checked]:text-neutral-900">
                            <input class="sr-only" type="radio" name="equipo_modo" value="existente" @checked(old('equipo_modo', 'existente') === 'existente')> Existente
                        </label>
                        <label class="cursor-pointer rounded px-3 py-1.5 text-sm font-medium has-[:checked]:bg-neutral-900 has-[:checked]:text-white dark:has-[:checked]:bg-white dark:has-[:checked]:text-neutral-900">
                            <input class="sr-only" type="radio" name="equipo_modo" value="nuevo" @checked(old('equipo_modo') === 'nuevo')> Nuevo equipo
                        </label>
                    </div>

                    <div class="mt-5" data-mode-panel="equipo-existente">
                        <x-searchable-select
                            id="equipo_id"
                            name="equipo_id"
                            label="Equipo registrado"
                            :url="str_replace('__CLIENTE__', '{cliente}', route($routePrefix.'.clientes.equipos.buscar', '__CLIENTE__'))"
                            :selected-id="$equipoSeleccionado?->id"
                            :selected-label="$equipoSeleccionado ? $equipoSeleccionado->tipo_equipo.' · '.$equipoSeleccionado->marca.' '.$equipoSeleccionado->modelo : null"
                            depends-on="cliente_id"
                            required
                        />
                        @error('equipo_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2" data-mode-panel="equipo-nuevo">
                        <div><label for="equipo_tipo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de equipo</label><input id="equipo_tipo" name="equipo[tipo_equipo]" value="{{ old('equipo.tipo_equipo') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                        <div><label for="equipo_marca" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Marca</label><input id="equipo_marca" name="equipo[marca]" value="{{ old('equipo.marca') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                        <div><label for="equipo_modelo" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Modelo</label><input id="equipo_modelo" name="equipo[modelo]" value="{{ old('equipo.modelo') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                        <div><label for="equipo_serie" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Número de serie</label><input id="equipo_serie" name="equipo[numero_serie]" value="{{ old('equipo.numero_serie') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                        <div><label for="equipo_accesorios" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Accesorios recibidos</label><input id="equipo_accesorios" name="equipo[accesorios_recibidos]" value="{{ old('equipo.accesorios_recibidos') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                        <div><label for="equipo_password" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Contraseña del equipo <span class="font-normal text-neutral-500">(sensible)</span></label><input id="equipo_password" type="password" autocomplete="new-password" name="equipo[password_equipo]" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                        <div class="sm:col-span-2"><label for="equipo_estado" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Estado físico y observaciones</label><textarea id="equipo_estado" name="equipo[estado_fisico_inicial]" rows="2" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">{{ old('equipo.estado_fisico_inicial') }}</textarea></div>
                    </div>
                </section>

                <section aria-labelledby="orden-heading">
                    <div class="flex items-center gap-3 border-b border-neutral-200 pb-3 dark:border-neutral-700"><span class="flex size-7 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">3</span><div><h2 id="orden-heading" class="font-semibold text-neutral-950 dark:text-white">Orden de servicio</h2><p class="text-sm text-neutral-500 dark:text-neutral-400">La orden iniciará en estado Recibido.</p></div></div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div><label for="tipo_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Tipo de recepción</label><select id="tipo_recepcion" name="orden[tipo_recepcion]" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"><option value="sucursal">Sucursal</option><option value="domicilio" @selected(old('orden.tipo_recepcion') === 'domicilio')>Domicilio</option><option value="directo" @selected(old('orden.tipo_recepcion') === 'directo')>Directo</option></select></div>
                        @if (auth()->user()->isAdmin())
                            <div><label for="partner_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Punto / socio de recepción (opcional)</label><select id="partner_recepcion" name="orden[partner_recepcion_id]" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"><option value="" data-commission="">Sin partner</option>@foreach ($partnersRecepcion as $partner)<option value="{{ $partner->id }}" data-commission="{{ $partner->comision_fija }}" @selected((int) old('orden.partner_recepcion_id') === $partner->id)>{{ $partner->nombre }}</option>@endforeach</select></div>
                            <div><label for="comision_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Comisión de recepción</label><input id="comision_recepcion" name="orden[comision_recepcion]" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('orden.comision_recepcion') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"><p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Vacío significa pendiente. La sugerencia del partner es editable.</p>@error('orden.comision_recepcion') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                        @else
                            <div class="border-l-2 border-blue-500 pl-3 text-sm"><p class="text-neutral-500 dark:text-neutral-400">Partner logístico</p><p class="font-medium text-neutral-950 dark:text-white">{{ auth()->user()->partner?->nombre ?? 'Sin partner asignado' }}</p></div>
                        @endif
                        <div><label for="partner_tecnico" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Partner técnico</label><select id="partner_tecnico" name="orden[partner_tecnico_id]" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"><option value="">Por asignar</option>@foreach ($partnersTecnicos as $partner)<option value="{{ $partner->id }}" @selected((int) old('orden.partner_tecnico_id') === $partner->id)>{{ $partner->nombre }}</option>@endforeach</select></div>
                        <div class="sm:col-span-2"><label for="nota_recepcion" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Nota de recepción</label><input id="nota_recepcion" name="orden[nota_recepcion]" maxlength="255" value="{{ old('orden.nota_recepcion') }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"><p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Referencia interna opcional, por ejemplo sucursal o persona que recibió el equipo.</p>@error('orden.nota_recepcion') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                        <div class="sm:col-span-2"><label for="problema_reportado" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Problema reportado</label><textarea id="problema_reportado" name="orden[problema_reportado]" rows="3" required class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" placeholder="Describe lo que reporta el cliente y el servicio solicitado.">{{ old('orden.problema_reportado') }}</textarea>@error('orden.problema_reportado') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                        <div class="sm:col-span-2"><label for="orden_notas" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Notas internas</label><textarea id="orden_notas" name="orden[notas]" rows="2" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">{{ old('orden.notas') }}</textarea></div>
                    </div>
                </section>

                <section aria-labelledby="servicios-heading">
                    <div class="flex items-center justify-between border-b border-neutral-200 pb-3 dark:border-neutral-700"><div class="flex items-center gap-3"><span class="flex size-7 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">4</span><div><h2 id="servicios-heading" class="font-semibold text-neutral-950 dark:text-white">Servicios solicitados</h2><p class="text-sm text-neutral-500 dark:text-neutral-400">Opcional. Puedes agregarlos ahora o después del diagnóstico.</p></div></div></div>
                    @error('servicios') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-4 divide-y divide-neutral-200 border-y border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
                        @foreach ($servicioRows as $index => $row)
                            <div class="grid gap-3 py-4 md:grid-cols-[minmax(12rem,2fr)_6rem_8rem_minmax(10rem,1fr)]" data-service-row>
                                <div><label class="block text-xs font-medium text-neutral-500">Servicio {{ $index + 1 }}</label><select name="servicios[{{ $index }}][servicio_id]" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-service-select><option value="">Sin servicio</option>@foreach ($servicios as $servicio)<option value="{{ $servicio->id }}" data-price="{{ $servicio->precio_base }}" @selected((int) ($row['servicio_id'] ?? 0) === $servicio->id)>{{ $servicio->nombre }} · ${{ number_format($servicio->precio_base, 2) }}</option>@endforeach</select></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Cantidad</label><input type="number" min="1" name="servicios[{{ $index }}][cantidad]" value="{{ $row['cantidad'] ?? 1 }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-quantity></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Precio</label><input type="number" min="0" step="0.01" name="servicios[{{ $index }}][precio_unitario]" value="{{ $row['precio_unitario'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-service-price></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Notas</label><input name="servicios[{{ $index }}][notas]" value="{{ $row['notas'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section aria-labelledby="refacciones-heading">
                    <div class="flex items-center gap-3 border-b border-neutral-200 pb-3 dark:border-neutral-700"><span class="flex size-7 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">5</span><div><h2 id="refacciones-heading" class="font-semibold text-neutral-950 dark:text-white">Refacciones</h2><p class="text-sm text-neutral-500 dark:text-neutral-400">Opcional. Registra costo y precio al cliente.</p></div></div>
                    <div class="mt-4 divide-y divide-neutral-200 border-y border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
                        @foreach ($refaccionRows as $index => $row)
                            <div class="grid gap-3 py-4 md:grid-cols-[minmax(11rem,2fr)_5rem_8rem_8rem_minmax(9rem,1fr)]" data-part-row>
                                <div><label class="block text-xs font-medium text-neutral-500">Descripción {{ $index + 1 }}</label><input name="refacciones[{{ $index }}][descripcion]" value="{{ $row['descripcion'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-part-description></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Cantidad</label><input type="number" min="1" name="refacciones[{{ $index }}][cantidad]" value="{{ $row['cantidad'] ?? 1 }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-quantity></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Costo</label><input type="number" min="0" step="0.01" name="refacciones[{{ $index }}][costo_unitario]" value="{{ $row['costo_unitario'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-part-cost></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Precio cliente</label><input type="number" min="0" step="0.01" name="refacciones[{{ $index }}][precio_unitario_cliente]" value="{{ $row['precio_unitario_cliente'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" data-part-price></div>
                                <div><label class="block text-xs font-medium text-neutral-500">Notas</label><input name="refacciones[{{ $index }}][notas]" value="{{ $row['notas'] ?? '' }}" class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"></div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="xl:sticky xl:top-6 xl:self-start" aria-label="Resumen de recepción">
                <div class="border-t-4 border-neutral-900 bg-neutral-50 p-5 dark:border-white dark:bg-neutral-900">
                    <h2 class="font-semibold text-neutral-950 dark:text-white">Resumen estimado</h2>
                    <dl class="mt-5 space-y-3 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-neutral-500 dark:text-neutral-400">Servicios</dt><dd id="summary-services" class="font-medium">$0.00</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-neutral-500 dark:text-neutral-400">Refacciones</dt><dd id="summary-parts" class="font-medium">$0.00</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-neutral-500 dark:text-neutral-400">Costo refacciones</dt><dd id="summary-parts-cost" class="font-medium text-red-700 dark:text-red-300">$0.00</dd></div>
                        @if (auth()->user()->isAdmin())
                            <div class="flex justify-between gap-4"><dt class="text-neutral-500 dark:text-neutral-400">Comisión de recepción</dt><dd id="summary-commission" class="font-medium text-red-700 dark:text-red-300">Pendiente</dd></div>
                        @endif
                        <div class="flex justify-between gap-4 border-t border-neutral-300 pt-3 dark:border-neutral-700"><dt class="font-semibold">Total al cliente</dt><dd id="summary-total" class="text-lg font-semibold">$0.00</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-neutral-500 dark:text-neutral-400">Utilidad estimada</dt><dd id="summary-profit" class="font-semibold text-emerald-700 dark:text-emerald-300">$0.00</dd></div>
                    </dl>
                    <p class="mt-4 text-xs leading-5 text-neutral-500 dark:text-neutral-400">Estimación previa. La comisión de recepción queda registrada, pero todavía no participa en las finanzas legacy.</p>
                    <button type="submit" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-semibold text-white hover:bg-neutral-700 focus:outline-none focus:ring-2 focus:ring-neutral-500 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200">Guardar recepción</button>
                </div>
            </aside>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('recepcion-form');
            const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
            const commissionInput = document.getElementById('comision_recepcion');
            let commissionTouched = commissionInput?.value !== '';

            const syncModes = () => {
                ['cliente', 'equipo'].forEach(group => {
                    const value = form.querySelector(`input[name="${group}_modo"]:checked`)?.value;
                    form.querySelectorAll(`[data-mode-panel^="${group}-"]`).forEach(panel => {
                        panel.hidden = !panel.matches(`[data-mode-panel="${group}-${value}"]`);
                        panel.querySelectorAll('input, select, textarea').forEach(field => field.disabled = panel.hidden);
                    });
                });
                if (form.querySelector('input[name="cliente_modo"]:checked')?.value === 'nuevo') {
                    const newEquipment = form.querySelector('input[name="equipo_modo"][value="nuevo"]');
                    if (newEquipment && !newEquipment.checked) { newEquipment.checked = true; syncModes(); }
                }
            };

            const calculate = () => {
                let services = 0, parts = 0, costs = 0;
                form.querySelectorAll('[data-service-row]').forEach(row => {
                    if (!row.querySelector('[data-service-select]').value) return;
                    services += (Number(row.querySelector('[data-quantity]').value) || 0) * (Number(row.querySelector('[data-service-price]').value) || 0);
                });
                form.querySelectorAll('[data-part-row]').forEach(row => {
                    if (!row.querySelector('[data-part-description]').value.trim()) return;
                    const quantity = Number(row.querySelector('[data-quantity]').value) || 0;
                    costs += quantity * (Number(row.querySelector('[data-part-cost]').value) || 0);
                    parts += quantity * (Number(row.querySelector('[data-part-price]').value) || 0);
                });
                const commission = Number(commissionInput?.value || 0);
                document.getElementById('summary-services').textContent = money(services);
                document.getElementById('summary-parts').textContent = money(parts);
                document.getElementById('summary-parts-cost').textContent = money(costs);
                if (document.getElementById('summary-commission')) {
                    document.getElementById('summary-commission').textContent = commissionInput?.value === '' ? 'Pendiente' : money(commission);
                }
                document.getElementById('summary-total').textContent = money(services + parts);
                document.getElementById('summary-profit').textContent = money(services + parts - costs);
            };

            form.addEventListener('change', event => {
                if (event.target.matches('input[name$="_modo"]')) syncModes();
                if (event.target.matches('#partner_recepcion') && commissionInput && !commissionTouched) {
                    commissionInput.value = event.target.selectedOptions[0]?.dataset.commission || '';
                }
                if (event.target.matches('[data-service-select]')) {
                    const row = event.target.closest('[data-service-row]');
                    const price = row.querySelector('[data-service-price]');
                    if (event.target.value && !price.value) price.value = event.target.selectedOptions[0].dataset.price || '';
                }
                calculate();
            });
            form.addEventListener('input', event => {
                if (event.target === commissionInput) commissionTouched = true;
                calculate();
            });
            syncModes(); calculate();
        });
    </script>
</x-layouts::app>
