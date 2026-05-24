<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            CategoriaServicioSeeder::class,
            ServicioSeeder::class,
        ]);

        $electrocomAlameda = Partner::where('nombre', 'Electrocom')->first();

        if ($electrocomAlameda) {
            $electrocomAlameda->update([
                'nombre' => 'Electrocom Alameda',
                'tipo_socio' => 'logistico',
                'comision_fija' => 50,
                'activo' => true,
            ]);
        } else {
            $electrocomAlameda = Partner::updateOrCreate(
                ['nombre' => 'Electrocom Alameda'],
                [
                    'tipo_socio' => 'logistico',
                    'comision_fija' => 50,
                    'activo' => true,
                ],
            );
        }

        $electrocomRodolfo = Partner::updateOrCreate(
            ['nombre' => 'Electrocom Rodolfo'],
            [
                'tipo_socio' => 'logistico',
                'comision_fija' => 50,
                'activo' => true,
            ],
        );

        $fixop = Partner::updateOrCreate(
            ['nombre' => 'Fixop'],
            [
                'tipo_socio' => 'tecnico',
                'comision_fija' => 0,
                'activo' => true,
            ],
        );

        User::updateOrCreate([
            'email' => 'admin@ecore.test',
        ], [
            'name' => 'Administrador',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'partner_id' => null,
            'email_verified_at' => now(),
        ]);

        $legacyElectrocom = User::where('email', 'electrocom@ecore.test')->first();
        $existingLegacyElectrocom = User::where('email', 'electrocom.legacy@ecore.test')->first();

        if ($legacyElectrocom && ! $existingLegacyElectrocom) {
            $legacyElectrocom->update([
                'name' => 'Electrocom Alameda Legacy',
                'email' => 'electrocom.legacy@ecore.test',
                'role' => 'socio_logistico',
                'partner_id' => $electrocomAlameda->id,
                'email_verified_at' => $legacyElectrocom->email_verified_at ?? now(),
            ]);
        } elseif ($existingLegacyElectrocom) {
            $existingLegacyElectrocom->update([
                'name' => 'Electrocom Alameda Legacy',
                'role' => 'socio_logistico',
                'partner_id' => $electrocomAlameda->id,
                'email_verified_at' => $existingLegacyElectrocom->email_verified_at ?? now(),
            ]);

            if ($legacyElectrocom) {
                $legacyElectrocom->update([
                    'name' => 'Electrocom Alameda Legacy',
                    'role' => 'socio_logistico',
                    'partner_id' => $electrocomAlameda->id,
                    'email_verified_at' => $legacyElectrocom->email_verified_at ?? now(),
                ]);
            }
        }

        User::updateOrCreate([
            'email' => 'electrocom.alameda@ecore.test',
        ], [
            'name' => 'Electrocom Alameda',
            'password' => Hash::make('password'),
            'role' => 'socio_logistico',
            'partner_id' => $electrocomAlameda->id,
            'email_verified_at' => now(),
        ]);

        User::updateOrCreate([
            'email' => 'electrocom.rodolfo@ecore.test',
        ], [
            'name' => 'Electrocom Rodolfo',
            'password' => Hash::make('password'),
            'role' => 'socio_logistico',
            'partner_id' => $electrocomRodolfo->id,
            'email_verified_at' => now(),
        ]);

        User::updateOrCreate([
            'email' => 'fixop@ecore.test',
        ], [
            'name' => 'Fixop',
            'password' => Hash::make('password'),
            'role' => 'socio_tecnico',
            'partner_id' => $fixop->id,
            'email_verified_at' => now(),
        ]);
    }
}
