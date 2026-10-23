@props(['month' => ''])
@php
    // Navegação por mês das listas do Financeiro (trait NavigatesMonths do componente).
    $all = $month === 'todos';
    $selected = \App\Services\Finance\Month::isValid($month) ? $month : \App\Services\Finance\Month::current();
    $isCurrent = $selected === \App\Services\Finance\Month::current();
    $small = 'inline-flex items-center justify-center min-h-11 min-w-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700';
@endphp
<div class="flex flex-wrap items-center gap-2" data-test="navegacao-mes">
    @if ($all)
        <span class="font-display font-bold text-lg uppercase tracking-wide">Todos os meses</span>
        <button type="button" wire:click="setMonth('')" class="{{ $small }}">Navegar por mês</button>
    @else
        <button type="button" wire:click="shiftMonth(-1)" class="{{ $small }}" aria-label="Mês anterior"><i class="fas fa-chevron-left"></i></button>
        <span class="font-display font-bold text-lg uppercase tracking-wide min-w-[6.5rem] text-center" data-test="mes-selecionado">{{ \App\Services\Finance\Month::label($selected) }}</span>
        <button type="button" wire:click="shiftMonth(1)" class="{{ $small }}" aria-label="Próximo mês"><i class="fas fa-chevron-right"></i></button>
        @unless ($isCurrent)
            <button type="button" wire:click="setMonth('')" class="{{ $small }}">Mês atual</button>
        @endunless
        <button type="button" wire:click="setMonth('todos')" class="{{ $small }}">Ver todos os meses</button>
    @endif
</div>
