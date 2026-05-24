<?php

namespace Database\Seeders;

use App\Models\CategoriaServicio;
use Illuminate\Database\Seeder;

class CategoriaServicioSeeder extends Seeder
{
    /**
     * Seed the service categories.
     */
    public function run(): void
    {
        $categorias = [
            [
                'nombre' => 'Diagnóstico',
                'descripcion' => 'Revisión inicial del equipo para detectar fallas.',
            ],
            [
                'nombre' => 'Software',
                'descripcion' => 'Servicios relacionados con sistema operativo, programas y configuración.',
            ],
            [
                'nombre' => 'Hardware',
                'descripcion' => 'Reparaciones o cambios físicos de componentes.',
            ],
            [
                'nombre' => 'Mantenimiento',
                'descripcion' => 'Limpieza, optimización y prevención de fallas.',
            ],
            [
                'nombre' => 'Marketing',
                'descripcion' => 'Servicios de marketing digital y gestión publicitaria.',
            ],
        ];

        foreach ($categorias as $categoria) {
            CategoriaServicio::updateOrCreate(
                ['nombre' => $categoria['nombre']],
                ['descripcion' => $categoria['descripcion']],
            );
        }
    }
}
