@props([
    'id',
    'name',
    'label',
    'url',
    'placeholder' => 'Escribe para buscar',
    'emptyLabel' => 'Sin resultados',
    'selectedId' => null,
    'selectedLabel' => null,
    'dependsOn' => null,
    'required' => false,
])

<div
    data-searchable-select
    data-url="{{ $url }}"
    @if ($dependsOn) data-depends-on="{{ $dependsOn }}" @endif
>
    <label for="{{ $id }}_search" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $label }}</label>
    <input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ $selectedId }}" @if ($required) required @endif>
    <input
        id="{{ $id }}_search"
        type="search"
        value="{{ $selectedLabel }}"
        class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded="false"
        aria-controls="{{ $id }}_results"
    >
    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400" data-searchable-hint>Escribe para buscar; sin texto muestra los más recientes.</p>
    <ul id="{{ $id }}_results" class="mt-1 hidden max-h-52 overflow-auto rounded-md border border-neutral-200 bg-white py-1 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" role="listbox"></ul>
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-searchable-select]').forEach(container => {
                const hidden = container.querySelector('input[type="hidden"]');
                const input = container.querySelector('input[type="search"]');
                const results = container.querySelector('[role="listbox"]');
                const hint = container.querySelector('[data-searchable-hint]');
                const dependency = container.dataset.dependsOn;
                let timer;

                const setEnabled = enabled => {
                    input.disabled = !enabled;
                    input.placeholder = enabled ? 'Escribe para buscar' : 'Selecciona primero un cliente';
                };

                const close = () => {
                    results.hidden = true;
                    input.setAttribute('aria-expanded', 'false');
                };

                const select = item => {
                    hidden.value = item.value;
                    input.value = item.label;
                    close();
                    window.dispatchEvent(new CustomEvent('ecore-searchable-select:change', {
                        detail: { name: hidden.name, value: item.value },
                    }));
                };

                const search = async () => {
                    const selectedDependency = dependency
                        ? document.querySelector(`[name="${dependency}"]`)?.value
                        : null;

                    if (dependency && !selectedDependency) return;

                    const url = new URL(container.dataset.url.replace('{cliente}', selectedDependency || ''), window.location.origin);
                    url.searchParams.set('q', input.value);
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const items = response.ok ? (await response.json()).data : [];

                    results.replaceChildren(...items.map(item => {
                        const option = document.createElement('li');
                        option.className = 'cursor-pointer px-3 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-neutral-800';
                        option.role = 'option';
                        option.textContent = item.label;
                        option.addEventListener('mousedown', event => {
                            event.preventDefault();
                            select(item);
                        });
                        return option;
                    }));

                    if (!items.length) {
                        const empty = document.createElement('li');
                        empty.className = 'px-3 py-2 text-sm text-neutral-500';
                        empty.textContent = '{{ $emptyLabel }}';
                        results.append(empty);
                    }

                    results.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                };

                setEnabled(!dependency || Boolean(document.querySelector(`[name="${dependency}"]`)?.value));
                input.addEventListener('focus', () => search());
                input.addEventListener('input', () => {
                    hidden.value = '';
                    clearTimeout(timer);
                    timer = setTimeout(search, 200);
                });
                input.addEventListener('blur', () => setTimeout(close, 150));
                window.addEventListener('ecore-searchable-select:change', event => {
                    if (event.detail.name !== dependency) return;
                    hidden.value = '';
                    input.value = '';
                    setEnabled(Boolean(event.detail.value));
                    close();
                    hint.textContent = event.detail.value
                        ? 'Escribe para buscar equipos de este cliente; sin texto muestra los más recientes.'
                        : 'Selecciona primero un cliente.';
                });
            });
        });
    </script>
@endonce
