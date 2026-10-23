<?php

use App\Enums\ExpenseCategory;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Payable;
use App\Models\RecurringBill;
use App\Models\Supplier;
use App\Services\Finance\Month;
use App\Services\Finance\RecurringBills;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Contas fixas: os modelos (salário, aluguel, energia, sistema, contador...) que geram a conta do mês no "A pagar",
 * sem duplicar. O botão gera o mês atual e o próximo; o agendador faz o mesmo todo dia. Editar o modelo vale para
 * as próximas contas (as já geradas não mudam).
 */
new #[Title('Contas fixas')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    public bool $editing = false;

    public ?int $billId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $newSupplier = false;

    #[Computed]
    public function bills()
    {
        return RecurringBill::query()->with('supplier:id,name')->orderByDesc('active')->orderBy('due_day')->orderBy('description')->get();
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->where(fn ($q) => $q->active()->orWhereIn('id', RecurringBill::query()->select('supplier_id')))->orderBy('name')->get(['id', 'name', 'active']);
    }

    /** Contas geradas para este mês e o próximo (com a situação no A pagar). */
    #[Computed]
    public function generated()
    {
        [$from] = Month::bounds(Month::current());
        [, $to] = Month::bounds(Month::shift(Month::current(), 1));

        return Payable::query()->whereNotNull('recurring_bill_id')->whereBetween('due_date', [$from, $to])->with('supplier:id,name')->orderBy('due_date')->get();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->billId = null;
        $this->newSupplier = false;
        $this->form = ['supplier_id' => '', 'supplier_name' => '', 'description' => '', 'category' => ExpenseCategory::Fixo->value, 'due_day' => '5', 'amount' => '', 'first_month' => '', 'active' => true, 'notes' => ''];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $bill = RecurringBill::findOrFail($id);
        $this->resetValidation();
        $this->billId = $bill->id;
        $this->newSupplier = false;
        $this->form = [
            'supplier_id' => (string) $bill->supplier_id, 'supplier_name' => '',
            'description' => $bill->description,
            'category' => $bill->category->value,
            'due_day' => (string) $bill->due_day,
            'amount' => number_format($bill->amount_cents / 100, 2, ',', '.'),
            'first_month' => $bill->first_month,
            'active' => $bill->active,
            'notes' => (string) $bill->notes,
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

    public function save(RecurringBills $service): void
    {
        $this->resetErrorBag();
        $data = [
            'supplier_id' => $this->newSupplier || ! ctype_digit((string) ($this->form['supplier_id'] ?? '')) ? null : (int) $this->form['supplier_id'],
            'supplier_name' => $this->newSupplier ? ($this->form['supplier_name'] ?? '') : '',
            'description' => $this->form['description'] ?? '',
            'category' => $this->form['category'] ?? '',
            'due_day' => $this->form['due_day'] ?? '',
            'amount' => (string) ($this->form['amount'] ?? ''),
            'first_month' => $this->form['first_month'] ?? '',
            'active' => (bool) ($this->form['active'] ?? true),
            'notes' => $this->form['notes'] ?? '',
        ];
        $user = auth()->user();
        $bill = $this->billId ? RecurringBill::findOrFail($this->billId) : null;

        if ($this->attempt(fn () => $service->save($bill, $data, $user), 'form')) {
            $created = $service->generateDue($user);
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: 'Conta fixa salva.'.($created > 0 ? ' '.$created.' conta(s) gerada(s) no A pagar.' : ''), type: 'success');
        }
    }

    public function toggle(int $id, RecurringBills $service): void
    {
        if ($bill = $this->attempt(fn () => $service->toggle(RecurringBill::findOrFail($id), auth()->user()))) {
            $this->refreshLists();
            $this->dispatch('toast', message: $bill->active ? 'Conta fixa reativada.' : 'Conta fixa pausada: não gera as próximas.', type: 'success');
        }
    }

    public function generate(RecurringBills $service): void
    {
        if (($created = $this->attempt(fn () => $service->generateDue(auth()->user()))) !== null) {
            $this->refreshLists();
            $count = $created === true ? 0 : (int) $created;
            $this->dispatch('toast', message: $count > 0 ? $count.' conta(s) gerada(s) no A pagar.' : 'Nada novo: as contas do mês já estavam no A pagar.', type: 'success');
        }
    }

    private function refreshLists(): void
    {
        unset($this->bills, $this->suppliers, $this->generated);
    }
};
?>

@php
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $active = $this->bills->where('active', true);
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Contas fixas
        </h1>
        <div class="flex flex-wrap gap-2 sm:ml-auto w-full sm:w-auto">
            <button type="button" wire:click="generate" class="{{ $btnSecondary }} flex-1 sm:flex-none" data-test="gerar-contas"><i class="fas fa-rotate"></i> Gerar contas do mês</button>
            <button type="button" wire:click="create" class="{{ $btnPrimary }} flex-1 sm:flex-none" data-test="nova-conta-fixa"><i class="fas fa-plus"></i> Nova conta fixa</button>
        </div>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Cada conta fixa gera a conta do mês no <a href="{{ route('finance.payables') }}" wire:navigate class="text-brand-600 hover:underline">A pagar</a>, no dia do vencimento, uma vez só (o mês atual e o próximo; todo dia o sistema confere sozinho). Mudar o modelo vale para as próximas contas.</p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
        <x-finance-tile label="Por mês" :value="$money((int) $active->sum('amount_cents'))" :note="$active->count().' conta(s) ativa(s)'" tone="brand" data-test="total-fixas" />
        @foreach ([ExpenseCategory::Folha, ExpenseCategory::Fixo, ExpenseCategory::Socios] as $category)
            <x-finance-tile :label="$category->shortLabel()" :value="$money((int) $active->where('category', $category)->sum('amount_cents'))" note="por mês" wire:key="cat-{{ $category->value }}" />
        @endforeach
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
        @forelse ($this->bills as $bill)
            <div class="flex items-stretch {{ $bill->active ? '' : 'opacity-60' }}" wire:key="f-{{ $bill->id }}">
                <button type="button" wire:click="edit({{ $bill->id }})" class="min-w-0 flex-1 text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40" data-test="conta-fixa-{{ $bill->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold flex flex-wrap items-center gap-1">
                            <span class="break-words">{{ $bill->description }}</span>
                            <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">{{ $bill->category->shortLabel() }}</span>
                            @unless ($bill->active) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">pausada</span> @endunless
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ $bill->supplier?->name ?? '—' }} · todo dia {{ $bill->due_day }}{{ $bill->generated_through ? ' · gerada até '.Month::label($bill->generated_through) : ' · começa em '.Month::label($bill->first_month) }}</p>
                    </div>
                    <p class="font-display font-bold text-lg">{{ $money($bill->amount_cents) }}</p>
                </button>
                <button type="button" wire:click="toggle({{ $bill->id }})" class="shrink-0 w-20 flex flex-col items-center justify-center gap-1 border-l border-gray-100 dark:border-gray-700 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/40" data-test="pausar-{{ $bill->id }}">
                    <i class="fas {{ $bill->active ? 'fa-pause' : 'fa-play' }} text-base"></i>{{ $bill->active ? 'Pausar' : 'Reativar' }}
                </button>
            </div>
        @empty
            <p class="p-6 text-center text-gray-600 dark:text-gray-300">Nenhuma conta fixa ainda. Cadastre salários, aluguel, energia, sistema, contador, pró-labore…</p>
        @endforelse
    </div>

    @if ($this->generated->isNotEmpty())
        <section class="space-y-2">
            <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Geradas para {{ Month::label(Month::current()) }} e {{ Month::label(Month::shift(Month::current(), 1)) }}</h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="contas-geradas">
                @foreach ($this->generated as $payable)
                    <a href="{{ route('finance.payables', ['mes' => Month::of($payable->due_date)]) }}" wire:navigate class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="p-{{ $payable->id }}">
                        <span class="min-w-0 flex-1 break-words">{{ $payable->description }} <span class="text-sm text-gray-500">· {{ $payable->due_date->format('d/m/Y') }}</span></span>
                        <span @class(['text-xs font-semibold', 'text-red-700 dark:text-red-400' => $payable->isOverdue(), 'text-gray-500' => ! $payable->isOverdue()])>{{ $payable->isPaid() ? 'paga' : ($payable->isOverdue() ? 'vencida' : 'a pagar') }}</span>
                        <span class="font-semibold">{{ $money($payable->amount_cents) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($editing)
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-conta-fixa">
            <h2 class="font-display font-bold text-xl uppercase">{{ $billId ? 'Conta fixa' : 'Nova conta fixa' }}</h2>
            @error('form') <p class="text-sm text-red-600" data-test="erro-form">{{ $message }}</p> @enderror
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="f-desc">Descrição</label>
                    <input id="f-desc" type="text" wire:model="form.description" maxlength="200" placeholder="Ex.: Aluguel do galpão" class="{{ $inputClass }}" data-test="fixa-descricao">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="f-forn">Fornecedor (quem recebe)</label>
                    <select id="f-forn" wire:model.live="form.supplier_id" class="{{ $inputClass }}" data-test="fixa-fornecedor">
                        <option value="">Escolha…</option>
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}{{ $supplier->active ? '' : ' (inativo)' }}</option>
                        @endforeach
                        <option value="novo">+ Cadastrar novo fornecedor</option>
                    </select>
                </div>
                @if ($newSupplier)
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="f-novo">Nome do novo fornecedor</label>
                        <input id="f-novo" type="text" wire:model="form.supplier_name" maxlength="120" placeholder="Ex.: Imobiliária, contador, empresa de energia" class="{{ $inputClass }}" data-test="fixa-fornecedor-novo">
                        <p class="mt-1 text-xs text-gray-500">Entra no cadastro de Fornecedores (o mesmo do A pagar e da cotação).</p>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="f-cat">Categoria</label>
                    <select id="f-cat" wire:model="form.category" class="{{ $inputClass }}" data-test="fixa-categoria">
                        @foreach (ExpenseCategory::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Folha, fixos e outros descontam na cascata da Meta do mês; impostos e sócios aparecem à parte.</p>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="f-valor">Valor mensal (R$)</label>
                    <input id="f-valor" type="text" inputmode="decimal" wire:model="form.amount" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="fixa-valor">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="f-dia">Dia do vencimento</label>
                    <input id="f-dia" type="number" min="1" max="31" inputmode="numeric" wire:model="form.due_day" class="{{ $inputClass }}" data-test="fixa-dia">
                    <p class="mt-1 text-xs text-gray-500">Dia 31 em mês mais curto vence no último dia.</p>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="f-mes">Primeira conta em</label>
                    <input id="f-mes" type="month" wire:model="form.first_month" class="{{ $inputClass }}" data-test="fixa-primeiro-mes">
                    <p class="mt-1 text-xs text-gray-500">Em branco: este mês, se o dia ainda não passou; senão o próximo.</p>
                </div>
                @if ($billId)
                    <label class="flex items-center gap-3 min-h-11 sm:pt-5"><input type="checkbox" wire:model="form.active" class="size-5 rounded border-gray-300 text-brand-600"> Ativa (gera as próximas contas)</label>
                @endif
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="f-obs">Observações</label>
                    <textarea id="f-obs" wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
            </div>
            @if ($billId)
                <p class="text-xs text-gray-500">Mudar valor, dia ou categoria vale para as próximas contas: as já geradas no A pagar não mudam.</p>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-conta-fixa"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
