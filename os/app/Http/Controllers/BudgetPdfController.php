<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Services\Budgets\BudgetPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF do orçamento para o atendimento e o gestor (tem preço: o mecânico não abre). Com ?enviado=1, exatamente
 * o arquivo que foi para o cliente; sem orçamento, a prévia do que vai ser enviado.
 */
class BudgetPdfController extends Controller
{
    public function show(Request $request, ServiceOrder $order, BudgetPdf $pdf, ?Budget $budget = null): Response
    {
        abort_if($budget && $budget->service_order_id !== $order->id, 404);
        $order->loadMissing('vehicle');
        $name = ($budget?->isAddendum() ? 'adendo-'.$budget->number : 'orcamento').'-'.$order->number.'-'.($order->vehicle?->plate ?? 'sem-placa').'.pdf';

        $binary = match (true) {
            $budget && $request->boolean('enviado') && $budget->pdf_path && Storage::disk(Media::DISK)->exists($budget->pdf_path) => (string) Storage::disk(Media::DISK)->get($budget->pdf_path),
            $budget !== null => $pdf->render($budget),
            default => $pdf->preview($order),
        };

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
