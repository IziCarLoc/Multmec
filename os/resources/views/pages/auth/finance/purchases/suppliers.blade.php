<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\BankSlip;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\Purchases\PurchaseSuppliers;
use App\Support\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Fornecedores na conferência de compras (doc 09 §6): o cadastro é o mesmo de Fornecedores; aqui o gestor marca
 * "conferi" (viu o cartão CNPJ e confirmou o telefone por um número que a oficina já tinha) e autoriza outros CNPJs a
 * receber os boletos (filial, banco, factoring). Fornecedor não conferido trava o primeiro pagamento.
 */
new #[Title('Fornecedores das compras')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** pendentes (sem conferir ou sem CNPJ) ou vazio (todos). */
    #[Url(as: 'filtro', except: '')]
    public string $filter = '';

    public ?int $editingPayees = null;

    public string $payees = '';

    #[Computed]
    public function suppliers()
    {
        $term = trim($this->search);
        $digits = preg_replace('/\D/', '', $term);

        return Supplier::query()
            ->where(fn ($q) => $q->whereHas('purchaseInvoices')->orWhereHas('bankSlips'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$term.'%')->when(strlen($digits) >= 4, fn ($d) => $d->orWhere('document', 'like', '%'.$digits.'%'))))
            ->when($this->filter === 'pendentes', fn ($q) => $q->where(fn ($w) => $w->whereNull('confirmed_at')->orWhereNull('document')))
            ->withCount(['purchaseInvoices as invoices_count' => fn ($q) => $q->where('status', PurchaseInvoice::ACTIVE)])
            ->withSum(['bankSlips as open_cents' => fn ($q) => $q->where('status', BankSlip::OPEN)], 'amount_cents')
            ->withCount(['bankSlips as paid_count' => fn ($q) => $q->where('status', BankSlip::PAID)])
            ->with('confirmer:id,name')
            ->orderByRaw('confirmed_at is null desc')->orderBy('name')->limit(300)->get();
    }

    public function confirm(int $id, bool $confirmed, PurchaseSuppliers $service): void
    {
        $supplier = Supplier::findOrFail($id);

        if ($this->attempt(fn () => $service->confirm($supplier, $confirmed, auth()->user()))) {
            unset($this->suppliers);
            $this->dispatch('toast', message: $confirmed ? 'Fornecedor conferido.' : 'Conferência desfeita.', type: 'success');
        }
    }

    public function startPayees(int $id): void
    {
        $supplier = Supplier::findOrFail($id);
        $this->editingPayees = $id;
        $this->payees = implode(', ', array_map(fn ($c) => Document::format($c), $supplier->authorizedPayees()));
        $this->resetErrorBag();
    }

    public function cancelPayees(): void
    {
        $this->editingPayees = null;
    }

    public function savePayees(PurchaseSuppliers $service): void
    {
        $this->resetErrorBag();
        $supplier = Supplier::findOrFail($this->editingPayees);

        if ($this->attempt(fn () => $service->setAuthorizedPayees($supplier, $this->payees, auth()->user()), 'payees')) {
            $this->editingPayees = null;
            unset($this->suppliers);
            $this->dispatch('toast', message: 'Recebedores autorizados gravados.', type: 'success');
        }
    }
};
?>

@php
    $gestor = auth()->user()->isGestor();
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
@endphp
<div class="space-y-4">
    <x-finance-nav />
    <x-purchases-nav />

    <div class="flex flex-wrap items-center gap-2">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2 mr-auto">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Fornecedores
        </h1>
        <a href="{{ route('suppliers') }}" wire:navigate class="{{ $btnSecondary }}"><i class="fas fa-pen"></i> Cadastro de fornecedores</a>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Antes do primeiro pagamento a um fornecedor, o gestor confere o CNPJ (cartão CNPJ) e o telefone, ligando para um número que a oficina já tinha (nunca o que veio no boleto ou no e-mail).
        CNPJ e telefone se corrigem no cadastro de fornecedores; trocar o CNPJ desfaz a conferência.
    </p>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Nome ou CNPJ" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <select wire:model.live="filter" class="{{ $inputClass }} sm:w-52" data-test="filtro-fornecedores">
            <option value="">Todos</option>
            <option value="pendentes">Falta conferir</option>
        </select>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
        @forelse ($this->suppliers as $supplier)
            <div class="p-3 space-y-2" wire:key="fo-{{ $supplier->id }}" data-test="fornecedor-{{ $supplier->id }}">
                <div class="flex flex-wrap items-start gap-x-3 gap-y-1">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold flex flex-wrap items-center gap-1">
                            <span class="break-words">{{ $supplier->name }}</span>
                            @if ($supplier->isConfirmed())
                                <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200"><i class="fas fa-check mr-1"></i>conferido</span>
                            @else
                                <span class="{{ $pill }} bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">falta conferir</span>
                            @endif
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-300 break-words">
                            {{ $supplier->document ? Document::typeLabel($supplier->document).' '.Document::format($supplier->document) : 'sem CNPJ' }}
                            · {{ $supplier->invoices_count }} nota(s) · {{ $supplier->paid_count }} boleto(s) pago(s)
                            @if ($supplier->open_cents) · em aberto {{ $money((int) $supplier->open_cents) }} @endif
                        </p>
                        @if ($supplier->isConfirmed())
                            <p class="text-xs text-gray-500">Conferido {{ $supplier->confirmer ? 'por '.$supplier->confirmer->name : '' }} em {{ $supplier->confirmed_at?->format('d/m/Y') }}</p>
                        @endif
                        @if ($supplier->authorizedPayees())
                            <p class="text-xs text-gray-600 dark:text-gray-300 break-words">Também recebem: {{ collect($supplier->authorizedPayees())->map(fn ($c) => Document::format($c))->implode(', ') }}</p>
                        @endif
                    </div>
                    @if ($gestor)
                        <div class="flex flex-wrap gap-2">
                            @if ($supplier->isConfirmed())
                                <button type="button" wire:click="confirm({{ $supplier->id }}, false)" wire:confirm="Desfazer a conferência deste fornecedor?" class="{{ $btnSecondary }} !min-h-9 text-sm">Desfazer</button>
                            @else
                                <button type="button" wire:click="confirm({{ $supplier->id }}, true)" wire:confirm="Você viu o cartão CNPJ e confirmou o telefone por um número que a oficina já tinha?" class="{{ $btnPrimary }} !min-h-9 text-sm" data-test="conferir-{{ $supplier->id }}"><i class="fas fa-check"></i> Conferi</button>
                            @endif
                            <button type="button" wire:click="startPayees({{ $supplier->id }})" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="recebedores-{{ $supplier->id }}">Outros recebedores</button>
                        </div>
                    @endif
                </div>
                @if ($editingPayees === $supplier->id)
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2 space-y-2">
                        <label class="{{ $labelClass }}" for="payees-{{ $supplier->id }}">CNPJs que também podem receber os boletos (separados por vírgula). Só depois de confirmar por telefone.</label>
                        <textarea id="payees-{{ $supplier->id }}" wire:model="payees" rows="2" class="{{ $inputClass }}" data-test="campo-recebedores"></textarea>
                        @error('payees') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" wire:click="cancelPayees" class="{{ $btnSecondary }}">Cancelar</button>
                            <button type="button" wire:click="savePayees" class="{{ $btnPrimary }}" data-test="salvar-recebedores">Gravar</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="p-6 text-center text-gray-600 dark:text-gray-300">Nenhum fornecedor com nota ou boleto{{ $filter === 'pendentes' ? ' faltando conferir' : '' }}.</p>
        @endforelse
    </div>
</div>
