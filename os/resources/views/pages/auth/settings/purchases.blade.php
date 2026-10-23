<?php

use App\Models\Unit;
use App\Services\Purchases\PurchaseSettings;
use App\Support\Document;
use App\Support\Money;
use App\Support\Settings;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Parâmetros da conferência de compras (doc 09 §8), só do gestor. Os padrões vêm do sistema anterior (Node), em
 * config/oficina.php `purchases`. O CNPJ da oficina não fica aqui: é o das unidades (Ajustes > Oficina e numeração).
 */
new #[Title('Ajustes da conferência de compras')] class extends Component
{
    /** @var array<string, string> chave => valor digitado (dinheiro em reais, o resto inteiro) */
    public array $values = [];

    public string $auditSince = '';

    public function mount(): void
    {
        foreach (PurchaseSettings::EDITABLE as $key => $rule) {
            $value = (int) Settings::get('purchases.'.$key);
            $this->values[$key] = str_ends_with($key, '_cents') ? number_format($value / 100, 2, ',', '.') : (string) $value;
        }

        $this->auditSince = (string) Settings::get('purchases.audit_since');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $parsed = [];

        foreach (PurchaseSettings::EDITABLE as $key => [$label, $min, $max]) {
            $raw = trim((string) ($this->values[$key] ?? ''));
            $money = str_ends_with($key, '_cents');
            $value = $money ? Money::parseBr($raw) : (preg_match('/^\d+$/', $raw) ? (int) $raw : null);

            if ($value === null || $value < $min || $value > $max) {
                $this->addError('values.'.$key, $money
                    ? 'Informe um valor entre '.Money::brl($min).' e '.Money::brl($max).'.'
                    : 'Informe um número inteiro entre '.$min.' e '.$max.'.');

                continue;
            }

            $parsed[$key] = $value;
        }

        $since = trim($this->auditSince);

        if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            $this->addError('auditSince', 'Data inválida.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($parsed as $key => $value) {
            Settings::set('purchases.'.$key, $value);
        }

        Settings::set('purchases.audit_since', $since ?: null);
        $this->dispatch('toast', message: 'Parâmetros da conferência de compras salvos.', type: 'success');
    }
};
?>

@php
    $units = Unit::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'cnpj', 'active']);
    $defaults = config('oficina.purchases');
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2"><span class="w-1 h-6 rounded bg-brand-600"></span>Conferência de compras</h1>
        <a href="{{ route('settings') }}" wire:navigate class="{{ $btnSecondary }} sm:ml-auto"><i class="fas fa-arrow-left"></i> Ajustes</a>
    </div>

    <section class="{{ $cardClass }} p-4 space-y-2" data-test="cnpj-oficina">
        <h2 class="font-display font-bold text-xl uppercase">CNPJ da oficina</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">É com ele que o sistema confere o destinatário das notas e o pagador dos boletos. Vem das unidades ativas (Ajustes > Oficina e numeração).</p>
        <ul class="text-sm space-y-1">
            @foreach ($units as $unit)
                <li wire:key="un-{{ $unit->id }}">{{ $unit->name }}: {{ $unit->cnpj ? Document::format($unit->cnpj) : 'sem CNPJ' }}{{ $unit->active ? '' : ' (inativa: não conta)' }}@if ($unit->cnpj && ! Document::isValidCnpj(Document::normalizeCnpj($unit->cnpj))) <span class="text-red-700 dark:text-red-300">· CNPJ inválido: não conta</span> @endif</li>
            @endforeach
        </ul>
        @if (PurchaseSettings::workshopCnpjs() === [])
            <p class="text-sm text-red-700 dark:text-red-300">Nenhum CNPJ válido: informe em Ajustes > Oficina e numeração.</p>
        @endif
    </section>

    <form wire:submit="save" class="{{ $cardClass }} p-4 space-y-3">
        <h2 class="font-display font-bold text-xl uppercase">Parâmetros</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">Os padrões são os do sistema anterior. Mudar vale para a conferência de agora em diante (o que já foi aceito com motivo continua aceito enquanto o fato não mudar).</p>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (PurchaseSettings::EDITABLE as $key => [$label, $min, $max])
                <div wire:key="p-{{ $key }}">
                    <label class="{{ $labelClass }}" for="p-{{ $key }}">{{ $label }}</label>
                    <input id="p-{{ $key }}" type="text" inputmode="{{ str_ends_with($key, '_cents') ? 'decimal' : 'numeric' }}" wire:model="values.{{ $key }}" class="{{ $inputClass }}" data-test="parametro-{{ $key }}">
                    <p class="text-xs text-gray-500">Padrão: {{ str_ends_with($key, '_cents') ? $money($defaults[$key]) : $defaults[$key] }}</p>
                    @error('values.'.$key) <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
            <div>
                <label class="{{ $labelClass }}" for="p-audit">OS a partir de (aviso "OS com custo de peça e nenhuma nota")</label>
                <input id="p-audit" type="date" wire:model="auditSince" class="{{ $inputClass }}" data-test="parametro-audit_since">
                <p class="text-xs text-gray-500">Vazio: a partir do dia 1º do mês atual.</p>
                @error('auditSince') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-parametros"><i class="fas fa-check"></i> Salvar</button>
        </div>
    </form>
</div>
