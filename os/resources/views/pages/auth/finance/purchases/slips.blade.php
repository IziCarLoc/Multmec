<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseLink;
use App\Models\Supplier;
use App\Services\Purchases\BankSlips;
use App\Services\Purchases\Dda;
use App\Services\Purchases\Occurrences;
use App\Services\Purchases\PurchaseSettings;
use App\Support\Document;
use App\Support\Money;
use App\Support\Purchases\BoletoCode;
use App\Support\Purchases\BoletoText;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Boletos de fornecedor (doc 09 §4, §5 e §10): cadastrar pela linha digitável (ou o texto do boleto), ligar à nota,
 * informar quem recebe e quem paga, ver o veredito ("NÃO PAGUE", "Conferir", "OK") e pagar com a trava. O atendimento
 * lança e corrige; aceitar ocorrência, pagar, contestar e reabrir é do gestor.
 */
new #[Title('Boletos de fornecedor')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    /** aberto, sem_nota, pago, fora (contestado ou cancelado) ou todos. */
    #[Url(as: 'filtro', except: 'aberto')]
    public string $filter = 'aberto';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'boleto', except: null)]
    public ?int $slipId = null;

    // Cadastrar
    public bool $creating = false;

    public string $text = '';

    public array $reading = [];

    public bool $newSupplier = false;

    /** @var array<string, string> */
    public array $form = [];

    // Vários de uma vez
    public bool $batching = false;

    public string $batchText = '';

    public string $batchSupplierId = '';

    public string $batchSupplierName = '';

    public array $batchResults = [];

    // Boleto aberto
    /** @var array<string, string> */
    public array $edit = [];

    public string $linkInvoiceId = '';

    public string $linkAmount = '';

    public string $linkInstallmentId = '';

    public ?string $acceptKey = null;

    public string $acceptReason = '';

    public bool $paying = false;

    /** @var array<string, mixed> */
    public array $pay = [];

    public string $cancelReason = '';

    public function mount(): void
    {
        if ($this->slipId) {
            $this->open($this->slipId);          // boleto aberto pelo link (?boleto=): os campos de correção vêm dele
        }
    }

    #[Computed]
    public function slips()
    {
        $term = trim($this->search);
        $cents = $term !== '' && preg_match('/^[\d.,]+$/', $term) ? Money::parseBr($term) : null;
        $digits = preg_replace('/\D/', '', $term);

        $slips = BankSlip::query()->with(['supplier:id,name', 'groupCompany:id,name', 'links.invoice:id,number,series'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('supplier', fn ($s) => $s->where('name', 'like', '%'.$term.'%'))
                ->orWhere('document_number', 'like', '%'.$term.'%')
                ->when($cents, fn ($c) => $c->orWhere('amount_cents', $cents))
                ->when(strlen($digits) >= 6, fn ($c) => $c->orWhere('digitable_line', 'like', '%'.$digits.'%'))))
            ->when(in_array($this->filter, ['aberto', 'sem_nota'], true), fn ($q) => $q->where('status', BankSlip::OPEN))
            ->when($this->filter === 'sem_nota', fn ($q) => $q->whereDoesntHave('links'))
            ->when($this->filter === 'pago', fn ($q) => $q->where('status', BankSlip::PAID))
            ->when($this->filter === 'fora', fn ($q) => $q->whereIn('status', [BankSlip::CONTESTED, BankSlip::CANCELED]))
            ->when($this->filter === 'pago', fn ($q) => $q->orderByDesc('paid_on'), fn ($q) => $q->orderBy('due_date'))
            ->orderBy('id')->limit(300)->get();

        if ($slips->isEmpty()) {
            return $slips;
        }

        $dda = app(Dda::class);
        $cross = $dda->cross();
        $all = app(Occurrences::class)->all(null, $cross);
        $ddaStatus = $dda->statusBySlip($cross);

        return $slips->each(fn (BankSlip $slip) => $slip->setAttribute('verdict', app(Occurrences::class)->verdict(
            $slip, array_values(array_filter($all, fn ($o) => in_array($slip->id, $o['slips'], true))), $ddaStatus[$slip->id] ?? null,
        )));
    }

    #[Computed]
    public function current(): ?BankSlip
    {
        return $this->slipId
            ? BankSlip::query()->with(['supplier', 'payable', 'groupCompany', 'links.invoice', 'links.installment', 'creator'])->find($this->slipId)
            : null;
    }

    #[Computed]
    public function detail(): array
    {
        $slip = $this->current;

        if (! $slip) {
            return [];
        }

        $dda = app(Dda::class);
        $cross = $dda->cross();
        $occurrences = array_values(array_filter(app(Occurrences::class)->all(null, $cross), fn ($o) => in_array($slip->id, $o['slips'], true)));
        $ddaStatus = $dda->statusBySlip($cross)[$slip->id] ?? null;
        $linked = $slip->links->pluck('purchase_invoice_id')->all();

        return [
            'occurrences' => $occurrences,
            'verdict' => app(Occurrences::class)->verdict($slip, $occurrences, $ddaStatus),
            'dda' => $ddaStatus,
            'suggestions' => $slip->isOpen() ? array_values(array_filter(app(BankSlips::class)->suggestInvoices($slip), fn ($s) => array_diff(array_column($s['items'], 'invoice_id'), $linked) !== [])) : [],
            'candidates' => $slip->isOpen() || $slip->isPaid() ? $this->candidates($slip) : collect(),
            'history' => AuditLog::query()->with('user:id,name')->where('auditable_type', $slip->getMorphClass())->where('auditable_id', $slip->id)->orderByDesc('id')->limit(30)->get(),
        ];
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->where(fn ($q) => $q->sellsParts()->active()->orWhereHas('bankSlips'))->orderBy('name')->get(['id', 'name', 'document']);
    }

    // ------------------------------------------------------------------ cadastrar

    public function openCreate(): void
    {
        $this->resetErrorBag();
        $this->reset('text', 'reading', 'newSupplier');
        $this->form = ['line' => '', 'amount' => '', 'due_date' => '', 'document_number' => '', 'supplier_id' => '', 'supplier_name' => '', 'payee_cnpj' => '', 'payee_name' => '', 'payer_cnpj' => '', 'notes' => ''];
        $this->creating = true;
    }

    public function closeCreate(): void
    {
        $this->creating = false;
    }

    public function updatedFormSupplierId(mixed $value): void
    {
        $this->newSupplier = $value === 'novo';
    }

    /** Lê a linha digitável (ou o texto inteiro do boleto) e preenche valor, vencimento, banco e os CNPJs. */
    public function readText(): void
    {
        $this->resetErrorBag();
        $read = BoletoText::read($this->text, PurchaseSettings::today());
        $slip = $read['slip'];
        $supplier = $read['payee_cnpj'] ? Supplier::query()->where('document', $read['payee_cnpj'])->orderBy('id')->first() : null;

        $this->form['line'] = $slip['line'] ?? '';
        $this->form['amount'] = $slip && $slip['amount_cents'] ? $this->moneyInput($slip['amount_cents']) : $this->form['amount'];
        $this->form['due_date'] = $slip['due_date'] ?? $this->form['due_date'];
        $this->form['payee_cnpj'] = $read['payee_cnpj'] ? Document::format($read['payee_cnpj']) : $this->form['payee_cnpj'];
        $this->form['payer_cnpj'] = $read['payer_cnpj'] ? Document::format($read['payer_cnpj']) : $this->form['payer_cnpj'];
        $this->form['document_number'] = $read['document_number'] ?? $this->form['document_number'];

        if ($supplier) {
            $this->form['supplier_id'] = (string) $supplier->id;
            $this->newSupplier = false;
        }

        $this->reading = [
            'ok' => (bool) $slip,
            'bank' => $slip['bank_name'] ?? null,
            'type' => $slip['type'] ?? null,
            'amount' => $slip && $slip['amount_cents'] ? Money::brl($slip['amount_cents']) : null,
            'due' => $slip && $slip['due_date'] ? CarbonImmutable::parse($slip['due_date'])->format('d/m/Y') : null,
            'supplier' => $supplier?->name,
            'warnings' => array_values(array_unique([...($slip['errors'] ?? []), ...($slip['warnings'] ?? []), ...$read['warnings']])),
        ];
    }

    public function save(BankSlips $slips): void
    {
        $this->resetErrorBag();

        if ($this->form['line'] === '' && trim($this->text) !== '') {
            $this->readText();
        }

        $data = [
            ...$this->form,
            'supplier_id' => ! $this->newSupplier && ctype_digit((string) $this->form['supplier_id']) ? (int) $this->form['supplier_id'] : null,
            'supplier_name' => $this->newSupplier ? $this->form['supplier_name'] : '',
        ];

        $result = $this->attempt(fn () => $slips->create($data, auth()->user()), 'form');

        if ($result) {
            $this->creating = false;
            $this->open($result['slip']->id);
            $message = match (true) {
                $result['auto']['linked'] => 'Boleto cadastrado e ligado à nota '.collect($result['auto']['suggestion']['items'])->pluck('number')->implode(', ').'.',
                ($result['auto']['suggestions'] ?? []) !== [] => 'Boleto cadastrado. Escolha a nota nas sugestões.',
                default => 'Boleto cadastrado, mas nenhuma nota bate: confira antes de pagar.',
            };
            $this->dispatch('toast', message: $message.($result['adopted'] ? ' A conta que já estava no A pagar virou este boleto.' : ''), type: $result['auto']['linked'] ? 'success' : 'error');
        }
    }

    public function openBatch(): void
    {
        $this->resetErrorBag();
        $this->reset('batchText', 'batchSupplierId', 'batchSupplierName', 'batchResults');
        $this->batching = true;
    }

    public function closeBatch(): void
    {
        $this->batching = false;
    }

    public function saveBatch(BankSlips $slips): void
    {
        $this->resetErrorBag();
        $supplierId = ctype_digit($this->batchSupplierId) ? (int) $this->batchSupplierId : null;
        $results = $this->attempt(fn () => $slips->createBatch($this->batchText, $supplierId, $supplierId ? null : $this->batchSupplierName, auth()->user()), 'batch');

        if ($results) {
            $this->batchResults = $results;
            $this->batchText = '';
            unset($this->slips);
            $created = count(array_filter($results, fn ($r) => $r['status'] === 'criado'));
            $this->dispatch('toast', message: $created.' de '.count($results).' boleto(s) cadastrado(s).', type: $created ? 'success' : 'error');
        }
    }

    // ------------------------------------------------------------------ boleto aberto

    public function open(int $id): void
    {
        $this->slipId = $id;
        $this->reset('linkInvoiceId', 'linkAmount', 'linkInstallmentId', 'acceptKey', 'acceptReason', 'paying', 'cancelReason');
        $this->resetErrorBag();
        $this->refresh();
        $slip = $this->current;
        $this->edit = $slip ? [
            'payee_cnpj' => $slip->payee_cnpj ? Document::format($slip->payee_cnpj) : '', 'payer_cnpj' => $slip->payer_cnpj ? Document::format($slip->payer_cnpj) : '',
            'payee_name' => (string) $slip->payee_name, 'document_number' => (string) $slip->document_number, 'supplier_id' => (string) $slip->supplier_id,
        ] : [];
    }

    public function close(): void
    {
        $this->slipId = null;
    }

    public function saveFields(BankSlips $slips): void
    {
        $this->resetErrorBag();
        $fields = $this->edit;

        if ($this->current?->links->isNotEmpty()) {
            unset($fields['supplier_id']);
        }

        $result = $this->attempt(fn () => $slips->update($this->current, $fields, auth()->user()), 'edit');

        if ($result) {
            $this->open($this->slipId);
            $this->dispatch('toast', message: $result['changed'] ? 'Boleto corrigido.'.(($result['auto']['linked'] ?? false) ? ' Ligado à nota.' : '') : 'Nada mudou.', type: 'success');
        }
    }

    public function applySuggestion(int $index, BankSlips $slips): void
    {
        $suggestion = $this->detail['suggestions'][$index] ?? null;

        if (! $suggestion) {
            return;
        }

        $items = array_map(fn ($i) => ['invoice_id' => $i['invoice_id'], 'amount' => $i['amount_cents'], 'installment_id' => $i['installment_id']], $suggestion['items']);

        if ($this->attempt(fn () => $slips->link($this->current, $items, auth()->user()))) {
            $this->refresh();
            $this->dispatch('toast', message: 'Boleto ligado à nota.', type: 'success');
        }
    }

    public function updatedLinkInvoiceId(mixed $value): void
    {
        $this->linkInstallmentId = '';
        $candidate = $this->detail['candidates']->firstWhere('id', (int) $value);
        $rest = max(0, $this->current->amount_cents - (int) $this->current->links->sum('amount_cents'));
        $this->linkAmount = $candidate ? $this->moneyInput($rest > 0 ? min($candidate->open_cents, $rest) : $candidate->open_cents) : '';
    }

    public function link(BankSlips $slips): void
    {
        $this->resetErrorBag();
        $item = ['invoice_id' => (int) $this->linkInvoiceId, 'amount' => $this->linkAmount, 'installment_id' => ctype_digit($this->linkInstallmentId) ? (int) $this->linkInstallmentId : null];

        if (! $item['invoice_id']) {
            $this->addError('link', 'Escolha a nota.');

            return;
        }

        if ($this->attempt(fn () => $slips->link($this->current, [$item], auth()->user()), 'link')) {
            $this->reset('linkInvoiceId', 'linkAmount', 'linkInstallmentId');
            $this->refresh();
            $this->dispatch('toast', message: 'Boleto ligado à nota.', type: 'success');
        }
    }

    public function unlink(int $invoiceId, BankSlips $slips): void
    {
        if ($this->attempt(fn () => $slips->unlink($this->current, $invoiceId, auth()->user()))) {
            $this->refresh();
            $this->dispatch('toast', message: 'Ligação desfeita.', type: 'success');
        }
    }

    public function startAccept(string $key): void
    {
        $this->acceptKey = $key;
        $this->acceptReason = '';
        $this->resetErrorBag();
    }

    public function cancelAccept(): void
    {
        $this->acceptKey = null;
    }

    public function accept(Occurrences $occurrences): void
    {
        $this->resetErrorBag();

        if ($this->attempt(fn () => $occurrences->accept((string) $this->acceptKey, $this->acceptReason, auth()->user()), 'acceptReason')) {
            $this->acceptKey = null;
            $this->refresh();
            $this->dispatch('toast', message: 'Conferido: o motivo ficou no histórico.', type: 'success');
        }
    }

    public function undoAccept(string $key, Occurrences $occurrences): void
    {
        if ($this->attempt(fn () => $occurrences->undoAcceptance($key, auth()->user()))) {
            $this->refresh();
            $this->dispatch('toast', message: 'Aceite desfeito.', type: 'success');
        }
    }

    public function startPay(): void
    {
        $this->resetErrorBag();
        $this->pay = ['paid_on' => PurchaseSettings::today(), 'paid_amount' => '', 'bank_checked' => false, 'release' => false, 'reason' => '', 'paid_by_party' => ''];
        $this->paying = true;
    }

    public function cancelPay(): void
    {
        $this->paying = false;
    }

    public function confirmPay(BankSlips $slips): void
    {
        $this->resetErrorBag();
        $data = [...$this->pay, 'bank_checked' => (bool) ($this->pay['bank_checked'] ?? false), 'release' => (bool) ($this->pay['release'] ?? false), 'paid_by_party' => ($this->pay['paid_by_party'] ?? '') ?: null];
        $result = $this->attempt(fn () => $slips->pay($this->current, $data, auth()->user()), 'pay');

        if ($result) {
            $this->paying = false;
            $this->refresh();
            $this->dispatch('toast', message: $result['released'] ? 'Pagamento registrado com ressalva (o motivo ficou no histórico).' : 'Pagamento registrado. A conta do A pagar ficou paga junto.', type: 'success');
        }
    }

    public function undoPayment(BankSlips $slips): void
    {
        if ($this->attempt(fn () => $slips->undoPayment($this->current, auth()->user()))) {
            $this->refresh();
            $this->dispatch('toast', message: 'Pagamento desfeito: o boleto voltou a ficar em aberto.', type: 'success');
        }
    }

    public function cancel(string $status, BankSlips $slips): void
    {
        $this->resetErrorBag();

        if ($this->attempt(fn () => $slips->cancel($this->current, $status, $this->cancelReason, auth()->user()), 'cancel')) {
            $this->cancelReason = '';
            $this->refresh();
            $this->dispatch('toast', message: $status === BankSlip::CONTESTED ? 'Boleto contestado: fora do A pagar até o fornecedor confirmar.' : 'Boleto cancelado: saiu do A pagar.', type: 'success');
        }
    }

    public function reopen(BankSlips $slips): void
    {
        if ($result = $this->attempt(fn () => $slips->reopen($this->current, auth()->user()))) {
            $this->refresh();
            $this->dispatch('toast', message: 'Boleto reaberto'.(($result['auto']['linked'] ?? false) ? ' e ligado à nota.' : '.'), type: 'success');
        }
    }

    /** Notas do fornecedor do boleto com saldo para ligar (e as que já estão ligadas a ele). */
    private function candidates(BankSlip $slip)
    {
        return PurchaseInvoice::query()->with('installments')
            ->where('supplier_id', $slip->supplier_id)->where('status', PurchaseInvoice::ACTIVE)->chargeable()
            ->select(['id', 'number', 'series', 'issue_date', 'total_cents', 'total_with_taxes_cents', 'status', 'purpose'])
            ->addSelect(['linked_cents' => PurchaseLink::query()->selectRaw('COALESCE(SUM(amount_cents), 0)')->whereColumn('purchase_invoice_id', 'purchase_invoices.id')])
            ->orderByDesc('issue_date')->limit(60)->get()
            ->each(fn ($i) => $i->setAttribute('open_cents', max(0, $i->total_cents - (int) $i->linked_cents)))
            ->filter(fn ($i) => $i->open_cents > 0)->values();
    }

    private function moneyInput(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    private function refresh(): void
    {
        unset($this->slips, $this->current, $this->detail);
    }
};
?>

@php
    $gestor = auth()->user()->isGestor();
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $tone = fn (string $level) => match ($level) {
        'ruim' => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
        'atencao' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'ok' => 'bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    };
@endphp
<div class="space-y-4">
    <x-finance-nav />
    <x-purchases-nav />

    <div class="flex flex-wrap items-center gap-2">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2 mr-auto">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Boletos
        </h1>
        <button type="button" wire:click="openBatch" class="{{ $btnSecondary }}" data-test="varios-boletos"><i class="fas fa-layer-group"></i> Vários</button>
        <button type="button" wire:click="openCreate" class="{{ $btnPrimary }}" data-test="cadastrar-boleto"><i class="fas fa-plus"></i> Cadastrar boleto</button>
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Fornecedor, documento, valor ou linha" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <select wire:model.live="filter" class="{{ $inputClass }} sm:w-52" data-test="filtro-boletos">
            <option value="aberto">Em aberto</option>
            <option value="sem_nota">Em aberto sem nota</option>
            <option value="pago">Pagos</option>
            <option value="fora">Contestados e cancelados</option>
            <option value="todos">Todos</option>
        </select>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
        @forelse ($this->slips as $slip)
            <button type="button" wire:click="open({{ $slip->id }})" wire:key="bl-{{ $slip->id }}" @class(['w-full text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40', 'border-l-4 border-l-stripe' => $slip->verdict['level'] === 'ruim']) data-test="boleto-{{ $slip->id }}">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold flex flex-wrap items-center gap-1">
                        <span class="break-words">{{ $slip->supplier?->name ?? 'Fornecedor?' }}</span>
                        <span class="{{ $pill }} {{ $tone($slip->verdict['level']) }}" data-test="veredito-{{ $slip->id }}">{{ $slip->verdict['short'] }}</span>
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-300 break-words">
                        venc. {{ $slip->due_date->format('d/m/Y') }}
                        @if ($slip->links->isNotEmpty()) · nota {{ $slip->links->map(fn ($l) => $l->invoice?->number)->filter()->implode(', ') }} @else · sem nota @endif
                        @if ($slip->groupCompany) · da {{ $slip->groupCompany->name }} @endif
                        @if ($slip->isPaid()) · pago {{ $slip->paid_on?->format('d/m/Y') }} @endif
                    </p>
                </div>
                <p class="font-display font-bold text-lg">{{ $money($slip->amount_cents) }}</p>
            </button>
        @empty
            <p class="p-6 text-center text-gray-600 dark:text-gray-300">{{ BankSlip::query()->exists() ? 'Nenhum boleto com esse filtro.' : 'Nenhum boleto. Toque em "Cadastrar boleto" e cole a linha digitável.' }}</p>
        @endforelse
    </div>

    {{-- Cadastrar --}}
    @if ($creating)
        <x-modal close="closeCreate" form="save" size="2xl" scroll data-test="janela-cadastrar">
            <h2 class="font-display font-bold text-xl uppercase">Cadastrar boleto</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">Cole a linha digitável (os números embaixo do nome do banco) ou o texto inteiro do boleto (abra o PDF, selecione tudo e copie) e toque em Ler.</p>
            <div class="space-y-2">
                <textarea wire:model="text" rows="3" class="{{ $inputClass }} font-mono text-xs" placeholder="34191.79001 01043.510047 91020.150008 1 …" data-test="texto-boleto"></textarea>
                <button type="button" wire:click="readText" class="{{ $btnSecondary }}" data-test="ler-boleto"><i class="fas fa-wand-magic-sparkles"></i> Ler</button>
                @if ($reading)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-2 text-sm space-y-1" data-test="leitura-boleto">
                        @if ($reading['ok'])
                            <p><i class="fas fa-check mr-1"></i>{{ $reading['type'] === 'convenio' ? 'Conta de convênio' : 'Boleto' }}{{ $reading['bank'] ? ' do '.$reading['bank'] : '' }}{{ $reading['amount'] ? ', '.$reading['amount'] : '' }}{{ $reading['due'] ? ', vence '.$reading['due'] : '' }}{{ $reading['supplier'] ? '. Fornecedor: '.$reading['supplier'] : '' }}.</p>
                        @endif
                        @foreach ($reading['warnings'] as $warning) <p class="text-amber-800 dark:text-amber-200">{{ $warning }}</p> @endforeach
                    </div>
                @endif
            </div>
            @error('form') <p class="text-sm text-red-600" data-test="erro-boleto">{{ $message }}</p> @enderror
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="bl-forn">Fornecedor</label>
                    <select id="bl-forn" wire:model.live="form.supplier_id" class="{{ $inputClass }}" data-test="boleto-fornecedor">
                        <option value="">Escolha…</option>
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}{{ $supplier->document ? ' · '.Document::format($supplier->document) : '' }}</option>
                        @endforeach
                        <option value="novo">+ Fornecedor novo</option>
                    </select>
                </div>
                @if ($newSupplier)
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="bl-novo">Nome do fornecedor novo</label>
                        <input id="bl-novo" type="text" wire:model="form.supplier_name" maxlength="120" class="{{ $inputClass }}" data-test="boleto-fornecedor-novo">
                    </div>
                @endif
                @if ($form['line'] === '')
                    <div>
                        <label class="{{ $labelClass }}" for="bl-valor">Valor (R$)</label>
                        <input id="bl-valor" type="text" inputmode="decimal" wire:model="form.amount" placeholder="0,00" class="{{ $inputClass }}" data-test="boleto-valor">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="bl-venc">Vencimento</label>
                        <input id="bl-venc" type="date" wire:model="form.due_date" class="{{ $inputClass }}" data-test="boleto-vencimento">
                    </div>
                @else
                    <p class="sm:col-span-2 font-mono text-xs break-all rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2">{{ BoletoCode::formatLine($form['line']) }}</p>
                @endif
                <div>
                    <label class="{{ $labelClass }}" for="bl-benef">CNPJ de quem recebe (beneficiário)</label>
                    <x-document-input id="bl-benef" model="form.payee_cnpj" letters placeholder="00.000.000/0000-00" />
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="bl-pag">CNPJ do pagador</label>
                    <x-document-input id="bl-pag" model="form.payer_cnpj" letters placeholder="00.000.000/0000-00" />
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="bl-nome">Nome de quem recebe (como está no boleto)</label>
                    <input id="bl-nome" type="text" wire:model="form.payee_name" maxlength="80" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="bl-doc">Nº do documento</label>
                    <input id="bl-doc" type="text" wire:model="form.document_number" maxlength="40" class="{{ $inputClass }}" data-test="boleto-documento">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="bl-obs">Observação</label>
                    <input id="bl-obs" type="text" wire:model="form.notes" maxlength="300" class="{{ $inputClass }}">
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeCreate" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-boleto"><i class="fas fa-check"></i> Cadastrar</button>
            </div>
        </x-modal>
    @endif

    {{-- Vários de uma vez --}}
    @if ($batching)
        <x-modal close="closeBatch" form="saveBatch" size="2xl" scroll data-test="janela-varios">
            <h2 class="font-display font-bold text-xl uppercase">Vários boletos do mesmo fornecedor</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">Uma linha digitável por linha (até 60). Cada boleto é ligado sozinho à nota quando só uma bate.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <select wire:model.live="batchSupplierId" class="{{ $inputClass }}" aria-label="Fornecedor" data-test="varios-fornecedor">
                    <option value="">Fornecedor…</option>
                    @foreach ($this->suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
                @if ($batchSupplierId === '')
                    <input type="text" wire:model="batchSupplierName" maxlength="120" placeholder="ou o nome de um fornecedor novo" class="{{ $inputClass }}">
                @endif
            </div>
            <textarea wire:model="batchText" rows="6" class="{{ $inputClass }} font-mono text-xs" placeholder="Uma linha digitável por linha" data-test="varios-linhas"></textarea>
            @error('batch') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @if ($batchResults)
                <ul class="space-y-1 text-sm" data-test="resultado-varios">
                    @foreach ($batchResults as $result)
                        <li class="rounded-lg border border-gray-200 dark:border-gray-700 p-2 break-words">
                            <span class="font-mono text-xs break-all">{{ $result['line'] }}</span>:
                            @if ($result['status'] === 'criado')
                                {{ $result['amount_cents'] ? $money($result['amount_cents']) : '' }} {{ $result['linked'] ? 'ligado à nota' : 'sem nota' }}{{ $result['serious'] ? ', '.$result['serious'].' problema(s) grave(s)' : '' }}
                                <button type="button" wire:click="open({{ $result['slip_id'] }})" class="text-brand-700 dark:text-brand-300 underline">abrir</button>
                            @else
                                <span class="text-red-700 dark:text-red-300">{{ $result['error'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeBatch" class="{{ $btnSecondary }}">Fechar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-varios"><i class="fas fa-check"></i> Cadastrar</button>
            </div>
        </x-modal>
    @endif

    {{-- Boleto aberto --}}
    @if ($slipId && ($slip = $this->current))
        @php
            $d = $this->detail;
            $verdict = $d['verdict'];
            $serious = collect($d['occurrences'])->where('severity', 'alta')->where('accepted', null);
            $bankOk = $slip->bank_checked_on || $d['dda'] === 'confirmado';
        @endphp
        <x-modal close="close" size="3xl" scroll top data-test="janela-boleto">
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <h2 class="font-display font-bold text-xl uppercase break-words">{{ $money($slip->amount_cents) }} · {{ $slip->supplier?->name }}</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Vence {{ $slip->due_date->format('d/m/Y') }} · {{ $slip->statusLabel() }}{{ $slip->bankName() ? ' · '.$slip->bankName() : '' }}{{ $slip->isPaid() ? ' · pago em '.$slip->paid_on?->format('d/m/Y').' ('.$money($slip->paid_cents).')' : '' }}</p>
                </div>
                <button type="button" wire:click="close" class="size-11 inline-flex items-center justify-center text-gray-500" aria-label="Fechar"><i class="fas fa-xmark"></i></button>
            </div>

            <p @class(['rounded-lg p-3 font-semibold', $tone($verdict['level']), 'border-l-4 border-l-stripe' => $verdict['level'] === 'ruim']) data-test="veredito">{{ $verdict['text'] }}</p>

            @if ($slip->digitable_line)
                <p class="font-mono text-xs break-all">{{ BoletoCode::formatLine($slip->digitable_line) }}</p>
            @endif
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Lançado por {{ $slip->creator?->name ?? '—' }} ({{ $slip->created_role ?? '—' }}).
                @if ($slip->payable) Conta no A pagar: {{ $money($slip->payable->amount_cents) }}, vence {{ $slip->payable->due_date->format('d/m/Y') }}{{ $slip->payable->isPaid() ? ', paga' : '' }}. @elseif ($slip->groupCompany) Boleto da {{ $slip->groupCompany->name }}: fora do A pagar da oficina. @endif
                @if ($d['dda'] === 'confirmado') Confirmado no DDA do banco. @endif
                @if ($slip->released_reason) Ressalva: {{ $slip->released_reason }} @endif
            </p>

            @if ($d['occurrences'])
                <section class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden" data-test="ocorrencias-boleto">
                    @foreach ($d['occurrences'] as $o)
                        <x-purchase-occurrence :occurrence="$o" wire:key="boc-{{ md5($o['key']) }}">
                            @if ($gestor && ! $o['accepted'] && $acceptKey !== $o['key'])
                                <button type="button" wire:click="startAccept('{{ $o['key'] }}')" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="aceitar-{{ $o['type'] }}"><i class="fas fa-check"></i> Está certo, conferi</button>
                            @elseif ($gestor && $o['accepted'])
                                <button type="button" wire:click="undoAccept('{{ $o['key'] }}')" wire:confirm="Desfazer o aceite?" class="{{ $btnSecondary }} !min-h-9 text-sm">Desfazer o aceite</button>
                            @endif
                        </x-purchase-occurrence>
                        @if ($acceptKey === $o['key'])
                            <div class="p-3 space-y-2 bg-gray-50 dark:bg-gray-900/40" wire:key="acc-{{ md5($o['key']) }}">
                                <textarea wire:model="acceptReason" rows="2" class="{{ $inputClass }}" placeholder="Motivo (pelo menos 10 letras): ex. liguei no fornecedor pelo telefone do cadastro" data-test="motivo-aceite"></textarea>
                                @error('acceptReason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="cancelAccept" class="{{ $btnSecondary }}">Cancelar</button>
                                    <button type="button" wire:click="accept" class="{{ $btnPrimary }}" data-test="confirmar-aceite"><i class="fas fa-check"></i> Aceitar</button>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </section>
            @endif

            {{-- Notas ligadas --}}
            <section class="space-y-2">
                <h3 class="font-display font-bold uppercase">Nota(s) do boleto</h3>
                @forelse ($slip->links as $link)
                    <p class="text-sm flex flex-wrap items-center gap-2" wire:key="lk-{{ $link->id }}">
                        <a href="{{ route('finance.purchases.invoices', ['nota' => $link->purchase_invoice_id]) }}" wire:navigate class="underline min-w-0 break-words">{{ $link->invoice?->label() }}</a>
                        <span>{{ $money($link->amount_cents) }}{{ $link->installment ? ' · parcela de '.$link->installment->due_date->format('d/m/Y') : '' }}{{ $link->source === 'auto' ? ' · ligada sozinha' : '' }}</span>
                        @unless ($slip->isPaid())
                            <button type="button" wire:click="unlink({{ $link->purchase_invoice_id }})" wire:confirm="Desfazer a ligação com esta nota?" class="text-red-700 dark:text-red-300 underline min-h-9">desfazer</button>
                        @endunless
                    </p>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-300">Nenhuma nota ligada.</p>
                @endforelse

                @foreach ($d['suggestions'] as $i => $s)
                    <div class="rounded-lg bg-brand-50 dark:bg-brand-900/30 p-2 text-sm flex flex-wrap items-center gap-2" wire:key="sg-{{ $i }}" data-test="sugestao-{{ $i }}">
                        <span class="min-w-0 flex-1 break-words"><i class="fas fa-lightbulb mr-1"></i>Nota {{ collect($s['items'])->pluck('number')->implode(' + ') }}: {{ $s['reason'] }}</span>
                        <button type="button" wire:click="applySuggestion({{ $i }})" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="ligar-sugestao-{{ $i }}">Ligar</button>
                    </div>
                @endforeach

                @if (($slip->isOpen() || ($slip->isPaid() && $gestor)) && $d['candidates']->isNotEmpty())
                    <div class="grid gap-2 sm:grid-cols-[1fr_9rem_auto] items-start">
                        <select wire:model.live="linkInvoiceId" class="{{ $inputClass }}" aria-label="Nota" data-test="ligar-nota">
                            <option value="">Ligar a outra nota…</option>
                            @foreach ($d['candidates'] as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->label() }} · {{ $candidate->issue_date->format('d/m/Y') }} · falta {{ $money($candidate->open_cents) }}</option>
                            @endforeach
                        </select>
                        <input type="text" inputmode="decimal" wire:model="linkAmount" placeholder="Valor" class="{{ $inputClass }}" aria-label="Valor ligado" data-test="ligar-valor">
                        <button type="button" wire:click="link" class="{{ $btnSecondary }}" data-test="ligar">Ligar</button>
                        @if ($linkInvoiceId !== '' && ($chosen = $d['candidates']->firstWhere('id', (int) $linkInvoiceId)) && $chosen->installments->isNotEmpty())
                            <select wire:model="linkInstallmentId" class="{{ $inputClass }} sm:col-span-3" aria-label="Parcela">
                                <option value="">Sem parcela (vale para a nota)</option>
                                @foreach ($chosen->installments as $installment)
                                    <option value="{{ $installment->id }}">Parcela {{ $installment->number }} · {{ $installment->due_date->format('d/m/Y') }} · {{ $money($installment->amount_cents) }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    @error('link') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            </section>

            {{-- Quem recebe e quem paga --}}
            @if ($slip->isOpen())
                <section class="space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <h3 class="font-display font-bold uppercase">Quem recebe e quem paga</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}" for="ed-benef">CNPJ de quem recebe</label>
                            <x-document-input id="ed-benef" model="edit.payee_cnpj" letters placeholder="00.000.000/0000-00" />
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="ed-pag">CNPJ do pagador</label>
                            <x-document-input id="ed-pag" model="edit.payer_cnpj" letters placeholder="00.000.000/0000-00" />
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="ed-nome">Nome de quem recebe</label>
                            <input id="ed-nome" type="text" wire:model="edit.payee_name" maxlength="80" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="ed-doc">Nº do documento</label>
                            <input id="ed-doc" type="text" wire:model="edit.document_number" maxlength="40" class="{{ $inputClass }}">
                        </div>
                        @if ($slip->links->isEmpty())
                            <div class="sm:col-span-2">
                                <label class="{{ $labelClass }}" for="ed-forn">Fornecedor</label>
                                <select id="ed-forn" wire:model="edit.supplier_id" class="{{ $inputClass }}">
                                    @foreach ($this->suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                    @error('edit') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <button type="button" wire:click="saveFields" class="{{ $btnSecondary }}" data-test="gravar-correcoes">Gravar</button>
                </section>
            @endif

            {{-- Pagar (gestor) --}}
            @if ($gestor && $slip->isOpen())
                <section class="space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3" data-test="secao-pagar">
                    @if (! $paying)
                        <button type="button" wire:click="startPay" class="{{ $btnPrimary }}" data-test="pagar"><i class="fas fa-money-bill-transfer"></i> Registrar pagamento</button>
                    @else
                        <h3 class="font-display font-bold uppercase">Registrar pagamento</h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}" for="pg-data">Data do pagamento</label>
                                <input id="pg-data" type="date" wire:model="pay.paid_on" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}" for="pg-valor">Valor pago (vazio = {{ $money($slip->amount_cents) }})</label>
                                <input id="pg-valor" type="text" inputmode="decimal" wire:model="pay.paid_amount" placeholder="0,00" class="{{ $inputClass }}" data-test="valor-pago">
                            </div>
                        </div>
                        @if ($slip->groupCompany)
                            <fieldset class="space-y-1 text-sm">
                                <legend class="{{ $labelClass }}">Quem está pagando?</legend>
                                <label class="flex items-center gap-2 min-h-11"><input type="radio" wire:model="pay.paid_by_party" value="oficina"> A oficina (fica a receber da {{ $slip->groupCompany->name }})</label>
                                <label class="flex items-center gap-2 min-h-11"><input type="radio" wire:model="pay.paid_by_party" value="empresa"> A própria {{ $slip->groupCompany->name }}</label>
                            </fieldset>
                        @endif
                        @if ($bankOk)
                            <p class="text-sm text-gray-600 dark:text-gray-300"><i class="fas fa-check mr-1"></i>{{ $slip->bank_checked_on ? 'Quem recebe já foi conferido no app do banco em '.$slip->bank_checked_on->format('d/m/Y').'.' : 'Quem recebe foi confirmado pelo DDA do banco.' }}</p>
                        @else
                            <label class="flex items-start gap-2 text-sm min-h-11">
                                <input type="checkbox" wire:model="pay.bank_checked" class="mt-1" data-test="conferi-banco">
                                <span>Colei a linha no app do banco e quem recebe é <b>{{ $slip->supplier?->name ?? 'o fornecedor' }}</b>{{ $slip->supplier?->document ? ' (CNPJ '.Document::format($slip->supplier->document).')' : '' }}.</span>
                            </label>
                        @endif
                        @if ($serious->isNotEmpty())
                            <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-2 text-sm text-red-800 dark:text-red-200 space-y-1">
                                <p class="font-semibold">Problema(s) grave(s) em aberto:</p>
                                @foreach ($serious as $o) <p>· {{ $o['title'] }}</p> @endforeach
                                <label class="flex items-start gap-2 min-h-11"><input type="checkbox" wire:model.live="pay.release" class="mt-1" data-test="pagar-mesmo-assim"> <span>Pagar mesmo assim (fica registrado com o motivo e o meu nome)</span></label>
                            </div>
                        @endif
                        <div>
                            <label class="{{ $labelClass }}" for="pg-motivo">Motivo {{ $serious->isNotEmpty() ? '(obrigatório para pagar mesmo assim)' : '(se o valor pago for diferente: juros, multa, desconto)' }}</label>
                            <textarea id="pg-motivo" wire:model="pay.reason" rows="2" class="{{ $inputClass }}" data-test="motivo-pagamento"></textarea>
                        </div>
                        @error('pay') <p class="text-sm text-red-600" data-test="erro-pagamento">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" wire:click="cancelPay" class="{{ $btnSecondary }}">Cancelar</button>
                            <button type="button" wire:click="confirmPay" class="{{ $btnPrimary }}" data-test="confirmar-pagamento"><i class="fas fa-check"></i> Pago</button>
                        </div>
                    @endif
                </section>
            @endif

            {{-- Cancelar, contestar, reabrir, desfazer pagamento --}}
            <section class="space-y-2">
                @if ($slip->isOpen() && ($gestor || ((int) $slip->created_by === auth()->id() && $slip->created_role === 'atendimento')))
                    <input type="text" wire:model="cancelReason" maxlength="300" placeholder="Motivo de cancelar ou contestar" class="{{ $inputClass }}" data-test="motivo-cancelar">
                    @error('cancel') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="cancel('cancelado')" wire:confirm="Cancelar este boleto? Ele sai do A pagar." class="{{ $btnSecondary }}" data-test="cancelar-boleto">{{ $gestor ? 'Cancelar' : 'Cancelar (cadastrei errado)' }}</button>
                        @if ($gestor)
                            <button type="button" wire:click="cancel('contestado')" wire:confirm="Contestar este boleto? Ele sai do A pagar até o fornecedor confirmar." class="{{ $btnSecondary }}" data-test="contestar-boleto">Contestar</button>
                        @endif
                    </div>
                @endif
                @if ($gestor && in_array($slip->status, [BankSlip::CANCELED, BankSlip::CONTESTED], true))
                    <button type="button" wire:click="reopen" class="{{ $btnSecondary }}" data-test="reabrir-boleto">Reabrir</button>
                @endif
                @if ($gestor && $slip->isPaid())
                    <button type="button" wire:click="undoPayment" wire:confirm="Desfazer o pagamento? O boleto e a conta do A pagar voltam a ficar em aberto." class="{{ $btnSecondary }}" data-test="desfazer-pagamento">Desfazer o pagamento</button>
                @endif
            </section>

            @if ($d['history']->isNotEmpty())
                <details class="text-sm">
                    <summary class="cursor-pointer font-semibold min-h-11 flex items-center">Histórico ({{ $d['history']->count() }})</summary>
                    <ul class="space-y-1 text-gray-600 dark:text-gray-300" data-test="historico-boleto">
                        @foreach ($d['history'] as $log)
                            <li wire:key="h-{{ $log->id }}">{{ $log->created_at?->format('d/m/Y H:i') }} · {{ $log->user?->name ?? 'sistema' }} · {{ $log->actionLabel() }}{{ $log->reason ? ': '.$log->reason : '' }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </x-modal>
    @endif
</div>
