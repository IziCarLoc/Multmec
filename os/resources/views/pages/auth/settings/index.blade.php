<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Unit;
use App\Services\Settings\UnitSettings;
use App\Support\Settings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ajustes')] class extends Component
{
    use RunsDomainActions;

    public ?int $unitId = null;

    /** Dados da unidade escolhida. */
    public array $unit = [];

    /** Regras (alçada, garantia, diagnóstico). Dinheiro em centavos. */
    public ?int $iziLimit = null;

    public ?int $budgetValidityDays = null;

    public ?int $warrantyDays = null;

    public ?int $warrantyKm = null;

    public ?int $diagnosisFee = null;

    public string $torqueColor = '';

    /** Textos dos termos (modelos para o advogado revisar). */
    public array $texts = [];

    public function mount(): void
    {
        $this->loadUnit(Unit::default()?->id);
        $this->iziLimit = Settings::int('izi_approval_limit_cents');
        $this->budgetValidityDays = Settings::int('budget_validity_days');
        $this->warrantyDays = Settings::int('warranty_days');
        $this->warrantyKm = Settings::int('warranty_km');
        $this->diagnosisFee = Settings::int('diagnosis_fee_cents');
        $this->torqueColor = (string) Settings::get('torque_mark_color');
        $this->texts = collect(array_keys(config('oficina.texts')))->mapWithKeys(fn ($key) => [$key => Settings::text($key)])->all();
    }

    #[Computed]
    public function units()
    {
        return Unit::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function loadUnit(?int $id): void
    {
        $unit = $id ? Unit::find($id) : null;

        if (! $unit) {
            return;
        }

        $this->unitId = $unit->id;
        $this->unit = [
            'trade_name' => $unit->trade_name,
            'legal_name' => $unit->legal_name,
            'cnpj' => $unit->cnpjFormatted(),
            'address' => $unit->address,
            'city' => $unit->city,
            'phone' => $unit->phone,
            'numbering_mode' => $unit->numbering_mode,
            'next_number' => $unit->next_number,
            'active' => $unit->active,
        ];
        $this->resetValidation();
    }

    public function saveUnit(UnitSettings $settings): void
    {
        $this->validate([
            'unit.trade_name' => 'required|string|max:120',
            'unit.next_number' => 'nullable|integer|min:1',
        ], [
            'unit.trade_name.required' => 'Informe o nome da oficina.',
            'unit.next_number.integer' => 'O próximo número precisa ser um número inteiro.',
        ]);

        $unit = Unit::findOrFail($this->unitId);

        if ($this->attempt(fn () => $settings->save($unit, [...$this->unit, 'next_number' => $this->unit['next_number'] ? (int) $this->unit['next_number'] : null, 'active' => (bool) $this->unit['active']]), 'unit.trade_name')) {
            unset($this->units);
            $this->dispatch('toast', message: 'Dados da oficina salvos.', type: 'success');
        }
    }

    public function saveRules(): void
    {
        $this->validate([
            'iziLimit' => 'required|integer|min:0',
            'budgetValidityDays' => 'required|integer|min:1|max:90',
            'warrantyDays' => 'required|integer|min:90|max:3650',
            'warrantyKm' => 'nullable|integer|min:1',
            'diagnosisFee' => 'nullable|integer|min:0',
            'torqueColor' => 'nullable|string|max:40',
        ], [
            'iziLimit.required' => 'Informe o valor da alçada.',
            'budgetValidityDays.required' => 'Informe a validade do orçamento.',
            'warrantyDays.required' => 'Informe o prazo de garantia.',
            'warrantyDays.min' => 'A garantia contratual não pode ser menor que os 90 dias da garantia legal.',
        ]);

        Settings::set('izi_approval_limit_cents', $this->iziLimit);
        Settings::set('budget_validity_days', $this->budgetValidityDays);
        Settings::set('warranty_days', $this->warrantyDays);
        Settings::set('warranty_km', $this->warrantyKm ?: null);
        Settings::set('diagnosis_fee_cents', $this->diagnosisFee ?: null);
        Settings::set('torque_mark_color', trim($this->torqueColor) ?: null);

        $this->dispatch('toast', message: 'Regras salvas.', type: 'success');
    }

    public function saveTexts(): void
    {
        foreach ($this->texts as $key => $text) {
            if (array_key_exists($key, config('oficina.texts'))) {
                Settings::set('texts.'.$key, (string) $text);
            }
        }

        $this->dispatch('toast', message: 'Textos salvos.', type: 'success');
    }

    public function textLabel(string $key): string
    {
        return match ($key) {
            'warranty' => 'Termo de garantia',
            'pickup' => 'Termo de retirada',
            'entry' => 'Vistoria de entrada (declaração do cliente)',
            'approval' => 'Aceite do orçamento',
            'privacy' => 'Aviso de privacidade',
            default => $key,
        };
    }
};
?>

<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2"><span class="w-1 h-6 rounded bg-brand-600"></span>Ajustes</h1>
        <div class="flex flex-wrap gap-2 sm:ml-auto">
            <a href="{{ route('settings.users') }}" class="{{ $btnSecondary }}"><i class="fas fa-users"></i> Usuários</a>
            <a href="{{ route('settings.izi-fleet') }}" class="{{ $btnSecondary }}"><i class="fas fa-car-side"></i> Frota Izi</a>
            <a href="{{ route('settings.health') }}" class="{{ $btnSecondary }}"><i class="fas fa-heart-pulse"></i> Saúde do sistema</a>
        </div>
    </div>

    {{-- Oficina e numeração --}}
    <form wire:submit="saveUnit" class="{{ $cardClass }} p-5 space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="font-display font-bold text-xl uppercase">Oficina e numeração</h2>
            @if ($this->units->count() > 1)
                <div class="flex gap-1 sm:ml-auto" role="tablist">
                    @foreach ($this->units as $u)
                        <button type="button" wire:click="loadUnit({{ $u->id }})" @class(['px-3 py-1.5 rounded-full text-sm font-semibold border', 'bg-gray-900 text-white border-gray-900 dark:bg-gray-100 dark:text-gray-900' => $u->id === $unitId, 'border-gray-300 text-gray-600 dark:text-gray-300' => $u->id !== $unitId])>
                            {{ $u->name }} @if (! $u->active) <span class="font-normal">(inativa)</span> @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="trade">Nome da oficina (aparece no cabeçalho e no PDF)</label>
                <input id="trade" type="text" wire:model="unit.trade_name" class="{{ $inputClass }}">
                @error('unit.trade_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="legal">Razão social</label>
                <input id="legal" type="text" wire:model="unit.legal_name" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="cnpj">CNPJ</label>
                <x-document-input id="cnpj" model="unit.cnpj" placeholder="00.000.000/0000-00" />
            </div>
            <div>
                <label class="{{ $labelClass }}" for="phone">Telefone / WhatsApp</label>
                <input id="phone" type="tel" wire:model="unit.phone" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="address">Endereço</label>
                <input id="address" type="text" wire:model="unit.address" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="city">Cidade/UF</label>
                <input id="city" type="text" wire:model="unit.city" class="{{ $inputClass }}">
            </div>
        </div>

        <fieldset class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <legend class="px-1 text-sm font-semibold">Número da OS</legend>
            <label class="flex items-start gap-3">
                <input type="radio" wire:model.live="unit.numbering_mode" value="manual" class="mt-1 size-5 text-brand-600">
                <span><span class="font-semibold">Digitado do sistema atual</span><br><span class="text-sm text-gray-500 dark:text-gray-400">A Giovana abre a OS no sistema atual (R65) e digita o mesmo número aqui. É o modo do piloto.</span></span>
            </label>
            <label class="flex items-start gap-3">
                <input type="radio" wire:model.live="unit.numbering_mode" value="auto" class="mt-1 size-5 text-brand-600">
                <span><span class="font-semibold">Automático</span><br><span class="text-sm text-gray-500 dark:text-gray-400">O sistema numera sozinho a partir do número abaixo (quando o sistema atual deixar de ser usado).</span></span>
            </label>
            @if (($unit['numbering_mode'] ?? null) === 'auto')
                <div class="max-w-xs">
                    <label class="{{ $labelClass }}" for="next">Próximo número de OS</label>
                    <input id="next" type="number" min="1" inputmode="numeric" wire:model="unit.next_number" class="{{ $inputClass }}">
                    @error('unit.next_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </fieldset>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="unit.active" class="size-5 rounded border-gray-300 text-brand-600">
            Unidade ativa (aparece para abrir OS)
        </label>

        <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}">Salvar oficina</button></div>
    </form>

    {{-- Regras --}}
    <form wire:submit="saveRules" class="{{ $cardClass }} p-5 space-y-4">
        <h2 class="font-display font-bold text-xl uppercase">Regras</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="izi">Alçada da frota Izi Car (R$)</label>
                <input id="izi" type="text" inputmode="decimal" x-data="moneyInput($wire.entangle('iziLimit'))" :value="text" @input="onInput" @focus="onFocus" @blur="onBlur" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-gray-500">Até este valor o Atendimento aprova o orçamento de carro da Izi; acima, só o gestor.</p>
                @error('iziLimit') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="validity">Validade do orçamento (dias)</label>
                <input id="validity" type="number" min="1" inputmode="numeric" wire:model="budgetValidityDays" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-gray-500">O CDC (art. 40) usa 10 dias, contados de quando o cliente recebe.</p>
                @error('budgetValidityDays') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="wdays">Garantia contratual (dias)</label>
                <input id="wdays" type="number" min="90" inputmode="numeric" wire:model="warrantyDays" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-gray-500">Além da garantia legal de 90 dias. Não pode ser menor que 90.</p>
                @error('warrantyDays') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="wkm">Garantia em km (em branco = sem limite)</label>
                <input id="wkm" type="number" min="1" inputmode="numeric" wire:model="warrantyKm" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="diag">Valor padrão do diagnóstico cobrado (R$)</label>
                <input id="diag" type="text" inputmode="decimal" x-data="moneyInput($wire.entangle('diagnosisFee'))" :value="text" @input="onInput" @focus="onFocus" @blur="onBlur" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-gray-500">Em branco: combinado caso a caso na entrada.</p>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="torque">Cor da marcação de torque</label>
                <input id="torque" type="text" wire:model="torqueColor" placeholder="Ex.: amarela" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-gray-500">Vai no termo de garantia. Em branco até a oficina definir.</p>
            </div>
        </div>
        <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}">Salvar regras</button></div>
    </form>

    {{-- Textos --}}
    <form wire:submit="saveTexts" class="{{ $cardClass }} p-5 space-y-4">
        <div>
            <h2 class="font-display font-bold text-xl uppercase">Textos dos termos</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Modelos marcados [REVISAR] até o advogado aprovar. No termo de garantia, {dias}, {km} e {cor_torque} são trocados pelos valores das regras.</p>
        </div>
        @foreach ($texts as $key => $text)
            <div wire:key="text-{{ $key }}">
                <label class="{{ $labelClass }}" for="text-{{ $key }}">{{ $this->textLabel($key) }}</label>
                <textarea id="text-{{ $key }}" rows="5" wire:model="texts.{{ $key }}" class="{{ $inputClass }} font-mono text-sm"></textarea>
            </div>
        @endforeach
        <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}">Salvar textos</button></div>
    </form>
</div>
