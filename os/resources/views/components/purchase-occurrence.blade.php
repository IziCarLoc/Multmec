@props(['occurrence'])
@php
    // Uma ocorrência da conferência de compras: gravidade, título, valor, detalhe e (no slot) as ações.
    $o = $occurrence;
    [$chipClass, $chipLabel, $edge] = match ($o['severity']) {
        'alta' => ['bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200', 'Grave', 'border-l-stripe'],
        'media' => ['bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200', 'Conferir', 'border-l-amber-400'],
        default => ['bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200', 'Detalhe', 'border-l-gray-300 dark:border-l-gray-600'],
    };
@endphp
<div {{ $attributes->merge(['class' => 'p-3 border-l-4 '.$edge.' space-y-1']) }} data-test="ocorrencia-{{ $o['type'] }}">
    <div class="flex flex-wrap items-start gap-x-2 gap-y-1">
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $chipClass }}">{{ $chipLabel }}</span>
        <p class="font-semibold min-w-0 flex-1 break-words">{{ $o['title'] }}</p>
        @if ($o['amount_cents'] !== null && $o['amount_cents'] !== 0)
            <span class="font-display font-bold whitespace-nowrap">{{ $money($o['amount_cents']) }}</span>
        @endif
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ $o['detail'] }}</p>
    @if ($o['accepted'])
        <p class="text-xs text-gray-500"><i class="fas fa-check mr-1"></i>Conferido{{ $o['accepted']['by'] ? ' por '.$o['accepted']['by'] : '' }} em {{ $o['accepted']['at']?->format('d/m/Y H:i') }}: {{ $o['accepted']['reason'] }}</p>
    @elseif ($o['acceptance_expired'])
        <p class="text-xs text-red-700 dark:text-red-300"><i class="fas fa-rotate-left mr-1"></i>Foi aceita antes ("{{ $o['acceptance_expired']['reason'] }}"), mas o fato mudou: confira de novo.</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="flex flex-wrap gap-2 pt-1">{{ $slot }}</div>
    @endif
</div>
