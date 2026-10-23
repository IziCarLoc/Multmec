@props(['label', 'value', 'note' => null, 'tone' => 'plain', 'active' => false, 'href' => null])
@php
    // Cartão de número do Financeiro. Vermelho só para alerta (vencido, prejuízo, saldo negativo); azul é o destaque.
    $edge = match ($tone) {
        'bad' => 'border-l-stripe',
        'brand' => 'border-l-brand-600',
        default => 'border-l-gray-300 dark:border-l-gray-600',
    };
    $valueColor = $tone === 'bad' ? 'text-red-700 dark:text-red-400' : 'text-gray-900 dark:text-gray-100';
    $classes = 'block w-full text-left min-h-11 rounded-xl border border-l-4 p-3 shadow-sm bg-white dark:bg-gray-800 '.$edge.' '
        .($active ? 'border-brand-600 ring-2 ring-brand-500' : 'border-gray-200 dark:border-gray-700')
        .(($href || $attributes->has('wire:click')) ? ' hover:bg-gray-50 dark:hover:bg-gray-700/50' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->merge(['class' => $classes]) }}>
@elseif ($attributes->has('wire:click'))
    <button type="button" {{ $attributes->merge(['class' => $classes]) }} @if ($active) aria-pressed="true" @endif>
@else
    <div {{ $attributes->merge(['class' => $classes]) }}>
@endif
    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</span>
    <span class="block font-display font-bold text-xl sm:text-2xl leading-tight {{ $valueColor }}">{{ $value }}</span>
    @if ($note)
        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $note }}</span>
    @endif
@if ($href)
    </a>
@elseif ($attributes->has('wire:click'))
    </button>
@else
    </div>
@endif
