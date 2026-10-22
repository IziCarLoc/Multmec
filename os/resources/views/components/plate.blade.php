@props(['plate' => null, 'size' => 'md'])
@php
    $text = $plate ? (strlen($plate) === 7 ? substr($plate, 0, 3).'-'.substr($plate, 3) : $plate) : 'SEM PLACA';
    $font = $size === 'lg' ? 'text-xl px-3 py-1' : 'text-[13px] px-1.5 py-[3px]';
@endphp
{{-- Placa no desenho do v12: faixa azul "BRASIL" em cima, letras em fonte mono. --}}
<span {{ $attributes->class('inline-flex flex-col shrink-0 overflow-hidden rounded-[5px] border-[1.5px] border-gray-900 dark:border-gray-200 bg-white dark:bg-gray-900 font-mono font-bold leading-none') }}>
    <span class="bg-brand-600 text-white text-[6px] tracking-[0.2em] text-center py-px">BRASIL</span>
    <span class="{{ $font }} tracking-wider {{ $plate ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 font-medium' }}">{{ $text }}</span>
</span>
