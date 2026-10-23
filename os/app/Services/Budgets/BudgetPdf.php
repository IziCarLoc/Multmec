<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\Media;
use App\Models\ServiceOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF do orçamento (ou adendo), sempre a partir da cópia congelada: o que o cliente recebeu é o que sai aqui.
 * A prévia (antes de enviar) usa os itens de agora e sai marcada "PRÉVIA".
 */
final class BudgetPdf
{
    public function __construct(private readonly Budgets $budgets) {}

    public function render(Budget $budget): string
    {
        $budget->loadMissing(['consent', 'decider']);
        $consent = $budget->consent;

        return $this->output($budget->snapshot, [
            'budget' => $budget,
            'hash' => $budget->snapshot_sha256,
            'consent' => $consent,
            'signatureImage' => $consent?->signature_path && Storage::disk(Media::DISK)->exists($consent->signature_path)
                ? 'data:image/png;base64,'.base64_encode((string) Storage::disk(Media::DISK)->get($consent->signature_path))
                : null,
            'preview' => false,
        ]);
    }

    /** Prévia do que vai ser enviado agora (nada fica gravado). */
    public function preview(ServiceOrder $order): string
    {
        $kind = Budgets::isAddendumStage($order->stage) ? Budget::KIND_ADDENDUM : Budget::KIND_BUDGET;
        $snapshot = $this->budgets->snapshot($order, $this->budgets->draftItems($order), $kind, (int) $order->budgets()->max('number') + 1);

        return $this->output($snapshot, ['budget' => null, 'hash' => Budgets::hash($snapshot), 'consent' => null, 'signatureImage' => null, 'preview' => true]);
    }

    private function output(array $snapshot, array $data): string
    {
        return Pdf::loadView('pdf.budget', $data + [
            's' => $snapshot,
            'generatedAt' => now(),
            'logo' => is_file(public_path('images/logo.png')) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('images/logo.png'))) : null,
        ])->setPaper('a4')->output();
    }
}
