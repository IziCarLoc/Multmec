<?php

namespace App\Services\Delivery;

use App\Enums\MediaKind;
use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Consent;
use App\Models\Media;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Finance\ProviderPayments;
use App\Services\Finance\Receivables;
use App\Services\Inspections\EntryInspection;
use App\Services\Inspections\SignatureImage;
use App\Services\Media\MediaStore;
use App\Services\Orders\StageMachine;
use App\Support\Document;
use App\Support\Money;
use App\Support\Settings;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Entrega (pronta para retirada → entregue): fotos de saída (os mesmos cantos e o painel da entrada), km e
 * combustível de saída, o que fazer com as peças substituídas e o termo de retirada assinado na tela por quem
 * retira (o cliente ou pessoa autorizada por escrito), com o documento conferido. A nota fiscal não trava.
 * Na entrega nascem, sem redigitar, o "A receber" (valor aprovado, peças, mão de obra) e o pagamento de cada
 * prestador externo (Douglas e outros).
 */
final class Delivery
{
    public const REPLACED_PARTS = ['entregues' => 'Entregues ao cliente', 'descarte' => 'Cliente não quis (descarte)', 'recusou' => 'Cliente recusou receber'];

    public const WHO = ['cliente' => 'O próprio cliente', 'autorizado' => 'Pessoa autorizada pelo cliente'];

    public function __construct(
        private readonly MediaStore $store,
        private readonly Budgets $budgets,
    ) {}

    /** Itens aprovados (orçamento e adendos) que vão para a cobrança e a nota. */
    public function approvedItems(ServiceOrder $order): Collection
    {
        return $order->items()->with('supplier')->get()->filter(fn (OrderItem $i) => $i->isApproved() && ! $i->not_used)->values();
    }

    /**
     * Valores da OS a cobrar: peças, mão de obra, total e o custo das peças (do vencedor da cotação; peça do cliente
     * não tem custo). Recusada: só o diagnóstico combinado.
     *
     * @return array{parts: int, labor: int, total: int, parts_cost: int, provider_cost: int, customer_brought_part: bool}
     */
    public function amounts(ServiceOrder $order): array
    {
        $items = $this->approvedItems($order);
        $parts = (int) $items->filter->isPart()->sum(fn (OrderItem $i) => (int) $i->lineTotalCents());
        $labor = (int) $items->filter->isService()->sum(fn (OrderItem $i) => (int) $i->lineTotalCents());

        if ($items->isEmpty()) {
            $fee = (int) ($order->budgets()->where('status', Budget::STATUS_REFUSED)->reorder('number', 'desc')->value('diagnosis_fee_cents') ?? 0);

            return ['parts' => 0, 'labor' => $fee, 'total' => $fee, 'parts_cost' => 0, 'provider_cost' => 0, 'customer_brought_part' => false];
        }

        $partItems = $items->filter->isPart();

        return [
            'parts' => $parts,
            'labor' => $labor,
            'total' => $parts + $labor,
            'parts_cost' => (int) $partItems->sum(fn (OrderItem $i) => $i->origin === PartOrigin::Cliente ? 0 : (int) $i->lineCostCents()),
            'provider_cost' => (int) $items->filter(fn (OrderItem $i) => $i->isService() && $i->supplier_id !== null)->sum(fn (OrderItem $i) => (int) $i->cost_cents),
            'customer_brought_part' => $partItems->isNotEmpty() && $partItems->every(fn (OrderItem $i) => $i->origin === PartOrigin::Cliente),
        ];
    }

    public function storeExitPhoto(ServiceOrder $order, string $position, UploadedFile $file, ?string $hash, ?int $takenAt, User $by): Media
    {
        $this->assertAtendimento($by);
        $this->assertReady($order);

        if (! array_key_exists($position, EntryInspection::PHOTOS)) {
            throw new DomainException('Posição de foto inválida.');
        }

        $previous = $order->media()->where('kind', MediaKind::Saida->value)->where('position', $position)->whereNull('replaced_at')->latest('id')->first();

        return $this->store->storePhoto($order, MediaKind::Saida, $file, $hash, $takenAt, $by, ['position' => $position, 'caption' => 'Saída: '.EntryInspection::PHOTOS[$position], 'replaces' => $previous]);
    }

    /**
     * Km e combustível de saída e as peças substituídas.
     *
     * @param  array{km_out?: mixed, fuel_out?: ?string, replaced_parts?: ?string}  $data
     */
    public function update(ServiceOrder $order, array $data, User $by): ServiceOrder
    {
        $this->assertAtendimento($by);
        $this->assertReady($order);
        $values = [];

        if (array_key_exists('km_out', $data)) {
            $km = (int) preg_replace('/\D/', '', (string) $data['km_out']);

            if ($km > 0 && $order->km_in && $km < $order->km_in) {
                throw new DomainException('O km de saída ficou menor que o de entrada ('.number_format($order->km_in, 0, ',', '.').').');
            }

            $values['km_out'] = $km ?: null;
        }

        if (array_key_exists('fuel_out', $data)) {
            $values['fuel_out'] = array_key_exists((string) $data['fuel_out'], EntryInspection::FUEL) ? $data['fuel_out'] : null;
        }

        if (array_key_exists('replaced_parts', $data)) {
            $values['replaced_parts'] = array_key_exists((string) $data['replaced_parts'], self::REPLACED_PARTS) ? $data['replaced_parts'] : null;
        }

        $order->fill($values);

        if ($order->isDirty()) {
            AuditLog::record($order, 'delivery.updated', null, $order->getDirty());
            $order->save();
        }

        return $order;
    }

    /**
     * O que falta para registrar a entrega (a assinatura é o último passo, no próprio registro).
     *
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order): array
    {
        $missing = [];
        $photos = $order->media()->where('kind', MediaKind::Saida->value)->whereNull('replaced_at')->pluck('position')->all();

        foreach (EntryInspection::PHOTOS as $position => $label) {
            if (! in_array($position, $photos, true)) {
                $missing[] = ['label' => 'Foto de saída: '.mb_strtolower($label), 'tab' => 'entrega', 'field' => 'saida-'.$position];
            }
        }

        if (! $order->km_out) {
            $missing[] = ['label' => 'Km de saída', 'tab' => 'entrega', 'field' => 'kmOut'];
        }

        if (! $order->fuel_out) {
            $missing[] = ['label' => 'Combustível de saída', 'tab' => 'entrega', 'field' => 'fuelOut'];
        }

        if ($this->approvedItems($order)->contains(fn (OrderItem $i) => $i->isPart()) && ! $order->replaced_parts) {
            $missing[] = ['label' => 'Peças substituídas: entregues, descarte ou recusou', 'tab' => 'entrega', 'field' => 'replacedParts'];
        }

        return $missing;
    }

    /** Termo de retirada assinado depois que a OS ficou pronta. */
    public function pickupSigned(ServiceOrder $order): bool
    {
        return $order->consents()->where('purpose', Consent::PURPOSE_PICKUP)
            ->where('signed_at', '>=', $order->stage_changed_at ?? $order->created_at)->exists();
    }

    /**
     * Registra a entrega: assinatura do termo, a etapa "Entregue" e o que vai para o Financeiro.
     *
     * @param  array{who: string, signer_name: ?string, signer_document: ?string, authorization?: ?string, signature: ?string, payment_method?: ?string, due_dates?: list<string>, received_now?: bool}  $data
     */
    public function deliver(ServiceOrder $order, array $data, User $by, ?string $ip, ?string $userAgent): ServiceOrder
    {
        $this->assertAtendimento($by);
        $this->assertReady($order);

        $missing = $this->missing($order);

        if ($missing !== []) {
            throw new DomainException('Falta para entregar: '.implode('; ', array_map(fn ($m) => mb_strtolower(mb_substr($m['label'], 0, 1)).mb_substr($m['label'], 1), $missing)).'.');
        }

        $who = (string) ($data['who'] ?? '');

        if (! array_key_exists($who, self::WHO)) {
            throw new DomainException('Diga quem está retirando o carro.');
        }

        $name = trim(preg_replace('/\s+/', ' ', (string) ($data['signer_name'] ?? '')) ?? '');
        $digits = Document::digits($data['signer_document'] ?? null);
        $authorization = trim((string) ($data['authorization'] ?? ''));

        if (mb_strlen($name) < 3) {
            throw new DomainException('Escreva o nome de quem está retirando.');
        }

        if ($digits === '' || ! Document::isValid($digits)) {
            throw new DomainException('Confira o documento (CPF ou CNPJ) de quem está retirando.');
        }

        if ($who === 'autorizado' && mb_strlen($authorization) < 5) {
            throw new DomainException('Pessoa autorizada: registre a autorização por escrito do cliente (cole a mensagem ou descreva o documento).');
        }

        $png = SignatureImage::decode((string) ($data['signature'] ?? ''));
        $amounts = $this->amounts($order);
        $dueDates = array_values(array_filter((array) ($data['due_dates'] ?? []), fn ($d) => filled($d)));

        if ($amounts['total'] > 0 && $dueDates === []) {
            throw new DomainException('Informe o vencimento do pagamento (ou a data de hoje, se pagou agora).');
        }

        return DB::transaction(function () use ($order, $who, $name, $digits, $authorization, $png, $amounts, $dueDates, $data, $by, $ip, $userAgent) {
            $legal = Settings::text('pickup')."\n\n".$this->budgets->warrantyText();
            $snapshot = [
                'os' => $order->label(),
                'quem' => $who,
                'autorizacao' => $who === 'autorizado' ? $authorization : null,
                'km_saida' => $order->km_out,
                'combustivel_saida' => $order->fuel_out,
                'pecas_substituidas' => $order->replaced_parts,
                'itens' => $this->approvedItems($order)->map(fn (OrderItem $i) => ['id' => $i->id, 'descricao' => $i->description, 'total' => $i->lineTotalCents()])->all(),
                'total' => $amounts['total'],
                'fotos_saida' => $order->media()->where('kind', MediaKind::Saida->value)->whereNull('replaced_at')->get()->map(fn (Media $m) => ['posicao' => $m->position, 'sha256' => $m->original_sha256])->all(),
            ];
            $path = $order->storageDir().'/assinatura-retirada-'.now()->format('Ymd-His').'.png';
            Storage::disk(Media::DISK)->put($path, $png);

            $consent = Consent::create([
                'service_order_id' => $order->id,
                'purpose' => Consent::PURPOSE_PICKUP,
                'method' => Consent::METHOD_SCREEN,
                'signer_name' => mb_substr($name, 0, 120),
                'signer_document' => $digits,
                'signer_relation' => $who === 'autorizado' ? 'autorizado por escrito' : 'cliente',
                'message_text' => $who === 'autorizado' ? mb_substr($authorization, 0, 2000) : null,
                'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'signed_at' => now(),
                'signature_path' => $path,
                'signature_sha256' => hash('sha256', $png),
                'snapshot' => $snapshot,
                'snapshot_sha256' => Budgets::hash($snapshot),
                'legal_text' => $legal,
                'legal_text_sha256' => hash('sha256', $legal),
                'recorded_by' => $by->id,
            ]);

            $order = app(StageMachine::class)->move($order, Stage::ProntaRetirada, Stage::Entregue, $by);
            $order->update(['delivered_by' => $by->id]);

            $this->toFinance($order, $amounts, $dueDates, $data, $by);
            AuditLog::record($order, 'delivery.done', null, ['consentimento' => $consent->id, 'total' => $amounts['total']]);

            return $order;
        });
    }

    /** Nota fiscal (emitida no sistema fiscal): situação e números. Pode ser lançada depois da entrega. */
    public function updateInvoice(ServiceOrder $order, string $status, ?string $serviceNumber, ?string $partsNumber, User $by): ServiceOrder
    {
        $this->assertAtendimento($by);

        if (! in_array($status, ['pendente', 'emitida'], true)) {
            throw new DomainException('Situação da nota inválida.');
        }

        $order->update([
            'invoice_status' => $status,
            'invoice_service_number' => mb_substr(trim((string) $serviceNumber), 0, 30) ?: null,
            'invoice_parts_number' => mb_substr(trim((string) $partsNumber), 0, 30) ?: null,
            'invoice_updated_at' => now(),
        ]);
        AuditLog::record($order, 'invoice.updated', null, ['situacao' => $status]);

        return $order;
    }

    /** "Copiar resumo" para o sistema fiscal: cliente, serviços e peças aprovados com os valores. */
    public function fiscalSummary(ServiceOrder $order): string
    {
        $order->loadMissing(['customer', 'vehicle']);
        $items = $this->approvedItems($order);
        $document = $order->customer->document ? Document::format($order->customer->document) : 'sem documento no cadastro';
        $lines = [
            $order->label().' · '.$order->vehicle->plateFormatted().' · '.$order->vehicle->description(),
            'Cliente: '.$order->customer->name.' · '.$document.($order->customer->address ? ' · '.$order->customer->address : ''),
            '',
        ];

        foreach (['servico' => 'SERVIÇOS (nota de serviço)', 'peca' => 'PEÇAS (nota de produto)'] as $kind => $title) {
            $rows = $items->where('kind', $kind);

            if ($rows->isEmpty()) {
                continue;
            }

            $lines[] = $title;

            foreach ($rows as $item) {
                $lines[] = $item->description
                    .($item->isPart() ? ' · '.Budgets::quantity((float) $item->quantity).' × '.Money::brl((int) $item->price_cents).($item->part_code ? ' · cód. '.$item->part_code : '') : '')
                    .' · '.Money::brl((int) $item->lineTotalCents());
            }

            $lines[] = 'Total '.mb_strtolower(explode(' ', $title)[0]).': '.Money::brl((int) $rows->sum(fn (OrderItem $i) => (int) $i->lineTotalCents()));
            $lines[] = '';
        }

        $lines[] = 'TOTAL: '.Money::brl($this->amounts($order)['total']);

        return implode("\n", $lines);
    }

    /** A receber (com parcelas, se houver) e o pagamento de cada prestador externo, sem redigitar. */
    private function toFinance(ServiceOrder $order, array $amounts, array $dueDates, array $data, User $by): void
    {
        if ($amounts['total'] > 0) {
            app(Receivables::class)->create([
                'service_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'service_date' => now()->toDateString(),
                'parts_cost_cents' => $amounts['parts_cost'],
                'labor_cents' => $amounts['labor'],
                'total_cents' => $amounts['total'],
                'customer_brought_part' => $amounts['customer_brought_part'],
                'payment_method' => $data['payment_method'] ?? null,
                'received_on' => ! empty($data['received_now']) && count($dueDates) === 1 ? now()->toDateString() : null,
                'notes' => 'Lançado na entrega da '.$order->label().'.',
            ], $dueDates, $by);
        }

        foreach ($this->approvedItems($order)->filter(fn (OrderItem $i) => $i->isService() && $i->supplier_id !== null && (int) $i->cost_cents > 0) as $service) {
            app(ProviderPayments::class)->create([
                'supplier_id' => $service->supplier_id,
                'service_order_id' => $order->id,
                'description' => $service->description,
                'amount_cents' => (int) $service->cost_cents,
                'due_date' => now()->toDateString(),
            ], $by);
        }
    }

    private function assertReady(ServiceOrder $order): void
    {
        if ($order->stage !== Stage::ProntaRetirada) {
            throw new DomainException('A entrega é registrada com a OS "Pronta para retirada".');
        }
    }

    private function assertAtendimento(User $by): void
    {
        if (! Gate::forUser($by)->allows('atendimento')) {
            throw new DomainException('A entrega é com o atendimento.');
        }
    }
}
