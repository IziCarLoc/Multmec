<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\GroupCompany;
use App\Models\GroupSettlement;
use App\Models\GroupSettlementReturn;
use App\Services\Purchases\GroupCompanies;
use App\Services\Purchases\PurchaseSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Empresas do grupo e o acerto entre elas (doc 09 §11), só do gestor: nota ou boleto no CNPJ de outra empresa do grupo
 * não é golpe, mas também não é despesa da oficina. O que a oficina pagou por ela (boleto ou peça entregue) fica a
 * receber; a devolução é registrada aqui, parcial ou total.
 */
new #[Title('Empresas do grupo')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    /** @var array<string, string> */
    public array $company = ['name' => '', 'cnpj' => '', 'role' => 'locadora'];

    public bool $addingCompany = false;

    public ?int $editingCompany = null;

    /** @var array<string, mixed> */
    public array $companyEdit = [];

    public bool $addingSettlement = false;

    /** @var array<string, mixed> */
    public array $settlement = [];

    public ?int $returningId = null;

    /** @var array<string, string> */
    public array $return = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->isGestor(), 403);
    }

    #[Computed]
    public function overview(): array
    {
        return app(GroupCompanies::class)->overview();
    }

    public function saveCompany(GroupCompanies $service): void
    {
        $this->resetErrorBag();

        if ($this->attempt(fn () => $service->create($this->company, auth()->user()), 'company')) {
            $this->company = ['name' => '', 'cnpj' => '', 'role' => 'locadora'];
            $this->addingCompany = false;
            unset($this->overview);
            $this->dispatch('toast', message: 'Empresa cadastrada. As notas e boletos em aberto no CNPJ dela foram revistos.', type: 'success');
        }
    }

    public function editCompany(int $id): void
    {
        $company = GroupCompany::findOrFail($id);
        $this->editingCompany = $id;
        $this->companyEdit = ['name' => $company->name, 'role' => $company->role, 'active' => $company->active];
        $this->resetErrorBag();
    }

    public function updateCompany(GroupCompanies $service): void
    {
        $this->resetErrorBag();
        $company = GroupCompany::findOrFail($this->editingCompany);

        if ($this->attempt(fn () => $service->update($company, [...$this->companyEdit, 'active' => (bool) ($this->companyEdit['active'] ?? false)], auth()->user()), 'companyEdit')) {
            $this->editingCompany = null;
            unset($this->overview);
            $this->dispatch('toast', message: 'Empresa alterada.', type: 'success');
        }
    }

    public function startSettlement(?int $companyId = null): void
    {
        $this->resetErrorBag();
        $this->settlement = ['group_company_id' => (string) ($companyId ?? ''), 'direction' => GroupSettlement::RECEIVE, 'amount' => '', 'occurred_on' => PurchaseSettings::today(), 'description' => '', 'cash' => false, 'notes' => ''];
        $this->addingSettlement = true;
    }

    public function saveSettlement(GroupCompanies $service): void
    {
        $this->resetErrorBag();

        if ($this->attempt(fn () => $service->createManual([...$this->settlement, 'cash' => (bool) ($this->settlement['cash'] ?? false)], auth()->user()), 'settlement')) {
            $this->addingSettlement = false;
            unset($this->overview);
            $this->dispatch('toast', message: 'Acerto lançado.', type: 'success');
        }
    }

    public function deleteSettlement(int $id, GroupCompanies $service): void
    {
        if ($this->attempt(fn () => $service->deleteManual(GroupSettlement::findOrFail($id), auth()->user()))) {
            unset($this->overview);
            $this->dispatch('toast', message: 'Acerto apagado.', type: 'success');
        }
    }

    public function startReturn(int $id): void
    {
        $settlement = GroupSettlement::with('returns')->findOrFail($id);
        $this->returningId = $id;
        $this->return = ['amount' => number_format($settlement->balanceCents() / 100, 2, ',', '.'), 'returned_on' => PurchaseSettings::today(), 'notes' => ''];
        $this->resetErrorBag();
    }

    public function saveReturn(GroupCompanies $service): void
    {
        $this->resetErrorBag();

        if ($this->attempt(fn () => $service->addReturn(GroupSettlement::findOrFail($this->returningId), $this->return, auth()->user()), 'return')) {
            $this->returningId = null;
            unset($this->overview);
            $this->dispatch('toast', message: 'Devolução registrada.', type: 'success');
        }
    }

    public function deleteReturn(int $id, GroupCompanies $service): void
    {
        if ($this->attempt(fn () => $service->deleteReturn(GroupSettlementReturn::findOrFail($id), auth()->user()))) {
            unset($this->overview);
            $this->dispatch('toast', message: 'Devolução desfeita.', type: 'success');
        }
    }
};
?>

@php
    $o = $this->overview;
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $sources = ['boleto' => 'boleto pago pela oficina', 'peca' => 'peça entregue', 'manual' => 'lançado à mão'];
@endphp
<div class="space-y-4">
    <x-finance-nav />
    <x-purchases-nav />

    <div class="flex flex-wrap items-center gap-2">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2 mr-auto">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Empresas do grupo
        </h1>
        <button type="button" wire:click="$set('addingCompany', true)" class="{{ $btnSecondary }}" data-test="nova-empresa"><i class="fas fa-plus"></i> Empresa</button>
        @if ($o['companies'])
            <button type="button" wire:click="startSettlement" class="{{ $btnPrimary }}" data-test="novo-acerto"><i class="fas fa-plus"></i> Acerto</button>
        @endif
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Cadastre aqui só as OUTRAS empresas do grupo (o CNPJ da oficina fica em Ajustes > Oficina e numeração). Nota ou boleto no CNPJ delas sai do A pagar da oficina. Estes valores ficam nesta tela: não entram no saldo do Painel.</p>

    <div class="grid grid-cols-2 lg:grid-cols-3 gap-2">
        <x-finance-tile label="A receber das empresas" :value="$money($o['receivable'])" />
        <x-finance-tile label="A devolver às empresas" :value="$money($o['payable'])" />
        <x-finance-tile label="Atrasado" :value="$money($o['late'])" :note="'mais de '.$o['deadline_days'].' dias'" :tone="$o['late'] ? 'bad' : 'plain'" data-test="cartao-atrasado" />
    </div>

    @if ($addingCompany)
        <section class="{{ $cardClass }} p-3 space-y-2" data-test="form-empresa">
            <h2 class="font-display font-bold uppercase">Nova empresa do grupo</h2>
            <div class="grid gap-2 sm:grid-cols-3">
                <input type="text" wire:model="company.name" maxlength="80" placeholder="Nome" class="{{ $inputClass }}" aria-label="Nome" data-test="empresa-nome">
                <x-document-input id="empresa-cnpj" model="company.cnpj" letters placeholder="CNPJ" />
                <select wire:model="company.role" class="{{ $inputClass }}" aria-label="Papel">
                    @foreach (GroupCompany::ROLES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @error('company') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="$set('addingCompany', false)" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="button" wire:click="saveCompany" class="{{ $btnPrimary }}" data-test="salvar-empresa">Cadastrar</button>
            </div>
        </section>
    @endif

    @if ($addingSettlement)
        <section class="{{ $cardClass }} p-3 space-y-2" data-test="form-acerto">
            <h2 class="font-display font-bold uppercase">Acerto lançado à mão</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <select wire:model="settlement.group_company_id" class="{{ $inputClass }}" aria-label="Empresa" data-test="acerto-empresa">
                    <option value="">Empresa…</option>
                    @foreach ($o['companies'] as $row)
                        @if ($row['company']->active)
                            <option value="{{ $row['company']->id }}">{{ $row['company']->name }}</option>
                        @endif
                    @endforeach
                </select>
                <select wire:model.live="settlement.direction" class="{{ $inputClass }}" aria-label="Sentido">
                    <option value="{{ GroupSettlement::RECEIVE }}">A oficina tem a receber</option>
                    <option value="{{ GroupSettlement::PAY }}">A oficina tem a devolver</option>
                </select>
                <input type="text" inputmode="decimal" wire:model="settlement.amount" placeholder="Valor (R$)" class="{{ $inputClass }}" aria-label="Valor" data-test="acerto-valor">
                <input type="date" wire:model="settlement.occurred_on" class="{{ $inputClass }}" aria-label="Data">
                <input type="text" wire:model="settlement.description" maxlength="160" placeholder="Descrição" class="{{ $inputClass }} sm:col-span-2" aria-label="Descrição" data-test="acerto-descricao">
                @if (($settlement['direction'] ?? '') === GroupSettlement::RECEIVE)
                    <label class="flex items-center gap-2 text-sm min-h-11 sm:col-span-2"><input type="checkbox" wire:model="settlement.cash"> Saiu dinheiro da conta da oficina nesse dia</label>
                @endif
            </div>
            @error('settlement') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="$set('addingSettlement', false)" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="button" wire:click="saveSettlement" class="{{ $btnPrimary }}" data-test="salvar-acerto">Lançar</button>
            </div>
        </section>
    @endif

    @forelse ($o['companies'] as $row)
        @php($company = $row['company'])
        <section class="{{ $cardClass }} p-3 space-y-2" wire:key="emp-{{ $company->id }}" data-test="empresa-{{ $company->id }}">
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <h2 class="font-display font-bold uppercase break-words">{{ $company->name }} @if (! $company->active) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">inativa</span> @endif</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $company->roleLabel() }} · CNPJ {{ $company->cnpjFormatted() }}</p>
                </div>
                <p class="text-right text-sm">
                    @if ($row['receivable']) <span class="block">a receber <b>{{ $money($row['receivable']) }}</b></span> @endif
                    @if ($row['payable']) <span class="block">a devolver <b>{{ $money($row['payable']) }}</b></span> @endif
                </p>
                <button type="button" wire:click="editCompany({{ $company->id }})" class="{{ $btnSecondary }} !min-h-9 text-sm">Alterar</button>
            </div>

            @if ($editingCompany === $company->id)
                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2 space-y-2">
                    <div class="grid gap-2 sm:grid-cols-3">
                        <input type="text" wire:model="companyEdit.name" maxlength="80" class="{{ $inputClass }}" aria-label="Nome">
                        <select wire:model="companyEdit.role" class="{{ $inputClass }}" aria-label="Papel">
                            @foreach (GroupCompany::ROLES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-2 text-sm min-h-11"><input type="checkbox" wire:model="companyEdit.active"> Ativa</label>
                    </div>
                    @error('companyEdit') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="$set('editingCompany', null)" class="{{ $btnSecondary }}">Cancelar</button>
                        <button type="button" wire:click="updateCompany" class="{{ $btnPrimary }}">Gravar</button>
                    </div>
                </div>
            @endif

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($row['settlements'] as $s)
                    <div class="py-2 space-y-1" wire:key="ac-{{ $s->id }}" data-test="acerto-{{ $s->id }}">
                        <div class="flex flex-wrap items-start gap-x-2">
                            <p class="min-w-0 flex-1 break-words text-sm">
                                <span class="font-semibold">{{ $s->description }}</span>
                                <span class="text-gray-600 dark:text-gray-300">· {{ $s->occurred_on->format('d/m/Y') }} · {{ $sources[$s->source] ?? $s->source }} · {{ $s->direction === GroupSettlement::RECEIVE ? 'a receber' : 'a devolver' }}</span>
                                @if ($s->late) <span class="{{ $pill }} bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200">atrasado ({{ $s->days }} dias)</span> @endif
                            </p>
                            <p class="text-sm whitespace-nowrap">{{ $money($s->amount_cents) }}{{ $s->balance_cents !== $s->amount_cents ? ' · falta '.$money($s->balance_cents) : '' }}</p>
                        </div>
                        @foreach ($s->returns as $r)
                            <p class="text-xs text-gray-600 dark:text-gray-300 flex flex-wrap items-center gap-2" wire:key="dv-{{ $r->id }}">
                                <i class="fas fa-rotate-left"></i>devolvido {{ $money($r->amount_cents) }} em {{ $r->returned_on->format('d/m/Y') }}{{ $r->notes ? ' · '.$r->notes : '' }}
                                <button type="button" wire:click="deleteReturn({{ $r->id }})" wire:confirm="Desfazer esta devolução?" class="underline min-h-9">desfazer</button>
                            </p>
                        @endforeach
                        <div class="flex flex-wrap gap-2">
                            @if ($s->balance_cents > 0 && $returningId !== $s->id)
                                <button type="button" wire:click="startReturn({{ $s->id }})" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="devolver-{{ $s->id }}">{{ $s->direction === GroupSettlement::RECEIVE ? 'Registrar devolução' : 'Registrar que a oficina devolveu' }}</button>
                            @endif
                            @if ($s->source === 'manual' && $s->returns->isEmpty())
                                <button type="button" wire:click="deleteSettlement({{ $s->id }})" wire:confirm="Apagar este acerto?" class="{{ $btnSecondary }} !min-h-9 text-sm">Apagar</button>
                            @endif
                        </div>
                        @if ($returningId === $s->id)
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2 space-y-2">
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <input type="text" inputmode="decimal" wire:model="return.amount" class="{{ $inputClass }}" aria-label="Valor devolvido" data-test="devolucao-valor">
                                    <input type="date" wire:model="return.returned_on" class="{{ $inputClass }}" aria-label="Data da devolução">
                                    <input type="text" wire:model="return.notes" maxlength="200" placeholder="Observação" class="{{ $inputClass }}">
                                </div>
                                @error('return') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="$set('returningId', null)" class="{{ $btnSecondary }}">Cancelar</button>
                                    <button type="button" wire:click="saveReturn" class="{{ $btnPrimary }}" data-test="salvar-devolucao">Registrar</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="py-2 text-sm text-gray-500">Nada em aberto com esta empresa.</p>
                @endforelse
            </div>
        </section>
    @empty
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">Nenhuma empresa do grupo cadastrada.</p>
    @endforelse
</div>
