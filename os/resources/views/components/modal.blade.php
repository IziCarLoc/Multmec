@props([
    'close',
    'size' => 'md',
    'z' => '60',
    'form' => null,
    'top' => false,
    'scroll' => false,
    'padded' => true,
    'padding' => 'normal',
    'boxClass' => '',
])
@php
    // Classes inteiras (o Tailwind compila só o que lê nos arquivos).
    $width = match ($size) { 'sm' => 'max-w-sm', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '3xl' => 'max-w-3xl', '4xl' => 'max-w-4xl', '5xl' => 'max-w-5xl', default => 'max-w-md' };
    $layer = match ((string) $z) { '40' => 'z-40', '50' => 'z-50', '70' => 'z-[70]', '80' => 'z-[80]', '100' => 'z-[100]', default => 'z-[60]' };
    $gutter = $padding === 'tight' ? 'p-2 sm:p-4' : 'p-4';
    $box = trim('relative w-full '.$width.' '.($scroll ? 'max-h-[95vh] overflow-y-auto ' : '').'bg-white dark:bg-gray-800 rounded-lg shadow-xl '.($padded ? 'p-5 space-y-3 ' : '').$boxClass);
    $closeExpr = str_contains($close, '(') ? $close : $close.'()';
@endphp
{{-- Modal padrão: fundo escuro, ESC e clique fora fecham; o conteúdo vai no slot (form quando `form` é o método do submit). --}}
<div x-data x-on:keydown.escape.window="$wire.{{ $closeExpr }}" class="fixed inset-0 {{ $layer }} flex {{ $top ? 'items-start sm:items-center' : 'items-center' }} justify-center {{ $gutter }}" {{ $attributes->only(['data-test', 'wire:key']) }}>
    <div class="absolute inset-0 bg-black/50" wire:click="{{ $closeExpr }}"></div>
    @if($form)
        <form wire:submit="{{ $form }}" class="{{ $box }}" {{ $attributes->except(['data-test', 'wire:key', 'class']) }}>{{ $slot }}</form>
    @else
        <div class="{{ $box }}" {{ $attributes->except(['data-test', 'wire:key', 'class']) }}>{{ $slot }}</div>
    @endif
</div>
