<?php

namespace App\Services;

use App\Models\Cotizacion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Genera la cotización como imagen PNG usando un navegador headless
 * (Microsoft Edge en Windows/Herd, Chrome/Chromium como alternativa).
 * No requiere dependencias de Composer ni de Node: Edge viene preinstalado
 * en Windows y Symfony Process forma parte de Laravel.
 */
class ExportarCotizacionPng
{
    /**
     * Rutas típicas de Edge/Chrome en Windows; en Linux/CI se resuelven
     * por nombre en el PATH.
     *
     * @var list<string>
     */
    private const RUTAS_WINDOWS = [
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    ];

    /** @var list<string> */
    private const BINARIOS_PATH = ['msedge', 'google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium', 'chrome'];

    /**
     * Build the PNG image for a quote and return its raw bytes.
     */
    public function generar(Cotizacion $cotizacion): string
    {
        $navegador = $this->navegador();

        if ($navegador === null) {
            throw new RuntimeException(
                'No se encontró un navegador para generar el PNG. Instala Microsoft Edge o Google Chrome, '
                .'o define COTIZACIONES_BROWSER_BIN en el archivo .env con la ruta del ejecutable.',
            );
        }

        $cotizacion->loadMissing(['items', 'cliente', 'equipo', 'partner']);

        $html = view('cotizaciones.png', [
            'cotizacion' => $cotizacion,
            'negocio' => config('negocio'),
        ])->render();

        $directorio = storage_path('app'.DIRECTORY_SEPARATOR.'cotizaciones-png');
        File::ensureDirectoryExists($directorio);

        $base = $directorio.DIRECTORY_SEPARATOR.'cotizacion-'.$cotizacion->id.'-'.Str::random(8);
        $rutaHtml = $base.'.html';
        $rutaPng = $base.'.png';

        try {
            file_put_contents($rutaHtml, $html);

            $ancho = (int) config('cotizaciones.png_ancho', 880);
            $altura = $this->alturaEstimada($cotizacion);

            $proceso = new Process([
                $navegador,
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--hide-scrollbars',
                '--force-device-scale-factor=2',
                '--window-size='.$ancho.','.$altura,
                '--screenshot='.$rutaPng,
                'file:///'.str_replace('\\', '/', $rutaHtml),
            ]);
            $proceso->setTimeout((float) config('cotizaciones.png_timeout', 60));
            $proceso->run();

            if (! is_file($rutaPng)) {
                throw new RuntimeException(
                    'El navegador no pudo generar el PNG de la cotización: '
                    .Str::limit(trim($proceso->getErrorOutput()) ?: 'sin detalle', 300),
                );
            }

            return $this->recortarBlancoInferior((string) file_get_contents($rutaPng));
        } finally {
            @unlink($rutaHtml);
            @unlink($rutaPng);
        }
    }

    /**
     * Get the suggested download file name for a quote.
     */
    public function nombreArchivo(Cotizacion $cotizacion): string
    {
        return 'cotizacion-'.$cotizacion->folio.'.png';
    }

    /**
     * Determine if a headless-capable browser is available on this machine.
     */
    public function navegadorDisponible(): bool
    {
        return $this->navegador() !== null;
    }

    /**
     * Resolve the browser executable: .env override, well-known Windows
     * install paths, then PATH lookup (Linux/CI).
     */
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

    /**
     * Estimate a viewport height (CSS px) tall enough for the whole quote;
     * the excess white space is trimmed afterwards.
     */
    private function alturaEstimada(Cotizacion $cotizacion): int
    {
        $altura = 900
            + $cotizacion->items->count() * 44
            + (int) ceil(mb_strlen((string) $cotizacion->notas) / 90) * 20
            + (int) ceil(mb_strlen((string) $cotizacion->direccion_recepcion) / 90) * 20;

        return min(max($altura, 900), 4000);
    }

    /**
     * Trim the surplus white area below the content, keeping a small margin.
     * If GD is unavailable or decoding fails, the image is returned as-is.
     */
    private function recortarBlancoInferior(string $png): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $png;
        }

        $imagen = @imagecreatefromstring($png);

        if ($imagen === false) {
            return $png;
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $ultimaFilaConContenido = 0;

        for ($y = $alto - 1; $y >= 0; $y--) {
            for ($x = 0; $x < $ancho; $x += 6) {
                $rgb = imagecolorat($imagen, $x, $y);

                if ((($rgb >> 16) & 0xFF) < 245 || (($rgb >> 8) & 0xFF) < 245 || ($rgb & 0xFF) < 245) {
                    $ultimaFilaConContenido = $y;
                    break 2;
                }
            }
        }

        $nuevoAlto = min($alto, $ultimaFilaConContenido + 80);

        if ($ultimaFilaConContenido === 0 || $nuevoAlto >= $alto) {
            imagedestroy($imagen);

            return $png;
        }

        $recorte = imagecrop($imagen, ['x' => 0, 'y' => 0, 'width' => $ancho, 'height' => $nuevoAlto]);
        imagedestroy($imagen);

        if ($recorte === false) {
            return $png;
        }

        ob_start();
        imagepng($recorte);
        imagedestroy($recorte);

        return (string) ob_get_clean();
    }
}
