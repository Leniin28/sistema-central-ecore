<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class DashboardRedirectController extends Controller
{
    /**
     * Redirect the authenticated user to the dashboard for their role.
     */
    public function __invoke(): RedirectResponse
    {
        return match (auth()->user()->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'socio_logistico' => redirect()->route('logistica.dashboard'),
            'socio_tecnico' => redirect()->route('tecnico.dashboard'),
            default => abort(403),
        };
    }
}
