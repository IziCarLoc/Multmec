<?php

namespace App\Http\Controllers;

use App\Services\Media\HostingProbe;
use Symfony\Component\HttpFoundation\Response;

class HealthCheckController extends Controller
{
    /** PDF de teste com 30 fotos (Saúde do sistema). O tempo e a memória vão no nome do arquivo. */
    public function pdf(HostingProbe $probe): Response
    {
        $result = $probe->pdfWithPhotos(30);
        $name = sprintf('teste-pdf-30-fotos-%ss-%sMB.pdf', str_replace('.', ',', (string) $result['seconds']), str_replace('.', ',', (string) $result['peak_mb']));

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Tempo-Segundos' => (string) $result['seconds'],
            'X-Memoria-MB' => (string) $result['peak_mb'],
        ]);
    }
}
