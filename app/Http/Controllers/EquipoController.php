<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Equipo;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EquipoController extends Controller
{
    /**
     * Search a client's equipment for operational capture forms.
     */
    public function buscar(Request $request, Cliente $cliente): JsonResponse
    {
        $termino = trim((string) $request->input('q'));

        $equipos = $cliente->equipos()
            ->select(['id', 'tipo_equipo', 'marca', 'modelo', 'numero_serie'])
            ->when($termino !== '', function ($query) use ($termino) {
                $query->where(function ($query) use ($termino) {
                    $query->where('tipo_equipo', 'like', "%{$termino}%")
                        ->orWhere('marca', 'like', "%{$termino}%")
                        ->orWhere('modelo', 'like', "%{$termino}%")
                        ->orWhere('numero_serie', 'like', "%{$termino}%");
                });
            })
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Equipo $equipo) => [
                'value' => (string) $equipo->id,
                'label' => trim(implode(' · ', array_filter([
                    $equipo->tipo_equipo,
                    trim($equipo->marca.' '.$equipo->modelo),
                    $equipo->numero_serie,
                ]))),
            ]);

        return response()->json(['data' => $equipos]);
    }

    /**
     * Display a listing of equipment.
     */
    public function index(): View
    {
        $equipos = Equipo::with('cliente')->latest()->paginate(10);

        return view('equipos.index', [
            'equipos' => $equipos,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Show the form for creating a new equipment record.
     */
    public function create(): View
    {
        return view('equipos.create', [
            'equipo' => new Equipo,
            'clientes' => $this->clientesForForm(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Store a newly created equipment record.
     */
    public function store(Request $request): RedirectResponse
    {
        Equipo::create($this->validatedData($request));

        return redirect()
            ->route($this->routePrefix().'.equipos.index')
            ->with('status', 'Equipo creado correctamente.');
    }

    /**
     * Display the specified equipment record.
     */
    public function show(Equipo $equipo): View
    {
        $equipo->load('cliente');

        $cotizaciones = $equipo->cotizaciones()
            ->when(! auth()->user()->isAdmin(), fn ($query) => $query->where('partner_id', auth()->user()->partner_id))
            ->latest()
            ->get();

        return view('equipos.show', [
            'equipo' => $equipo,
            'cotizaciones' => $cotizaciones,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Show the form for editing the specified equipment record.
     */
    public function edit(Equipo $equipo): View
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('equipos.edit', [
            'equipo' => $equipo,
            'clientes' => $this->clientesForForm(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    /**
     * Update the specified equipment record.
     */
    public function update(Request $request, Equipo $equipo): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $data = $this->validatedData($request);

        if (! filled($data['password_equipo'] ?? null)) {
            unset($data['password_equipo']);
        }

        $equipo->update($data);

        return redirect()
            ->route($this->routePrefix().'.equipos.index')
            ->with('status', 'Equipo actualizado correctamente.');
    }

    /**
     * Remove the specified equipment record.
     */
    public function destroy(Equipo $equipo): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        try {
            $equipo->delete();
        } catch (QueryException) {
            return redirect()
                ->route($this->routePrefix().'.equipos.index')
                ->with('error', 'No se puede eliminar el equipo porque tiene registros relacionados.');
        }

        return redirect()
            ->route($this->routePrefix().'.equipos.index')
            ->with('status', 'Equipo eliminado correctamente.');
    }

    /**
     * Validate equipment form data.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'tipo_equipo' => ['required', 'string', 'max:100'],
            'marca' => ['required', 'string', 'max:100'],
            'modelo' => ['nullable', 'string', 'max:100'],
            'numero_serie' => ['nullable', 'string', 'max:150'],
            'password_equipo' => ['nullable', 'string', 'max:150'],
            'estado_fisico_inicial' => ['nullable', 'string'],
            'accesorios_recibidos' => ['nullable', 'string'],
        ]);
    }

    /**
     * Get clients for form selects.
     */
    private function clientesForForm()
    {
        return Cliente::orderBy('nombre')->get();
    }

    /**
     * Get the route prefix for the authenticated user's role.
     */
    private function routePrefix(): string
    {
        return auth()->user()->isAdmin() ? 'admin' : 'logistica';
    }
}
