<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\Unit;
use App\Models\Vehicle;
use App\Services\Orders\OrderOpener;
use App\Support\Phone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nova OS')] class extends Component
{
    use RunsDomainActions;

    public string $plate = '';

    public string $number = '';

    public string $brandModel = '';

    public string $color = '';

    public string $year = '';

    public bool $iziFleet = false;

    /** Cliente já cadastrado (do carro conhecido ou achado pelo WhatsApp). */
    public ?int $customerId = null;

    public string $customerName = '';

    public string $customerWhatsapp = '';

    public string $complaint = '';

    public string $entryDate = '';

    public string $expectedDate = '';

    public function mount(): void
    {
        $this->entryDate = now()->toDateString();
    }

    #[Computed]
    public function unit(): Unit
    {
        return Unit::default();
    }

    #[Computed]
    public function known(): ?Vehicle
    {
        return Vehicle::isValidPlate($this->plate) ? Vehicle::byPlate($this->plate)?->load('customer') : null;
    }

    #[Computed]
    public function lastOrder(): ?ServiceOrder
    {
        return $this->known ? ServiceOrder::query()->where('vehicle_id', $this->known->id)->orderByDesc('id')->first() : null;
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    /** Placa digitada: carro conhecido traz modelo, cor, ano e o cliente da última vez. */
    public function updatedPlate(): void
    {
        $this->plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $this->plate));
        unset($this->known, $this->lastOrder);

        if ($vehicle = $this->known) {
            $this->brandModel = (string) $vehicle->brand_model;
            $this->color = (string) $vehicle->color;
            $this->year = (string) $vehicle->year;
            $this->iziFleet = $vehicle->is_izi_fleet;
            $this->customerId = $vehicle->is_izi_fleet ? null : $vehicle->customer_id;
        } else {
            $this->customerId = null;
        }
    }

    /** WhatsApp de cliente já cadastrado: usa o cadastro (o nome não precisa ser digitado igual). */
    public function updatedCustomerWhatsapp(): void
    {
        if ($this->customerId === null && Phone::isValid($this->customerWhatsapp)) {
            $existing = Customer::query()->where('whatsapp', Phone::digits($this->customerWhatsapp))->where('is_izi_fleet', false)->first();

            if ($existing) {
                $this->customerId = $existing->id;
                unset($this->customer);
            }
        }
    }

    public function changeCustomer(): void
    {
        $this->customerId = null;
        $this->customerName = '';
        $this->customerWhatsapp = '';
        unset($this->customer);
    }

    public function save(OrderOpener $opener)
    {
        $this->validate([
            'plate' => 'required',
            'number' => $this->unit->numbersAutomatically() ? 'nullable' : 'required',
            'complaint' => 'required|min:3',
            'entryDate' => 'required|date',
            'expectedDate' => 'nullable|date|after_or_equal:entryDate',
        ], [
            'plate.required' => 'Informe a placa.',
            'number.required' => 'Informe o número da OS (o mesmo do sistema atual).',
            'complaint.required' => 'Escreva a queixa do cliente.',
            'complaint.min' => 'Escreva a queixa do cliente.',
            'expectedDate.after_or_equal' => 'A previsão não pode ser antes da entrada.',
        ]);

        $order = $this->attempt(fn () => $opener->open($this->unit, [
            'number' => $this->number,
            'plate' => $this->plate,
            'brand_model' => $this->brandModel,
            'color' => $this->color,
            'year' => $this->year,
            'is_izi_fleet' => $this->iziFleet,
            'customer_id' => $this->customerId,
            'customer_name' => $this->customerName,
            'customer_whatsapp' => $this->customerWhatsapp,
            'complaint' => $this->complaint,
            'entry_date' => $this->entryDate,
            'expected_date' => $this->expectedDate ?: null,
        ], auth()->user()));

        if (! $order) {
            return null;
        }

        session()->flash('toast', ['message' => $order->label().' aberta.', 'type' => 'success']);

        return $this->redirectRoute('orders.show', $order, navigate: true);
    }
};
?>

<div class="max-w-2xl mx-auto space-y-4">
    <div class="flex items-center gap-3">
        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Quadro</a>
    </div>
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide">Nova OS</h1>

    <form wire:submit="save" class="space-y-4">
        <div class="{{ $cardClass }} p-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="plate">Placa</label>
                    <input id="plate" type="text" wire:model.live.debounce.500ms="plate" maxlength="8" autocapitalize="characters" autocomplete="off" placeholder="ABC1D23" class="{{ $inputClass }} font-mono text-xl uppercase tracking-widest" data-test="placa">
                    @error('plate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    @if ($this->unit->numbersAutomatically())
                        <p class="{{ $labelClass }}">Número</p>
                        <p class="min-h-11 flex items-center text-gray-600 dark:text-gray-300">Automático: OS {{ max((int) $this->unit->next_number, (int) \App\Models\ServiceOrder::where('unit_id', $this->unit->id)->max('number') + 1) }}</p>
                    @else
                        <label class="{{ $labelClass }}" for="number">Número da OS (sistema atual)</label>
                        <input id="number" type="text" inputmode="numeric" wire:model="number" placeholder="1190" class="{{ $inputClass }} text-xl" data-test="numero">
                        @error('number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @endif
                </div>
            </div>

            @if ($this->known)
                <div class="rounded-lg bg-brand-50 dark:bg-brand-900/30 p-3 text-sm" data-test="carro-conhecido">
                    <p class="font-semibold"><i class="fas fa-circle-check text-brand-600"></i> Carro já conhecido{{ $this->known->is_izi_fleet ? ' · frota Izi Car' : '' }}</p>
                    <p class="text-gray-600 dark:text-gray-300">
                        {{ $this->known->description() ?: 'Sem modelo cadastrado' }}
                        @if ($this->known->investor_name) · investidor {{ $this->known->investor_name }} @endif
                        @if ($this->lastOrder) · última: {{ $this->lastOrder->label() }} em {{ $this->lastOrder->entry_date?->format('d/m/Y') }} @endif
                    </p>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="{{ $labelClass }}" for="brandModel">Marca e modelo</label>
                    <input id="brandModel" type="text" wire:model="brandModel" maxlength="80" placeholder="Chevrolet Onix 1.0" class="{{ $inputClass }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="color">Cor</label>
                    <input id="color" type="text" wire:model="color" maxlength="30" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="year">Ano</label>
                    <input id="year" type="text" wire:model="year" maxlength="9" placeholder="2020/2021" class="{{ $inputClass }}">
                </div>
            </div>

            @if (! $this->known)
                <label class="flex items-center gap-3 min-h-11">
                    <input type="checkbox" wire:model.live="iziFleet" class="size-5 rounded border-gray-300 text-brand-600" data-test="frota-izi">
                    <span>Carro da frota <strong>Izi Car</strong> (o cliente é a Izi; aprovação pela alçada)</span>
                </label>
            @endif
        </div>

        <div class="{{ $cardClass }} p-4 space-y-4">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Cliente</h2>
            @if ($iziFleet)
                <p class="text-sm text-gray-600 dark:text-gray-300">Carro da frota: o cliente é a <strong>Izi Car</strong>. Acima da alçada, o gestor aprova; o orçamento vai ao investidor pelo WhatsApp.</p>
            @elseif ($this->customer)
                <div class="flex flex-wrap items-center gap-3" data-test="cliente-conhecido">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate">{{ $this->customer->name }}</p>
                        <p class="text-sm text-gray-500">{{ $this->customer->whatsappFormatted() ?: 'sem WhatsApp' }}</p>
                    </div>
                    <button type="button" wire:click="changeCustomer" class="{{ $btnSecondary }}"><i class="fas fa-user-pen"></i> Outro cliente</button>
                </div>
                @if (! \App\Support\Phone::isValid($this->customer->whatsapp))
                    <div>
                        <label class="{{ $labelClass }}" for="customerWhatsapp">WhatsApp do cliente</label>
                        <input id="customerWhatsapp" type="tel" inputmode="tel" wire:model="customerWhatsapp" placeholder="(55) 99999-9999" class="{{ $inputClass }}">
                    </div>
                @endif
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="customerName">Nome</label>
                        <input id="customerName" type="text" wire:model="customerName" maxlength="120" autocomplete="off" class="{{ $inputClass }}" data-test="cliente-nome">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="customerWhatsapp">WhatsApp (com DDD)</label>
                        <input id="customerWhatsapp" type="tel" inputmode="tel" wire:model.live.debounce.600ms="customerWhatsapp" placeholder="(55) 99999-9999" class="{{ $inputClass }}" data-test="cliente-whatsapp">
                    </div>
                </div>
                <p class="text-xs text-gray-500">É por esse WhatsApp que vão a vistoria e o orçamento. CPF/CNPJ só é pedido no aceite e na retirada.</p>
            @endif
        </div>

        <div class="{{ $cardClass }} p-4 space-y-4">
            <div>
                <label class="{{ $labelClass }}" for="complaint">Queixa (nas palavras do cliente)</label>
                <textarea id="complaint" wire:model="complaint" rows="3" class="{{ $inputClass }}" placeholder="Ex.: barulho na frente quando passa em lombada" data-test="queixa"></textarea>
                @error('complaint') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="entryDate">Entrada</label>
                    <input id="entryDate" type="date" wire:model="entryDate" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="expectedDate">Previsão de entrega</label>
                    <input id="expectedDate" type="date" wire:model="expectedDate" class="{{ $inputClass }}">
                    @error('expectedDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('dashboard') }}" wire:navigate class="{{ $btnSecondary }}">Cancelar</a>
            <button type="submit" class="{{ $btnPrimary }}" data-test="abrir-os"><i class="fas fa-check"></i> Abrir OS</button>
        </div>
    </form>
</div>
