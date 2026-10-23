<?php

namespace App\Services\Orders;

use App\Enums\MediaKind;
use App\Models\Budget;
use App\Models\ChecklistItem;
use App\Models\Consent;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Services\Budgets\Budgets;
use App\Services\Delivery\Delivery;
use App\Services\Execution\FinalCheck;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF completo da OS (a "pasta de garantia"): entrada, diagnóstico, orçamento aprovado e aceites, execução com
 * as fotos das peças, torque e lacres, conferência e test drive, entrega com a assinatura, termo de garantia,
 * aviso de privacidade e os SHA-256. As fotos vão em miniatura (o PDF fica leve para mandar pelo WhatsApp).
 */
final class OrderPdf
{
    public function __construct(
        private readonly Delivery $delivery,
        private readonly Budgets $budgets,
        private readonly FinalCheck $finalCheck,
    ) {}

    public function render(ServiceOrder $order): string
    {
        $order->loadMissing(['vehicle', 'customer', 'unit', 'responsible', 'testDriver', 'finalChecker']);
        $media = $order->media()->whereNull('replaced_at')->get();
        $image = fn (?Media $m) => $m && ($path = $m->pathFor('mini')) && Storage::disk(Media::DISK)->exists($path)
            ? 'data:image/jpeg;base64,'.base64_encode((string) Storage::disk(Media::DISK)->get($path))
            : null;
        $consents = $order->consents()->get();
        $pickup = $consents->where('purpose', Consent::PURPOSE_PICKUP)->last();

        return Pdf::loadView('pdf.order', [
            'order' => $order,
            'unit' => $order->unit,
            'items' => $this->delivery->approvedItems($order)->load('media'),
            'amounts' => $this->delivery->amounts($order),
            'budgets' => $order->budgets()->with(['consent', 'decider'])->get()->where('status', '!=', Budget::STATUS_CANCELLED),
            'entry' => $media->whereIn('kind', [MediaKind::Entrada, MediaKind::Painel])->keyBy('position'),
            'exit' => $media->where('kind', MediaKind::Saida)->keyBy('position'),
            'wheels' => $media->where('kind', MediaKind::Roda)->keyBy('position'),
            'seals' => $media->where('kind', MediaKind::Lacre),
            'image' => $image,
            'finalItems' => $this->finalCheck->items($order),
            'finalConsent' => $consents->where('purpose', Consent::PURPOSE_FINAL_CHECK)->last(),
            'pickup' => $pickup,
            'pickupSignature' => $pickup?->signature_path && Storage::disk(Media::DISK)->exists($pickup->signature_path)
                ? 'data:image/png;base64,'.base64_encode((string) Storage::disk(Media::DISK)->get($pickup->signature_path))
                : null,
            'technical' => $order->checklistItems()->where('kind', ChecklistItem::KIND_TECHNICAL)->get(),
            'workers' => $order->timeEntries()->with('user')->get()->pluck('user.short_name')->unique()->values(),
            'warranty' => $this->budgets->warrantyText(),
            'privacy' => Settings::text('privacy'),
            'generatedAt' => now(),
            'logo' => is_file(public_path('images/logo.png')) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('images/logo.png'))) : null,
        ])->setPaper('a4')->output();
    }
}
