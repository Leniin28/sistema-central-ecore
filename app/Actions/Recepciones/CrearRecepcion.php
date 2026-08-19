<?php

namespace App\Actions\Recepciones;

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CrearRecepcion
{
    public function __construct(private CrearOrdenServicio $crearOrden) {}

    /** @param array<string, mixed> $data */
    public function ejecutar(array $data, User $actor): OrdenServicio
    {
        return DB::transaction(function () use ($data, $actor): OrdenServicio {
            $cliente = $data['cliente_modo'] === 'nuevo'
                ? Cliente::create($data['cliente'])
                : Cliente::findOrFail($data['cliente_id']);

            $equipo = $data['equipo_modo'] === 'nuevo'
                ? Equipo::create([...$data['equipo'], 'cliente_id' => $cliente->id])
                : Equipo::findOrFail($data['equipo_id']);

            $ordenData = $data['orden'];
            $problema = trim($ordenData['problema_reportado']);
            $notas = trim((string) ($ordenData['notas'] ?? ''));
            $ordenData['notas'] = "Problema reportado:\n{$problema}".($notas !== '' ? "\n\nNotas internas:\n{$notas}" : '');
            unset($ordenData['problema_reportado']);

            return $this->crearOrden->ejecutar([
                ...$ordenData,
                'cliente_id' => $cliente->id,
                'equipo_id' => $equipo->id,
                'costo_tecnico' => null,
            ], $data['servicios'], $data['refacciones'] ?? [], $actor);
        });
    }
}
