<?php

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\OrderItem;
use App\Models\QuoteRequest;
use App\Models\QuoteResponse;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Services\Quotes\Quotations;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Cotação (só atendimento e gestor: tem custo). Monta o pedido COT-AAAA-NNNN, abre o WhatsApp de cada
 * fornecedor com a mensagem pronta (a hora fica registrada), recebe as respostas por item e marca a vencedora.
 */
new class extends Component
{
    use RunsDomainActions;

    public ServiceOrder $order;

    /** @var list<int> */
    public array $selectedItems = [];

    /** @var list<int> */
    public array $selectedSuppliers = [];

    public string $maxDelivery = '';

    public string $paymentTerms = '';

    public string $answerUntil = '';

    /** Respostas na tela: [cotação][item][fornecedor] => campos. Cada mudança grava. */
    public array $responses = [];

    public function mount(ServiceOrder $order): void
    {
        abort_unless(auth()->user()->can('atendimento'), 403);
        $this->order = $order;
        $this->resetForm();
        $this->loadResponses();
    }

    private function resetForm(): void
    {
        $this->selectedItems = $this->pending->pluck('id')->all();
        $this->selectedSuppliers = [];
        $this->maxDelivery = '';
        $this->paymentTerms = '';
        $this->answerUntil = now()->addHours(3)->startOfHour()->format('Y-m-d\TH:i');
    }

    private function loadResponses(): void
    {
        $this->responses = [];

        foreach ($this->requests as $request) {
            foreach ($request->items as $item) {
                foreach ($request->sends as $send) {
                    $response = $request->responses->first(fn (QuoteResponse $r) => $r->os_item_id === $item->id && $r->supplier_id === $send->supplier_id);
                    $this->responses[$request->id][$item->id][$send->supplier_id] = [
                        'status' => $response?->status ?? '',
                        'brand' => (string) $response?->brand,
                        'origin' => (string) $response?->origin?->value,
                        'cost' => $response?->cost_cents !== null ? number_format($response->cost_cents / 100, 2, ',', '.') : '',
                        'lead_time' => (string) $response?->lead_time,
                        'warranty' => (string) $response?->warranty,
                    ];
                }
            }
        }
    }

    #[Computed]
    public function requests()
    {
        return $this->order->quoteRequests()->with(['items', 'sends.supplier', 'responses'])->get();
    }

    /** Peças a cotar que ainda não estão em nenhuma cotação. */
    #[Computed]
    public function pending()
    {
        $quoted = $this->order->quoteRequests()->with('items')->get()->flatMap(fn (QuoteRequest $r) => $r->items->pluck('id'))->all();

        return $this->order->items()->get()->filter(fn (OrderItem $item) => $item->toQuote() && ! in_array($item->id, $quoted, true))->values();
    }

    #[Computed]
    public function partsToQuote()
    {
        return $this->order->items()->get()->filter(fn (OrderItem $item) => $item->toQuote())->values();
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->active()->sellsParts()->orderBy('name')->get();
    }

    #[Computed]
    public function editable(): bool
    {
        return in_array($this->order->refresh()->stage, [Stage::EmDiagnostico, Stage::AguardandoCotacao], true);
    }

    private function changed(): void
    {
        unset($this->requests, $this->pending, $this->partsToQuote);
        $this->loadResponses();
        $this->dispatch('order-updated');
    }

    public function createRequest(Quotations $quotations): void
    {
        $request = $this->attempt(fn () => $quotations->create($this->order, array_map('intval', $this->selectedItems), array_map('intval', $this->selectedSuppliers), $this->maxDelivery, $this->paymentTerms, $this->answerUntil, auth()->user()));

        if ($request) {
            $this->dispatch('toast', message: 'Cotação '.$request->code.' criada. Toque em cada fornecedor para abrir o WhatsApp.', type: 'success');
            $this->changed();
            $this->resetForm();
        }
    }

    public function markSent(int $requestId, int $supplierId, Quotations $quotations): void
    {
        $request = $this->order->quoteRequests()->findOrFail($requestId);

        if ($this->attempt(fn () => $quotations->markSent($request, Supplier::query()->findOrFail($supplierId), auth()->user()))) {
            unset($this->requests);
        }
    }

    /** Campo de uma resposta mudou: grava a linha (cotação, item, fornecedor). */
    public function updatedResponses(mixed $value, string $key): void
    {
        [$requestId, $itemId, $supplierId] = array_map('intval', array_slice(explode('.', $key), 0, 3));
        $row = $this->responses[$requestId][$itemId][$supplierId] ?? null;

        if (! $row) {
            return;
        }

        // Digitou o custo sem marcar a resposta: o fornecedor tem a peça.
        if (($row['status'] ?? '') === '' && filled($row['cost'] ?? null)) {
            $row['status'] = $this->responses[$requestId][$itemId][$supplierId]['status'] = 'disponivel';
        }

        if (($row['status'] ?? '') === '') {
            return;
        }

        $request = $this->order->quoteRequests()->findOrFail($requestId);
        $item = $this->order->items()->findOrFail($itemId);

        $done = $this->attempt(fn () => app(Quotations::class)->saveResponse($request, Supplier::query()->findOrFail($supplierId), $item, [
            'status' => $row['status'],
            'brand' => $row['brand'] ?? null,
            'origin' => $row['origin'] ?? null,
            'cost_cents' => filled($row['cost'] ?? null) ? Money::parseBr((string) $row['cost']) : null,
            'lead_time' => $row['lead_time'] ?? null,
            'warranty' => $row['warranty'] ?? null,
        ], auth()->user()));

        if ($done) {
            unset($this->requests);
        }
    }

    public function chooseWinner(int $requestId, int $itemId, int $supplierId, Quotations $quotations): void
    {
        $request = $this->order->quoteRequests()->findOrFail($requestId);
        $response = QuoteResponse::query()->where(['quote_request_id' => $request->id, 'os_item_id' => $itemId, 'supplier_id' => $supplierId])->first();

        if (! $response) {
            $this->dispatch('toast', message: 'Lance a resposta do fornecedor antes (tem a peça e o preço).', type: 'error');

            return;
        }

        if ($this->attempt(fn () => $quotations->chooseWinner($response, auth()->user()))) {
            $this->dispatch('toast', message: 'Vencedor marcado: o item ficou com o fornecedor e o custo.', type: 'success');
            $this->changed();
        }
    }
};
?>

@php
    $quotations = app(Quotations::class);
@endphp

<div class="space-y-4">
    @if ($this->partsToQuote->isEmpty())
        <div class="{{ $cardClass }} p-4 text-sm text-gray-600 dark:text-gray-300">Nenhuma peça para cotar. Peça do cliente ou do estoque não vai para a cotação.</div>
    @endif

    @foreach ($this->requests as $request)
        <section class="{{ $cardClass }} p-4 space-y-4" wire:key="req-{{ $request->id }}" data-test="cotacao-{{ $request->code }}">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">{{ $request->code }}</h2>
                <span class="text-xs text-gray-500">{{ $request->created_at->format('d/m H:i') }}{{ $request->answer_until ? ' · responder até '.$request->answer_until->format('d/m H:i') : '' }}</span>
            </div>

            <div class="space-y-2">
                <p class="{{ $labelClass }}">Fornecedores (um toque em cada)</p>
                @foreach ($request->sends as $send)
                    @php($link = $quotations->whatsappLink($request, $send->supplier))
                    <div class="flex flex-wrap items-center gap-2" wire:key="send-{{ $send->id }}">
                        <span class="flex-1 min-w-[8rem] text-sm font-semibold">{{ $send->supplier->name }}</span>
                        @if ($send->sent_at)
                            <span class="text-xs text-emerald-700 dark:text-emerald-300"><i class="fas fa-check"></i> enviada {{ $send->sent_at->format('d/m H:i') }}</span>
                        @endif
                        @if ($link && $this->editable)
                            <a href="{{ $link }}" target="_blank" rel="noopener" wire:click="markSent({{ $request->id }}, {{ $send->supplier_id }})" class="{{ $send->sent_at ? $btnSecondary : $btnPrimary }} text-sm" data-test="whatsapp-{{ $send->supplier_id }}">
                                <i class="fab fa-whatsapp"></i> {{ $send->sent_at ? 'Abrir de novo' : 'Abrir WhatsApp' }}
                            </a>
                        @elseif (! $link)
                            <span class="text-xs text-red-600">sem WhatsApp no cadastro</span>
                        @endif
                    </div>
                @endforeach
                <details class="text-sm">
                    <summary class="cursor-pointer text-brand-600">Ver a mensagem (sem dados do cliente)</summary>
                    <div x-data="{ copied: false }" class="mt-2 space-y-2">
                        <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 dark:bg-gray-900 p-3 text-xs" data-test="mensagem-cotacao">{{ $quotations->message($request) }}</pre>
                        <button type="button" x-on:click="navigator.clipboard.writeText($el.previousElementSibling.innerText); copied = true; setTimeout(() => copied = false, 2000)" class="{{ $btnSecondary }} text-sm"><i class="fas fa-copy"></i> <span x-text="copied ? 'Copiada' : 'Copiar mensagem'"></span></button>
                    </div>
                </details>
            </div>

            <div class="space-y-3">
                <p class="{{ $labelClass }}">Respostas por peça</p>
                @foreach ($request->items as $item)
                    @php($winner = $request->responses->first(fn ($r) => $r->os_item_id === $item->id && $r->is_winner))
                    <div id="cotar-{{ $item->id }}" class="rounded-lg border {{ $winner ? 'border-emerald-300 dark:border-emerald-800' : 'border-gray-200 dark:border-gray-700' }} p-3 space-y-2" wire:key="ci-{{ $request->id }}-{{ $item->id }}">
                        <p class="font-semibold text-sm">{{ $item->description }} <span class="font-normal text-gray-500">· {{ $item->quantityLabel() }}</span></p>
                        @foreach ($request->sends as $send)
                            @php($key = $request->id.'.'.$item->id.'.'.$send->supplier_id)
                            @php($isWinner = $winner && $winner->supplier_id === $send->supplier_id)
                            {{-- Celular: dois campos por linha (resposta e custo primeiro); computador: uma linha só. --}}
                            <div @class(['grid grid-cols-2 gap-2 sm:grid-cols-12 items-end rounded-md p-2', 'bg-emerald-50 dark:bg-emerald-900/20' => $isWinner, 'bg-gray-50 dark:bg-gray-900/40' => ! $isWinner]) wire:key="r-{{ $key }}">
                                <p class="col-span-2 sm:col-span-12 text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $send->supplier->name }} @if ($isWinner) · <span class="text-emerald-700 dark:text-emerald-300"><i class="fas fa-trophy"></i> vencedor</span> @endif</p>
                                <select wire:model.live="responses.{{ $key }}.status" @disabled(! $this->editable) class="{{ $inputClass }} sm:col-span-2" aria-label="Tem a peça?">
                                    <option value="">Resposta…</option>
                                    @foreach (QuoteResponse::STATUSES as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="relative sm:col-span-2">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">R$</span>
                                    <input type="text" inputmode="decimal" wire:model.blur="responses.{{ $key }}.cost" @disabled(! $this->editable) placeholder="Custo" class="{{ $inputClass }} pl-9" data-test="custo-{{ $item->id }}-{{ $send->supplier_id }}">
                                </div>
                                <input type="text" wire:model.blur="responses.{{ $key }}.brand" @disabled(! $this->editable) placeholder="Marca" class="{{ $inputClass }} sm:col-span-2">
                                <select wire:model.live="responses.{{ $key }}.origin" @disabled(! $this->editable) class="{{ $inputClass }} sm:col-span-2" aria-label="Tipo da peça">
                                    <option value="">Tipo…</option>
                                    @foreach ([PartOrigin::Genuina, PartOrigin::Original, PartOrigin::Paralela, PartOrigin::Usada] as $origin)
                                        <option value="{{ $origin->value }}">{{ $origin->shortLabel() }}</option>
                                    @endforeach
                                </select>
                                <input type="text" wire:model.blur="responses.{{ $key }}.lead_time" @disabled(! $this->editable) placeholder="Prazo" class="{{ $inputClass }} sm:col-span-1">
                                <input type="text" wire:model.blur="responses.{{ $key }}.warranty" @disabled(! $this->editable) placeholder="Garantia" class="{{ $inputClass }} sm:col-span-1">
                                @if ($this->editable)
                                    <button type="button" wire:click="chooseWinner({{ $request->id }}, {{ $item->id }}, {{ $send->supplier_id }})" class="{{ $isWinner ? $btnSecondary : $btnPrimary }} col-span-2 sm:col-span-2 text-sm" data-test="vencedor-{{ $item->id }}-{{ $send->supplier_id }}"><i class="fas fa-trophy"></i> {{ $isWinner ? 'Vencedor' : 'Escolher' }}</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($this->editable && $this->pending->isNotEmpty())
        <form wire:submit="createRequest" class="{{ $cardClass }} p-4 space-y-4" data-test="nova-cotacao">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">{{ $this->requests->isEmpty() ? 'Pedir cotação' : 'Nova cotação' }}</h2>
            <div>
                <p class="{{ $labelClass }}">Peças</p>
                @foreach ($this->pending as $item)
                    <label class="flex items-center gap-3 min-h-11" wire:key="pi-{{ $item->id }}"><input type="checkbox" wire:model="selectedItems" value="{{ $item->id }}" class="size-5 rounded border-gray-300 text-brand-600"> {{ $item->description }} <span class="text-gray-500 text-sm">· {{ $item->quantityLabel() }}</span></label>
                @endforeach
            </div>
            <div>
                <p class="{{ $labelClass }}">Fornecedores</p>
                @forelse ($this->suppliers as $supplier)
                    <label class="flex items-center gap-3 min-h-11" wire:key="ps-{{ $supplier->id }}"><input type="checkbox" wire:model="selectedSuppliers" value="{{ $supplier->id }}" class="size-5 rounded border-gray-300 text-brand-600" data-test="fornecedor-{{ $supplier->id }}"> {{ $supplier->name }} <span class="text-gray-500 text-sm">{{ $supplier->lines ? '· '.$supplier->lines : '' }}</span></label>
                @empty
                    <p class="text-sm text-gray-500">Nenhum fornecedor de peças. Cadastre em <a href="{{ route('suppliers') }}" class="text-brand-600 hover:underline">Fornecedores</a>.</p>
                @endforelse
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label class="{{ $labelClass }}" for="maxDelivery">Prazo máximo de entrega</label><input id="maxDelivery" type="text" wire:model="maxDelivery" maxlength="60" placeholder="Ex.: hoje até 17h" class="{{ $inputClass }}"></div>
                <div><label class="{{ $labelClass }}" for="paymentTerms">Pagamento</label><input id="paymentTerms" type="text" wire:model="paymentTerms" maxlength="120" placeholder="Ex.: boleto 28 dias" class="{{ $inputClass }}"></div>
                <div><label class="{{ $labelClass }}" for="answerUntil">Responder até</label><input id="answerUntil" type="datetime-local" wire:model="answerUntil" class="{{ $inputClass }}"></div>
            </div>
            <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}" data-test="criar-cotacao"><i class="fas fa-paper-plane"></i> Montar a cotação</button></div>
        </form>
    @endif
</div>
