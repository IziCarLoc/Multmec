@props([
    'model',
    'id' => null,
    'placeholder' => '000.000.000-00 ou 00.000.000/0000-00',
    'live' => false,
    'inputClass' => 'w-full px-3 py-2.5 text-base sm:text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-brand-500 focus:border-brand-500',
])
{{-- CPF/CNPJ com a máscara pelo tamanho (11 ou 14 dígitos); o valor gravado é o texto mascarado, o sistema guarda só os dígitos (Document::digits). --}}
<input type="text" @if($id) id="{{ $id }}" @endif wire:model{{ $live ? '.live.blur' : '' }}="{{ $model }}" inputmode="numeric" maxlength="18" autocomplete="off" placeholder="{{ $placeholder }}"
    x-data="docMask('{{ $model }}')" x-on:input="mask($event)" x-on:blur="mask($event)" {{ $attributes->merge(['class' => $inputClass]) }}>
