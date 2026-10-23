<?php

use App\Enums\MediaKind;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Budget;
use App\Models\Media;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Services\Budgets\Budgets;
use App\Services\Items\OrderItems;
use App\Services\Media\MediaStore;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Orçamento (só atendimento e gestor: tem preço). Monta (preço de cada item, previsão, pagamento), mostra a
 * prévia, envia (PDF pelo WhatsApp, resumo ou link) e registra a resposta do cliente (aceite de todos ou de
 * parte dos itens, ou recusa). Depois do aceite, os itens novos viram adendo. O gestor vê a margem.
 */
new class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public ServiceOrder $order;

    /** Preço digitado de cada item em montagem: [itemId => "1.234,56"]. Grava ao sair do campo. */
    public array $prices = [];

    public string $expectedDate = '';

    public string $paymentTerms = '';

    public string $budgetNotes = '';

    // Envio pelo WhatsApp (PDF) e link
    #[Locked]
    public ?string $preparedPath = null;

    public bool $askSentConfirmation = false;

    // Resposta do cliente
    public ?string $deciding = null;

    public string $method = '';

    /** @var list<int> */
    public array $approvedItems = [];

    public bool $nonOriginal = false;

    public string $signerName = '';

    public string $signerDocument = '';

    public string $messageText = '';

    public string $messageAt = '';

    public string $reason = '';

    public string $diagnosisFee = '';

    public ?int $printMediaId = null;

    /** Print da mensagem ($wire.upload). */
    public $upload;

    public function mount(ServiceOrder $order, ?string $open = null): void
    {
        abort_unless(auth()->user()->can('atendimento'), 403);
        $this->order = $order;
        $this->fillForm();

        if ($open && $this->pending) {
            $this->openDecision($open);
        }
    }

    private function fillForm(): void
    {
        $this->order->refresh();
        $this->expectedDate = (string) $this->order->expected_date?->toDateString();
        $this->paymentTerms = (string) $this->order->payment_terms;
        $this->budgetNotes = (string) $this->order->budget_notes;
        $this->fillPrices();
    }

    private function fillPrices(): void
    {
        $this->prices = $this->draft->mapWithKeys(fn (OrderItem $i) => [$i->id => $i->price_cents === null ? '' : number_format($i->price_cents / 100, 2, ',', '.')])->all();
    }

    /** Algo mudou: recarrega listas e preços (as condições que a pessoa está digitando ficam como estão). */
    private function changed(): void
    {
        unset($this->draft, $this->budgets, $this->missing, $this->pending);
        $this->order->refresh();
        $this->fillPrices();
        $this->dispatch('order-updated');
    }

    #[Computed]
    public function draft()
    {
        return app(Budgets::class)->draftItems($this->order);
    }

    #[Computed]
    public function budgets()
    {
        return $this->order->budgets()->with(['items', 'consent', 'decider'])->get()->sortByDesc('number')->values();
    }

    #[Computed]
    public function pending(): ?Budget
    {
        return app(Budgets::class)->pending($this->order);
    }

    #[Computed]
    public function missing(): array
    {
        return app(Budgets::class)->missing($this->order);
    }

    /** Monta orçamento (antes do envio) ou adendo (depois do aceite). */
    public function assembling(): bool
    {
        return in_array($this->order->stage, [Stage::EmDiagnostico, Stage::AguardandoCotacao], true)
            || (Budgets::isAddendumStage($this->order->stage) && $this->draft->isNotEmpty());
    }

    public function isAddendum(): bool
    {
        return Budgets::isAddendumStage($this->order->stage);
    }

    /**
     * Preço digitado: grava no item (pelo serviço dos itens, que confere perfil e etapa). O navegador manda um
     * campo ("prices.12") ou, às vezes, a lista inteira: aí grava só o que mudou.
     */
    public function updatedPrices(mixed $value, ?string $key = null): void
    {
        $typed = $key === null ? (array) $value : [$key => $value];
        $saved = false;

        foreach ($typed as $id => $text) {
            $item = OrderItem::query()->where('service_order_id', $this->order->id)->find((int) $id);
            $cents = Money::parseBr((string) $text);

            if (! $item || $item->price_cents === $cents) {
                continue;
            }

            $saved = $this->attempt(fn () => app(OrderItems::class)->update($item, ['price_cents' => $cents], auth()->user()), 'prices.'.$id) || $saved;
        }

        if ($saved) {
            $this->changed();
        }
    }

    public function saveConditions(Budgets $budgets): void
    {
        $done = $this->attempt(fn () => $budgets->updateConditions($this->order, [
            'expected_date' => $this->expectedDate,
            'payment_terms' => $this->paymentTerms,
            'budget_notes' => $this->budgetNotes,
        ], auth()->user()), 'expectedDate');

        if ($done) {
            $this->dispatch('toast', message: 'Condições salvas.', type: 'success');
            $this->changed();
            $this->fillForm();
        }
    }

    public function sendAddendum(Budgets $budgets): void
    {
        if ($this->attempt(fn () => $budgets->sendAddendum($this->order, auth()->user()))) {
            $this->dispatch('toast', message: 'Adendo pronto: envie ao cliente e registre a resposta.', type: 'success');
            $this->changed();
        }
    }

    /** Primeiro toque do envio: gera e guarda o PDF e devolve o arquivo para o compartilhar do celular. */
    public function preparePdf(Budgets $budgets): ?array
    {
        $budget = $this->pending;

        if (! $budget) {
            return null;
        }

        $prepared = $this->attempt(fn () => $budgets->preparePdf($budget, auth()->user()));

        if (! $prepared) {
            return null;
        }

        $this->preparedPath = $prepared['path'];

        return [
            'b64' => base64_encode($prepared['binary']),
            'name' => ($budget->isAddendum() ? 'adendo-'.$budget->number : 'orcamento').'-'.$this->order->number.'-'.$this->order->vehicle->plate.'.pdf',
            'text' => $budgets->message($budget),
            'link' => $budgets->whatsappLink($budget),
        ];
    }

    public function confirmSent(string $via, Budgets $budgets): void
    {
        $budget = $this->pending;
        $prepared = null;

        if ($budget && $this->preparedPath && str_starts_with($this->preparedPath, $this->order->storageDir().'/') && Storage::disk(Media::DISK)->exists($this->preparedPath)) {
            $binary = (string) Storage::disk(Media::DISK)->get($this->preparedPath);
            preg_match('/(\d{8}-\d{6})\.pdf$/', $this->preparedPath, $m);
            $prepared = ['path' => $this->preparedPath, 'sha256' => hash('sha256', $binary), 'binary' => $binary, 'at' => CarbonImmutable::createFromFormat('Ymd-His', $m[1] ?? now()->format('Ymd-His'))];
        }

        if ($budget && $this->attempt(fn () => $budgets->markSent($budget, $via, auth()->user(), $prepared))) {
            $this->reset('preparedPath', 'askSentConfirmation');
            $this->dispatch('toast', message: 'Envio registrado. A validade conta a partir de agora.', type: 'success');
            $this->changed();
        }
    }

    /** Link para o cliente responder: cria (ou recria) e devolve o endereço para copiar e a conversa do WhatsApp. */
    public function createLink(Budgets $budgets): ?array
    {
        $budget = $this->pending;
        $link = $budget ? $this->attempt(fn () => $budgets->createLink($budget, auth()->user())) : null;

        if (! $link) {
            return null;
        }

        unset($this->budgets, $this->pending);

        return ['url' => $link->url(), 'whatsapp' => $budgets->whatsappLink($link->budget->refresh(), $link->url())];
    }

    #[On('open-decision')]
    public function openDecision(string $kind): void
    {
        $budget = $this->pending;

        if (! $budget) {
            $this->dispatch('toast', message: 'Nenhum orçamento esperando resposta.', type: 'error');

            return;
        }

        $this->resetValidation();
        $this->deciding = $kind === 'recusa' ? 'recusa' : 'aceite';
        $this->method = $this->order->isIziApproval() && $this->deciding === 'aceite' ? 'alcada' : 'mensagem';
        $this->approvedItems = $budget->items->pluck('id')->all();
        $this->nonOriginal = false;
        $this->signerName = $this->order->approver_kind === 'outro' ? (string) $this->order->approver_name : ($this->order->customer->is_izi_fleet ? '' : $this->order->customer->name);
        $this->signerDocument = (string) ($this->order->approver_kind === 'outro' ? '' : $this->order->customer->document);
        $this->messageText = '';
        $this->messageAt = '';
        $this->reason = '';
        $fee = $this->order->diagnosis_charged ? ($this->order->diagnosis_fee_cents ?? Settings::int('diagnosis_fee_cents')) : null;
        $this->diagnosisFee = $fee ? number_format($fee / 100, 2, ',', '.') : '';
        $this->printMediaId = null;
    }

    public function closeDecision(): void
    {
        $this->deciding = null;
    }

    /** Print da mensagem do cliente (opcional), guardado na OS com o hash conferido. */
    public function savePhoto(string $slot, ?string $hash, ?int $takenAt, MediaStore $store): void
    {
        if (! $this->upload || $slot !== 'print') {
            return;
        }

        $this->validate(['upload' => 'image|max:15360'], ['upload.image' => 'O print precisa ser uma imagem.', 'upload.max' => 'A imagem pode ter no máximo 15 MB.']);
        $media = $this->attempt(fn () => $store->storePhoto($this->order, MediaKind::Geral, $this->upload, $hash, $takenAt, auth()->user(), ['caption' => 'Print da resposta do cliente ao orçamento']));
        $this->reset('upload');

        if ($media) {
            $this->printMediaId = $media->id;
        }
    }

    public function saveDecision(?string $signature, Budgets $budgets): void
    {
        $budget = $this->pending;

        if (! $budget) {
            return;
        }

        $data = [
            'method' => $this->method,
            'items' => array_map('intval', $this->approvedItems),
            'non_original' => $this->nonOriginal,
            'signer_name' => $this->signerName,
            'signer_document' => $this->signerDocument,
            'message_text' => $this->messageText,
            'message_at' => $this->messageAt ?: null,
            'signature' => $signature,
            'reason' => $this->reason,
            'diagnosis_fee_cents' => Money::parseBr($this->diagnosisFee),
            'evidence_media_id' => $this->printMediaId,
        ];

        $user = auth()->user();
        $done = $this->deciding === 'recusa'
            ? $this->attempt(fn () => $budgets->refuse($budget, $data, $user, request()->ip(), request()->userAgent()))
            : $this->attempt(fn () => $budgets->approve($budget, $data, $user, request()->ip(), request()->userAgent()));

        if ($done) {
            $this->dispatch('toast', message: $this->deciding === 'recusa' ? 'Recusa registrada.' : $budget->title().' aprovado.', type: 'success');
            $this->deciding = null;
            $this->changed();
        }
    }
};
?>

@php
    $user = auth()->user();
    $budgets = app(Budgets::class);
    $showMargin = $user->isGestor();
    $assembling = $this->assembling();
    $addendum = $this->isAddendum();
    $pctMargin = fn (?int $price, ?int $cost) => $price && $cost !== null ? round(($price - $cost) / $price * 100) : null;
@endphp

<div class="space-y-4">
    {{-- Montagem do orçamento (ou do adendo) --}}
    @if ($assembling)
        <section id="montagem" class="{{ $cardClass }} p-4 space-y-4" data-test="montagem">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">{{ $addendum ? 'Adendo: itens novos' : 'Montar o orçamento' }}</h2>
                <a href="{{ route('orders.budget.preview', $order) }}" target="_blank" class="{{ $btnSecondary }} text-sm" data-test="previa"><i class="fas fa-file-pdf"></i> Prévia do PDF</a>
            </div>
            @if ($addendum)
                <p class="text-sm text-gray-600 dark:text-gray-300">Itens lançados depois do aceite. O cliente aprova à parte; o serviço não termina com adendo sem resposta.</p>
            @endif

            @forelse ($this->draft as $item)
                @php($line = $item->lineTotalCents())
                @php($cost = $item->lineCostCents())
                <div id="preco-{{ $item->id }}" class="grid gap-2 sm:grid-cols-12 items-center border-b border-gray-100 dark:border-gray-700 pb-3" wire:key="d-{{ $item->id }}">
                    <div class="sm:col-span-6 min-w-0">
                        <p class="font-semibold">{{ $item->description }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $item->isPart() ? 'Peça' : 'Serviço' }} · {{ $item->quantityLabel() }}
                            @if ($item->isService() && $item->hoursLabel()) · {{ $item->hoursLabel() }} @endif
                            @if ($item->origin) · {{ $item->origin->shortLabel() }} @endif
                            @if ($item->priority === 'recomendado') · recomendado @endif
                            @if ($cost !== null) · custo {{ $money($cost) }}{{ $item->supplier ? ' ('.$item->supplier->name.')' : '' }} @endif
                        </p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="sr-only" for="price-{{ $item->id }}">Preço {{ $item->isPart() ? 'unitário' : 'do serviço' }}</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">R$</span>
                            <input id="price-{{ $item->id }}" type="text" inputmode="decimal" wire:model.live.blur="prices.{{ $item->id }}" placeholder="{{ $item->isPart() ? 'Preço unitário' : 'Preço do serviço' }}" class="{{ $inputClass }} pl-9" data-test="preco-{{ $item->id }}">
                        </div>
                        @error('prices.'.$item->id) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-3 text-right">
                        <p class="font-semibold">{{ $line !== null ? $money($line) : '—' }}</p>
                        @if ($showMargin && $pctMargin($line, $cost) !== null)
                            <p class="text-xs text-gray-500" data-test="margem-{{ $item->id }}">margem {{ $pctMargin($line, $cost) }}%</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum item para orçar. Lance as peças e os serviços na aba "Peças e serviços".</p>
            @endforelse

            @php($totals = $budgets->totals($this->draft))
            <div class="ml-auto w-full sm:w-72 space-y-1 text-sm">
                <div class="flex justify-between"><span>Peças</span><span>{{ $money($totals['parts']) }}</span></div>
                <div class="flex justify-between"><span>Mão de obra</span><span>{{ $money($totals['labor']) }}</span></div>
                <div class="flex justify-between border-t border-gray-200 dark:border-gray-700 pt-1 text-base font-bold"><span>Total</span><span data-test="total-montagem">{{ $money($totals['total']) }}</span></div>
                @if ($showMargin)
                    @php($costTotal = (int) $this->draft->sum(fn ($i) => (int) $i->lineCostCents()))
                    <div class="flex justify-between text-xs text-gray-500"><span>Custo · margem</span><span>{{ $money($costTotal) }} · {{ $pctMargin($totals['total'], $costTotal) ?? '—' }}%</span></div>
                @endif
            </div>

            @unless ($addendum)
                <form wire:submit="saveConditions" class="grid gap-3 sm:grid-cols-2 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <div>
                        <label class="{{ $labelClass }}" for="expectedDate">Previsão de entrega</label>
                        <input id="expectedDate" type="date" wire:model="expectedDate" class="{{ $inputClass }}" data-test="previsao">
                        @error('expectedDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="paymentTerms">Forma de pagamento</label>
                        <input id="paymentTerms" type="text" wire:model="paymentTerms" maxlength="200" list="formas-pagamento" placeholder="Ex.: Pix ou cartão na retirada" class="{{ $inputClass }}" data-test="pagamento">
                        <datalist id="formas-pagamento">
                            <option value="Pix na retirada"></option>
                            <option value="Cartão de crédito na retirada (até 3x)"></option>
                            <option value="Dinheiro ou Pix na retirada"></option>
                            <option value="50% na aprovação e 50% na retirada"></option>
                            <option value="Faturado (frota)"></option>
                        </datalist>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="budgetNotes">Observações para o cliente</label>
                        <textarea id="budgetNotes" wire:model="budgetNotes" rows="2" maxlength="2000" class="{{ $inputClass }}"></textarea>
                    </div>
                    <p class="text-xs text-gray-500 sm:col-span-2">Validade: {{ Settings::int('budget_validity_days') ?? 10 }} dias a partir do envio. Garantia de {{ Settings::int('warranty_days') ?? 90 }} dias (texto em Ajustes).</p>
                    <div class="sm:col-span-2 flex justify-end"><button type="submit" class="{{ $btnPrimary }}" data-test="salvar-condicoes"><i class="fas fa-check"></i> Salvar condições</button></div>
                </form>
            @endunless

            @if ($this->missing !== [])
                <div class="text-sm">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">Falta para enviar:</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($this->missing as $item)
                            <li class="text-red-700 dark:text-red-300"><i class="fas fa-circle-exclamation"></i> {{ $item['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @elseif ($addendum)
                <div class="flex justify-end"><button type="button" wire:click="sendAddendum" class="{{ $btnPrimary }}" data-test="enviar-adendo"><i class="fas fa-paper-plane"></i> Fechar o adendo para enviar</button></div>
            @else
                <p class="text-sm text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check"></i> Pronto. Use "Enviar orçamento ao cliente" no Próximo passo: o orçamento fica congelado e não muda mais.</p>
            @endif
        </section>
    @endif

    {{-- Orçamento e adendos enviados --}}
    @foreach ($this->budgets as $budget)
        <section id="orcamento-{{ $budget->number }}" class="{{ $cardClass }} p-4 space-y-3" wire:key="b-{{ $budget->id }}" data-test="orcamento-{{ $budget->number }}">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide">{{ $budget->title() }}</h2>
                <span @class([
                    'rounded px-2 py-0.5 text-xs font-semibold',
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $budget->isPending(),
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $budget->status === Budget::STATUS_APPROVED,
                    'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200' => $budget->status === Budget::STATUS_REFUSED,
                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $budget->status === Budget::STATUS_CANCELLED,
                ]) data-test="situacao-{{ $budget->number }}">{{ $budget->statusLabel() }}</span>
                <span class="ml-auto text-lg font-bold">{{ $money($budget->total_cents) }}</span>
            </div>
            <p class="text-xs text-gray-500">
                Congelado em {{ $budget->frozen_at?->format('d/m/Y H:i') }}
                @if ($budget->sent_at) · enviado em {{ $budget->sent_at->format('d/m/Y H:i') }} @endif
                @if ($budget->valid_until) · válido até {{ $budget->valid_until->format('d/m/Y') }} @endif
                · SHA-256 {{ substr($budget->snapshot_sha256, 0, 12) }}…
            </p>
            @if ($budget->isExpired())
                <p class="text-sm font-semibold text-amber-700 dark:text-amber-300"><i class="fas fa-triangle-exclamation"></i> A validade venceu. Confirme os preços com o cliente antes de registrar o aceite.</p>
            @endif

            <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @foreach ($budget->snapshot['itens'] as $row)
                    @php($current = $budget->items->firstWhere('id', $row['id']))
                    <li class="flex flex-wrap items-baseline gap-2 py-1.5">
                        <span @class(['flex-1 min-w-0', 'line-through text-gray-400' => $current?->approval === OrderItem::REFUSED])>{{ $row['descricao'] }} <span class="text-gray-500">· {{ Budgets::quantity($row['quantidade']) }}{{ $row['tipo'] === 'peca' ? ' un' : 'x' }}{{ $row['origem'] ? ' · '.$row['origem'] : '' }}</span></span>
                        <span>{{ $money($row['total']) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($budget->status === Budget::STATUS_APPROVED && $budget->approved_total_cents !== $budget->total_cents)
                <p class="text-right text-sm font-semibold">Aprovado: {{ $money($budget->approved_total_cents) }}</p>
            @endif

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('orders.budget.pdf', ['order' => $order, 'budget' => $budget]) }}" target="_blank" class="{{ $btnSecondary }} text-sm"><i class="fas fa-file-pdf"></i> Ver PDF</a>
                @if ($budget->pdf_path)
                    <a href="{{ route('orders.budget.pdf', ['order' => $order, 'budget' => $budget, 'enviado' => 1]) }}" target="_blank" class="{{ $btnSecondary }} text-sm"><i class="fas fa-paper-plane"></i> PDF enviado</a>
                @endif
            </div>

            @if ($budget->isPending())
                {{-- Enviar --}}
                <div class="space-y-2 border-t border-gray-100 dark:border-gray-700 pt-3">
                    <p class="{{ $labelClass }}">Enviar ao cliente</p>
                    <div class="flex flex-wrap gap-2" x-data="shareDocument">
                        <button type="button" x-show="! file" x-on:click="prepare()" x-bind:disabled="busy" class="{{ $btnPrimary }}" data-test="preparar-envio">
                            <i class="fab fa-whatsapp"></i> <span x-text="busy ? 'Preparando o PDF…' : @js($budget->sent_at ? 'Enviar de novo pelo WhatsApp' : 'Enviar PDF pelo WhatsApp')"></span>
                        </button>
                        <button type="button" x-show="file" x-cloak x-on:click="send()" class="{{ $btnPrimary }}" data-test="enviar"><i class="fas fa-share-nodes"></i> PDF pronto: escolher o contato</button>
                    </div>
                    <div class="flex flex-wrap gap-2" x-data="{ copied: false, url: @js(($l = $budgets->activeLink($budget)) ? $l->url() : null), wa: null }">
                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($budgets->summary($budget))); copied = true; setTimeout(() => copied = false, 2000)" class="{{ $btnSecondary }}" data-test="copiar-resumo"><i class="fas fa-copy"></i> <span x-text="copied ? 'Resumo copiado' : 'Copiar resumo'"></span></button>
                        @unless ($order->isIziApproval())
                            <button type="button" x-on:click="$wire.createLink().then(r => { if (r) { url = r.url; wa = r.whatsapp; navigator.clipboard?.writeText(r.url) } })" class="{{ $btnSecondary }}" data-test="criar-link"><i class="fas fa-link"></i> <span x-text="url ? 'Gerar link novo' : 'Link para o cliente responder'"></span></button>
                            <template x-if="url">
                                <div class="w-full flex flex-wrap items-center gap-2 text-xs">
                                    <code class="flex-1 min-w-0 truncate rounded bg-gray-50 dark:bg-gray-900 px-2 py-2" x-text="url" data-test="link-cliente"></code>
                                    <button type="button" x-on:click="navigator.clipboard.writeText(url)" class="{{ $btnSecondary }} text-sm"><i class="fas fa-copy"></i> Copiar</button>
                                    <a x-show="wa" x-bind:href="wa" target="_blank" rel="noopener" class="{{ $btnPrimary }} text-sm"><i class="fab fa-whatsapp"></i> Mandar o link</a>
                                </div>
                            </template>
                        @endunless
                    </div>
                    <p class="text-xs text-gray-500">Mandar para: {{ \App\Support\Phone::format($budgets->recipientPhone($order)) ?: 'sem WhatsApp cadastrado' }}{{ $order->isIziApproval() ? ' (investidor / Izi)' : '' }}.</p>
                    @if ($askSentConfirmation)
                        <div class="rounded-lg bg-amber-50 dark:bg-amber-900/30 p-3 text-sm space-y-2">
                            <p>O PDF foi baixado e o WhatsApp abriu com a mensagem. Anexe o PDF na conversa e envie.</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="confirmSent('manual')" class="{{ $btnPrimary }}" data-test="confirmar-envio">Já enviei</button>
                                <button type="button" wire:click="$set('askSentConfirmation', false)" class="{{ $btnSecondary }}">Ainda não</button>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Resposta --}}
                <div id="resposta" class="space-y-2 border-t border-gray-100 dark:border-gray-700 pt-3">
                    <p class="{{ $labelClass }}">Resposta do cliente</p>
                    @if ($budgets->aboveAuthority($budget))
                        <p class="text-sm font-semibold text-amber-700 dark:text-amber-300"><i class="fas fa-user-shield"></i> Carro da Izi acima da alçada ({{ $money((int) (Settings::int('izi_approval_limit_cents') ?? 150000)) }}): {{ $user->isGestor() ? 'a aprovação é sua.' : 'quem aprova é o gestor (aparece nos avisos dele).' }}</p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="openDecision('aceite')" class="{{ $btnPrimary }}" data-test="registrar-aceite"><i class="fas fa-circle-check"></i> Registrar aceite</button>
                        <button type="button" wire:click="openDecision('recusa')" class="{{ $btnSecondary }}" data-test="registrar-recusa"><i class="fas fa-circle-xmark"></i> Registrar recusa</button>
                    </div>
                </div>
            @elseif ($budget->decided_at)
                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3 text-sm space-y-1" data-test="resposta-{{ $budget->number }}">
                    <p><strong>{{ $budget->status === Budget::STATUS_APPROVED ? 'Aceite' : 'Recusa' }}</strong> em {{ $budget->decided_at->format('d/m/Y H:i') }} · {{ Budget::METHODS[$budget->decision_method] ?? $budget->decision_method }}</p>
                    @if ($budget->consent)
                        <p>{{ $budget->consent->signer_name }}{{ $budget->consent->maskedDocument() ? ' · '.$budget->consent->maskedDocument() : '' }} · registrado por {{ $budget->decider?->short_name ?? 'o cliente, pelo link' }}</p>
                        @if ($budget->consent->message_text) <p class="text-gray-600 dark:text-gray-300">"{{ $budget->consent->message_text }}"</p> @endif
                    @endif
                    @if ($budget->refusal_reason) <p>Motivo: {{ $budget->refusal_reason }}</p> @endif
                    @if ($budget->diagnosis_fee_cents) <p>Diagnóstico a cobrar: {{ $money($budget->diagnosis_fee_cents) }}</p> @endif
                    @if ($budget->non_original_authorized) <p>Cliente autorizou peça não original.</p> @endif
                </div>
            @endif
        </section>
    @endforeach

    @if (! $assembling && $this->budgets->isEmpty())
        <div class="{{ $cardClass }} p-4 text-sm text-gray-600 dark:text-gray-300">O orçamento é montado depois do diagnóstico.</div>
    @endif

    {{-- Janela da resposta --}}
    @if ($deciding && $this->pending)
        @php($pendingBudget = $this->pending)
        <x-modal close="closeDecision" size="lg" scroll data-test="janela-resposta">
            <h2 class="font-display font-bold text-xl uppercase">{{ $deciding === 'recusa' ? 'Recusa' : 'Aceite' }} · {{ $pendingBudget->title() }}</h2>

            <div>
                <p class="{{ $labelClass }}">Como o cliente respondeu</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (Budget::METHODS as $value => $label)
                        @continue($value === 'link')
                        @continue($value === 'alcada' && (! $order->isIziApproval() || $deciding === 'recusa'))
                        @continue($value === 'assinatura' && $deciding === 'recusa')
                        <label>
                            <input type="radio" wire:model.live="method" value="{{ $value }}" class="peer sr-only">
                            <span class="flex min-h-11 items-center rounded-lg border border-gray-300 dark:border-gray-600 px-3 text-sm font-semibold peer-checked:bg-brand-600 peer-checked:border-brand-600 peer-checked:text-white cursor-pointer" data-test="metodo-{{ $value }}">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            @if ($deciding === 'aceite')
                <div>
                    <p class="{{ $labelClass }}">Itens aprovados</p>
                    @foreach ($pendingBudget->items as $item)
                        <label class="flex items-center gap-3 min-h-11 text-sm" wire:key="ap-{{ $item->id }}">
                            <input type="checkbox" wire:model.live="approvedItems" value="{{ $item->id }}" class="size-5 rounded border-gray-300 text-brand-600">
                            <span class="flex-1">{{ $item->description }}{{ $item->priority === 'recomendado' ? ' (recomendado)' : '' }}</span>
                            <span>{{ $money($item->lineTotalCents()) }}</span>
                        </label>
                    @endforeach
                    <p class="text-right text-sm font-semibold">Aprovado: {{ $money((int) $pendingBudget->items->whereIn('id', array_map('intval', $approvedItems))->sum(fn ($i) => (int) $i->lineTotalCents())) }}</p>
                </div>
                @if ($pendingBudget->items->whereIn('id', array_map('intval', $approvedItems))->contains(fn ($i) => $i->isPart() && ($i->origin?->needsAuthorization() ?? false)))
                    <label class="flex items-center gap-3 min-h-11 rounded-lg bg-amber-50 dark:bg-amber-900/30 px-3 text-sm">
                        <input type="checkbox" wire:model="nonOriginal" class="size-5 rounded border-gray-300 text-brand-600" data-test="autoriza-nao-original">
                        O cliente autorizou a peça não original (paralela ou usada).
                    </label>
                @endif
            @else
                <div>
                    <label class="{{ $labelClass }}" for="reason">Motivo da recusa</label>
                    <textarea id="reason" wire:model="reason" rows="2" class="{{ $inputClass }}" data-test="motivo-recusa"></textarea>
                    @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="diagnosisFee">Diagnóstico a cobrar (em branco: sem cobrança)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">R$</span>
                        <input id="diagnosisFee" type="text" inputmode="decimal" wire:model="diagnosisFee" class="{{ $inputClass }} pl-9">
                    </div>
                </div>
            @endif

            @if ($method !== 'alcada')
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="signerName">Nome de quem {{ $deciding === 'recusa' ? 'recusou' : 'aprovou' }}</label>
                        <input id="signerName" type="text" wire:model="signerName" maxlength="120" class="{{ $inputClass }}" data-test="nome-resposta">
                        @error('signerName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="signerDocument">CPF ou CNPJ{{ $deciding === 'recusa' ? ' (opcional)' : '' }}</label>
                        <x-document-input id="signerDocument" model="signerDocument" />
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">Aprovação pela alçada da Izi Car, registrada por {{ $user->short_name }}.</p>
            @endif

            @if ($method === 'mensagem')
                <div>
                    <label class="{{ $labelClass }}" for="messageText">Mensagem do cliente (cole do WhatsApp)</label>
                    <textarea id="messageText" wire:model="messageText" rows="3" class="{{ $inputClass }}" placeholder="Ex.: Pode fazer tudo, menos o alinhamento." data-test="mensagem-cliente"></textarea>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="messageAt">Quando o cliente mandou (opcional)</label>
                        <input id="messageAt" type="datetime-local" wire:model="messageAt" class="{{ $inputClass }}">
                    </div>
                    <div x-data="photoCapture">
                        <p class="{{ $labelClass }}">Print da conversa (opcional)</p>
                        <label class="{{ $btnSecondary }} w-full cursor-pointer">
                            <input type="file" accept="image/*" class="sr-only" x-on:change="take($event, 'print')">
                            <span x-show="busy !== 'print'"><i class="fas fa-image"></i> {{ $printMediaId ? 'Print anexado: trocar' : 'Anexar print' }}</span>
                            <span x-show="busy === 'print'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                        </label>
                    </div>
                </div>
            @endif

            @if ($deciding === 'aceite' && $method !== 'alcada')
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-xs whitespace-pre-line text-gray-700 dark:text-gray-200 max-h-32 overflow-y-auto">{{ $pendingBudget->snapshot['texto_aceite'] }}</div>
            @endif

            @if ($method === 'assinatura')
                <div x-data="signaturePad" class="space-y-3" wire:key="pad">
                    <div class="relative rounded-lg bg-white border-2 border-dashed border-gray-300 overflow-hidden">
                        <canvas x-ref="canvas" class="w-full block" style="height: 180px; touch-action: none;"
                            x-on:pointerdown.prevent="start($event)" x-on:pointermove.prevent="move($event)"
                            x-on:pointerup.prevent="end()" x-on:pointerleave="end()" x-on:pointercancel="end()"></canvas>
                        <span x-show="! dirty" class="absolute inset-x-0 bottom-3 text-center text-xs text-gray-400 pointer-events-none">Assine com o dedo</span>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" x-on:click="clear()" class="{{ $btnSecondary }}"><i class="fas fa-eraser"></i> Limpar</button>
                        <button type="button" wire:click="closeDecision" class="{{ $btnSecondary }}">Cancelar</button>
                        <button type="button" x-on:click="$wire.saveDecision(dataUrl())" class="{{ $btnPrimary }}" data-test="confirmar-resposta"><i class="fas fa-check"></i> Confirmar aceite</button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="closeDecision" class="{{ $btnSecondary }}">Cancelar</button>
                    <button type="button" wire:click="saveDecision(null)" class="{{ $btnPrimary }}" data-test="confirmar-resposta"><i class="fas fa-check"></i> {{ $deciding === 'recusa' ? 'Registrar recusa' : 'Registrar aceite' }}</button>
                </div>
            @endif
        </x-modal>
    @endif
</div>
