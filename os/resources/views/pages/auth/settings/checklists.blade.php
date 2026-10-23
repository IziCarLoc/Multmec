<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Models\ChecklistItem;
use App\Services\Settings\Checklists;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Ajustes > Checklists (gestor): itens da vistoria técnica e dos modelos da conferência. Vale para as próximas
 * OS (cada OS guarda a cópia que recebeu). Item semeado aparece com "revisar" até ser conferido.
 */
new #[Title('Checklists')] class extends Component
{
    use RunsDomainActions;

    #[Url(as: 'lista', except: 'tecnico')]
    public string $list = 'tecnico';

    public ?int $editingId = null;

    public string $section = '';

    public string $label = '';

    public bool $isFluid = false;

    public bool $requiresDetail = false;

    #[Computed]
    public function items()
    {
        return app(Checklists::class)->items(array_key_exists($this->list, Checklists::LISTS) ? $this->list : 'tecnico');
    }

    public function updatedList(): void
    {
        $this->cancel();
        unset($this->items);
    }

    public function edit(int $id): void
    {
        $item = ChecklistItem::query()->findOrFail($id);
        $this->editingId = $item->id;
        $this->section = $item->section;
        $this->label = $item->label;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'section', 'label', 'isFluid', 'requiresDetail');
        $this->resetValidation();
    }

    public function save(Checklists $checklists): void
    {
        $data = ['section' => $this->section, 'label' => $this->label, 'is_fluid' => $this->isFluid, 'requires_detail' => $this->requiresDetail];
        $done = $this->editingId
            ? $this->attempt(fn () => $checklists->update(ChecklistItem::query()->findOrFail($this->editingId), $data, auth()->user()), 'label')
            : $this->attempt(fn () => $checklists->add($this->list, $data, auth()->user()), 'label');

        if ($done) {
            $this->dispatch('toast', message: 'Checklist salvo.', type: 'success');
            $this->cancel();
            unset($this->items);
        }
    }

    public function toggle(int $id, Checklists $checklists): void
    {
        if ($this->attempt(fn () => $checklists->toggle(ChecklistItem::query()->findOrFail($id), auth()->user()))) {
            unset($this->items);
        }
    }

    public function reviewed(int $id, Checklists $checklists): void
    {
        if ($this->attempt(fn () => $checklists->reviewed(ChecklistItem::query()->findOrFail($id), auth()->user()))) {
            unset($this->items);
        }
    }

    public function move(int $id, int $direction, Checklists $checklists): void
    {
        if ($this->attempt(fn () => $checklists->move(ChecklistItem::query()->findOrFail($id), $direction, auth()->user()))) {
            unset($this->items);
        }
    }
};
?>

<div class="space-y-4">
    <a href="{{ route('settings') }}" wire:navigate class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Ajustes</a>
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide">Checklists</h1>

    <div class="grid grid-cols-3 gap-2">
        @foreach (Checklists::LISTS as $key => [, , $title])
            <button type="button" wire:click="$set('list', '{{ $key }}')" class="{{ $list === $key ? $btnPrimary : $btnSecondary }} text-sm" data-test="lista-{{ $key }}">{{ $title }}</button>
        @endforeach
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Vale para as próximas OS: cada OS guarda a lista que recebeu. "Revisar" marca o item que veio pronto e ainda não foi conferido pela oficina.</p>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="itens-checklist">
        @foreach ($this->items->groupBy('section') as $section => $rows)
            <div class="p-3 space-y-1" wire:key="sec-{{ \Illuminate\Support\Str::slug($section) }}">
                <p class="{{ $labelClass }}">{{ $section }}</p>
                @foreach ($rows as $item)
                    <div class="flex flex-wrap items-center gap-2 py-1" wire:key="ci-{{ $item->id }}">
                        <span @class(['flex-1 min-w-[10rem] text-sm', 'line-through text-gray-400' => ! $item->active])>
                            {{ $item->label }}
                            @if ($item->is_fluid) <span class="text-xs text-gray-500">· fluido</span> @endif
                            @if ($item->requires_detail) <span class="text-xs text-gray-500">· pede detalhe na troca</span> @endif
                            @if ($item->needs_review) <button type="button" wire:click="reviewed({{ $item->id }})" class="ml-1 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 px-1.5 text-[11px] font-semibold" title="Marcar como revisado" data-test="revisar-{{ $item->id }}">revisar</button> @endif
                        </span>
                        <button type="button" wire:click="move({{ $item->id }}, -1)" class="min-h-11 px-2 text-gray-500" title="Subir"><i class="fas fa-arrow-up"></i></button>
                        <button type="button" wire:click="move({{ $item->id }}, 1)" class="min-h-11 px-2 text-gray-500" title="Descer"><i class="fas fa-arrow-down"></i></button>
                        <button type="button" wire:click="edit({{ $item->id }})" class="min-h-11 px-2 text-brand-600" title="Editar"><i class="fas fa-pen"></i></button>
                        <button type="button" wire:click="toggle({{ $item->id }})" class="min-h-11 px-2 {{ $item->active ? 'text-gray-500' : 'text-emerald-600' }}" title="{{ $item->active ? 'Desligar' : 'Religar' }}" data-test="ligar-{{ $item->id }}"><i class="fas {{ $item->active ? 'fa-eye-slash' : 'fa-eye' }}"></i></button>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <form wire:submit="save" class="{{ $cardClass }} p-4 space-y-3" data-test="form-checklist">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">{{ $editingId ? 'Editar item' : 'Novo item' }}</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="{{ $labelClass }}" for="section">Bloco</label>
                <input id="section" type="text" wire:model="section" maxlength="60" list="blocos" class="{{ $inputClass }}" data-test="bloco">
                <datalist id="blocos">
                    @foreach ($this->items->pluck('section')->unique() as $existing)
                        <option value="{{ $existing }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $labelClass }}" for="label">Item</label>
                <input id="label" type="text" wire:model="label" maxlength="160" class="{{ $inputClass }}" data-test="item">
                @error('label') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        @if ($list === 'tecnico' && ! $editingId)
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 min-h-11"><input type="checkbox" wire:model="isFluid" class="size-5 rounded border-gray-300 text-brand-600"> É fluido (Baixo / Na média / Ideal)</label>
                <label class="flex items-center gap-2 min-h-11"><input type="checkbox" wire:model="requiresDetail" class="size-5 rounded border-gray-300 text-brand-600"> Na troca, pedir qual (ex.: lâmpada)</label>
            </div>
        @endif
        <div class="flex justify-end gap-2">
            @if ($editingId) <button type="button" wire:click="cancel" class="{{ $btnSecondary }}">Cancelar</button> @endif
            <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-checklist"><i class="fas fa-check"></i> {{ $editingId ? 'Salvar' : 'Adicionar' }}</button>
        </div>
    </form>
</div>
