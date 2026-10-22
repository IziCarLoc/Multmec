<?php

use App\Enums\Stage;
use App\Models\ServiceOrder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Lista de OS')] class extends Component
{
    use WithPagination;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** 'abertas' (padrão), 'todas' ou o valor de uma etapa. */
    #[Url(as: 'situacao', except: 'abertas')]
    public string $filter = 'abertas';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    /** @return array<string, int> etapa => quantas OS, só as que têm alguma */
    #[Computed]
    public function counts(): array
    {
        return ServiceOrder::query()->search($this->search)
            ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage')
            ->map(fn ($total) => (int) $total)->all();
    }

    #[Computed]
    public function orders()
    {
        return ServiceOrder::query()
            ->with(['vehicle', 'customer', 'responsible'])
            ->search($this->search)
            ->when($this->filter === 'abertas', fn ($q) => $q->open())
            ->when(! in_array($this->filter, ['abertas', 'todas'], true), fn ($q) => $q->where('stage', $this->filter))
            ->orderByDesc('number')
            ->paginate(30);
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Lista de OS
        </h1>
        <div class="flex gap-2 ml-auto">
            <a href="{{ route('dashboard') }}" wire:navigate class="{{ $btnSecondary }}"><i class="fas fa-table-columns"></i> Quadro</a>
            @can('atendimento')
                <a href="{{ route('orders.create') }}" wire:navigate class="{{ $btnPrimary }}"><i class="fas fa-plus"></i> Nova OS</a>
            @endcan
        </div>
    </div>

    <div class="relative">
        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Placa, número, cliente, telefone, modelo, responsável..." class="{{ $inputClass }} pl-9" data-test="busca">
    </div>

    @php($openTotal = collect($this->counts)->only(Stage::openValues())->sum())
    <div class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4">
        <button type="button" wire:click="setFilter('abertas')" @class(['shrink-0 rounded-full px-3 min-h-9 text-sm font-semibold border', 'bg-brand-600 text-white border-brand-600' => $filter === 'abertas', 'border-gray-300 dark:border-gray-600' => $filter !== 'abertas'])>Em aberto ({{ $openTotal }})</button>
        <button type="button" wire:click="setFilter('todas')" @class(['shrink-0 rounded-full px-3 min-h-9 text-sm font-semibold border', 'bg-brand-600 text-white border-brand-600' => $filter === 'todas', 'border-gray-300 dark:border-gray-600' => $filter !== 'todas'])>Todas ({{ array_sum($this->counts) }})</button>
        @foreach (Stage::cases() as $case)
            @if (($this->counts[$case->value] ?? 0) > 0)
                <button type="button" wire:click="setFilter('{{ $case->value }}')" wire:key="f-{{ $case->value }}" @class(['shrink-0 rounded-full px-3 min-h-9 text-sm font-semibold border whitespace-nowrap', 'bg-brand-600 text-white border-brand-600' => $filter === $case->value, 'border-gray-300 dark:border-gray-600' => $filter !== $case->value])>{{ $case->label() }} ({{ $this->counts[$case->value] }})</button>
            @endif
        @endforeach
    </div>

    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->orders as $order)
            <x-order-card :order="$order" wire:key="o-{{ $order->id }}" />
        @empty
            <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300 sm:col-span-2 xl:col-span-3">Nenhuma OS encontrada.</p>
        @endforelse
    </div>

    {{ $this->orders->links() }}
</div>
