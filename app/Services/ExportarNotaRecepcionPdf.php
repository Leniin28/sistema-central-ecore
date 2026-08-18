<?php

namespace App\Services;

use App\Models\OrdenServicio;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

class ExportarNotaRecepcionPdf
{
    /** Build the PDF document for a service-order reception note. */
    public function generar(OrdenServicio $orden): PdfDocument
    {
        $orden->loadMissing(['cliente', 'equipo']);

        return Pdf::loadView('ordenes-servicio.nota-recepcion-pdf', [
            'orden' => $orden,
            'negocio' => config('negocio'),
        ])->setPaper('letter');
    }

    public function nombreArchivo(OrdenServicio $orden): string
    {
        return 'nota-recepcion-'.$orden->folio.'.pdf';
    }
}
