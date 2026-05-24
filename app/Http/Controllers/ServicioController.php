<?php

namespace App\Http\Controllers;

use App\Models\CategoriaServicio;
use App\Models\Servicio;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServicioController extends Controller
{
    /**
     * Display a listing of services.
     */
    public function index(): View
    {
        $servicios = Servicio::with('categoriaServicio')->latest()->paginate(10);

        return view('servicios.index', [
            'servicios' => $servicios,
        ]);
    }

    /**
     * Show the form for creating a new service.
     */
    public function create(): View
    {
        return view('servicios.create', [
            'servicio' => new Servicio(['activo' => true]),
            'categorias' => $this->categoriasForForm(),
        ]);
    }

    /**
     * Store a newly created service.
     */
    public function store(Request $request): RedirectResponse
    {
        Servicio::create($this->validatedData($request));

        return redirect()
            ->route('admin.servicios.index')
            ->with('status', 'Servicio creado correctamente.');
    }

    /**
     * Display the specified service.
     */
    public function show(Servicio $servicio): View
    {
        $servicio->load('categoriaServicio');

        return view('servicios.show', [
            'servicio' => $servicio,
        ]);
    }

    /**
     * Show the form for editing the specified service.
     */
    public function edit(Servicio $servicio): View
    {
        return view('servicios.edit', [
            'servicio' => $servicio,
            'categorias' => $this->categoriasForForm(),
        ]);
    }

    /**
     * Update the specified service.
     */
    public function update(Request $request, Servicio $servicio): RedirectResponse
    {
        $servicio->update($this->validatedData($request));

        return redirect()
            ->route('admin.servicios.index')
            ->with('status', 'Servicio actualizado correctamente.');
    }

    /**
     * Remove the specified service.
     */
    public function destroy(Servicio $servicio): RedirectResponse
    {
        try {
            $servicio->delete();
        } catch (QueryException) {
            return redirect()
                ->route('admin.servicios.index')
                ->with('error', 'No se puede eliminar el servicio porque tiene registros relacionados.');
        }

        return redirect()
            ->route('admin.servicios.index')
            ->with('status', 'Servicio eliminado correctamente.');
    }

    /**
     * Validate service form data.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'categoria_servicio_id' => ['required', 'exists:categorias_servicio,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio_base' => ['required', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $data['activo'] = $request->boolean('activo');

        return $data;
    }

    /**
     * Get service categories for form selects.
     */
    private function categoriasForForm()
    {
        return CategoriaServicio::orderBy('nombre')->get();
    }
}
