<?php

use App\Enums\DueWindow;
use App\Enums\PaymentMethod;
use App\Enums\ReceivableStatus;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\NavigatesMonths;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Vehicle;
use App\Services\Finance\Credit;
use App\Services\Finance\FinanceSummary;
use App\Services\Finance\Installments;
use App\Services\Finance\Month;
use App\Services\Finance\Profit;
use App\Services\Finance\Receivables;
use App\Support\LicensePlate;
use App\Support\Money;
use App\Support\Phone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * A receber: o que os clientes devem pelos serviços. Lançamento com parcelas (total dividido em centavos exatos),
 * pagamentos parciais com data, "marcar como recebido" e baixa como prejuízo do vencido.
 */
new #[Title('A receber')] class extends Component
{
    use FinanceAccess;
    use NavigatesMonths;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** aberto (inclui parcial), recebido, prejuizo ou todos. */
    #[Url(as: 'situacao', except: 'aberto')]
    public string $status = 'aberto';

    /** Janela de vencimento dos cartões (DueWindow); com ela a lista mostra todos os meses. */
    #[Url(as: 'prazo', except: '')]
    public string $window = '';

    #[Url(as: 'cliente', except: '')]
    public string $customerFilter = '';

    /** Abre a janela de um lançamento (link do Painel ou dos Inadimplentes). */
    #[Url(as: 'abrir', except: '')]
    public string $openId = '';

    public bool $editing = false;

    public ?int $receivableId = null;

    /** @var array<string, mixed> campos do formulário; dinheiro como texto digitado ("1.234,56") */
    public array $form = [];

    public bool $installments = false;

    public int $installmentCount = 2;

    /** @var list<string> vencimento de cada parcela */
    public array $dueDates = [];

    public string $customerSearch = '';

    public bool $newCustomer = false;

    public string $paymentDate = '';

    public string $paymentAmount = '';

    /** Confirmação dentro da janela: 'baixa' (prejuízo) ou 'excluir'. */
    public string $confirm = '';

    /** Gestor: motivo para liberar a prazo cliente travado, sem crédito ou acima do limite. */
    public string $creditRelease = '';

    public function mount(): void
    {
        if (ctype_digit($this->openId) && Receivable::query()->whereKey((int) $this->openId)->exists()) {
            $this->edit((int) $this->openId);
        }

        $this->openId = '';
    }

    protected function monthChanged(): void
    {
        unset($this->receivables);
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['aberto', 'recebido', 'prejuizo', 'todos'], true) ? $status : 'aberto';
        $this->window = '';
    }

    /** Cartão tocado: filtra a janela (tocar de novo, ou no "A receber", tira o filtro). */
    public function setWindow(string $window): void
    {
        $this->window = $this->window === $window ? '' : (DueWindow::tryFrom($window)?->value ?? '');
        $this->status = 'aberto';
    }

    public function clearCustomerFilter(): void
    {
        $this->customerFilter = '';
    }

    #[Computed]
    public function deadlines(): array
    {
        return app(FinanceSummary::class)->receivableDeadlines();
    }

    #[Computed]
    public function openTotal(): int
    {
        return (int) Receivable::query()->open()->counted()->sum(\Illuminate\Support\Facades\DB::raw('total_cents - paid_cents'));
    }

    #[Computed]
    public function filteredCustomer(): ?Customer
    {
        return ctype_digit($this->customerFilter) ? Customer::find((int) $this->customerFilter) : null;
    }

    #[Computed]
    public function receivables()
    {
        $term = trim($this->search);
        $window = DueWindow::tryFrom($this->window);
        $query = Receivable::query()->with('customer');

        if ($term !== '') {
            $plate = LicensePlate::clean($term);
            $query->where(function ($q) use ($term, $plate) {
                $q->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('notes', 'like', '%'.$term.'%')
                    ->orWhere('vehicle_model', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', fn ($c) => $c->search($term));

                if (strlen($plate) >= 3) {
                    $q->orWhere('plate', 'like', '%'.$plate.'%');
                }
            });
        }

        if ($this->filteredCustomer) {
            $query->where('customer_id', $this->filteredCustomer->id);
        }

        if ($window) {
            // Cartão: tudo o que está em aberto naquela janela, de qualquer mês (sem o recebimento pela matriz).
            $window->constrain($query->open()->counted());
        } else {
            match ($this->status) {
                'recebido' => $query->received(),
                'prejuizo' => $query->writtenOff(),
                'todos' => null,
                default => $query->open(),
            };

            if (! $this->allMonths()) {
                // Mês de referência: o do recebimento para o que foi recebido; o do vencimento para o resto.
                [$start, $end] = Month::bounds($this->selectedMonth());
                $query->where(function ($q) use ($start, $end) {
                    $q->where(fn ($r) => $r->received()->whereBetween('received_on', [$start, $end]))
                        ->orWhere(fn ($o) => $o->where(fn ($x) => $x->whereNotNull('written_off_at')->orWhereColumn('paid_cents', '<', 'total_cents'))->whereBetween('due_date', [$start, $end]));
                });
            }
        }

        return $this->status === 'recebido' && ! $window
            ? $query->orderByDesc('received_on')->orderByDesc('id')->limit(500)->get()
            : $query->orderBy('due_date')->orderBy('id')->limit(500)->get();
    }

    /** Grupos da lista: mês do vencimento (em aberto), "Recebidos · mês" e "Baixados · mês". */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ($this->receivables as $receivable) {
            $status = $receivable->status();
            $key = match ($status) {
                ReceivableStatus::Recebido => 'Recebidos · '.Month::label(Month::of($receivable->received_on)),
                ReceivableStatus::Prejuizo => 'Baixados como prejuízo · '.Month::label(Month::of($receivable->due_date)),
                default => Month::label(Month::of($receivable->due_date)),
            };
            $groups[$key] ??= ['label' => $key, 'cents' => 0, 'items' => [], 'overdue' => false];
            $groups[$key]['items'][] = $receivable;
            $groups[$key]['cents'] += $status === ReceivableStatus::Recebido || $status === ReceivableStatus::Prejuizo ? $receivable->total_cents : $receivable->balanceCents();
            $groups[$key]['overdue'] = $groups[$key]['overdue'] || $receivable->isOverdue();
        }

        return array_values($groups);
    }

    #[Computed]
    public function current(): ?Receivable
    {
        return $this->receivableId ? Receivable::with(['payments', 'customer', 'loss'])->find($this->receivableId) : null;
    }

    #[Computed]
    public function customerResults()
    {
        $term = trim($this->customerSearch);

        return mb_strlen($term) < 2 ? collect() : Customer::query()->search($term)->orderBy('name')->limit(6)->get();
    }

    #[Computed]
    public function selectedCustomer(): ?Customer
    {
        return filled($this->form['customer_id'] ?? null) ? Customer::with('vehicles')->find((int) $this->form['customer_id']) : null;
    }

    /** Valor de cada parcela, como vai ser lançado (centavos exatos). */
    #[Computed]
    public function installmentValues(): array
    {
        $total = Money::parseBr((string) ($this->form['total'] ?? '')) ?? 0;

        return $total > 0 && $this->installmentCount >= 2 && $this->installmentCount <= Installments::MAX ? Installments::split($total, $this->installmentCount) : [];
    }

    /** Lucro da peça e lucro bruto do formulário: só para o gestor (o atendimento nunca recebe esse número). */
    #[Computed]
    public function profitPreview(): ?array
    {
        if (! $this->canSeeProfit()) {
            return null;
        }

        $cents = fn (string $field) => Money::parseBr((string) ($this->form[$field] ?? '')) ?? 0;
        $parts = ($this->form['customer_brought_part'] ?? false) ? 0 : $cents('parts_cost');
        $profit = app(Profit::class);

        return [
            'part' => $profit->part($cents('total'), $cents('labor'), $parts, $cents('freight')),
            'gross' => $profit->gross($cents('total'), $parts, $cents('freight'), $this->current ? $profit->providerCost($this->current) : 0),
        ];
    }

    /**
     * A trava do crédito para o lançamento como está no formulário (nulo = pode). A regra que vale é a do serviço
     * (Receivables::create/update); aqui é só o aviso, com o campo de liberação para o gestor.
     */
    #[Computed]
    public function creditWarning(): ?string
    {
        if (! $this->editing || ($this->form['received_by_matrix'] ?? false)) {
            return null;
        }

        $total = max(0, Money::parseBr((string) ($this->form['total'] ?? '')) ?? 0);
        $customer = $this->newCustomer ? new Customer(['name' => trim((string) ($this->form['customer_name'] ?? '')) ?: 'O cliente novo']) : $this->selectedCustomer;
        $current = $this->current;

        if ($current) {
            [$onCredit, $newCents] = Credit::onCreditEdit($current, $this->newCustomer ? null : $this->selectedCustomer?->id, (string) ($this->form['due_date'] ?? ''), $total, false);

            return app(Credit::class)->warning($customer, $newCents, $onCredit);
        }

        $dates = $this->installments ? $this->dueDates : [(string) ($this->form['due_date'] ?? '')];

        return app(Credit::class)->warning($customer, $total, Credit::isOnCredit($dates, ! $this->installments && (bool) ($this->form['received_now'] ?? false)));
    }

    public function create(): void
    {
        $this->resetForm();
        $this->form = [
            'order_number' => '', 'customer_id' => null, 'customer_name' => '', 'customer_whatsapp' => '',
            'service_date' => now()->toDateString(), 'plate' => '', 'vehicle_model' => '',
            'customer_brought_part' => false, 'received_by_matrix' => false,
            'parts_cost' => '', 'labor' => '', 'freight' => '', 'total' => '',
            'payment_method' => PaymentMethod::Pix->value, 'due_date' => now()->toDateString(),
            'received_now' => false, 'received_on' => now()->toDateString(), 'notes' => '',
        ];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $receivable = Receivable::with('customer')->findOrFail($id);
        $this->resetForm();
        $this->receivableId = $receivable->id;
        $format = fn (int $cents) => $cents > 0 ? number_format($cents / 100, 2, ',', '.') : '';
        $this->form = [
            'order_number' => (string) $receivable->order_number,
            'customer_id' => $receivable->customer_id,
            'customer_name' => '', 'customer_whatsapp' => '',
            'service_date' => $receivable->service_date?->toDateString() ?? '',
            'plate' => (string) $receivable->plate,
            'vehicle_model' => (string) $receivable->vehicle_model,
            'customer_brought_part' => $receivable->customer_brought_part,
            'received_by_matrix' => $receivable->received_by_matrix,
            'parts_cost' => $format($receivable->parts_cost_cents),
            'labor' => $format($receivable->labor_cents),
            'freight' => $format($receivable->freight_cents),
            'total' => $format($receivable->total_cents),
            'payment_method' => $receivable->payment_method?->value ?? '',
            'due_date' => $receivable->due_date->toDateString(),
            'notes' => (string) $receivable->notes,
        ];
        $this->paymentAmount = $format($receivable->balanceCents());
        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
        $this->confirm = '';
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

    public function pickCustomer(int $id): void
    {
        $customer = Customer::with('vehicles')->findOrFail($id);
        $this->form['customer_id'] = $customer->id;
        $this->newCustomer = false;
        $this->customerSearch = '';

        // Cliente com um carro só: a placa e o modelo já vêm.
        if (blank($this->form['plate'] ?? null) && $customer->vehicles->count() === 1) {
            $this->pickVehicle($customer->vehicles->first()->id);
        }
    }

    public function pickVehicle(int $id): void
    {
        $vehicle = Vehicle::findOrFail($id);
        $this->form['plate'] = $vehicle->plate;
        $this->form['vehicle_model'] = (string) $vehicle->brand_model;
    }

    public function clearCustomer(): void
    {
        $this->form['customer_id'] = null;
    }

    /** Placa digitada que já é de um carro do cadastro: completa o modelo (e o cliente, se faltar). */
    public function updatedFormPlate(): void
    {
        $vehicle = Vehicle::byPlate((string) ($this->form['plate'] ?? ''));

        if (! $vehicle) {
            return;
        }

        if (blank($this->form['vehicle_model'] ?? null)) {
            $this->form['vehicle_model'] = (string) $vehicle->brand_model;
        }

        if (blank($this->form['customer_id'] ?? null) && ! $this->newCustomer && $vehicle->customer_id) {
            $this->form['customer_id'] = $vehicle->customer_id;
        }
    }

    public function save(Receivables $service): void
    {
        $this->resetErrorBag();
        $data = [
            'customer_id' => $this->newCustomer ? null : ($this->form['customer_id'] ?? null),
            'customer_name' => $this->newCustomer ? ($this->form['customer_name'] ?? '') : '',
            'customer_whatsapp' => $this->newCustomer ? ($this->form['customer_whatsapp'] ?? '') : '',
            'order_number' => $this->form['order_number'] ?? '',
            'service_date' => $this->form['service_date'] ?? '',
            'plate' => $this->form['plate'] ?? '',
            'vehicle_model' => $this->form['vehicle_model'] ?? '',
            'customer_brought_part' => (bool) ($this->form['customer_brought_part'] ?? false),
            'received_by_matrix' => (bool) ($this->form['received_by_matrix'] ?? false),
            // Texto digitado → centavos pelo Money::parseBr (dentro do serviço).
            'parts_cost_cents' => (string) ($this->form['parts_cost'] ?? ''),
            'labor_cents' => (string) ($this->form['labor'] ?? ''),
            'freight_cents' => (string) ($this->form['freight'] ?? ''),
            'total_cents' => (string) ($this->form['total'] ?? ''),
            'payment_method' => $this->form['payment_method'] ?? null,
            'notes' => $this->form['notes'] ?? '',
            'credit_release' => $this->creditRelease,
        ];
        $user = auth()->user();

        if ($this->receivableId) {
            $done = $this->attempt(fn () => $service->update(Receivable::findOrFail($this->receivableId), $data + ['due_date' => $this->form['due_date'] ?? ''], $user), 'form');
            $message = 'Lançamento atualizado.';
        } else {
            $dates = $this->installments ? $this->dueDates : [$this->form['due_date'] ?? ''];

            if ($this->installments && count(array_filter($dates)) < $this->installmentCount) {
                $this->addError('form', 'Informe o vencimento de todas as parcelas.');

                return;
            }

            $data['received_on'] = ! $this->installments && ($this->form['received_now'] ?? false) ? ($this->form['received_on'] ?? '') : null;
            $done = $this->attempt(fn () => $service->create($data, $dates, $user), 'form');
            $message = $this->installments ? $this->installmentCount.' parcelas lançadas.' : 'Lançamento salvo.';
        }

        if ($done) {
            $this->editing = false;
            $this->refreshLists();
            $this->dispatch('toast', message: $message, type: 'success');
        }
    }

    public function addPayment(Receivables $service): void
    {
        $this->resetErrorBag('payment');
        $amount = Money::parseBr($this->paymentAmount);

        if ($this->attempt(fn () => $service->addPayment($this->current, $this->paymentDate, $amount, auth()->user()), 'payment')) {
            $this->afterPaymentChange('Pagamento registrado.');
        }
    }

    public function receiveRemaining(Receivables $service): void
    {
        $this->resetErrorBag('payment');
        if ($this->attempt(fn () => $service->receiveRemaining($this->current, $this->paymentDate ?: now()->toDateString(), auth()->user()), 'payment')) {
            $this->afterPaymentChange('Recebido.');
        }
    }

    public function removePayment(int $paymentId, Receivables $service): void
    {
        $payment = ReceivablePayment::query()->where('receivable_id', $this->receivableId)->findOrFail($paymentId);

        if ($this->attempt(fn () => $service->removePayment($payment, auth()->user()))) {
            $this->afterPaymentChange('Pagamento removido.');
        }
    }

    public function writeOff(Receivables $service): void
    {
        if ($this->attempt(fn () => $service->writeOff($this->current, auth()->user()))) {
            $this->editing = false;
            $this->confirm = '';
            $this->refreshLists();
            $this->dispatch('toast', message: 'Baixa lançada como prejuízo.', type: 'success');
        }
    }

    public function delete(Receivables $service): void
    {
        if ($this->attempt(fn () => $service->delete($this->current, auth()->user()))) {
            $this->editing = false;
            $this->confirm = '';
            $this->refreshLists();
            $this->dispatch('toast', message: 'Lançamento excluído.', type: 'success');
        }
    }

    private function afterPaymentChange(string $message): void
    {
        unset($this->current);
        $balance = $this->current?->balanceCents() ?? 0;
        $this->paymentAmount = $balance > 0 ? number_format($balance / 100, 2, ',', '.') : '';
        $this->refreshLists();
        $this->dispatch('toast', message: $message, type: 'success');
    }

    private function refreshLists(): void
    {
        unset($this->receivables, $this->groups, $this->deadlines, $this->openTotal, $this->current);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->receivableId = null;
        $this->installments = false;
        $this->installmentCount = 2;
        $this->dueDates = [];
        $this->customerSearch = '';
        $this->newCustomer = false;
        $this->paymentDate = now()->toDateString();
        $this->paymentAmount = '';
        $this->confirm = '';
        $this->creditRelease = '';
        unset($this->current);
    }
};
?>

@php
    $showProfit = auth()->user()->can('gestor');
    $profit = app(\App\Services\Finance\Profit::class);
    $providerCosts = $showProfit ? $profit->providerCosts($this->receivables) : [];
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>A receber
        </h1>
        <button type="button" wire:click="create" class="{{ $btnPrimary }} ml-auto" data-test="novo-receber"><i class="fas fa-plus"></i> Novo lançamento</button>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
        <x-finance-tile label="A receber" :value="$money($this->openTotal)" note="em aberto, sem a matriz" wire:click="setWindow('')" :active="$window === '' && $status === 'aberto'" />
        @foreach (DueWindow::cases() as $case)
            <x-finance-tile :label="$case->label()" :value="$money($this->deadlines[$case->value]['cents'])" :note="$this->deadlines[$case->value]['count'].' lançamento(s)'" :tone="$case === DueWindow::Vencidos && $this->deadlines[$case->value]['count'] > 0 ? 'bad' : 'plain'" wire:click="setWindow('{{ $case->value }}')" :active="$window === $case->value" wire:key="tile-{{ $case->value }}" data-test="cartao-{{ $case->value }}" />
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="OS, cliente, placa, observação" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <select wire:change="setStatus($event.target.value)" class="{{ $inputClass }} sm:w-48" data-test="filtro-situacao">
            @foreach (['aberto' => 'A receber', 'recebido' => 'Recebidos', 'prejuizo' => 'Baixados como prejuízo', 'todos' => 'Todos'] as $value => $label)
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
        @if ($this->filteredCustomer)
            <span class="inline-flex items-center gap-2 min-h-11 px-3 rounded-full bg-gray-100 dark:bg-gray-700 text-sm font-semibold">
                Cliente: {{ $this->filteredCustomer->name }}
                <button type="button" wire:click="clearCustomerFilter" class="size-8 inline-flex items-center justify-center" aria-label="Tirar o filtro do cliente"><i class="fas fa-xmark"></i></button>
            </span>
        @endif
    </div>

    @forelse ($this->groups as $group)
        <section class="space-y-2" wire:key="g-{{ md5($group['label']) }}">
            <h2 @class(['flex items-baseline gap-2 font-display font-bold uppercase tracking-wide text-sm', 'text-red-700 dark:text-red-400' => $group['overdue'], 'text-gray-500 dark:text-gray-400' => ! $group['overdue']])>
                {{ $group['label'] }} <span class="ml-auto font-sans text-gray-700 dark:text-gray-200">{{ $money($group['cents']) }}</span>
            </h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($group['items'] as $receivable)
                    @php
                        $st = $receivable->status();
                        $overdue = $receivable->isOverdue();
                        $days = (int) now()->startOfDay()->diffInDays($receivable->due_date, false);
                    @endphp
                    <button type="button" wire:click="edit({{ $receivable->id }})" class="w-full text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40 {{ $overdue ? 'border-l-4 border-l-stripe' : '' }}" wire:key="r-{{ $receivable->id }}" data-test="receber-{{ $receivable->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold flex flex-wrap items-center gap-1">
                                <span class="truncate">{{ $receivable->customer?->name ?? '(sem cliente)' }}</span>
                                @if ($receivable->installmentLabel()) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">{{ $receivable->installmentLabel() }}</span> @endif
                                @if ($receivable->received_by_matrix) <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">matriz</span> @endif
                                @if ($receivable->opening_balance) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">saldo anterior</span> @endif
                                @if ($st === ReceivableStatus::Prejuizo)
                                    <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">baixado como prejuízo {{ $receivable->written_off_at->format('d/m/Y') }}</span>
                                @elseif ($st === ReceivableStatus::Recebido)
                                    <span class="{{ $pill }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200"><i class="fas fa-check mr-1"></i>recebido {{ $receivable->received_on?->format('d/m/Y') }}</span>
                                @else
                                    @if ($st === ReceivableStatus::Parcial) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">parcial · pago {{ $money($receivable->paid_cents) }}</span> @endif
                                    @if ($overdue)
                                        <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">vencido há {{ -$days }} d</span>
                                    @elseif ($days === 0)
                                        <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">vence hoje</span>
                                    @elseif ($days <= 7)
                                        <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">em {{ $days }} d</span>
                                    @endif
                                @endif
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300 break-words">
                                {{ collect([
                                    'OS '.($receivable->order_number ?: '—'),
                                    trim(($receivable->plate ? LicensePlate::clean($receivable->plate) : '').' '.$receivable->vehicle_model),
                                    $receivable->payment_method?->label(),
                                    $receivable->customer_brought_part ? 'peça do cliente' : null,
                                    $receivable->received_by_matrix ? 'não entra no Painel' : null,
                                    $st === ReceivableStatus::Parcial ? 'total '.$money($receivable->total_cents) : null,
                                    $showProfit && ! $receivable->opening_balance ? 'lucro '.$money($profit->grossFor($receivable, $providerCosts[$receivable->id] ?? 0)) : null,
                                    $receivable->notes,
                                ])->filter()->implode(' · ') }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-display font-bold text-lg">{{ $money($st->isOpen() ? $receivable->balanceCents() : $receivable->total_cents) }}</p>
                            <p class="text-xs text-gray-500">venc. {{ $receivable->due_date->format('d/m/Y') }}</p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    @empty
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">
            {{ Receivable::query()->exists() ? 'Nenhum lançamento com esse filtro.' : 'Nenhum lançamento ainda. Toque em "Novo lançamento" para registrar o primeiro.' }}
        </p>
    @endforelse

    @if ($editing)
        @php($current = $this->current)
        <x-modal close="close" form="save" size="2xl" scroll data-test="janela-receber">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase">{{ $current ? 'Lançamento' : 'Novo lançamento' }}</h2>
                @if ($current?->installmentLabel()) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">parcela {{ $current->installmentLabel() }}</span> @endif
                @if ($current) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700" data-test="situacao-lancamento">{{ $current->status()->label() }}</span> @endif
            </div>
            @if ($current?->isWrittenOff())
                <p class="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm p-3">Baixado como prejuízo em {{ $current->written_off_at->format('d/m/Y') }} ({{ $money($current->loss?->amount_cents ?? 0) }}). O lançamento fica no histórico e não muda mais.</p>
            @endif
            @error('form') <p class="text-sm text-red-600" data-test="erro-form">{{ $message }}</p> @enderror

            <fieldset @disabled($current?->isWrittenOff()) class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="r-os">Nº da OS</label>
                    <input id="r-os" type="text" wire:model="form.order_number" maxlength="20" inputmode="numeric" placeholder="Ex.: 4521" class="{{ $inputClass }}" data-test="receber-os">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="r-data">Data da manutenção</label>
                    <input id="r-data" type="date" wire:model="form.service_date" class="{{ $inputClass }}">
                </div>

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">Cliente</label>
                    @if ($this->selectedCustomer && ! $newCustomer)
                        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 p-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold truncate" data-test="cliente-escolhido">{{ $this->selectedCustomer->name }}</p>
                                <p class="text-sm text-gray-500">{{ $this->selectedCustomer->whatsappFormatted() ?: 'sem WhatsApp' }}</p>
                            </div>
                            @unless ($current?->isWrittenOff())
                                <button type="button" wire:click="clearCustomer" class="{{ $btnSecondary }}">Trocar</button>
                            @endunless
                        </div>
                        @if ($this->selectedCustomer->vehicles->count() > 1)
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($this->selectedCustomer->vehicles as $vehicle)
                                    <button type="button" wire:click="pickVehicle({{ $vehicle->id }})" class="inline-flex items-center gap-2 min-h-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 text-sm" wire:key="cv-{{ $vehicle->id }}"><x-plate :plate="$vehicle->plate" /> {{ $vehicle->brand_model }}</button>
                                @endforeach
                            </div>
                        @endif
                    @elseif ($newCustomer)
                        <div class="grid gap-2 sm:grid-cols-2 rounded-lg border border-gray-200 dark:border-gray-700 p-2">
                            <input type="text" wire:model="form.customer_name" maxlength="120" placeholder="Nome do cliente" class="{{ $inputClass }}" data-test="cliente-novo-nome">
                            <input type="tel" wire:model="form.customer_whatsapp" placeholder="WhatsApp com DDD" class="{{ $inputClass }}" data-test="cliente-novo-whatsapp">
                            <p class="sm:col-span-2 text-xs text-gray-500">O mesmo WhatsApp é o mesmo cliente: se ele já existe, o lançamento vai para o cadastro dele.</p>
                            <button type="button" wire:click="$set('newCustomer', false)" class="{{ $btnSecondary }} sm:col-span-2">Procurar no cadastro</button>
                        </div>
                    @else
                        <div class="relative">
                            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="Nome, telefone ou CPF/CNPJ" class="{{ $inputClass }} pl-9" data-test="busca-cliente">
                        </div>
                        <div class="mt-2 rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($this->customerResults as $customer)
                                <button type="button" wire:click="pickCustomer({{ $customer->id }})" class="w-full text-left min-h-11 px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="cr-{{ $customer->id }}">
                                    <span class="font-semibold">{{ $customer->name }}</span> <span class="text-sm text-gray-500">{{ $customer->whatsappFormatted() }}</span>
                                </button>
                            @endforeach
                            <button type="button" wire:click="$set('newCustomer', true)" class="w-full text-left min-h-11 px-3 py-2 text-brand-700 dark:text-brand-300 font-semibold"><i class="fas fa-plus mr-1"></i> Cadastrar novo cliente</button>
                        </div>
                    @endif
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="r-placa">Placa do veículo</label>
                    <input id="r-placa" type="text" wire:model.live.blur="form.plate" maxlength="8" placeholder="Ex.: ABC1D23" class="{{ $inputClass }} uppercase font-mono">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="r-modelo">Modelo do veículo</label>
                    <input id="r-modelo" type="text" wire:model="form.vehicle_model" maxlength="80" placeholder="Ex.: Fiat Strada" class="{{ $inputClass }}">
                </div>

                <label class="sm:col-span-2 flex items-start gap-3 min-h-11">
                    <input type="checkbox" wire:model.live="form.customer_brought_part" class="mt-1 size-5 rounded border-gray-300 text-brand-600" data-test="cliente-trouxe-peca">
                    <span>Cliente trouxe a peça <span class="text-sm text-gray-500">(sem custo de peça para a oficina)</span></span>
                </label>
                <label class="sm:col-span-2 flex items-start gap-3 min-h-11">
                    <input type="checkbox" wire:model="form.received_by_matrix" class="mt-1 size-5 rounded border-gray-300 text-brand-600" data-test="recebimento-matriz">
                    <span>Recebimento pela matriz <span class="text-sm text-gray-500">(fica registrado, mas não entra no saldo nem no "a receber" do Painel)</span></span>
                </label>

                @unless ($form['customer_brought_part'] ?? false)
                    <div>
                        <label class="{{ $labelClass }}" for="r-peca">Custo da peça (R$)</label>
                        <input id="r-peca" type="text" inputmode="decimal" wire:model.live.blur="form.parts_cost" placeholder="0,00" class="{{ $inputClass }}">
                    </div>
                @endunless
                <div>
                    <label class="{{ $labelClass }}" for="r-mo">Mão de obra (R$)</label>
                    <input id="r-mo" type="text" inputmode="decimal" wire:model.live.blur="form.labor" placeholder="0,00" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="r-frete">Frete (R$)</label>
                    <input id="r-frete" type="text" inputmode="decimal" wire:model.live.blur="form.freight" placeholder="0,00" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="r-total">Valor total a cobrar (R$)</label>
                    <input id="r-total" type="text" inputmode="decimal" wire:model.live.blur="form.total" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="receber-total">
                </div>

                @if ($this->profitPreview)
                    <div class="sm:col-span-2 grid grid-cols-2 gap-3 rounded-lg bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-3" data-test="previa-lucro">
                        <div>
                            <p class="{{ $labelClass }}">Lucro da peça</p>
                            <p class="font-display font-bold text-lg {{ $this->profitPreview['part'] < 0 ? 'text-red-700' : '' }}">{{ $money($this->profitPreview['part']) }}</p>
                        </div>
                        <div>
                            <p class="{{ $labelClass }}">Lucro bruto</p>
                            <p class="font-display font-bold text-lg {{ $this->profitPreview['gross'] < 0 ? 'text-red-700' : '' }}">{{ $money($this->profitPreview['gross']) }}</p>
                        </div>
                        <p class="col-span-2 text-xs text-gray-500">Lucro bruto = total − peças − frete − prestador. Lucro da peça = (total − mão de obra) − peças − frete.</p>
                    </div>
                @endif

                <div>
                    <label class="{{ $labelClass }}" for="r-forma">Forma de pagamento</label>
                    <select id="r-forma" wire:model="form.payment_method" class="{{ $inputClass }}">
                        <option value="">—</option>
                        @foreach (PaymentMethod::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @unless ($installments)
                    <div>
                        <label class="{{ $labelClass }}" for="r-venc">Vencimento</label>
                        <input id="r-venc" type="date" wire:model.live="form.due_date" class="{{ $inputClass }}" data-test="receber-vencimento">
                    </div>
                @endunless

                @unless ($current)
                    <label class="sm:col-span-2 flex items-center gap-3 min-h-11">
                        <input type="checkbox" wire:model.live="installments" class="size-5 rounded border-gray-300 text-brand-600" data-test="parcelas">
                        Lançar em parcelas (datas de vencimento diferentes)
                    </label>
                    @if ($installments)
                        <div class="sm:col-span-2 space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="{{ $labelClass }} mb-0" for="r-n">Número de parcelas</label>
                                <input id="r-n" type="number" min="2" max="{{ Installments::MAX }}" wire:model.live.debounce.400ms="installmentCount" class="{{ $inputClass }} w-24" data-test="numero-parcelas">
                            </div>
                            <p class="text-xs text-gray-500">O total é dividido em centavos exatos (o centavo que sobra vai nas primeiras). Cada parcela é um lançamento separado, com o vencimento que você informar.</p>
                            @for ($i = 0; $i < $installmentCount; $i++)
                                {{-- No celular: "Parcela N" e o valor numa linha, a data embaixo; no computador, tudo numa linha. --}}
                                <div class="grid grid-cols-2 sm:grid-cols-[6rem_1fr_7rem] items-center gap-x-2 gap-y-1" wire:key="due-{{ $i }}">
                                    <span class="text-sm text-gray-500">Parcela {{ $i + 1 }}</span>
                                    <span class="text-right text-sm font-semibold sm:order-last">{{ isset($this->installmentValues[$i]) ? $money($this->installmentValues[$i]) : '—' }}</span>
                                    <input type="date" wire:model.live="dueDates.{{ $i }}" class="{{ $inputClass }} col-span-2 sm:col-span-1 min-w-0" data-test="vencimento-parcela-{{ $i + 1 }}">
                                </div>
                            @endfor
                        </div>
                    @else
                        <label class="flex items-center gap-3 min-h-11">
                            <input type="checkbox" wire:model.live="form.received_now" class="size-5 rounded border-gray-300 text-brand-600"> Já recebido
                        </label>
                        @if ($form['received_now'] ?? false)
                            <div>
                                <label class="{{ $labelClass }}" for="r-rec">Data do recebimento</label>
                                <input id="r-rec" type="date" wire:model="form.received_on" class="{{ $inputClass }}">
                            </div>
                        @endif
                    @endif
                @endunless

                @if ($this->creditWarning)
                    <div class="sm:col-span-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 p-3 text-sm space-y-2" data-test="trava-credito">
                        <p class="font-semibold text-red-800 dark:text-red-200"><i class="fas fa-lock"></i> {{ $this->creditWarning }}</p>
                        @can('gestor')
                            <label class="{{ $labelClass }}" for="r-liberar">Motivo para liberar a prazo (fica na trilha de auditoria)</label>
                            <input id="r-liberar" type="text" wire:model="creditRelease" maxlength="180" placeholder="Ex.: combinou pagar na sexta" class="{{ $inputClass }}" data-test="motivo-liberacao">
                        @else
                            <p class="text-gray-700 dark:text-gray-200">Lance à vista (vencimento hoje) ou peça ao gestor para liberar em Financeiro › Crédito.</p>
                        @endcan
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="r-obs">Observações</label>
                    <textarea id="r-obs" wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
            </fieldset>

            @if ($current)
                <div class="space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3" data-test="pagamentos">
                    <p class="{{ $labelClass }}">Pagamentos recebidos (parciais ou o integral, cada um com a sua data)</p>
                    @forelse ($current->payments as $payment)
                        <div class="flex items-center gap-2 min-h-11" wire:key="p-{{ $payment->id }}">
                            <span class="text-sm">{{ $payment->paid_on->format('d/m/Y') }}</span>
                            <span class="font-semibold ml-auto">{{ $money($payment->amount_cents) }}</span>
                            @unless ($current->isWrittenOff())
                                <button type="button" wire:click="removePayment({{ $payment->id }})" wire:confirm="Remover este pagamento?" class="size-11 inline-flex items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" aria-label="Remover pagamento"><i class="fas fa-trash"></i></button>
                            @endunless
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Nenhum pagamento registrado ainda.</p>
                    @endforelse
                    <p class="text-sm" data-test="resumo-pagamentos">Pago até agora: <strong>{{ $money($current->paid_cents) }}</strong> · Saldo restante: <strong>{{ $money($current->balanceCents()) }}</strong></p>
                    @if ($current->status()->isOpen())
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="flex-1 min-w-[8rem]">
                                <label class="{{ $labelClass }}" for="pg-data">Data do pagamento</label>
                                <input id="pg-data" type="date" wire:model="paymentDate" class="{{ $inputClass }}">
                            </div>
                            <div class="flex-1 min-w-[8rem]">
                                <label class="{{ $labelClass }}" for="pg-valor">Valor pago (R$)</label>
                                <input id="pg-valor" type="text" inputmode="decimal" wire:model="paymentAmount" class="{{ $inputClass }}" data-test="valor-pagamento">
                            </div>
                            <button type="button" wire:click="addPayment" class="{{ $btnSecondary }}" data-test="registrar-pagamento"><i class="fas fa-plus"></i> Registrar pagamento</button>
                        </div>
                        @error('payment') <p class="text-sm text-red-600" data-test="erro-pagamento">{{ $message }}</p> @enderror
                    @endif
                </div>
            @endif

            @if ($confirm === 'baixa')
                <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-3 space-y-2">
                    <p class="text-sm text-red-800 dark:text-red-200">Dar baixa no saldo em aberto ({{ $money($current?->balanceCents() ?? 0) }}) e lançar como prejuízo (inadimplência)? O valor sai do "a receber" e passa a somar como prejuízo, mas o lançamento continua aqui no histórico.</p>
                    <div class="flex flex-wrap gap-2 justify-end">
                        <button type="button" wire:click="$set('confirm', '')" class="{{ $btnSecondary }}">Cancelar</button>
                        <button type="button" wire:click="writeOff" class="{{ $btnPrimary }} !bg-red-600 hover:!bg-red-700" data-test="confirmar-prejuizo">Sim, lançar prejuízo</button>
                    </div>
                </div>
            @elseif ($confirm === 'excluir')
                <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-3 space-y-2">
                    <p class="text-sm text-red-800 dark:text-red-200">Excluir este lançamento?</p>
                    <div class="flex flex-wrap gap-2 justify-end">
                        <button type="button" wire:click="$set('confirm', '')" class="{{ $btnSecondary }}">Cancelar</button>
                        <button type="button" wire:click="delete" class="{{ $btnPrimary }} !bg-red-600 hover:!bg-red-700" data-test="confirmar-exclusao">Sim, excluir</button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if ($current && ! $current->isWrittenOff() && $current->payments->isEmpty())
                    <button type="button" wire:click="$set('confirm', 'excluir')" class="{{ $btnSecondary }} sm:mr-auto text-red-700">Excluir</button>
                @endif
                @if ($current && $current->isOverdue() && ! $current->received_by_matrix)
                    <button type="button" wire:click="$set('confirm', 'baixa')" class="{{ $btnSecondary }} text-red-700" data-test="lancar-prejuizo">Lançar como prejuízo</button>
                @endif
                @if ($current && $current->status()->isOpen())
                    <button type="button" wire:click="receiveRemaining" class="{{ $btnSecondary }}" data-test="marcar-recebido"><i class="fas fa-check"></i> Marcar como recebido</button>
                @endif
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                @unless ($current?->isWrittenOff())
                    <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-receber"><i class="fas fa-check"></i> Salvar</button>
                @endunless
            </div>
        </x-modal>
    @endif
</div>
