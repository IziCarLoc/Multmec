<?php

namespace App\Services\Inspections;

use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Document;
use App\Support\Phone;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A vistoria chega ao cliente de um destes jeitos (qualquer um destrava o avanço):
 *  - enviada pelo WhatsApp: o PDF é gerado e guardado (com o SHA-256) e a hora do envio fica registrada;
 *  - assinada na tela, com o dedo, quando o cliente está presente (aceite eletrônico da entrada).
 * Se a parte A mudar depois, precisa enviar ou assinar de novo (o sistema compara o resumo dos dados).
 */
final class InspectionDelivery
{
    public function __construct(
        private readonly EntryInspection $inspection,
        private readonly InspectionPdf $pdf,
    ) {}

    /** Texto da mensagem do WhatsApp (o PDF vai anexado pelo compartilhar do celular). */
    public function message(ServiceOrder $order): string
    {
        $order->loadMissing(['vehicle', 'customer', 'unit']);
        $name = $order->approver_kind === 'outro' ? $order->approver_name : $order->customer->name;
        $first = trim(strtok((string) $name, ' ') ?: '');

        return 'Olá'.($first !== '' && ! $order->customer->is_izi_fleet ? ', '.$first : '').'! Segue a vistoria de entrada do '
            .($order->vehicle->brand_model ?: 'veículo').' placa '.$order->vehicle->plateFormatted()
            .' na '.($order->unit->trade_name ?? 'oficina').' ('.$order->label().'), com as fotos e o estado em que o carro chegou. '
            .'Qualquer dúvida, é só responder esta mensagem.';
    }

    /** Para quem vai a vistoria: quem aprova (ou o investidor, no carro da frota Izi). */
    public function recipientPhone(ServiceOrder $order): ?string
    {
        $order->loadMissing(['vehicle', 'customer']);

        return match (true) {
            $order->approver_kind === 'izi' => $order->vehicle->investor_whatsapp ?: Settings::get('izi_whatsapp'),
            default => $order->approver_whatsapp ?: $order->customer->whatsapp,
        };
    }

    public function whatsappLink(ServiceOrder $order): ?string
    {
        return Phone::whatsappLink($this->recipientPhone($order), $this->message($order));
    }

    /**
     * Gera e guarda o PDF que vai ser enviado (só com a parte A completa).
     *
     * @return array{path: string, sha256: string, binary: string, at: CarbonImmutable}
     */
    public function preparePdf(ServiceOrder $order, User $by): array
    {
        $this->assertReady($order, $by);
        $at = CarbonImmutable::now();
        $binary = $this->pdf->render($order, $at);
        $path = $order->storageDir().'/vistoria-entrada-'.$at->format('Ymd-His').'.pdf';
        Storage::disk(Media::DISK)->put($path, $binary);

        return ['path' => $path, 'sha256' => hash('sha256', $binary), 'binary' => $binary, 'at' => $at];
    }

    /** Registra o envio pelo WhatsApp (hora do servidor), com o PDF e o resumo do que foi enviado. */
    public function markSent(ServiceOrder $order, string $via, User $by, ?array $prepared = null): ServiceOrder
    {
        $prepared ??= $this->preparePdf($order, $by);

        $order->update([
            'inspection_sent_at' => $prepared['at'],
            'inspection_sent_by' => $by->id,
            'inspection_sent_via' => in_array($via, ['compartilhar', 'whatsapp', 'manual'], true) ? $via : 'manual',
            'inspection_pdf_path' => $prepared['path'],
            'inspection_pdf_sha256' => $prepared['sha256'],
            'inspection_snapshot_sha256' => $this->inspection->snapshotHash($order),
        ]);

        AuditLog::record($order, 'inspection.sent', null, ['como' => $via, 'pdf_sha256' => $prepared['sha256']]);

        return $order;
    }

    /** Assinatura do cliente na tela (cliente presente na entrada). CPF é opcional na entrada. */
    public function sign(ServiceOrder $order, string $signerName, ?string $document, string $signaturePng, User $by, ?string $ip, ?string $userAgent): Consent
    {
        $this->assertReady($order, $by);
        $signerName = trim(preg_replace('/\s+/', ' ', $signerName) ?? '');

        if (mb_strlen($signerName) < 3) {
            throw new DomainException('Escreva o nome de quem está assinando.');
        }

        $digits = Document::digits($document);

        if ($digits !== '' && ! Document::isValid($digits)) {
            throw new DomainException('CPF ou CNPJ inválido (ou deixe em branco).');
        }

        $png = SignatureImage::decode($signaturePng);
        $snapshot = $this->inspection->snapshot($order);
        $legal = Settings::text('entry');

        return DB::transaction(function () use ($order, $signerName, $digits, $png, $snapshot, $legal, $by, $ip, $userAgent) {
            $path = $order->storageDir().'/assinatura-entrada-'.now()->format('Ymd-His').'.png';
            Storage::disk(Media::DISK)->put($path, $png);

            $consent = Consent::create([
                'service_order_id' => $order->id,
                'purpose' => Consent::PURPOSE_ENTRY,
                'method' => Consent::METHOD_SCREEN,
                'signer_name' => mb_substr($signerName, 0, 120),
                'signer_document' => $digits ?: null,
                'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'signed_at' => now(),
                'signature_path' => $path,
                'signature_sha256' => hash('sha256', $png),
                'snapshot' => $snapshot,
                'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'legal_text' => $legal,
                'legal_text_sha256' => hash('sha256', $legal),
                'recorded_by' => $by->id,
            ]);

            AuditLog::record($order, 'inspection.signed', null, ['quem' => $consent->signer_name, 'consentimento' => $consent->id]);

            return $consent;
        });
    }

    private function assertReady(ServiceOrder $order, User $by): void
    {
        if (! Gate::forUser($by)->allows('atendimento')) {
            throw new DomainException('Quem envia ou colhe a assinatura da vistoria é o atendimento.');
        }

        if ($order->stage !== Stage::EmVistoria) {
            throw new DomainException('A vistoria de entrada é enviada ou assinada na etapa "Em vistoria".');
        }

        $missing = $this->inspection->missing($order);

        if ($missing !== []) {
            throw new DomainException('Complete a vistoria antes: '.implode('; ', array_column($missing, 'label')).'.');
        }
    }
}
