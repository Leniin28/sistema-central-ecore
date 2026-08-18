<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClienteController extends Controller
{
    /**
     * Search clients for operational capture forms.
     */
    public function buscar(Request $request): JsonResponse
    {
        $termino = trim((string) $request->input('q'));

        $clientes = Cliente::query()
            ->select(['id', 'nombre', 'telefono'])
            ->when($termino !== '', function ($query) use ($termino) {
                $query->where(function ($query) use ($termino) {
                    $query->where('nombre', 'like', "%{$termino}%")
                        ->orWhere('telefono', 'like', "%{$termino}%");
                });
            })
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Cliente $cliente) => [
                'value' => (string) $cliente->id,
                'label' => trim($cliente->nombre.' · '.$cliente->telefono),
            ]);

        return response()->json(['data' => $clientes]);
    }

    /**
     * Display a listing of clients.
     */
    public function index(): View
    {
        $clientes = Cliente::latest()->paginate(10);

        return view('clientes.index', [
            'clientes' => $clientes,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Show the form for creating a new client.
     */
    public function create(): View
    {
        return view('clientes.create', [
            'cliente' => new Cliente,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Store a newly created client.
     */
    public function store(Request $request): RedirectResponse
    {
        Cliente::create($this->validatedData($request));

        return redirect()
            ->route($this->routePrefix().'.clientes.index')
            ->with('status', 'Cliente creado correctamente.');
    }

    /**
     * Display the specified client.
     */
    public function show(Cliente $cliente): View
    {
        $cotizaciones = $cliente->cotizaciones()
            ->when(! auth()->user()->isAdmin(), fn ($query) => $query->where('partner_id', auth()->user()->partner_id))
            ->latest()
            ->get();

        return view('clientes.show', [
            'cliente' => $cliente,
            'cotizaciones' => $cotizaciones,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Show the form for editing the specified client.
     */
    public function edit(Cliente $cliente): View
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('clientes.edit', [
            'cliente' => $cliente,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Update the specified client.
     */
    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $cliente->update($this->validatedData($request));

        return redirect()
            ->route($this->routePrefix().'.clientes.index')
            ->with('status', 'Cliente actualizado correctamente.');
    }

    /**
     * Remove the specified client.
     */
    public function destroy(Cliente $cliente): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        try {
            $cliente->delete();
        } catch (QueryException) {
            return redirect()
                ->route($this->routePrefix().'.clientes.index')
                ->with('error', 'No se puede eliminar el cliente porque tiene registros relacionados.');
        }

        return redirect()
            ->route($this->routePrefix().'.clientes.index')
            ->with('status', 'Cliente eliminado correctamente.');
    }

    /**
     * Validate client form data.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:50'],
            'correo' => ['nullable', 'email', 'max:255'],
            'tipo_cliente' => ['required', 'in:mantenimiento,marketing,ambos'],
            'notas' => ['nullable', 'string'],
        ]);
    }

    /**
     * Get the route prefix for the authenticated user's role.
     */
    private function routePrefix(): string
    {
        return auth()->user()->isAdmin() ? 'admin' : 'logistica';
    }
}
