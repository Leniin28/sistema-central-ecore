<?php

namespace App\Services;

use App\Models\Cotizacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

class ExportarCotizacionPdf
{
    /**
     * Build the PDF document for a quote.
     */
    public function generar(Cotizacion $cotizacion): PdfDocument
    {
        $cotizacion->loadMissing(['items', 'cliente', 'equipo']);

        return Pdf::loadView('cotizaciones.pdf', [
            'cotizacion' => $cotizacion,
            'negocio' => config('negocio'),
        ])->setPaper('letter');
    }

    /**
     * Get the suggested download file name for a quote.
     */
    public function nombreArchivo(Cotizacion $cotizacion): string
    {
        return 'cotizacion-'.$cotizacion->folio.'.pdf';
    }
}
