<x-layouts::app :title="__('Equipos')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $equipo->tipo_equipo }} - {{ $equipo->marca }}</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Detalle del equipo registrado.</p>
            </div>

            <div class="flex gap-3">
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.equipos.edit', $equipo) }}" class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300">
                        Editar
                    </a>
                @endif

                <a href="{{ route($routePrefix.'.equipos.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-900">
                    Volver al listado
                </a>
            </div>
        </section>

        <section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Datos del equipo</h2>

            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Tipo de equipo</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->tipo_equipo }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Marca</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->marca }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Modelo</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->modelo ?? 'Sin modelo' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Numero de serie</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->numero_serie ?? 'Sin serie' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Password del equipo</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->password_equipo ?? 'Sin password' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Creado</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->created_at?->format('d/m/Y H:i') }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Estado fisico inicial</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->estado_fisico_inicial ?: 'Sin registro' }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Accesorios recibidos</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->accesorios_recibidos ?: 'Sin registro' }}</dd>
                </div>
            </dl>
        </section>

        <section class="max-w-4xl rounded-lg border border-neutral-200 p-5 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Cliente relacionado</h2>

            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Nombre</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->cliente?->nombre ?? 'Sin cliente' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Telefono</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->cliente?->telefono ?? 'Sin telefono' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Correo</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->cliente?->correo ?? 'Sin correo' }}</dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Tipo de cliente</dt>
                    <dd class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">{{ $equipo->cliente?->tipo_cliente ? ucfirst($equipo->cliente->tipo_cliente) : 'Sin tipo' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</x-layouts::app>
