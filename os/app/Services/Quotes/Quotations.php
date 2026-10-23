<?php

namespace App\Services\Quotes;

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\QuoteRequest;
use App\Models\QuoteResponse;
use App\Models\QuoteSend;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cotação semi-automática: monta o pedido COT-AAAA-NNNN com as peças a cotar, abre o WhatsApp de cada
 * fornecedor com a mensagem pronta (um toque por fornecedor, hora registrada), recebe as respostas por item
 * e marca a vencedora, que preenche o item (fornecedor, custo, marca, prazo).
 *
 * A mensagem NUNCA leva nome, CPF ou telefone do cliente: só o carro (placa, chassi, modelo) e as peças.
 */
final class Quotations
{
    /**
     * @param  list<int>  $itemIds
     * @param  list<int>  $supplierIds
     */
    public function create(ServiceOrder $order, array $itemIds, array $supplierIds, ?string $maxDelivery, ?string $paymentTerms, ?string $answerUntil, User $by): QuoteRequest
    {
        $this->assertCanQuote($order, $by);

        $items = $order->items()->whereIn('id', $itemIds)->get()->filter(fn (OrderItem $i) => $i->toQuote() && ! $i->isFrozen());
        $suppliers = Supplier::query()->active()->sellsParts()->whereIn('id', $supplierIds)->get();

        if ($items->isEmpty()) {
            throw new DomainException('Escolha ao menos uma peça para cotar.');
        }

        if ($suppliers->isEmpty()) {
            throw new DomainException('Escolha ao menos um fornecedor de peças.');
        }

        $until = filled($answerUntil) ? CarbonImmutable::parse($answerUntil) : null;

        return DB::transaction(function () use ($order, $items, $suppliers, $maxDelivery, $paymentTerms, $until, $by) {
            $request = QuoteRequest::create([
                'service_order_id' => $order->id,
                'code' => $this->nextCode(),
                'max_delivery' => mb_substr(trim((string) $maxDelivery), 0, 60) ?: null,
                'payment_terms' => mb_substr(trim((string) $paymentTerms), 0, 120) ?: null,
                'answer_until' => $until,
                'created_by' => $by->id,
            ]);

            $request->items()->attach($items->pluck('id')->all());

            foreach ($suppliers as $supplier) {
                QuoteSend::create(['quote_request_id' => $request->id, 'supplier_id' => $supplier->id]);
            }

            AuditLog::record($order, 'quote.created', null, ['cotacao' => $request->code, 'itens' => $items->count(), 'fornecedores' => $suppliers->count()]);

            return $request;
        });
    }

    /** COT-2026-0001: sequência por ano, em todas as OS. */
    public function nextCode(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = 'COT-'.$year.'-';
        $last = QuoteRequest::query()->where('code', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('code')->value('code');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** A mensagem para o fornecedor: carro e peças, sem nenhum dado do cliente. */
    public function message(QuoteRequest $request): string
    {
        $request->loadMissing(['serviceOrder.vehicle', 'serviceOrder.unit', 'items']);
        $order = $request->serviceOrder;
        $vehicle = $order->vehicle;

        $lines = [
            '*Cotação '.$request->code.'* · '.($order->unit->trade_name ?? 'Oficina'),
            '',
            'Veículo: '.collect([$vehicle->brand_model, $vehicle->year, $vehicle->engine ? 'motor '.$vehicle->engine : null])->filter()->implode(' · '),
            'Placa: '.$vehicle->plateFormatted().($vehicle->chassis ? ' · Chassi: '.$vehicle->chassis : ''),
            '',
            '*Peças*',
        ];

        foreach ($request->items as $i => $item) {
            $lines[] = ($i + 1).'. '.$item->description
                .' · qtd '.rtrim(rtrim(number_format((float) $item->quantity, 2, ',', ''), '0'), ',')
                .($item->part_code ? ' · cód. '.$item->part_code : '')
                .($item->preferred_brand ? ' · marca preferida '.$item->preferred_brand : '')
                .($item->accepted_brands ? ' · aceita '.$item->accepted_brands : '')
                .($item->quote_detail ? ' · '.$item->quote_detail : '');
        }

        $lines[] = '';

        if ($request->max_delivery) {
            $lines[] = 'Prazo máximo de entrega: '.$request->max_delivery;
        }

        if ($request->payment_terms) {
            $lines[] = 'Pagamento: '.$request->payment_terms;
        }

        if ($request->answer_until) {
            $lines[] = 'Responder até: '.$request->answer_until->format('d/m H:i');
        }

        $lines[] = 'Por favor, responda com marca, preço, prazo e garantia de cada item.';

        return implode("\n", $lines);
    }

    public function whatsappLink(QuoteRequest $request, Supplier $supplier): ?string
    {
        return Phone::whatsappLink($supplier->whatsapp, $this->message($request));
    }

    /** Toque no fornecedor: o WhatsApp abre com a mensagem e a hora fica registrada. */
    public function markSent(QuoteRequest $request, Supplier $supplier, User $by): QuoteSend
    {
        $this->assertCanQuote($request->serviceOrder, $by);

        $send = QuoteSend::query()->firstOrCreate(['quote_request_id' => $request->id, 'supplier_id' => $supplier->id]);
        $send->update(['sent_at' => now(), 'sent_by' => $by->id]);

        return $send;
    }

    /**
     * Resposta de um fornecedor para um item.
     *
     * @param  array{status: string, brand?: ?string, origin?: ?string, cost_cents?: ?int, lead_time?: ?string, warranty?: ?string}  $data
     */
    public function saveResponse(QuoteRequest $request, Supplier $supplier, OrderItem $item, array $data, User $by): QuoteResponse
    {
        $this->assertCanQuote($request->serviceOrder, $by);

        if (! $request->items()->whereKey($item->id)->exists()) {
            throw new DomainException('Este item não está nesta cotação.');
        }

        if (! array_key_exists($data['status'] ?? '', QuoteResponse::STATUSES)) {
            throw new DomainException('Escolha se o fornecedor tem a peça.');
        }

        $available = $data['status'] !== 'nao_tem';
        $cost = $available && ($data['cost_cents'] ?? null) !== null ? max(0, (int) $data['cost_cents']) : null;

        return QuoteResponse::query()->updateOrCreate(
            ['quote_request_id' => $request->id, 'supplier_id' => $supplier->id, 'os_item_id' => $item->id],
            [
                'status' => $data['status'],
                'brand' => $available ? (mb_substr(trim((string) ($data['brand'] ?? '')), 0, 60) ?: null) : null,
                'origin' => $available && filled($data['origin'] ?? null) ? (PartOrigin::tryFrom($data['origin']) ?? throw new DomainException('Tipo de peça inválido.')) : null,
                'cost_cents' => $cost,
                'lead_time' => $available ? (mb_substr(trim((string) ($data['lead_time'] ?? '')), 0, 60) ?: null) : null,
                'warranty' => $available ? (mb_substr(trim((string) ($data['warranty'] ?? '')), 0, 60) ?: null) : null,
                'created_by' => $by->id,
            ],
        );
    }

    /** Vencedor do item: só um por item; o item passa a ter o fornecedor, o custo, a marca e o prazo dele. */
    public function chooseWinner(QuoteResponse $response, User $by): OrderItem
    {
        $request = QuoteRequest::query()->with('serviceOrder')->findOrFail($response->quote_request_id);
        $this->assertCanQuote($request->serviceOrder, $by);

        if ($response->status === 'nao_tem' || $response->cost_cents === null) {
            throw new DomainException('Para ser o vencedor, a resposta precisa ter o preço.');
        }

        return DB::transaction(function () use ($response, $request) {
            QuoteResponse::query()->where('os_item_id', $response->os_item_id)->update(['is_winner' => false]);
            $response->update(['is_winner' => true]);

            $item = OrderItem::query()->findOrFail($response->os_item_id);
            $item->update([
                'supplier_id' => $response->supplier_id,
                'cost_cents' => $response->cost_cents,
                'brand' => $response->brand,
                'lead_time' => $response->lead_time,
                'origin' => $response->origin ?? $item->origin,
            ]);

            AuditLog::record($request->serviceOrder, 'quote.winner', null, ['cotacao' => $request->code, 'item' => $item->id, 'fornecedor' => $response->supplier_id, 'custo' => $response->cost_cents]);

            return $item;
        });
    }

    /**
     * O que falta na cotação para seguir para o orçamento.
     *
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order, ?Collection $items = null): array
    {
        $missing = [];

        foreach ($items ?? $order->items()->get() as $item) {
            if ($item->toQuote() && ($item->supplier_id === null || $item->cost_cents === null)) {
                $missing[] = ['label' => 'Vencedor da cotação: '.$item->description, 'tab' => 'cotacao', 'field' => 'cotar-'.$item->id];
            }

            if ($item->isService() && $item->supplier_id !== null && $item->cost_cents === null) {
                $missing[] = ['label' => 'Custo do prestador: '.$item->description, 'tab' => 'itens', 'field' => 'item-'.$item->id];
            }
        }

        return $missing;
    }

    private function assertCanQuote(ServiceOrder $order, User $by): void
    {
        if (! Gate::forUser($by)->allows('atendimento')) {
            throw new DomainException('A cotação é feita pelo atendimento.');
        }

        $stages = [Stage::EmDiagnostico, Stage::AguardandoCotacao, ...Budgets::ADDENDUM_STAGES];

        if (! in_array($order->stage, $stages, true)) {
            throw new DomainException('A cotação é feita na montagem do orçamento (ou de um adendo, depois do aceite).');
        }
    }
}
