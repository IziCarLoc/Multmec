<?php

use App\Enums\Stage;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\GroupCompany;
use App\Models\OrderItem;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseLink;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Services\Purchases\Allocations;
use App\Services\Purchases\Invoices;
use App\Services\Purchases\Occurrences;
use App\Support\Document;
use App\Support\Money;
use App\Support\Purchases\AccessKey;
use App\Support\Purchases\DanfeText;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Notas de compra (doc 09 §2 e §12): importar o XML (vários de uma vez, ou colado), lançar a nota sem XML (pelo texto
 * da DANFE ou só a chave), registrar a consulta no portal da NF-e e dar destino a cada peça (OS, estoque, uso interno,
 * devolvida, outra empresa). O atendimento lança; cancelar, apagar e entregar peça a outra empresa é do gestor.
 */
new #[Title('Notas de compra')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;
    use WithFileUploads;

    /** Endereço da consulta resumida da NF-e no portal nacional (pede a chave e um captcha). */
    public const PORTAL_URL = 'https://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx?tipoConsulta=resumo&tipoConteudo=7PhJ+gAVw2g=';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** sem_boleto, sem_destino, digitadas ou vazio (todas). */
    #[Url(as: 'filtro', except: '')]
    public string $filter = '';

    #[Url(as: 'nota', except: null)]
    public ?int $invoiceId = null;

    // Importar XML
    public bool $importing = false;

    public array $xmlFiles = [];

    public string $xmlText = '';

    public array $importResults = [];

    // Nota sem XML
    public bool $creating = false;

    public string $danfeText = '';

    public array $danfeRead = [];

    public bool $newSupplier = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{due_date: string, amount: string}> */
    public array $installments = [];

    // Destino das peças
    public ?int $allocItemId = null;

    /** @var array<string, mixed> */
    public array $alloc = [];

    public string $wholeSearch = '';

    public ?int $wholeOrderId = null;

    // Consulta no portal, chave, situação
    /** @var array<string, string> */
    public array $portal = [];

    public string $newKey = '';

    public string $statusReason = '';

    public bool $confirmDelete = false;

    public function mount(): void
    {
        $this->resetPortal();
    }

    #[Computed]
    public function invoices()
    {
        $term = trim($this->search);

        return PurchaseInvoice::query()->with(['supplier:id,name', 'groupCompany:id,name'])
            ->select(['id', 'supplier_id', 'access_key', 'number', 'series', 'issue_date', 'total_cents', 'purpose', 'status', 'source', 'group_company_id', 'portal_status'])
            ->addSelect(['linked_cents' => PurchaseLink::query()->selectRaw('COALESCE(SUM(amount_cents), 0)')->whereColumn('purchase_invoice_id', 'purchase_invoices.id')])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.ltrim($term, '0').'%')->orWhere('access_key', 'like', '%'.preg_replace('/\s+/', '', $term).'%')
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', '%'.$term.'%'))))
            ->when($this->filter === 'digitadas', fn ($q) => $q->where('source', PurchaseInvoice::SOURCE_MANUAL))
            ->orderByDesc('issue_date')->orderByDesc('id')->limit(300)->get()
            ->map(function (PurchaseInvoice $invoice) {
                $items = PurchaseInvoiceItem::query()->where('purchase_invoice_id', $invoice->id)->get(['id', 'quantity']);
                $allocated = PurchaseAllocation::query()->whereIn('purchase_invoice_item_id', $items->pluck('id'))->valid()->sum('quantity');
                $invoice->setAttribute('balance_cents', $invoice->total_cents - (int) $invoice->linked_cents);
                $invoice->setAttribute('without_destination', $items->sum('quantity') - (float) $allocated > 0.000001);

                return $invoice;
            })
            ->filter(fn ($i) => match ($this->filter) {
                'sem_boleto' => $i->isActive() && $i->generatesCharge() && $i->balance_cents > 5,
                'sem_destino' => $i->isActive() && $i->generatesCharge() && $i->without_destination,
                default => true,
            })->values();
    }

    #[Computed]
    public function current(): ?PurchaseInvoice
    {
        return $this->invoiceId
            ? PurchaseInvoice::query()->with(['supplier', 'groupCompany', 'items.allocations.serviceOrder.vehicle', 'items.allocations.orderItem', 'items.allocations.groupCompany', 'links.slip', 'creator'])->find($this->invoiceId)
            : null;
    }

    #[Computed]
    public function details(): array
    {
        $invoice = $this->current;

        if (! $invoice) {
            return [];
        }

        $allocations = app(Allocations::class);
        $remaining = $invoice->items->mapWithKeys(fn ($item) => [$item->id => $allocations->remaining($item)])->all();

        return [
            'remaining' => $remaining,
            'installments' => app(Invoices::class)->installmentsWithBalance($invoice),
            'linked_cents' => app(Invoices::class)->linkedCents($invoice),
            'suggestions' => $invoice->isActive() && $invoice->generatesCharge() ? $allocations->suggestions($invoice) : [],
            'occurrences' => array_values(array_filter(app(Occurrences::class)->all(), fn ($o) => in_array($invoice->id, $o['invoices'], true))),
        ];
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->where(fn ($q) => $q->sellsParts()->active()->orWhereHas('purchaseInvoices'))->orderBy('name')->get(['id', 'name', 'document']);
    }

    #[Computed]
    public function companies()
    {
        return GroupCompany::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    /** OS para escolher no destino da peça (número, placa ou modelo). */
    #[Computed]
    public function orderOptions(): array
    {
        $term = trim((string) ($this->alloc['order_search'] ?? $this->wholeSearch));

        if (mb_strlen($term) < 2) {
            return [];
        }

        return ServiceOrder::query()->with('vehicle:id,plate,brand_model')->where('stage', '!=', Stage::Cancelada->value)->search($term)
            ->orderByDesc('entry_date')->limit(12)->get(['id', 'number', 'vehicle_id', 'entry_date', 'stage'])->all();
    }

    #[Computed]
    public function orderParts(): array
    {
        $orderId = (int) ($this->alloc['service_order_id'] ?? 0);

        return $orderId ? OrderItem::query()->where('service_order_id', $orderId)->where('kind', OrderItem::KIND_PART)->orderBy('position')->get(['id', 'description', 'quantity', 'cost_cents', 'part_code'])->all() : [];
    }

    // ------------------------------------------------------------------ importar

    public function openImport(): void
    {
        $this->reset('xmlFiles', 'xmlText', 'importResults');
        $this->resetErrorBag();
        $this->importing = true;
    }

    public function closeImport(): void
    {
        $this->importing = false;
    }

    public function import(Invoices $invoices): void
    {
        $this->resetErrorBag();
        $this->validate(['xmlFiles.*' => 'file|max:3072'], ['xmlFiles.*.max' => 'Um dos arquivos passa de 3 MB: não é o XML de uma nota.']);
        $files = [];

        foreach ($this->xmlFiles as $i => $file) {
            $files[($file->getClientOriginalName() ?: 'arquivo '.($i + 1)).' ('.($i + 1).')'] = (string) file_get_contents($file->getRealPath());
        }

        if (trim($this->xmlText) !== '') {
            $files['XML colado'] = $this->xmlText;
        }

        if ($files === []) {
            $this->addError('xmlFiles', 'Escolha o(s) arquivo(s) XML ou cole o conteúdo do XML.');

            return;
        }

        $results = $this->attempt(fn () => $invoices->importMany($files, auth()->user()), 'xmlFiles');

        if ($results) {
            $this->importResults = $results;
            $this->reset('xmlFiles', 'xmlText');
            unset($this->invoices);
            $done = count(array_filter($results, fn ($r) => in_array($r['status'], ['importada', 'atualizada', 'cancelada'], true)));
            $this->dispatch('toast', message: $done.' de '.count($results).' arquivo(s) importado(s).', type: $done ? 'success' : 'error');
        }
    }

    // ------------------------------------------------------------------ nota sem XML

    public function openManual(): void
    {
        $this->resetErrorBag();
        $this->reset('danfeText', 'danfeRead', 'newSupplier', 'installments');
        $this->form = ['supplier_id' => '', 'supplier_name' => '', 'issuer_cnpj' => '', 'number' => '', 'series' => '1', 'issue_date' => '', 'total' => '', 'key' => '', 'recipient_cnpj' => '', 'info' => ''];
        $this->creating = true;
    }

    public function closeManual(): void
    {
        $this->creating = false;
    }

    public function updatedFormSupplierId(mixed $value): void
    {
        $this->newSupplier = $value === 'novo';
    }

    /** Lê o texto colado da DANFE (ou só a chave) e preenche o que deu para ler. */
    public function readDanfe(): void
    {
        $read = DanfeText::read($this->danfeText);
        $key = ($read['key']['valid'] ?? false) && $read['key']['model'] === '55' ? $read['key'] : null;
        $supplier = $key ? Supplier::query()->where('document', $key['issuer_cnpj'])->first() : null;
        $existing = $key ? PurchaseInvoice::query()->where('access_key', $key['key'])->first(['id', 'number']) : null;
        $company = $read['recipient_cnpj'] ? GroupCompany::query()->active()->where('cnpj', $read['recipient_cnpj'])->value('name') : null;

        if ($key) {
            $this->form = array_merge($this->form, [
                'key' => AccessKey::format($key['key']), 'number' => $key['number'], 'series' => $key['series'], 'issuer_cnpj' => Document::format($key['issuer_cnpj']),
                'supplier_id' => $supplier ? (string) $supplier->id : ($this->form['supplier_id'] ?: 'novo'),
            ]);
            $this->newSupplier = ! $supplier && $this->form['supplier_id'] === 'novo';
        }

        $this->form['issue_date'] = $read['issue_date'] ?? $this->form['issue_date'];
        $this->form['total'] = $read['total_cents'] !== null ? number_format($read['total_cents'] / 100, 2, ',', '.') : $this->form['total'];
        $this->form['recipient_cnpj'] = $read['recipient_cnpj'] ? Document::format($read['recipient_cnpj']) : $this->form['recipient_cnpj'];
        $this->danfeRead = [
            'ok' => (bool) $key, 'warnings' => $read['warnings'], 'possible' => array_map(fn ($c) => Money::brl($c), $read['possible_totals']),
            'month' => $key ? substr($key['yymm'], 2).'/20'.substr($key['yymm'], 0, 2) : null, 'supplier' => $supplier?->name,
            'existing' => $existing ? ['id' => $existing->id, 'number' => $existing->number] : null, 'company' => $company,
        ];
    }

    public function addInstallment(): void
    {
        $this->installments[] = ['due_date' => '', 'amount' => ''];
    }

    public function removeInstallment(int $index): void
    {
        unset($this->installments[$index]);
        $this->installments = array_values($this->installments);
    }

    public function saveManual(Invoices $invoices): void
    {
        $this->resetErrorBag();
        $data = [
            'supplier_id' => ! $this->newSupplier && ctype_digit((string) ($this->form['supplier_id'] ?? '')) ? (int) $this->form['supplier_id'] : null,
            'supplier_name' => $this->newSupplier ? ($this->form['supplier_name'] ?? '') : '',
            'issuer_cnpj' => $this->form['issuer_cnpj'] ?? '', 'number' => $this->form['number'] ?? '', 'series' => $this->form['series'] ?? '',
            'issue_date' => $this->form['issue_date'] ?? '', 'total' => (string) ($this->form['total'] ?? ''), 'key' => $this->form['key'] ?? '',
            'recipient_cnpj' => $this->form['recipient_cnpj'] ?? '', 'info' => $this->form['info'] ?? '',
            'installments' => array_map(fn ($i) => ['due_date' => $i['due_date'] ?? '', 'amount' => (string) ($i['amount'] ?? '')], $this->installments),
        ];

        if ($invoice = $this->attempt(fn () => $invoices->createManual($data, auth()->user()), 'form')) {
            $this->creating = false;
            unset($this->invoices);
            $this->open($invoice->id);
            $this->dispatch('toast', message: 'Nota lançada. Consulte a chave no portal da NF-e para ela valer como prova.', type: 'success');
        }
    }

    // ------------------------------------------------------------------ nota aberta

    public function open(int $id): void
    {
        $this->invoiceId = $id;
        $this->reset('allocItemId', 'alloc', 'wholeSearch', 'wholeOrderId', 'newKey', 'statusReason', 'confirmDelete');
        $this->resetPortal();
        $this->resetErrorBag();
        $this->refreshDetail();
    }

    public function close(): void
    {
        $this->invoiceId = null;
    }

    public function startAllocate(int $itemId): void
    {
        $this->allocItemId = $itemId;
        $this->alloc = ['destination' => 'os', 'order_search' => '', 'service_order_id' => '', 'os_item_id' => '', 'quantity' => '', 'group_company_id' => '', 'notes' => ''];
        $this->resetErrorBag();
        unset($this->orderOptions, $this->orderParts);
    }

    public function cancelAllocate(): void
    {
        $this->allocItemId = null;
    }

    public function pickOrder(int $orderId): void
    {
        $this->alloc['service_order_id'] = (string) $orderId;
        $this->alloc['os_item_id'] = '';
        $order = ServiceOrder::find($orderId);
        $item = PurchaseInvoiceItem::find($this->allocItemId);

        if ($order && $item) {
            $this->alloc['os_item_id'] = (string) (app(Allocations::class)->matchingPart($order, $item)?->id ?? '');
        }

        unset($this->orderParts);
    }

    public function allocate(Allocations $allocations): void
    {
        $this->resetErrorBag();
        $item = PurchaseInvoiceItem::query()->where('purchase_invoice_id', $this->invoiceId)->findOrFail($this->allocItemId);

        if ($this->attempt(fn () => $allocations->allocate($item, $this->alloc, auth()->user()), 'alloc')) {
            $this->allocItemId = null;
            $this->refreshDetail();
            $this->dispatch('toast', message: 'Destino da peça registrado.', type: 'success');
        }
    }

    public function removeAllocation(int $id, Allocations $allocations): void
    {
        $allocation = PurchaseAllocation::query()->whereHas('item', fn ($q) => $q->where('purchase_invoice_id', $this->invoiceId))->findOrFail($id);

        if ($this->attempt(fn () => $allocations->remove($allocation, auth()->user()))) {
            $this->refreshDetail();
            $this->dispatch('toast', message: 'Destino desfeito.', type: 'success');
        }
    }

    public function applySuggestions(Allocations $allocations): void
    {
        $count = $this->attempt(fn () => $allocations->applySuggestions($this->current, auth()->user()));

        if ($count !== null) {
            $this->refreshDetail();
            $this->dispatch('toast', message: $count.' peça(s) aplicada(s) nas OS sugeridas.', type: 'success');
        }
    }

    public function allocateWhole(Allocations $allocations): void
    {
        $this->resetErrorBag();
        $order = ServiceOrder::find($this->wholeOrderId);

        if (! $order) {
            $this->addError('whole', 'Escolha a OS.');

            return;
        }

        $count = $this->attempt(fn () => $allocations->allocateInvoiceToOrder($this->current, $order, auth()->user()), 'whole');

        if ($count !== null) {
            $this->reset('wholeSearch', 'wholeOrderId');
            $this->refreshDetail();
            $this->dispatch('toast', message: $count.' item(ns) aplicado(s) na OS '.$order->number.'.', type: 'success');
        }
    }

    public function registerPortal(Invoices $invoices): void
    {
        $this->resetErrorBag();
        $result = $this->attempt(fn () => $invoices->registerPortalCheck($this->current, $this->portal, auth()->user()), 'portal');

        if ($result) {
            $this->resetPortal();
            $this->refreshDetail();
            $message = match (true) {
                $result['canceled_here'] => 'Consulta registrada: a nota foi cancelada aqui.',
                $result['confirms'] && auth()->user()->isGestor() => 'Consulta registrada: a nota está provada.',
                $result['confirms'] => 'Consulta registrada. Falta o gestor repetir a consulta para valer como prova.',
                default => 'Consulta registrada: o portal não confirma a nota. Veja as ocorrências.',
            };
            $this->dispatch('toast', message: $message, type: $result['confirms'] || $result['canceled_here'] ? 'success' : 'error');
        }
    }

    public function saveKey(Invoices $invoices): void
    {
        $this->resetErrorBag();
        if ($this->attempt(fn () => $invoices->setKey($this->current, $this->newKey, auth()->user()), 'newKey')) {
            $this->newKey = '';
            $this->refreshDetail();
            $this->dispatch('toast', message: 'Chave conferida e gravada. Agora dá para consultar no portal.', type: 'success');
        }
    }

    public function setStatus(string $status, Invoices $invoices): void
    {
        $this->resetErrorBag();
        if ($this->attempt(fn () => $invoices->setStatus($this->current, $status, $this->statusReason, auth()->user()), 'statusReason')) {
            $this->statusReason = '';
            $this->refreshDetail();
            $this->dispatch('toast', message: $status === PurchaseInvoice::CANCELED ? 'Nota cancelada.' : 'Nota reativada.', type: 'success');
        }
    }

    public function delete(Invoices $invoices): void
    {
        if ($this->attempt(fn () => $invoices->delete($this->current, auth()->user()))) {
            $this->invoiceId = null;
            unset($this->invoices);
            $this->dispatch('toast', message: 'Nota apagada (fica o registro no histórico).', type: 'success');
        }
    }

    private function resetPortal(): void
    {
        $this->portal = ['status' => 'autorizada', 'amount' => '', 'issue_date' => '', 'recipient' => ''];
    }

    private function refreshDetail(): void
    {
        unset($this->current, $this->details, $this->invoices, $this->orderOptions, $this->orderParts);
    }
};
?>

@php
    $gestor = auth()->user()->isGestor();
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $statusLabels = ['importada' => 'importada', 'atualizada' => 'completou a nota digitada', 'cancelada' => 'nota cancelada', 'ja_existia' => 'já estava cadastrada', 'divergente_manual' => 'diferente da nota digitada', 'erro' => 'não importada'];
@endphp
<div class="space-y-4">
    <x-finance-nav />
    <x-purchases-nav />

    <div class="flex flex-wrap items-center gap-2">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2 mr-auto">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Notas de compra
        </h1>
        <button type="button" wire:click="openImport" class="{{ $btnSecondary }}" data-test="importar-xml"><i class="fas fa-file-import"></i> Importar XML</button>
        <button type="button" wire:click="openManual" class="{{ $btnPrimary }}" data-test="nota-sem-xml"><i class="fas fa-plus"></i> Nota sem XML</button>
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Número, fornecedor ou chave" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <select wire:model.live="filter" class="{{ $inputClass }} sm:w-52" data-test="filtro-notas">
            <option value="">Todas</option>
            <option value="sem_boleto">Sem boleto</option>
            <option value="sem_destino">Peças sem destino</option>
            <option value="digitadas">Digitadas (sem XML)</option>
        </select>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
        @forelse ($this->invoices as $invoice)
            <button type="button" wire:click="open({{ $invoice->id }})" wire:key="nf-{{ $invoice->id }}" class="w-full text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40" data-test="nota-{{ $invoice->id }}">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold break-words">{{ $invoice->label() }} · {{ $invoice->supplier?->name }}</p>
                    <p class="flex flex-wrap gap-1 text-xs text-gray-600 dark:text-gray-300">
                        <span>{{ $invoice->issue_date->format('d/m/Y') }}</span>
                        @if (! $invoice->isActive()) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">cancelada</span> @endif
                        @if ($invoice->purpose === 'devolucao') <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">devolução</span> @endif
                        @if ($invoice->isActive() && $invoice->generatesCharge() && $invoice->balance_cents > 5) <span class="{{ $pill }} bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">sem boleto {{ $money($invoice->balance_cents) }}</span> @endif
                        @if ($invoice->isActive() && $invoice->generatesCharge() && $invoice->without_destination) <span class="{{ $pill }} bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">peças sem destino</span> @endif
                        @if ($invoice->source === 'manual') <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">{{ $invoice->access_key ? 'sem XML, com chave' : 'digitada' }}</span> @endif
                        @if ($invoice->groupCompany) <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">em nome da {{ $invoice->groupCompany->name }}</span> @endif
                    </p>
                </div>
                <p class="font-display font-bold text-lg">{{ $money($invoice->total_cents) }}</p>
            </button>
        @empty
            <p class="p-6 text-center text-gray-600 dark:text-gray-300">{{ PurchaseInvoice::query()->exists() ? 'Nenhuma nota com esse filtro.' : 'Nenhuma nota. Importe os XML que os fornecedores mandam por e-mail.' }}</p>
        @endforelse
    </div>

    {{-- Importar XML --}}
    @if ($importing)
        <x-modal close="closeImport" form="import" size="2xl" scroll data-test="janela-importar">
            <h2 class="font-display font-bold text-xl uppercase">Importar XML de NF-e</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">O PDF (DANFE) não serve: precisa do arquivo .xml. Pode escolher vários de uma vez. O XML original fica guardado com a nota.</p>
            <div>
                <label class="{{ $labelClass }}" for="xml-files">Arquivos XML</label>
                <input id="xml-files" type="file" wire:model="xmlFiles" multiple accept=".xml,text/xml,application/xml" class="block w-full text-sm" data-test="arquivos-xml">
                <div wire:loading wire:target="xmlFiles" class="text-sm text-gray-500">Enviando…</div>
                @error('xmlFiles') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('xmlFiles.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="xml-text">Ou cole o conteúdo do XML</label>
                <textarea id="xml-text" wire:model="xmlText" rows="3" class="{{ $inputClass }} font-mono text-xs" data-test="xml-colado"></textarea>
            </div>
            @if ($importResults)
                <ul class="space-y-1 text-sm" data-test="resultado-importacao">
                    @foreach ($importResults as $result)
                        <li class="rounded-lg border border-gray-200 dark:border-gray-700 p-2">
                            <span class="font-semibold break-all">{{ $result['file'] }}</span>: {{ $statusLabels[$result['status']] ?? $result['status'] }}
                            @if (! empty($result['error'])) <span class="block text-red-700 dark:text-red-300">{{ $result['error'] }}</span> @endif
                            @foreach ($result['warnings'] as $warning) <span class="block text-amber-800 dark:text-amber-200">{{ $warning }}</span> @endforeach
                            @if ($result['invoice_id']) <button type="button" wire:click="open({{ $result['invoice_id'] }})" class="text-brand-700 dark:text-brand-300 underline">abrir a nota</button> @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeImport" class="{{ $btnSecondary }}">Fechar</button>
                <button type="submit" class="{{ $btnPrimary }}" wire:loading.attr="disabled" data-test="confirmar-importacao"><i class="fas fa-file-import"></i> Importar</button>
            </div>
        </x-modal>
    @endif

    {{-- Nota sem XML --}}
    @if ($creating)
        <x-modal close="closeManual" form="saveManual" size="2xl" scroll data-test="janela-nota-manual">
            <h2 class="font-display font-bold text-xl uppercase">Nota sem XML</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">Cole o texto da DANFE (abra o PDF, selecione tudo e copie) ou só a chave de 44 números e toque em Ler. Confira tudo com a nota na mão: a prova da nota é a consulta da chave no portal da NF-e.</p>
            <div class="space-y-2">
                <textarea wire:model="danfeText" rows="3" class="{{ $inputClass }} text-xs" placeholder="Texto da DANFE ou a chave de acesso" data-test="texto-danfe"></textarea>
                <button type="button" wire:click="readDanfe" class="{{ $btnSecondary }}" data-test="ler-danfe"><i class="fas fa-wand-magic-sparkles"></i> Ler</button>
                @if ($danfeRead)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-2 text-sm space-y-1" data-test="leitura-danfe">
                        @if ($danfeRead['ok']) <p><i class="fas fa-check mr-1"></i>Chave válida (mês {{ $danfeRead['month'] }}){{ $danfeRead['supplier'] ? ', fornecedor '.$danfeRead['supplier'] : ', fornecedor ainda não cadastrado' }}.</p> @endif
                        @if ($danfeRead['existing']) <p class="text-red-700 dark:text-red-300">Esta nota já está cadastrada (nota {{ $danfeRead['existing']['number'] }}). <button type="button" wire:click="open({{ $danfeRead['existing']['id'] }})" class="underline">abrir</button></p> @endif
                        @if ($danfeRead['company']) <p>Destinatário: {{ $danfeRead['company'] }} (empresa do grupo).</p> @endif
                        @if (count($danfeRead['possible']) > 1) <p class="text-gray-600 dark:text-gray-300">Valores no bloco de impostos: {{ implode(', ', $danfeRead['possible']) }} (o total da nota é o maior).</p> @endif
                        @foreach ($danfeRead['warnings'] as $warning) <p class="text-amber-800 dark:text-amber-200">{{ $warning }}</p> @endforeach
                    </div>
                @endif
            </div>
            @error('form') <p class="text-sm text-red-600" data-test="erro-form">{{ $message }}</p> @enderror
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="nf-forn">Fornecedor</label>
                    <select id="nf-forn" wire:model.live="form.supplier_id" class="{{ $inputClass }}" data-test="nota-fornecedor">
                        <option value="">Escolha…</option>
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}{{ $supplier->document ? ' · '.Document::format($supplier->document) : '' }}</option>
                        @endforeach
                        <option value="novo">+ Fornecedor novo</option>
                    </select>
                </div>
                @if ($newSupplier)
                    <div>
                        <label class="{{ $labelClass }}" for="nf-novo">Nome do fornecedor</label>
                        <input id="nf-novo" type="text" wire:model="form.supplier_name" maxlength="120" class="{{ $inputClass }}" data-test="nota-fornecedor-novo">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="nf-cnpj">CNPJ do fornecedor</label>
                        <x-document-input id="nf-cnpj" model="form.issuer_cnpj" letters placeholder="00.000.000/0000-00" />
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="nf-chave">Chave de acesso (44 números, opcional)</label>
                    <input id="nf-chave" type="text" wire:model="form.key" maxlength="60" class="{{ $inputClass }} font-mono" placeholder="0000 0000 0000 …" data-test="nota-chave">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $labelClass }}" for="nf-num">Número</label>
                        <input id="nf-num" type="text" inputmode="numeric" wire:model="form.number" maxlength="12" class="{{ $inputClass }}" data-test="nota-numero">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="nf-serie">Série</label>
                        <input id="nf-serie" type="text" inputmode="numeric" wire:model="form.series" maxlength="4" class="{{ $inputClass }}">
                    </div>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="nf-emis">Data de emissão</label>
                    <input id="nf-emis" type="date" wire:model="form.issue_date" class="{{ $inputClass }}" data-test="nota-emissao">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="nf-total">Valor total da nota (R$)</label>
                    <input id="nf-total" type="text" inputmode="decimal" wire:model="form.total" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="nota-total">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="nf-dest">CNPJ de quem comprou (destinatário)</label>
                    <x-document-input id="nf-dest" model="form.recipient_cnpj" letters placeholder="00.000.000/0000-00" />
                </div>
                <div class="sm:col-span-2 space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="{{ $labelClass }} mb-0">Parcelas (duplicatas)</span>
                        <button type="button" wire:click="addInstallment" class="{{ $btnSecondary }} !min-h-9 text-sm ml-auto" data-test="mais-parcela"><i class="fas fa-plus"></i> Parcela</button>
                    </div>
                    @forelse ($installments as $i => $installment)
                        <div class="grid grid-cols-[1fr_1fr_auto] gap-2 items-center" wire:key="parc-{{ $i }}">
                            <input type="date" wire:model="installments.{{ $i }}.due_date" class="{{ $inputClass }} min-w-0" aria-label="Vencimento da parcela {{ $i + 1 }}">
                            <input type="text" inputmode="decimal" wire:model="installments.{{ $i }}.amount" placeholder="0,00" class="{{ $inputClass }} min-w-0" aria-label="Valor da parcela {{ $i + 1 }}">
                            <button type="button" wire:click="removeInstallment({{ $i }})" class="size-11 inline-flex items-center justify-center text-gray-500" aria-label="Tirar a parcela"><i class="fas fa-xmark"></i></button>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Sem parcelas: o boleto é comparado com o total da nota.</p>
                    @endforelse
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="nf-info">Informações (OS, placa…)</label>
                    <textarea id="nf-info" wire:model="form.info" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeManual" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-nota"><i class="fas fa-check"></i> Lançar nota</button>
            </div>
        </x-modal>
    @endif

    {{-- Nota aberta --}}
    @if ($invoiceId && ($invoice = $this->current))
        @php($d = $this->details)
        <x-modal close="close" size="3xl" scroll top data-test="janela-nota">
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <h2 class="font-display font-bold text-xl uppercase break-words">{{ $invoice->label() }} · {{ $invoice->supplier?->name }}</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Emitida em {{ $invoice->issue_date->format('d/m/Y') }} · {{ $money($invoice->total_cents) }}@if ($invoice->total_with_taxes_cents) (com tributos por fora: {{ $money($invoice->total_with_taxes_cents) }})@endif</p>
                </div>
                <button type="button" wire:click="close" class="size-11 inline-flex items-center justify-center text-gray-500" aria-label="Fechar"><i class="fas fa-xmark"></i></button>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <div><dt class="inline text-gray-500">Situação:</dt> <dd class="inline font-semibold">{{ $invoice->isActive() ? 'ativa' : 'cancelada'.($invoice->canceled_source === 'sefaz' ? ' (SEFAZ)' : '') }} · {{ PurchaseInvoice::PURPOSES[$invoice->purpose] ?? $invoice->purpose }}</dd></div>
                <div><dt class="inline text-gray-500">Chave:</dt> <dd class="inline font-mono text-xs break-all">{{ $invoice->access_key ? AccessKey::format($invoice->access_key) : 'sem chave' }}</dd></div>
                <div><dt class="inline text-gray-500">Emitente:</dt> <dd class="inline">{{ $invoice->issuer_cnpj ? Document::format($invoice->issuer_cnpj) : '—' }}</dd></div>
                <div><dt class="inline text-gray-500">Destinatário:</dt> <dd class="inline">{{ $invoice->recipient_cnpj ? Document::format($invoice->recipient_cnpj) : 'não informado' }}{{ $invoice->groupCompany ? ' ('.$invoice->groupCompany->name.')' : '' }}</dd></div>
                <div class="sm:col-span-2"><dt class="inline text-gray-500">Prova:</dt> <dd class="inline" data-test="prova-nota">
                    @if ($invoice->source !== 'manual' && in_array($invoice->protocol_status, ['100', '150'], true))
                        XML com protocolo de autorização (confirme no portal se for valor alto)
                    @elseif ($invoice->portal_status)
                        consulta no portal em {{ $invoice->portal_checked_on?->format('d/m/Y') }} pelo {{ $invoice->portal_checked_role }}: {{ PurchaseInvoice::PORTAL_STATUSES[$invoice->portal_status] ?? $invoice->portal_status }}{{ $invoice->portal_amount_cents ? ', '.$money($invoice->portal_amount_cents) : '' }}
                    @else
                        {{ $invoice->source === 'manual' ? 'digitada à mão: ainda não prova nada' : 'XML sem protocolo de autorização' }}
                    @endif
                </dd></div>
                <div class="sm:col-span-2"><dt class="inline text-gray-500">Lançada por:</dt> <dd class="inline">{{ $invoice->creator?->name ?? '—' }} ({{ $invoice->created_role ?? '—' }}) · {{ $invoice->source === 'xml' ? 'XML' : 'digitada' }}</dd></div>
            </dl>
            @if ($invoice->hasXml())
                <a href="{{ route('finance.purchases.invoice-xml', $invoice) }}" class="{{ $btnSecondary }} !min-h-9 text-sm"><i class="fas fa-download"></i> Baixar o XML original</a>
            @endif

            @if ($d['occurrences'])
                <section class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
                    @foreach ($d['occurrences'] as $o)
                        <x-purchase-occurrence :occurrence="$o" wire:key="noc-{{ md5($o['key']) }}" />
                    @endforeach
                </section>
            @endif

            {{-- Itens e destino --}}
            <section class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-display font-bold uppercase">Peças da nota</h3>
                    @if (collect($d['suggestions'])->where('ambiguous', false)->isNotEmpty())
                        <button type="button" wire:click="applySuggestions" class="{{ $btnPrimary }} !min-h-9 text-sm ml-auto" data-test="aplicar-sugestoes"><i class="fas fa-wand-magic-sparkles"></i> Aplicar sugestões</button>
                    @endif
                </div>
                @foreach ($d['suggestions'] as $s)
                    <p class="text-sm rounded-lg bg-brand-50 dark:bg-brand-900/30 p-2" wire:key="sug-{{ $s['item_id'] }}">
                        <i class="fas fa-lightbulb mr-1"></i>{{ $s['description'] }} → OS {{ $s['order_number'] }}{{ $s['plate'] ? ' ('.$s['plate'].')' : '' }}: {{ $s['reason'] }}{{ $s['ambiguous'] ? ' (escolha à mão)' : '' }}.
                    </p>
                @endforeach
                <div class="divide-y divide-gray-100 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700">
                    @foreach ($invoice->items as $item)
                        @php($rest = $d['remaining'][$item->id])
                        <div class="p-2 space-y-1" wire:key="it-{{ $item->id }}" data-test="item-{{ $item->item_number }}">
                            <div class="flex flex-wrap items-start gap-x-2">
                                <p class="min-w-0 flex-1 break-words"><span class="font-semibold">{{ $item->description }}</span> <span class="text-xs text-gray-500">{{ $item->code }}{{ $item->customer_order ? ' · pedido '.$item->customer_order : '' }}</span></p>
                                <p class="text-sm whitespace-nowrap">{{ $item->quantityLabel() }} · {{ $money($item->cost_cents) }}</p>
                            </div>
                            @foreach ($item->allocations as $allocation)
                                <p class="text-sm text-gray-600 dark:text-gray-300 flex flex-wrap items-center gap-2" wire:key="al-{{ $allocation->id }}">
                                    <i class="fas fa-arrow-right text-xs"></i>
                                    <span class="min-w-0 break-words">{{ $item->quantityLabel($allocation->quantity) }} → {{ $allocation->destinationLabel() }}
                                        @if ($allocation->serviceOrder) <a href="{{ route('orders.show', $allocation->serviceOrder) }}" wire:navigate class="underline">{{ $allocation->serviceOrder->number }}</a>{{ $allocation->serviceOrder->vehicle ? ' · '.$allocation->serviceOrder->vehicle->plate : '' }}@if ($allocation->serviceOrder->stage === Stage::Cancelada) (OS cancelada: a peça voltou a ficar sem destino)@endif @endif
                                        {{ $allocation->orderItem ? '· '.$allocation->orderItem->description : '' }}{{ $allocation->groupCompany ? $allocation->groupCompany->name : '' }} · {{ $money($allocation->cost_cents) }}</span>
                                    <button type="button" wire:click="removeAllocation({{ $allocation->id }})" wire:confirm="Desfazer este destino?" class="text-red-700 dark:text-red-300 underline min-h-9">desfazer</button>
                                </p>
                            @endforeach
                            @if ($rest['quantity'] > 0.000001 && $invoice->isActive() && $invoice->generatesCharge())
                                @if ($allocItemId === $item->id)
                                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2 space-y-2" data-test="form-destino">
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <select wire:model.live="alloc.destination" class="{{ $inputClass }}" data-test="destino">
                                                @foreach (PurchaseAllocation::DESTINATIONS as $value => $label)
                                                    @if ($value !== 'outra_empresa' || $gestor)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                            <input type="text" inputmode="decimal" wire:model="alloc.quantity" placeholder="Quantidade (vazio = {{ rtrim(rtrim(number_format($rest['quantity'], 4, ',', ''), '0'), ',') }})" class="{{ $inputClass }}" data-test="quantidade">
                                        </div>
                                        @if (($alloc['destination'] ?? '') === 'os')
                                            <input type="search" wire:model.live.debounce.400ms="alloc.order_search" placeholder="OS: número, placa ou modelo" class="{{ $inputClass }}" data-test="busca-os">
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($this->orderOptions as $order)
                                                    <button type="button" wire:click="pickOrder({{ $order->id }})" wire:key="os-{{ $order->id }}" @class([$btnSecondary.' !min-h-9 text-sm', '!border-brand-600 !text-brand-700' => (int) ($alloc['service_order_id'] ?? 0) === $order->id]) data-test="escolher-os-{{ $order->number }}">OS {{ $order->number }} · {{ $order->vehicle?->plate }}</button>
                                                @endforeach
                                            </div>
                                            @if ($this->orderParts)
                                                <select wire:model="alloc.os_item_id" class="{{ $inputClass }}" data-test="peca-da-os">
                                                    <option value="">Peça da OS (opcional)</option>
                                                    @foreach ($this->orderParts as $part)
                                                        <option value="{{ $part->id }}">{{ $part->description }}{{ $part->part_code ? ' · '.$part->part_code : '' }}{{ $part->cost_cents !== null ? ' · custo '.$money($part->cost_cents) : '' }}</option>
                                                    @endforeach
                                                </select>
                                                <p class="text-xs text-gray-500">Com a peça escolhida, o custo dela na OS vem da nota (se não foi digitado e a OS ainda não foi entregue).</p>
                                            @endif
                                        @elseif (($alloc['destination'] ?? '') === 'outra_empresa')
                                            <select wire:model="alloc.group_company_id" class="{{ $inputClass }}">
                                                <option value="">Empresa do grupo…</option>
                                                @foreach ($this->companies as $company)
                                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                                @endforeach
                                            </select>
                                            <p class="text-xs text-gray-500">Peça comprada no CNPJ da oficina e entregue a outra empresa: o custo fica a receber dela (Compras > Grupo).</p>
                                        @endif
                                        @error('alloc') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button type="button" wire:click="cancelAllocate" class="{{ $btnSecondary }}">Cancelar</button>
                                            <button type="button" wire:click="allocate" class="{{ $btnPrimary }}" data-test="salvar-destino"><i class="fas fa-check"></i> Dar destino</button>
                                        </div>
                                    </div>
                                @else
                                    <button type="button" wire:click="startAllocate({{ $item->id }})" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="dar-destino-{{ $item->item_number }}"><i class="fas fa-arrow-right"></i> Dar destino ({{ $item->quantityLabel($rest['quantity']) }} sem destino)</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($invoice->isActive() && $invoice->generatesCharge() && collect($d['remaining'])->contains(fn ($r) => $r['quantity'] > 0.000001))
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="search" wire:model.live.debounce.400ms="wholeSearch" placeholder="Toda a nota para a OS: número ou placa" class="{{ $inputClass }} flex-1 min-w-[12rem]" data-test="busca-os-nota">
                        @if (! $allocItemId)
                            @foreach ($this->orderOptions as $order)
                                <button type="button" wire:click="$set('wholeOrderId', {{ $order->id }})" wire:key="w-{{ $order->id }}" @class([$btnSecondary.' !min-h-9 text-sm', '!border-brand-600 !text-brand-700' => $wholeOrderId === $order->id])>OS {{ $order->number }} · {{ $order->vehicle?->plate }}</button>
                            @endforeach
                        @endif
                        <button type="button" wire:click="allocateWhole" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="nota-toda-na-os">Aplicar o que falta na OS</button>
                    </div>
                    @error('whole') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            </section>

            {{-- Parcelas e boletos --}}
            <section class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <h3 class="font-display font-bold uppercase">Parcelas</h3>
                    @forelse ($d['installments'] as $row)
                        <p class="text-sm">{{ $row['installment']->due_date->format('d/m/Y') }} · {{ $money($row['installment']->amount_cents) }} @if ($row['balance_cents'] > 0) <span class="{{ $pill }} bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">sem boleto {{ $money($row['balance_cents']) }}</span> @endif</p>
                    @empty
                        <p class="text-sm text-gray-500">Sem parcelas informadas.</p>
                    @endforelse
                </div>
                <div class="space-y-1">
                    <h3 class="font-display font-bold uppercase">Boletos ligados</h3>
                    @forelse ($invoice->links as $link)
                        <a href="{{ route('finance.purchases.slips', ['boleto' => $link->bank_slip_id]) }}" wire:navigate class="block text-sm underline">Boleto de {{ $money($link->slip->amount_cents) }}, vence {{ $link->slip->due_date->format('d/m/Y') }} ({{ mb_strtolower($link->slip->statusLabel()) }}): {{ $money($link->amount_cents) }} desta nota</a>
                    @empty
                        <p class="text-sm text-gray-500">Nenhum boleto ligado. Saldo sem boleto: {{ $money($invoice->total_cents - $d['linked_cents']) }}.</p>
                    @endforelse
                </div>
            </section>

            {{-- Chave e consulta no portal --}}
            <section class="space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <h3 class="font-display font-bold uppercase">Consulta no portal da NF-e</h3>
                @if (! $invoice->access_key || ($invoice->source === 'manual' && $gestor))
                    <div class="flex flex-wrap gap-2">
                        <input type="text" wire:model="newKey" maxlength="60" placeholder="{{ $invoice->access_key ? 'Trocar a chave (gestor)' : 'Informar a chave (44 números)' }}" class="{{ $inputClass }} flex-1 min-w-[12rem] font-mono" data-test="informar-chave">
                        <button type="button" wire:click="saveKey" class="{{ $btnSecondary }}" data-test="salvar-chave">Gravar a chave</button>
                    </div>
                    @error('newKey') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
                @if ($invoice->access_key)
                    <ol class="list-decimal pl-5 text-sm text-gray-600 dark:text-gray-300">
                        <li>Abra o portal, cole a chave e resolva o captcha.</li>
                        <li>Leia a situação e o "Valor total da nota" e registre aqui. {{ $gestor ? '' : 'A consulta do atendimento fica pendente: o gestor repete para valer como prova.' }}</li>
                    </ol>
                    <a href="{{ $this::PORTAL_URL }}" target="_blank" rel="noopener noreferrer" class="{{ $btnSecondary }} !min-h-9 text-sm"><i class="fas fa-up-right-from-square"></i> Abrir o portal da NF-e</a>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <select wire:model.live="portal.status" class="{{ $inputClass }}" data-test="portal-situacao">
                            @foreach (PurchaseInvoice::PORTAL_STATUSES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if (($portal['status'] ?? '') === 'autorizada')
                            <input type="text" inputmode="decimal" wire:model="portal.amount" placeholder="Valor total que o portal mostra" class="{{ $inputClass }}" data-test="portal-valor">
                            <input type="date" wire:model="portal.issue_date" class="{{ $inputClass }}" aria-label="Data de emissão que o portal mostra (se mostrar)">
                            <input type="text" wire:model="portal.recipient" maxlength="20" placeholder="Destinatário que o portal mostra (pode ter *)" class="{{ $inputClass }}">
                        @endif
                    </div>
                    @error('portal') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <button type="button" wire:click="registerPortal" class="{{ $btnPrimary }}" data-test="registrar-consulta"><i class="fas fa-check"></i> Registrar a consulta</button>
                @endif
            </section>

            @if ($gestor)
                <section class="space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <h3 class="font-display font-bold uppercase">Situação da nota (gestor)</h3>
                    <input type="text" wire:model="statusReason" maxlength="300" placeholder="Motivo (mínimo 5 letras)" class="{{ $inputClass }}" data-test="motivo-situacao">
                    @error('statusReason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        @if ($invoice->isActive())
                            <button type="button" wire:click="setStatus('cancelada')" class="{{ $btnSecondary }}" data-test="cancelar-nota">Marcar como cancelada</button>
                        @elseif ($invoice->canceled_source !== 'sefaz')
                            <button type="button" wire:click="setStatus('ativa')" class="{{ $btnSecondary }}">Reativar</button>
                        @endif
                        @if ($confirmDelete)
                            <button type="button" wire:click="delete" class="{{ $btnPrimary }} !bg-red-600 hover:!bg-red-700" data-test="confirmar-apagar">Sim, apagar a nota</button>
                        @else
                            <button type="button" wire:click="$set('confirmDelete', true)" class="{{ $btnSecondary }} text-red-700">Apagar</button>
                        @endif
                    </div>
                </section>
            @endif
        </x-modal>
    @endif
</div>
