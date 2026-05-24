<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Sistema Central ECore</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        <style>
            :root {
                color-scheme: light dark;
                font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                background: #f7f7f4;
                color: #1f2933;
            }

            .page {
                display: flex;
                min-height: 100vh;
                flex-direction: column;
                padding: 24px;
            }

            .nav {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
                width: 100%;
                max-width: 980px;
                margin: 0 auto;
            }

            .hero {
                display: flex;
                width: 100%;
                max-width: 980px;
                flex: 1;
                flex-direction: column;
                justify-content: center;
                margin: 0 auto;
                padding: 56px 0;
            }

            .eyebrow {
                margin: 0 0 12px;
                color: #64748b;
                font-size: 14px;
                font-weight: 600;
                letter-spacing: 0;
            }

            h1 {
                max-width: 760px;
                margin: 0;
                color: #111827;
                font-size: clamp(40px, 8vw, 76px);
                line-height: 1;
                letter-spacing: 0;
            }

            .subtitle {
                max-width: 720px;
                margin: 24px 0 0;
                color: #4b5563;
                font-size: 20px;
                line-height: 1.6;
            }

            .actions {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 32px;
            }

            .button {
                display: inline-flex;
                min-height: 44px;
                align-items: center;
                justify-content: center;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                padding: 10px 18px;
                color: #111827;
                font-size: 15px;
                font-weight: 600;
                text-decoration: none;
            }

            .button.primary {
                border-color: #111827;
                background: #111827;
                color: #ffffff;
            }

            @media (prefers-color-scheme: dark) {
                body {
                    background: #0f1115;
                    color: #e5e7eb;
                }

                .eyebrow {
                    color: #94a3b8;
                }

                h1 {
                    color: #f9fafb;
                }

                .subtitle {
                    color: #cbd5e1;
                }

                .button {
                    border-color: #374151;
                    color: #f9fafb;
                }

                .button.primary {
                    border-color: #f9fafb;
                    background: #f9fafb;
                    color: #111827;
                }
            }
        </style>
    </head>
    <body>
        <div class="page">
            @if (Route::has('login'))
                <nav class="nav" aria-label="Acceso">
                    @auth
                        <a href="{{ route('dashboard') }}" class="button">Panel</a>
                    @else
                        <a href="{{ route('login') }}" class="button primary">Iniciar sesión</a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="button">Registrarse</a>
                        @endif
                    @endauth
                </nav>
            @endif

            <main class="hero">
                <p class="eyebrow">Sistema administrativo web</p>
                <h1>Sistema Central ECore</h1>
                <p class="subtitle">
                    Plataforma web para gestión de mantenimiento, socios operativos, órdenes de servicio, marketing y finanzas.
                </p>

                @if (Route::has('login'))
                    <div class="actions">
                        @auth
                            <a href="{{ route('dashboard') }}" class="button primary">Ir al panel</a>
                        @else
                            <a href="{{ route('login') }}" class="button primary">Iniciar sesión</a>

                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="button">Registrarse</a>
                            @endif
                        @endauth
                    </div>
                @endif
            </main>
        </div>
    </body>
</html>
