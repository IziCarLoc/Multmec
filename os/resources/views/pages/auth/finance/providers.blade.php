<?php

use App\Enums\DueWindow;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\NavigatesMonths;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\ProviderPayment;
use App\Models\Supplier;
use App\Services\Finance\FinanceSummary;
use App\Services\Finance\Month;
use App\Services\Finance\ProviderPayments;
use App\Support\LicensePlate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Prestadores externos (o "Douglas" do artefato e os outros): pagamento por OS realizada. Entra no Painel como
 * Pago na data em que for pago.
 */
new #[Title('Prestadores')] class extends Component
{
    use FinanceAccess;
    use NavigatesMonths;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'prestador', except: '')]
    public string $providerFilter = '';

    #[Url(as: 'situacao', except: 'aberto')]
    public string $status = 'aberto';

    #[Url(as: 'prazo', except: '')]
    public string $window = '';

    public bool $editing = false;

    public ?int $paymentId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $newProvider = false;

    public bool $confirmDelete = false;

    protected function monthChanged(): void
    {
        unset($this->payments);
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
        return app(FinanceSummary::class)->providerDeadlines();
    }

    #[Computed]
    public function openTotal(): int
    {
        return (int) ProviderPayment::query()->open()->sum('amount_cents');
    }

    /** Prestadores do cadastro (e quem já tem pagamento lançado, mesmo inativo). */
    #[Computed]
    public function providers()
    {
        return Supplier::query()
            ->where(fn ($q) => $q->where(fn ($p) => $p->active()->providesService())->orWhereIn('id', ProviderPayment::query()->select('supplier_id')))
            ->orderBy('name')->get(['id', 'name', 'active', 'kind']);
    }

    #[Computed]
    public function payments()
    {
        $term = trim($this->search);
        $window = DueWindow::tryFrom($this->window);
        $query = ProviderPayment::query()->with('supplier')
            ->when($term !== '', function ($q) use ($term) {
                $plate = LicensePlate::clean($term);
                $q->where(fn ($w) => $w->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
                    ->orWhere('notes', 'like', '%'.$term.'%')
                    ->when(strlen($plate) >= 3, fn ($p) => $p->orWhere('plate', 'like', '%'.$plate.'%')));
            })
            ->when(ctype_digit($this->providerFilter), fn ($q) => $q->where('supplier_id', (int) $this->providerFilter));

        if ($window) {
            $window->constrain($query->open());
        } else {
            match ($this->status) {
                'pago' => $query->paid(),
                'todos' => null,
                default => $query->open(),
            };

            if (! $this->allMonths()) {
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

        foreach ($this->payments as $payment) {
            $key = $payment->isPaid() ? 'Pagos · '.Month::label(Month::of($payment->paid_on)) : Month::label(Month::of($payment->due_date));
            $groups[$key] ??= ['label' => $key, 'cents' => 0, 'items' => [], 'overdue' => false];
            $groups[$key]['items'][] = $payment;
            $groups[$key]['cents'] += $payment->amount_cents;
            $groups[$key]['overdue'] = $groups[$key]['overdue'] || $payment->isOverdue();
        }

        return array_values($groups);
    }

    #[Computed]
    public function current(): ?ProviderPayment
    {
        return $this->paymentId ? ProviderPayment::with('supplier')->find($this->paymentId) : null;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->form = [
            'supplier_id' => ctype_digit($this->providerFilter) ? $this->providerFilter : '', 'supplier_name' => '',
            'order_number' => '', 'description' => '', 'plate' => '', 'amount' => '',
            'due_date' => now()->toDateString(), 'paid' => false, 'paid_on' => now()->toDateString(), 'notes' => '',
        ];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $payment = ProviderPayment::findOrFail($id);
        $this->resetForm();
        $this->paymentId = $payment->id;
        $this->form = [
            'supplier_id' => (string) $payment->supplier_id, 'supplier_name' => '',
            'order_number' => (string) $payment->order_number,
            'description' => (string) $payment->description,
            'plate' => (string) $payment->plate,
            'amount' => number_format($payment->amount_cents / 100, 2, ',', '.'),
            'due_date' => $payment->due_date->toDateString(),
            'paid' => $payment->isPaid(),
            'paid_on' => $payment->paid_on?->toDateString() ?? now()->toDateString(),
            'notes' => (string) $payment->notes,
        ];
        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
    }

    public function updatedFormSupplierId(mixed $value): void
    {
        $this->newProvider = $value === 'novo';
    }

    public function save(ProviderPayments $service): void
    {
        $this->resetErrorBag();
        $data = [
            'supplier_id' => $this->newProvider || ! ctype_digit((string) ($this->form['supplier_id'] ?? '')) ? null : (int) $this->form['supplier_id'],
            'supplier_name' => $this->newProvider ? ($this->form['supplier_name'] ?? '') : '',
            'order_number' => $this->form['order_number'] ?? '',
            'description' => $this->form['description'] ?? '',
            'plate' => $this->form['plate'] ?? '',
            'amount_cents' => (string) ($this->form['amount'] ?? ''),
            'due_date' => $this->form['due_date'] ?? '',
            'paid_on' => ($this->form['paid'] ?? false) ? ($this->form['paid_on'] ?? '') : null,
            'notes' => $this->form['notes'] ?? '',
        ];
        $user = auth()->user();
        $done = $this->paymentId
            ? $this->attempt(fn () => $service->update(ProviderPayment::findOrFail($this->paymentId), $data, $user), 'form')
            : $this->attempt(fn () => $service->create($data, $user), 'form');

        if ($done) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: $this->paymentId ? 'Pagamento atualizado.' : 'Pagamento lançado.', type: 'success');
        }
    }

    public function markPaid(int $id, ProviderPayments $service): void
    {
        if ($this->attempt(fn () => $service->markPaid(ProviderPayment::findOrFail($id), now()->toDateString(), auth()->user()))) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: 'Marcado como pago.', type: 'success');
        }
    }

    public function delete(ProviderPayments $service): void
    {
        if ($this->attempt(fn () => $service->delete(ProviderPayment::findOrFail($this->paymentId), auth()->user()))) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: 'Lançamento excluído.', type: 'success');
        }
    }

    private function refreshLists(): void
    {
        unset($this->payments, $this->groups, $this->deadlines, $this->openTotal, $this->providers, $this->current);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->paymentId = null;
        $this->newProvider = false;
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
            <span class="w-1 h-6 rounded bg-brand-600"></span>Prestadores
        </h1>
        <button type="button" wire:click="create" class="{{ $btnPrimary }} ml-auto" data-test="novo-prestador"><i class="fas fa-plus"></i> Novo pagamento</button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Pagamentos a prestadores externos (como o Douglas) por OS realizada. Entram no Painel como saída (Pago) na data em que forem pagos.</p>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
        <x-finance-tile label="Em aberto" :value="$money($this->openTotal)" note="a pagar a prestadores" wire:click="setWindow('')" :active="$window === '' && $status === 'aberto'" />
        @foreach (DueWindow::cases() as $case)
            <x-finance-tile :label="$case->label()" :value="$money($this->deadlines[$case->value]['cents'])" :note="$this->deadlines[$case->value]['count'].' lançamento(s)'" :tone="$case === DueWindow::Vencidos && $this->deadlines[$case->value]['count'] > 0 ? 'bad' : 'plain'" wire:click="setWindow('{{ $case->value }}')" :active="$window === $case->value" wire:key="tile-{{ $case->value }}" />
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="OS, descrição, placa, observação" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <select wire:model.live="providerFilter" class="{{ $inputClass }} sm:w-56" data-test="filtro-prestador">
            <option value="">Todos os prestadores</option>
            @foreach ($this->providers as $provider)
                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
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
                @foreach ($group['items'] as $payment)
                    @php
                        $overdue = $payment->isOverdue();
                        $days = (int) now()->startOfDay()->diffInDays($payment->due_date, false);
                    @endphp
                    <div class="flex items-stretch {{ $overdue ? 'border-l-4 border-l-stripe' : '' }}" wire:key="d-{{ $payment->id }}">
                        <button type="button" wire:click="edit({{ $payment->id }})" class="min-w-0 flex-1 text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40" data-test="prestador-{{ $payment->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold flex flex-wrap items-center gap-1">
                                    <span class="truncate">{{ $payment->supplier?->name }}</span>
                                    <span class="text-gray-500 font-normal">· OS {{ $payment->order_number ?: '—' }}</span>
                                    @if ($payment->plate) <span class="font-mono text-sm">{{ $payment->plate }}</span> @endif
                                    @if ($payment->isPaid())
                                        <span class="{{ $pill }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200"><i class="fas fa-check mr-1"></i>pago {{ $payment->paid_on->format('d/m/Y') }}</span>
                                    @elseif ($overdue)
                                        <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">vencido há {{ -$days }} d</span>
                                    @elseif ($days === 0)
                                        <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">vence hoje</span>
                                    @endif
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ collect([$payment->description, $payment->notes])->filter()->implode(' · ') ?: '—' }}</p>
                                <p class="text-xs text-gray-500">venc. {{ $payment->due_date->format('d/m/Y') }}</p>
                            </div>
                            <p class="font-display font-bold text-lg">{{ $money($payment->amount_cents) }}</p>
                        </button>
                        @unless ($payment->isPaid())
                            <button type="button" wire:click="markPaid({{ $payment->id }})" wire:confirm="Marcar como pago hoje?" class="shrink-0 w-16 sm:w-20 flex flex-col items-center justify-center gap-1 border-l border-gray-100 dark:border-gray-700 text-brand-700 dark:text-brand-300 text-xs font-semibold hover:bg-brand-50 dark:hover:bg-brand-900/30" data-test="pagar-{{ $payment->id }}">
                                <i class="fas fa-check text-base"></i>Pagar
                            </button>
                        @endunless
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">
            {{ ProviderPayment::query()->exists() ? 'Nenhum lançamento com esse filtro.' : 'Nenhum pagamento a prestador ainda. Toque em "Novo pagamento" para registrar o primeiro.' }}
        </p>
    @endforelse

    @if ($editing)
        @php($current = $this->current)
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-prestador">
            <h2 class="font-display font-bold text-xl uppercase">{{ $current ? 'Pagamento a prestador' : 'Novo pagamento a prestador' }}</h2>
            @error('form') <p class="text-sm text-red-600" data-test="erro-form">{{ $message }}</p> @enderror
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="d-prest">Prestador</label>
                    <select id="d-prest" wire:model.live="form.supplier_id" class="{{ $inputClass }}" data-test="prestador-escolha">
                        <option value="">Escolha…</option>
                        @foreach ($this->providers as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }}{{ $provider->active ? '' : ' (inativo)' }}</option>
                        @endforeach
                        <option value="novo">+ Cadastrar novo prestador</option>
                    </select>
                </div>
                @if ($newProvider)
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="d-novo">Nome do novo prestador</label>
                        <input id="d-novo" type="text" wire:model="form.supplier_name" maxlength="120" placeholder="Ex.: Douglas" class="{{ $inputClass }}">
                        <p class="mt-1 text-xs text-gray-500">Entra em Fornecedores como prestador de serviço.</p>
                    </div>
                @endif
                <div>
                    <label class="{{ $labelClass }}" for="d-os">Nº da OS</label>
                    <input id="d-os" type="text" wire:model="form.order_number" maxlength="20" inputmode="numeric" placeholder="Ex.: 4521" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="d-placa">Placa do veículo</label>
                    <input id="d-placa" type="text" wire:model="form.plate" maxlength="8" placeholder="Ex.: ABC1D23" class="{{ $inputClass }} uppercase font-mono">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="d-desc">Serviço / descrição</label>
                    <input id="d-desc" type="text" wire:model="form.description" maxlength="200" placeholder="Ex.: Troca de embreagem" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="d-valor">Valor (R$)</label>
                    <input id="d-valor" type="text" inputmode="decimal" wire:model="form.amount" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="prestador-valor">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="d-venc">Vencimento</label>
                    <input id="d-venc" type="date" wire:model="form.due_date" class="{{ $inputClass }}">
                </div>
                <label class="flex items-center gap-3 min-h-11">
                    <input type="checkbox" wire:model.live="form.paid" class="size-5 rounded border-gray-300 text-brand-600"> Já pago
                </label>
                @if ($form['paid'] ?? false)
                    <div>
                        <label class="{{ $labelClass }}" for="d-pago">Data do pagamento</label>
                        <input id="d-pago" type="date" wire:model="form.paid_on" class="{{ $inputClass }}">
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="d-obs">Observações</label>
                    <textarea id="d-obs" wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
            </div>

            @if ($confirmDelete)
                <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-3 space-y-2">
                    <p class="text-sm text-red-800 dark:text-red-200">Excluir este lançamento?</p>
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
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-prestador"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
