<?php

namespace Database\Seeders;

use App\Models\CategoriaServicio;
use App\Models\Servicio;
use Illuminate\Database\Seeder;

class ServicioSeeder extends Seeder
{
    /**
     * Seed the initial services.
     */
    public function run(): void
    {
        $servicios = [
            [
                'categoria' => 'Diagnóstico',
                'nombre' => 'Diagnóstico técnico',
                'descripcion' => 'Revisión general del equipo y detección de problema.',
                'precio_base' => 100,
                'activo' => true,
            ],
            [
                'categoria' => 'Software',
                'nombre' => 'Formateo e instalación de sistema',
                'descripcion' => 'Instalación limpia de sistema operativo y configuración básica.',
                'precio_base' => 350,
                'activo' => true,
            ],
            [
                'categoria' => 'Software',
                'nombre' => 'Instalación de programas básicos',
                'descripcion' => 'Instalación de programas esenciales solicitados por el cliente.',
                'precio_base' => 150,
                'activo' => true,
            ],
            [
                'categoria' => 'Hardware',
                'nombre' => 'Cambio de SSD',
                'descripcion' => 'Instalación física de unidad SSD. No incluye costo del componente.',
                'precio_base' => 500,
                'activo' => true,
            ],
            [
                'categoria' => 'Hardware',
                'nombre' => 'Reparación externa con Fixop',
                'descripcion' => 'Servicio enviado a socio técnico externo para reparación especializada.',
                'precio_base' => 0,
                'activo' => true,
            ],
            [
                'categoria' => 'Mantenimiento',
                'nombre' => 'Limpieza interna',
                'descripcion' => 'Limpieza física interna del equipo.',
                'precio_base' => 250,
                'activo' => true,
            ],
            [
                'categoria' => 'Mantenimiento',
                'nombre' => 'Mantenimiento preventivo',
                'descripcion' => 'Limpieza, revisión y optimización general del equipo.',
                'precio_base' => 300,
                'activo' => true,
            ],
            [
                'categoria' => 'Marketing',
                'nombre' => 'Iguala semanal de marketing',
                'descripcion' => 'Honorarios por gestión semanal de marketing digital.',
                'precio_base' => 450,
                'activo' => true,
            ],
            [
                'categoria' => 'Marketing',
                'nombre' => 'Gestión de Facebook Ads',
                'descripcion' => 'Administración de presupuesto publicitario del cliente.',
                'precio_base' => 150,
                'activo' => true,
            ],
        ];

        foreach ($servicios as $servicio) {
            $categoria = CategoriaServicio::where('nombre', $servicio['categoria'])->firstOrFail();

            Servicio::updateOrCreate(
                ['nombre' => $servicio['nombre']],
                [
                    'categoria_servicio_id' => $categoria->id,
                    'descripcion' => $servicio['descripcion'],
                    'precio_base' => $servicio['precio_base'],
                    'activo' => $servicio['activo'],
                ],
            );
        }
    }
}
