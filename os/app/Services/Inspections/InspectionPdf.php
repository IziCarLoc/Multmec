<?php

namespace App\Services\Inspections;

use App\Enums\MediaKind;
use App\Models\ChecklistItem;
use App\Models\Consent;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Services\Media\DamageMarks;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * PDF da vistoria de entrada: dados do carro e do cliente (CPF escondido), painel, objetos, acessórios,
 * fotos (800 px) com as avarias marcadas numa cópia, checklist técnico, assinatura ou registro do envio pelo
 * WhatsApp, o texto da entrada [REVISAR] e o SHA-256 de cada foto.
 */
final class InspectionPdf
{
    public function __construct(
        private readonly EntryInspection $inspection,
        private readonly DamageMarks $marks,
    ) {}

    /** `$sendingAt`: o PDF que vai ser enviado agora já diz "enviada pelo WhatsApp em ...". */
    public function render(ServiceOrder $order, ?CarbonInterface $sendingAt = null): string
    {
        $order->loadMissing(['vehicle', 'customer', 'unit', 'receiver']);
        $photos = $order->media()->whereIn('kind', [MediaKind::Entrada->value, MediaKind::Painel->value])->get()
            ->sortBy(fn (Media $m) => array_search($m->position, array_keys(EntryInspection::PHOTOS), true))->values();

        $number = 1;
        $damages = $order->damages()->with('media')->get()->map(function ($damage) use (&$number) {
            $first = $number;
            $number += count($damage->markList());

            return [
                'damage' => $damage,
                'first' => $first,
                'image' => $damage->media ? $this->marks->dataUri($damage->media, $damage->markList(), 800, $first) : null,
            ];
        });

        $signature = $order->consents()->where('purpose', Consent::PURPOSE_ENTRY)->latest('id')->first();

        return Pdf::loadView('pdf.inspection', [
            'order' => $order,
            'unit' => $order->unit,
            'photos' => $photos->map(fn (Media $m) => ['media' => $m, 'image' => $this->marks->dataUri($m)])->all(),
            'damages' => $damages->all(),
            'checklist' => $order->checklistItems()->with('media')->where('kind', ChecklistItem::KIND_TECHNICAL)->get(),
            'signature' => $signature,
            'signatureImage' => $signature?->signature_path && Storage::disk(Media::DISK)->exists($signature->signature_path)
                ? 'data:image/png;base64,'.base64_encode((string) Storage::disk(Media::DISK)->get($signature->signature_path))
                : null,
            'legalText' => Settings::text('entry'),
            'snapshotHash' => $this->inspection->snapshotHash($order),
            'generatedAt' => now(),
            'sentAt' => $sendingAt ?? $order->inspection_sent_at,
            'logo' => is_file(public_path('images/logo.png')) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('images/logo.png'))) : null,
        ])->setPaper('a4')->output();
    }
}
