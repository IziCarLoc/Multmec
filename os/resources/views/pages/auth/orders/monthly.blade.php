<?php

use App\Services\Orders\MonthlyReport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Por mês: as OS do mês pela entrada ou pela entrega, com o valor aprovado e a nota; relatório em PDF paisagem.
 */
new #[Title('Por mês')] class extends Component
{
    #[Url(as: 'mes')]
    public string $month = '';

    #[Url(as: 'por')]
    public string $mode = 'entrada';

    public function mount(): void
    {
        $this->month = MonthlyReport::month($this->month)->format('Y-m');
        $this->mode = array_key_exists($this->mode, MonthlyReport::MODES) ? $this->mode : 'entrada';
    }

    public function shift(int $months): void
    {
        $this->month = MonthlyReport::month($this->month)->addMonths($months)->format('Y-m');
        unset($this->orders);
    }

    public function updatedMode(): void
    {
        $this->mode = array_key_exists($this->mode, MonthlyReport::MODES) ? $this->mode : 'entrada';
        unset($this->orders);
    }

    #[Computed]
    public function orders()
    {
        return app(MonthlyReport::class)->orders(MonthlyReport::month($this->month), $this->mode);
    }
};
?>

@php($current = MonthlyReport::month($month))

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide mr-auto">Por mês</h1>
        <a href="{{ route('orders.monthly.pdf', ['mes' => $month, 'por' => $mode]) }}" target="_blank" class="{{ $btnSecondary }}" data-test="relatorio-pdf"><i class="fas fa-file-pdf"></i> Relatório em PDF</a>
    </div>

    <div class="{{ $cardClass }} p-3 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="{{ $btnSecondary }}" aria-label="Mês anterior"><i class="fas fa-chevron-left"></i></button>
        <span class="font-semibold min-w-[8rem] text-center capitalize" data-test="mes">{{ $current->translatedFormat('F/Y') }}</span>
        <button type="button" wire:click="shift(1)" class="{{ $btnSecondary }}" aria-label="Próximo mês"><i class="fas fa-chevron-right"></i></button>
        <select wire:model.live="mode" class="{{ $inputClass }} sm:w-56 sm:ml-auto" aria-label="Pela data de">
            @foreach (MonthlyReport::MODES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="lista-mes">
        @forelse ($this->orders as $order)
            <a href="{{ route('orders.show', $order) }}" wire:navigate class="flex flex-wrap items-center gap-x-3 gap-y-1 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="m-{{ $order->id }}">
                <span class="font-display font-bold text-lg w-16">{{ $order->number }}</span>
                <x-plate :plate="$order->vehicle->plate" />
                <span class="flex-1 min-w-[10rem] text-sm">{{ $order->customer->name }} <span class="text-gray-500">· {{ $order->vehicle->brand_model }}</span></span>
                <x-stage-badge :stage="$order->stage" />
                <span class="text-xs text-gray-500">{{ $mode === 'entrega' ? $order->delivered_at?->format('d/m') : $order->entry_date?->format('d/m') }}{{ $order->invoice_status === 'emitida' ? ' · nota emitida' : '' }}</span>
                <span class="font-semibold w-28 text-right">{{ $money((int) $order->approved_cents) }}</span>
            </a>
        @empty
            <p class="p-4 text-sm text-gray-500">Nenhuma OS neste mês.</p>
        @endforelse
        @if ($this->orders->isNotEmpty())
            <div class="flex items-center gap-2 p-3 font-semibold">
                <span class="flex-1">{{ $this->orders->count() }} OS</span>
                <span data-test="total-mes">{{ $money((int) $this->orders->sum('approved_cents')) }}</span>
            </div>
        @endif
    </div>
</div>
