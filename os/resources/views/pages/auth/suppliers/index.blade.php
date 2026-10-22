<?php

use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Support\Document;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Fornecedores e prestadores')] class extends Component
{
    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** '' = todos, ou o tipo. */
    #[Url(as: 'tipo', except: '')]
    public string $kind = '';

    public bool $editing = false;

    public ?int $supplierId = null;

    public string $name = '';

    public string $supplierKind = 'pecas';

    public string $whatsapp = '';

    public string $email = '';

    public string $document = '';

    public string $lines = '';

    public string $leadTime = '';

    public string $paymentTerms = '';

    public string $pixKey = '';

    public string $notes = '';

    public bool $active = true;

    #[Computed]
    public function suppliers()
    {
        $term = trim($this->search);

        return Supplier::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$term.'%')->orWhere('lines', 'like', '%'.$term.'%')))
            ->when($this->kind === 'pecas', fn ($q) => $q->sellsParts())
            ->when($this->kind === 'servico', fn ($q) => $q->providesService())
            ->orderByDesc('active')->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('supplierId', 'name', 'whatsapp', 'email', 'document', 'lines', 'leadTime', 'paymentTerms', 'pixKey', 'notes');
        $this->supplierKind = $this->kind === 'servico' ? SupplierKind::Servico->value : SupplierKind::Pecas->value;
        $this->active = true;
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $supplier = Supplier::findOrFail($id);
        $this->resetValidation();
        $this->supplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->supplierKind = $supplier->kind->value;
        $this->whatsapp = $supplier->whatsappFormatted();
        $this->email = (string) $supplier->email;
        $this->document = $supplier->document ? Document::format($supplier->document) : '';
        $this->lines = (string) $supplier->lines;
        $this->leadTime = (string) $supplier->lead_time;
        $this->paymentTerms = (string) $supplier->payment_terms;
        $this->pixKey = (string) $supplier->pix_key;
        $this->notes = (string) $supplier->notes;
        $this->active = $supplier->active;
        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:120',
            'supplierKind' => ['required', Rule::enum(SupplierKind::class)],
            'whatsapp' => ['nullable', fn ($attr, $value, $fail) => filled($value) && ! Phone::isValid($value) ? $fail('WhatsApp com DDD, por exemplo (55) 99999-9999.') : null],
            'email' => 'nullable|email|max:120',
            'document' => ['nullable', fn ($attr, $value, $fail) => filled($value) && ! Document::isValid($value) ? $fail('CPF ou CNPJ inválido.') : null],
        ], ['name.required' => 'Informe o nome.', 'email.email' => 'Esse e-mail não parece certo.']);

        $data = [
            'name' => trim($this->name),
            'kind' => $this->supplierKind,
            'whatsapp' => Phone::digits($this->whatsapp) ?: null,
            'email' => trim($this->email) ?: null,
            'document' => Document::digits($this->document) ?: null,
            'lines' => trim($this->lines) ?: null,
            'lead_time' => trim($this->leadTime) ?: null,
            'payment_terms' => trim($this->paymentTerms) ?: null,
            'pix_key' => trim($this->pixKey) ?: null,
            'notes' => trim($this->notes) ?: null,
            'active' => $this->active,
        ];

        $supplier = $this->supplierId ? tap(Supplier::findOrFail($this->supplierId))->update($data) : Supplier::create($data);
        AuditLog::record($supplier, $this->supplierId ? 'supplier.updated' : 'supplier.created');

        $this->editing = false;
        unset($this->suppliers);
        $this->dispatch('toast', message: 'Cadastro salvo.', type: 'success');
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Fornecedores
        </h1>
        <button type="button" wire:click="create" class="{{ $btnPrimary }} ml-auto" data-test="novo-fornecedor"><i class="fas fa-plus"></i> Novo</button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Quem vende peça (vai na cotação) e os prestadores externos de serviço, como o eletricista e o ar-condicionado.</p>

    <div class="flex flex-wrap gap-3">
        <div class="relative flex-1 min-w-[14rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Nome ou linha (marca, tipo de peça, especialidade)" class="{{ $inputClass }} pl-9">
        </div>
        <select wire:model.live="kind" class="{{ $inputClass }} sm:w-56">
            <option value="">Todos</option>
            <option value="pecas">Vendem peças</option>
            <option value="servico">Prestadores de serviço</option>
        </select>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
        @forelse ($this->suppliers as $supplier)
            <button type="button" wire:click="edit({{ $supplier->id }})" class="w-full text-left p-4 flex flex-wrap items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="s-{{ $supplier->id }}">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold truncate">{{ $supplier->name }} @unless ($supplier->active) <span class="text-xs text-red-600">(inativo)</span> @endunless</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $supplier->kind->label() }}{{ $supplier->lines ? ' · '.$supplier->lines : '' }}</p>
                </div>
                <span class="text-sm text-gray-500">{{ $supplier->whatsappFormatted() }}</span>
            </button>
        @empty
            <p class="p-6 text-center text-gray-600 dark:text-gray-300">Nenhum cadastro ainda.</p>
        @endforelse
    </div>

    @if ($editing)
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-fornecedor">
            <h2 class="font-display font-bold text-xl uppercase">{{ $supplierId ? 'Editar' : 'Novo' }} cadastro</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="name">Nome</label>
                    <input id="name" type="text" wire:model="name" maxlength="120" class="{{ $inputClass }}">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="supplierKind">O que faz</label>
                    <select id="supplierKind" wire:model="supplierKind" class="{{ $inputClass }}">
                        @foreach (SupplierKind::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="whatsapp">WhatsApp</label>
                    <input id="whatsapp" type="tel" wire:model="whatsapp" class="{{ $inputClass }}">
                    @error('whatsapp') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="email">E-mail</label>
                    <input id="email" type="email" wire:model="email" class="{{ $inputClass }}">
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="document">CNPJ ou CPF</label>
                    <x-document-input id="document" model="document" />
                    @error('document') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="pixKey">Chave Pix</label>
                    <input id="pixKey" type="text" wire:model="pixKey" maxlength="120" class="{{ $inputClass }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="lines">Linhas que atende / especialidade</label>
                    <input id="lines" type="text" wire:model="lines" placeholder="Ex.: suspensão e freio GM/VW; ou: elétrica e ar-condicionado" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="leadTime">Prazo típico</label>
                    <input id="leadTime" type="text" wire:model="leadTime" maxlength="60" placeholder="Ex.: mesmo dia até 15h" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="paymentTerms">Condições</label>
                    <input id="paymentTerms" type="text" wire:model="paymentTerms" maxlength="120" placeholder="Ex.: boleto 28 dias" class="{{ $inputClass }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="notes">Observações</label>
                    <textarea id="notes" wire:model="notes" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
                <label class="flex items-center gap-3 min-h-11"><input type="checkbox" wire:model="active" class="size-5 rounded border-gray-300 text-brand-600"> Ativo</label>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-fornecedor"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
