<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalApiToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $tokenConfigurado = (string) config('services.openclaw.internal_api_token');

        if ($tokenConfigurado === '') {
            Log::warning('API interna rechazada: OPENCLAW_INTERNAL_API_TOKEN no está configurado en .env.');

            return response()->json([
                'message' => 'La API interna no está habilitada: falta configurar el token en el servidor.',
            ], 403);
        }

        $tokenRecibido = (string) $request->bearerToken();

        if ($tokenRecibido === '' || ! hash_equals($tokenConfigurado, $tokenRecibido)) {
            Log::warning('API interna rechazada: token inválido o ausente.', [
                'ip' => $request->ip(),
                'ruta' => $request->path(),
            ]);

            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        return $next($request);
    }
}
