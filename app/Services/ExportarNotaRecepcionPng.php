<?php

namespace App\Services;

use App\Models\OrdenServicio;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Generates a reception note PNG using the same local headless-browser
 * infrastructure as quote exports.
 */
class ExportarNotaRecepcionPng
{
    /** @var list<string> */
    private const RUTAS_WINDOWS = [
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    ];

    /** @var list<string> */
    private const BINARIOS_PATH = ['msedge', 'google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium', 'chrome'];

    public function generar(OrdenServicio $orden): string
    {
        $navegador = $this->navegador();

        if ($navegador === null) {
            throw new RuntimeException(
                'No se encontrÃ³ un navegador para generar el PNG. Instala Microsoft Edge o Google Chrome, '
                .'o define COTIZACIONES_BROWSER_BIN en el archivo .env con la ruta del ejecutable.',
            );
        }

        $orden->loadMissing(['cliente', 'equipo']);
        $html = view('ordenes-servicio.nota-recepcion-png', [
            'orden' => $orden,
            'negocio' => config('negocio'),
        ])->render();

        $directorio = storage_path('app'.DIRECTORY_SEPARATOR.'notas-recepcion-png');
        File::ensureDirectoryExists($directorio);

        $base = $directorio.DIRECTORY_SEPARATOR.'nota-recepcion-'.$orden->id.'-'.Str::random(8);
        $rutaHtml = $base.'.html';
        $rutaPng = $base.'.png';

        try {
            file_put_contents($rutaHtml, $html);

            $proceso = new Process([
                $navegador,
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--hide-scrollbars',
                '--force-device-scale-factor=2',
                '--window-size=880,1800',
                '--screenshot='.$rutaPng,
                'file:///'.str_replace('\\', '/', $rutaHtml),
            ]);
            $proceso->setTimeout((float) config('cotizaciones.png_timeout', 60));
            $proceso->run();

            if (! is_file($rutaPng)) {
                throw new RuntimeException(
                    'El navegador no pudo generar el PNG de la nota de recepciÃ³n: '
                    .Str::limit(trim($proceso->getErrorOutput()) ?: 'sin detalle', 300),
                );
            }

            return (string) file_get_contents($rutaPng);
        } finally {
            @unlink($rutaHtml);
            @unlink($rutaPng);
        }
    }

    public function nombreArchivo(OrdenServicio $orden): string
    {
        return 'nota-recepcion-'.$orden->folio.'.png';
    }

    public function navegadorDisponible(): bool
    {
        return $this->navegador() !== null;
    }

    private function navegador(): ?string
    {
        $configurado = config('cotizaciones.browser_bin');

        if (filled($configurado)) {
            return is_file($configurado) ? $configurado : null;
        }

        foreach (self::RUTAS_WINDOWS as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        $finder = new ExecutableFinder;

        foreach (self::BINARIOS_PATH as $binario) {
            if ($encontrado = $finder->find($binario)) {
                return $encontrado;
            }
        }

        return null;
    }
}
