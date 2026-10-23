<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Services\Orders\OrderPdf;
use Symfony\Component\HttpFoundation\Response;

/** PDF completo da OS para o atendimento e o gestor (tem valores: o mecânico não abre). */
class OrderPdfController extends Controller
{
    public function show(ServiceOrder $order, OrderPdf $pdf): Response
    {
        $order->loadMissing('vehicle');

        return response($pdf->render($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="os-'.$order->number.'-'.($order->vehicle?->plate ?? 'sem-placa').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
