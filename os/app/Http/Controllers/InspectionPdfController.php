<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\ServiceOrder;
use App\Services\Inspections\InspectionPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF da vistoria de entrada para quem está logado: a versão de agora, ou (com ?enviado=1) exatamente o
 * arquivo que foi enviado ao cliente.
 */
class InspectionPdfController extends Controller
{
    public function show(Request $request, ServiceOrder $order, InspectionPdf $pdf): Response
    {
        $order->loadMissing('vehicle');
        $name = 'vistoria-'.$order->number.'-'.($order->vehicle?->plate ?? 'sem-placa').'.pdf';

        if ($request->boolean('enviado') && $order->inspection_pdf_path && Storage::disk(Media::DISK)->exists($order->inspection_pdf_path)) {
            $binary = (string) Storage::disk(Media::DISK)->get($order->inspection_pdf_path);
        } else {
            $binary = $pdf->render($order);
        }

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
