<?php

use App\Enums\DueWindow;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\NavigatesMonths;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Payable;
use App\Models\Supplier;
use App\Services\Finance\FinanceSummary;
use App\Services\Finance\Installments;
use App\Services\Finance\Month;
use App\Services\Finance\Payables;
use App\Services\Purchases\PaymentLock;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * A pagar: boletos de fornecedor. Parcelas como no artefato (vencimento de cada uma digitado; o valor é o de cada
 * parcela, ou o total dividido em centavos exatos) e "marcar como pago" direto na lista.
 */
new #[Title('A pagar')] class extends Component
{
    use FinanceAccess;
    use NavigatesMonths;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'fornecedor', except: '')]
    public string $supplierFilter = '';

    /** aberto, pago ou todos. */
    #[Url(as: 'situacao', except: 'aberto')]
    public string $status = 'aberto';

    #[Url(as: 'prazo', except: '')]
    public string $window = '';

    public bool $editing = false;

    public ?int $payableId = null;

    /** @var array<string, mixed> dinheiro como texto digitado */
    public array $form = [];

    public bool $newSupplier = false;

    public bool $installments = false;

    public int $installmentCount = 2;

    /** O valor digitado é o total, a dividir (senão é o de cada parcela, como no artefato). */
    public bool $amountIsTotal = false;

    /** @var list<string> */
    public array $dueDates = [];

    public bool $confirmDelete = false;

    protected function monthChanged(): void
    {
        unset($this->payables);
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['aberto', 'pago', 'todos'], true) ? $status : 'aberto';
        $this->window = '';
    }

    public function setWindow(string $window): void
    {
        $this->window = $this->window === $window ? '' : (DueWindow::tryFrom($window)?->value ?? '');
        $this->status = 'aberto';
    }

    #[Computed]
    public function deadlines(): array
    {
        return app(FinanceSummary::class)->payableDeadlines();
    }

    #[Computed]
    public function openTotal(): int
    {
        return (int) Payable::query()->open()->sum('amount_cents');
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->where(fn ($q) => $q->active()->orWhereIn('id', Payable::query()->select('supplier_id')))->orderBy('name')->get(['id', 'name', 'active']);
    }

    #[Computed]
    public function payables()
    {
        $term = trim($this->search);
        $window = DueWindow::tryFrom($this->window);
        $query = Payable::query()->with('supplier')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('document_number', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%')
                ->orWhere('notes', 'like', '%'.$term.'%')
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', '%'.$term.'%'))))
            ->when(ctype_digit($this->supplierFilter), fn ($q) => $q->where('supplier_id', (int) $this->supplierFilter));

        if ($window) {
            $window->constrain($query->open());
        } else {
            match ($this->status) {
                'pago' => $query->paid(),
                'todos' => null,
                default => $query->open(),
            };

            if (! $this->allMonths()) {
                // Mês de referência: o do pagamento para o que foi pago; o do vencimento para o que está em aberto.
                [$start, $end] = Month::bounds($this->selectedMonth());
                $query->where(fn ($q) => $q->where(fn ($p) => $p->paid()->whereBetween('paid_on', [$start, $end]))
                    ->orWhere(fn ($o) => $o->open()->whereBetween('due_date', [$start, $end])));
            }
        }

        return $this->status === 'pago' && ! $window
            ? $query->orderByDesc('paid_on')->orderByDesc('id')->limit(500)->get()
            : $query->orderBy('due_date')->orderBy('id')->limit(500)->get();
    }

    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ($this->payables as $payable) {
            $key = $payable->isPaid() ? 'Pagos · '.Month::label(Month::of($payable->paid_on)) : Month::label(Month::of($payable->due_date));
            $groups[$key] ??= ['label' => $key, 'cents' => 0, 'items' => [], 'overdue' => false];
            $groups[$key]['items'][] = $payable;
            $groups[$key]['cents'] += $payable->amount_cents;
            $groups[$key]['overdue'] = $groups[$key]['overdue'] || $payable->isOverdue();
        }

        return array_values($groups);
    }

    #[Computed]
    public function current(): ?Payable
    {
        return $this->payableId ? Payable::with('supplier')->find($this->payableId) : null;
    }

    /** Selo da conferência de compras das contas que têm boleto em Financeiro > Compras (conta => veredito). */
    #[Computed]
    public function purchaseVerdicts(): array
    {
        return app(PaymentLock::class)->verdicts($this->payables);
    }

    /** Valor de cada parcela como vai ser lançado. */
    #[Computed]
    public function installmentValues(): array
    {
        $amount = Money::parseBr((string) ($this->form['amount'] ?? '')) ?? 0;

        if ($amount <= 0 || $this->installmentCount < 2 || $this->installmentCount > Installments::MAX) {
            return [];
        }

        return $this->amountIsTotal ? Installments::split($amount, $this->installmentCount) : array_fill(0, $this->installmentCount, $amount);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->form = [
            'supplier_id' => ctype_digit($this->supplierFilter) ? $this->supplierFilter : '', 'supplier_name' => '',
            'document_number' => '', 'description' => '', 'issue_date' => now()->toDateString(), 'due_date' => '',
            'amount' => '', 'status' => Payable::STATUS_OPEN, 'paid_on' => now()->toDateString(), 'notes' => '',
        ];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $payable = Payable::findOrFail($id);
        $this->resetForm();
        $this->payableId = $payable->id;
        $this->form = [
            'supplier_id' => (string) $payable->supplier_id, 'supplier_name' => '',
            'document_number' => (string) $payable->document_number,
            'description' => (string) $payable->description,
            'issue_date' => $payable->issue_date?->toDateString() ?? '',
            'due_date' => $payable->due_date->toDateString(),
            'amount' => number_format($payable->amount_cents / 100, 2, ',', '.'),
            'status' => $payable->status,
            'paid_on' => $payable->paid_on?->toDateString() ?? now()->toDateString(),
            'notes' => (string) $payable->notes,
        ];
        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
    }

    public function updatedFormSupplierId(mixed $value): void
    {
        $this->newSupplier = $value === 'novo';
    }

    public function updatedInstallmentCount(): void
    {
        $this->installmentCount = max(2, min(Installments::MAX, (int) $this->installmentCount));
        $this->dueDates = array_slice(array_pad($this->dueDates, $this->installmentCount, ''), 0, $this->installmentCount);
    }

    public function updatedInstallments(bool $on): void
    {
        if ($on) {
            $this->updatedInstallmentCount();
        }
    }

    public function save(Payables $service): void
    {
        $this->resetErrorBag();
        $data = [
            'supplier_id' => $this->newSupplier || ! ctype_digit((string) ($this->form['supplier_id'] ?? '')) ? null : (int) $this->form['supplier_id'],
            'supplier_name' => $this->newSupplier ? ($this->form['supplier_name'] ?? '') : '',
            'document_number' => $this->form['document_number'] ?? '',
            'description' => $this->form['description'] ?? '',
            'issue_date' => $this->form['issue_date'] ?? '',
            'amount_cents' => (string) ($this->form['amount'] ?? ''),
            'status' => $this->form['status'] ?? Payable::STATUS_OPEN,
            'paid_on' => $this->form['paid_on'] ?? '',
            'notes' => $this->form['notes'] ?? '',
        ];
        $user = auth()->user();

        if ($this->payableId) {
            $done = $this->attempt(fn () => $service->update(Payable::findOrFail($this->payableId), $data + ['due_date' => $this->form['due_date'] ?? ''], $user), 'form');
            $message = 'Boleto atualizado.';
        } else {
            $dates = $this->installments ? $this->dueDates : [$this->form['due_date'] ?? ''];

            if ($this->installments && count(array_filter($dates)) < $this->installmentCount) {
                $this->addError('form', 'Informe o vencimento de todas as parcelas.');

                return;
            }

            $done = $this->attempt(fn () => $service->create($data, $dates, $user, $this->installments && $this->amountIsTotal), 'form');
            $message = $this->installments ? $this->installmentCount.' parcelas lançadas.' : 'Boleto salvo.';
        }

        if ($done) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: $message, type: 'success');
        }
    }

    /** "Marcar como pago" (da lista: com a data de hoje; da janela: com a data do campo). */
    public function markPaid(int $id, Payables $service): void
    {
        $date = $this->editing && $this->payableId === $id && filled($this->form['paid_on'] ?? null) ? $this->form['paid_on'] : now()->toDateString();

        if ($this->attempt(fn () => $service->markPaid(Payable::findOrFail($id), $date, auth()->user()))) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: 'Boleto marcado como pago.', type: 'success');
        }
    }

    public function delete(Payables $service): void
    {
        if ($this->attempt(fn () => $service->delete(Payable::findOrFail($this->payableId), auth()->user()))) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: 'Boleto excluído.', type: 'success');
        }
    }

    private function refreshLists(): void
    {
        unset($this->payables, $this->groups, $this->deadlines, $this->openTotal, $this->suppliers, $this->current, $this->purchaseVerdicts);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->payableId = null;
        $this->newSupplier = false;
        $this->installments = false;
        $this->installmentCount = 2;
        $this->amountIsTotal = false;
        $this->dueDates = [];
        $this->confirmDelete = false;
        unset($this->current);
    }
};
?>

@php
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>A pagar
        </h1>
        <button type="button" wire:click="create" class="{{ $btnPrimary }} ml-auto" data-test="novo-boleto"><i class="fas fa-plus"></i> Novo boleto</button>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
        <x-finance-tile label="Em aberto" :value="$money($this->openTotal)" note="boletos a pagar" wire:click="setWindow('')" :active="$window === '' && $status === 'aberto'" />
        @foreach (DueWindow::cases() as $case)
            <x-finance-tile :label="$case->label()" :value="$money($this->deadlines[$case->value]['cents'])" :note="$this->deadlines[$case->value]['count'].' boleto(s)'" :tone="$case === DueWindow::Vencidos && $this->deadlines[$case->value]['count'] > 0 ? 'bad' : 'plain'" wire:click="setWindow('{{ $case->value }}')" :active="$window === $case->value" wire:key="tile-{{ $case->value }}" data-test="cartao-{{ $case->value }}" />
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Fornecedor, documento, descrição" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <select wire:model.live="supplierFilter" class="{{ $inputClass }} sm:w-56" data-test="filtro-fornecedor">
            <option value="">Todos os fornecedores</option>
            @foreach ($this->suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select wire:change="setStatus($event.target.value)" class="{{ $inputClass }} sm:w-40" data-test="filtro-situacao">
            @foreach (['aberto' => 'Em aberto', 'pago' => 'Pagos', 'todos' => 'Todos'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if ($window !== '')
            <span class="inline-flex items-center gap-2 min-h-11 px-3 rounded-full bg-brand-50 dark:bg-brand-900/40 text-brand-800 dark:text-brand-200 text-sm font-semibold">
                {{ DueWindow::from($window)->label() }} · todos os meses
                <button type="button" wire:click="setWindow('')" class="size-8 inline-flex items-center justify-center" aria-label="Tirar o filtro"><i class="fas fa-xmark"></i></button>
            </span>
        @else
            <x-finance-month-nav :month="$month" />
        @endif
    </div>

    @forelse ($this->groups as $group)
        <section class="space-y-2" wire:key="g-{{ md5($group['label']) }}">
            <h2 @class(['flex items-baseline gap-2 font-display font-bold uppercase tracking-wide text-sm', 'text-red-700 dark:text-red-400' => $group['overdue'], 'text-gray-500 dark:text-gray-400' => ! $group['overdue']])>
                {{ $group['label'] }} <span class="ml-auto font-sans text-gray-700 dark:text-gray-200">{{ $money($group['cents']) }}</span>
            </h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($group['items'] as $payable)
                    @php
                        $overdue = $payable->isOverdue();
                        $days = (int) now()->startOfDay()->diffInDays($payable->due_date, false);
                    @endphp
                    <div class="flex items-stretch {{ $overdue ? 'border-l-4 border-l-stripe' : '' }}" wire:key="b-{{ $payable->id }}">
                        <button type="button" wire:click="edit({{ $payable->id }})" class="min-w-0 flex-1 text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40" data-test="boleto-{{ $payable->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold flex flex-wrap items-center gap-1">
                                    <span class="truncate">{{ $payable->supplier?->name ?? '(fornecedor removido)' }}</span>
                                    @if ($payable->installmentLabel()) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">parcela {{ $payable->installmentLabel() }}</span> @endif
                                    @if ($check = $this->purchaseVerdicts[$payable->id] ?? null)
                                        <span @class([$pill, 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $check['level'] === 'ruim', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $check['level'] === 'atencao', 'bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200' => $check['level'] === 'ok', 'bg-gray-100 dark:bg-gray-700' => $check['level'] === 'neutro']) title="{{ $check['text'] }}" data-test="selo-compras-{{ $payable->id }}"><i class="fas fa-receipt mr-1"></i>{{ $check['short'] }}</span>
                                    @endif
                                    @if ($payable->isPaid())
                                        <span class="{{ $pill }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200"><i class="fas fa-check mr-1"></i>pago {{ $payable->paid_on?->format('d/m/Y') }}</span>
                                    @elseif ($overdue)
                                        <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">vencido há {{ -$days }} d</span>
                                    @elseif ($days === 0)
                                        <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">vence hoje</span>
                                    @elseif ($days <= 7)
                                        <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">em {{ $days }} d</span>
                                    @endif
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ collect([$payable->document_number, $payable->description, $payable->notes])->filter()->implode(' · ') ?: '—' }}</p>
                                <p class="text-xs text-gray-500">emis. {{ $payable->issue_date?->format('d/m/Y') ?? '—' }} · venc. {{ $payable->due_date->format('d/m/Y') }}</p>
                            </div>
                            <p class="font-display font-bold text-lg">{{ $money($payable->amount_cents) }}</p>
                        </button>
                        @unless ($payable->isPaid())
                            <button type="button" wire:click="markPaid({{ $payable->id }})" wire:confirm="Marcar como pago hoje?" class="shrink-0 w-16 sm:w-20 flex flex-col items-center justify-center gap-1 border-l border-gray-100 dark:border-gray-700 text-brand-700 dark:text-brand-300 text-xs font-semibold hover:bg-brand-50 dark:hover:bg-brand-900/30" data-test="pagar-{{ $payable->id }}">
                                <i class="fas fa-check text-base"></i>Pagar
                            </button>
                        @endunless
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">
            {{ Payable::query()->exists() ? 'Nenhum boleto com esse filtro.' : 'Nenhum boleto ainda. Toque em "Novo boleto" para registrar o primeiro.' }}
        </p>
    @endforelse

    @if ($editing)
        @php($current = $this->current)
        <x-modal close="close" form="save" size="2xl" scroll data-test="janela-boleto">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase">{{ $current ? 'Boleto' : 'Novo boleto' }}</h2>
                @if ($current?->installmentLabel()) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">parcela {{ $current->installmentLabel() }}</span> @endif
            </div>
            @if ($current && ($check = $this->purchaseVerdicts[$current->id] ?? null))
                <p class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2 text-sm" data-test="conferencia-compras">
                    <i class="fas fa-receipt mr-1"></i>Boleto da conferência de compras: {{ $check['text'] }}.
                    <a href="{{ route('finance.purchases.slips', ['boleto' => $check['slip_id']]) }}" wire:navigate class="underline">Abrir em Compras</a>
                </p>
            @endif
            @error('form') <p class="text-sm text-red-600" data-test="erro-form">{{ $message }}</p> @enderror

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="b-forn">Fornecedor</label>
                    <select id="b-forn" wire:model.live="form.supplier_id" class="{{ $inputClass }}" data-test="boleto-fornecedor">
                        <option value="">Escolha…</option>
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}{{ $supplier->active ? '' : ' (inativo)' }}</option>
                        @endforeach
                        <option value="novo">+ Cadastrar novo fornecedor</option>
                    </select>
                </div>
                @if ($newSupplier)
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="b-novo">Nome do novo fornecedor</label>
                        <input id="b-novo" type="text" wire:model="form.supplier_name" maxlength="120" placeholder="Ex.: Autopeças Silva" class="{{ $inputClass }}" data-test="boleto-fornecedor-novo">
                        <p class="mt-1 text-xs text-gray-500">Entra no cadastro de Fornecedores (o mesmo da cotação), onde se completam WhatsApp e Pix.</p>
                    </div>
                @endif
                <div>
                    <label class="{{ $labelClass }}" for="b-doc">Nº do documento / NF</label>
                    <input id="b-doc" type="text" wire:model="form.document_number" maxlength="60" placeholder="Ex.: NF 1234" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="b-desc">Descrição</label>
                    <input id="b-desc" type="text" wire:model="form.description" maxlength="200" placeholder="Ex.: pastilhas e discos" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="b-emis">Emissão</label>
                    <input id="b-emis" type="date" wire:model="form.issue_date" class="{{ $inputClass }}">
                </div>
                @unless ($installments)
                    <div>
                        <label class="{{ $labelClass }}" for="b-venc">Vencimento</label>
                        <input id="b-venc" type="date" wire:model="form.due_date" class="{{ $inputClass }}" data-test="boleto-vencimento">
                    </div>
                @endunless
                <div>
                    <label class="{{ $labelClass }}" for="b-valor">{{ $installments ? ($amountIsTotal ? 'Valor total (R$)' : 'Valor de cada parcela (R$)') : 'Valor (R$)' }}</label>
                    <input id="b-valor" type="text" inputmode="decimal" wire:model.live.blur="form.amount" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="boleto-valor">
                </div>

                @unless ($current)
                    <label class="sm:col-span-2 flex items-center gap-3 min-h-11">
                        <input type="checkbox" wire:model.live="installments" class="size-5 rounded border-gray-300 text-brand-600" data-test="parcelas">
                        Lançar em parcelas (datas de vencimento diferentes)
                    </label>
                    @if ($installments)
                        <div class="sm:col-span-2 space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="{{ $labelClass }} mb-0" for="b-n">Número de parcelas</label>
                                <input id="b-n" type="number" min="2" max="{{ Installments::MAX }}" wire:model.live.debounce.400ms="installmentCount" class="{{ $inputClass }} w-24" data-test="numero-parcelas">
                            </div>
                            <label class="flex items-center gap-3 min-h-11 text-sm">
                                <input type="checkbox" wire:model.live="amountIsTotal" class="size-5 rounded border-gray-300 text-brand-600"> O valor informado é o total (dividir em centavos exatos)
                            </label>
                            <p class="text-xs text-gray-500">Cada parcela é um boleto separado, todas como "A pagar", com o vencimento que você informar.</p>
                            @for ($i = 0; $i < $installmentCount; $i++)
                                {{-- No celular: "Parcela N" e o valor numa linha, a data embaixo; no computador, tudo numa linha. --}}
                                <div class="grid grid-cols-2 sm:grid-cols-[6rem_1fr_7rem] items-center gap-x-2 gap-y-1" wire:key="due-{{ $i }}">
                                    <span class="text-sm text-gray-500">Parcela {{ $i + 1 }}</span>
                                    <span class="text-right text-sm font-semibold sm:order-last">{{ isset($this->installmentValues[$i]) ? $money($this->installmentValues[$i]) : '—' }}</span>
                                    <input type="date" wire:model="dueDates.{{ $i }}" class="{{ $inputClass }} col-span-2 sm:col-span-1 min-w-0" data-test="vencimento-parcela-{{ $i + 1 }}">
                                </div>
                            @endfor
                        </div>
                    @endif
                @endunless

                @if ($current || ! $installments)
                    <div>
                        <label class="{{ $labelClass }}" for="b-st">Situação</label>
                        <select id="b-st" wire:model.live="form.status" class="{{ $inputClass }}">
                            <option value="aberto">A pagar</option>
                            <option value="pago">Pago</option>
                        </select>
                    </div>
                    @if (($form['status'] ?? '') === 'pago')
                        <div>
                            <label class="{{ $labelClass }}" for="b-pago">Data do pagamento</label>
                            <input id="b-pago" type="date" wire:model="form.paid_on" class="{{ $inputClass }}">
                        </div>
                    @endif
                @endif

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="b-obs">Observações</label>
                    <textarea id="b-obs" wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
            </div>

            @if ($confirmDelete)
                <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-3 space-y-2">
                    <p class="text-sm text-red-800 dark:text-red-200">Excluir este boleto?{{ $current?->installmentLabel() ? ' As outras parcelas continuam.' : '' }}</p>
                    <div class="flex flex-wrap gap-2 justify-end">
                        <button type="button" wire:click="$set('confirmDelete', false)" class="{{ $btnSecondary }}">Cancelar</button>
                        <button type="button" wire:click="delete" class="{{ $btnPrimary }} !bg-red-600 hover:!bg-red-700">Sim, excluir</button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if ($current)
                    <button type="button" wire:click="$set('confirmDelete', true)" class="{{ $btnSecondary }} sm:mr-auto text-red-700">Excluir</button>
                    @unless ($current->isPaid())
                        <button type="button" wire:click="markPaid({{ $current->id }})" class="{{ $btnSecondary }}"><i class="fas fa-check"></i> Marcar como pago</button>
                    @endunless
                @endif
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-boleto"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
