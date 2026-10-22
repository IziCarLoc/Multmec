<?php

use App\Services\Orders\Inbox;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Avisos')] class extends Component
{
    #[Computed]
    public function items()
    {
        return app(Inbox::class)->for(auth()->user());
    }
};
?>

<div class="space-y-4">
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>Avisos
    </h1>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        @if (auth()->user()->isGestor())
            OS paradas há {{ Inbox::STALE_DAYS }} dias ou mais na mesma etapa.
        @else
            O que está esperando por você, da mais antiga para a mais nova.
        @endif
    </p>

    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" data-test="avisos">
        @forelse ($this->items as $item)
            <x-order-card :order="$item['order']" :task="$item['task']" wire:key="a-{{ $item['order']->id }}" />
        @empty
            <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300 sm:col-span-2 xl:col-span-3"><i class="fas fa-mug-hot"></i> Nada esperando por você agora.</p>
        @endforelse
    </div>
</div>
