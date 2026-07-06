<?php

namespace App\Actions\OpenClaw;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves the system user that internal-API (OpenClaw) operations are attributed
 * to. Orders and their status history require a real creator (non-null FK) and the
 * internal API has no web session, so everything is attributed to this dedicated
 * user: admin role (so the existing order actions authorize) and an unusable random
 * password. Created lazily once; pre-seed it in production.
 */
class ObtenerUsuarioSistema
{
    public function ejecutar(): User
    {
        $email = (string) config('services.openclaw.system_user_email', 'openclaw-bot@sistema.local');

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'OpenClaw (sistema)',
                'password' => bcrypt(Str::random(64)),
                'role' => 'admin',
            ],
        );
    }
}
