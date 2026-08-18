<?php

namespace App\Http\Controllers;

use App\Actions\Recepciones\CrearRecepcion;
use App\Http\Requests\StoreRecepcionRequest;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\Partner;
use App\Models\Servicio;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RecepcionController extends Controller
{
    public function create(): View
    {
        abort_if(auth()->user()->hasRole('socio_tecnico'), 403);

        return view('recepciones.create', [
            'clienteSeleccionado' => Cliente::find(old('cliente_id')),
            'equipoSeleccionado' => Equipo::find(old('equipo_id')),
            'servicios' => Servicio::query()->with('categoriaServicio')->where('activo', true)->orderBy('nombre')->get(),
            'partnersRecepcion' => Partner::query()->where('tipo_socio', 'logistico')->where('activo', true)->orderBy('nombre')->get(),
            'partnersTecnicos' => Partner::query()->where('tipo_socio', 'tecnico')->where('activo', true)->orderBy('nombre')->get(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function store(StoreRecepcionRequest $request, CrearRecepcion $crearRecepcion): RedirectResponse
    {
        $orden = $crearRecepcion->ejecutar($request->validated(), $request->user());

        return redirect()
            ->route($this->routePrefix().'.ordenes-servicio.show', $orden)
            ->with('status', 'Recepción y orden creadas correctamente.');
    }

    private function routePrefix(): string
    {
        return auth()->user()->isAdmin() ? 'admin' : 'logistica';
    }
}
