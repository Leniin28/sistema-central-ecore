<?php

namespace App\Http\Controllers;

use App\Models\CategoriaServicio;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoriaServicioController extends Controller
{
    /**
     * Display a listing of service categories.
     */
    public function index(): View
    {
        $categorias = CategoriaServicio::latest()->paginate(10);

        return view('categorias-servicio.index', [
            'categorias' => $categorias,
        ]);
    }

    /**
     * Show the form for creating a new service category.
     */
    public function create(): View
    {
        return view('categorias-servicio.create', [
            'categoria' => new CategoriaServicio,
        ]);
    }

    /**
     * Store a newly created service category.
     */
    public function store(Request $request): RedirectResponse
    {
        CategoriaServicio::create($this->validatedData($request));

        return redirect()
            ->route('admin.categorias-servicio.index')
            ->with('status', 'Categoría creada correctamente.');
    }

    /**
     * Display the specified service category.
     */
    public function show(CategoriaServicio $categoriaServicio): View
    {
        return view('categorias-servicio.show', [
            'categoria' => $categoriaServicio,
        ]);
    }

    /**
     * Show the form for editing the specified service category.
     */
    public function edit(CategoriaServicio $categoriaServicio): View
    {
        return view('categorias-servicio.edit', [
            'categoria' => $categoriaServicio,
        ]);
    }

    /**
     * Update the specified service category.
     */
    public function update(Request $request, CategoriaServicio $categoriaServicio): RedirectResponse
    {
        $categoriaServicio->update($this->validatedData($request));

        return redirect()
            ->route('admin.categorias-servicio.index')
            ->with('status', 'Categoría actualizada correctamente.');
    }

    /**
     * Remove the specified service category.
     */
    public function destroy(CategoriaServicio $categoriaServicio): RedirectResponse
    {
        try {
            $categoriaServicio->delete();
        } catch (QueryException) {
            return redirect()
                ->route('admin.categorias-servicio.index')
                ->with('error', 'No se puede eliminar la categoría porque tiene servicios asociados.');
        }

        return redirect()
            ->route('admin.categorias-servicio.index')
            ->with('status', 'Categoría eliminada correctamente.');
    }

    /**
     * Validate service category form data.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
        ]);
    }
}
