<?php

use App\Enums\LossCause;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\NavigatesMonths;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Loss;
use App\Services\Finance\Losses;
use App\Services\Finance\Month;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Prejuízos: peça avariada, erro de serviço, perda e as baixas de "A receber" vencido (inadimplência). Contam no
 * Painel na data em que foram lançados.
 */
new #[Title('Prejuízos')] class extends Component
{
    use FinanceAccess;
    use NavigatesMonths;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    public bool $editing = false;

    public ?int $lossId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $confirmDelete = false;

    protected function monthChanged(): void
    {
        unset($this->losses);
    }

    #[Computed]
    public function totals(): array
    {
        $today = now();
        $sum = fn ($q) => ['cents' => (int) (clone $q)->sum('amount_cents'), 'count' => (clone $q)->count()];

        return [
            'month' => $sum(Loss::query()->whereBetween('occurred_on', Month::bounds(Month::current()))),
            'year' => $sum(Loss::query()->whereBetween('occurred_on', [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfYear()->toDateString()])),
            'all' => $sum(Loss::query()),
        ];
    }

    #[Computed]
    public function losses()
    {
        $term = trim($this->search);
        $causes = collect(LossCause::cases())->filter(fn (LossCause $c) => $term !== '' && str_contains(mb_strtolower($c->label()), mb_strtolower($term)))->map->value->all();

        return Loss::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('item', 'like', '%'.$term.'%')->orWhere('notes', 'like', '%'.$term.'%')->orWhereIn('cause', $causes)))
            ->when(! $this->allMonths(), fn ($q) => $q->whereBetween('occurred_on', Month::bounds($this->selectedMonth())))
            ->orderByDesc('occurred_on')->orderByDesc('id')->limit(500)->get();
    }

    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ($this->losses as $loss) {
            $key = Month::label(Month::of($loss->occurred_on));
            $groups[$key] ??= ['label' => $key, 'cents' => 0, 'items' => []];
            $groups[$key]['items'][] = $loss;
            $groups[$key]['cents'] += $loss->amount_cents;
        }

        return array_values($groups);
    }

    #[Computed]
    public function current(): ?Loss
    {
        return $this->lossId ? Loss::find($this->lossId) : null;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->form = ['occurred_on' => now()->toDateString(), 'amount' => '', 'item' => '', 'cause' => LossCause::Avariada->value, 'notes' => ''];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $loss = Loss::findOrFail($id);
        $this->resetForm();
        $this->lossId = $loss->id;
        $this->form = [
            'occurred_on' => $loss->occurred_on->toDateString(),
            'amount' => number_format($loss->amount_cents / 100, 2, ',', '.'),
            'item' => $loss->item,
            'cause' => $loss->cause->value,
            'notes' => (string) $loss->notes,
        ];
        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
    }

    public function save(Losses $service): void
    {
        $this->resetErrorBag();
        $data = [
            'occurred_on' => $this->form['occurred_on'] ?? '',
            'amount_cents' => (string) ($this->form['amount'] ?? ''),
            'item' => $this->form['item'] ?? '',
            'cause' => $this->form['cause'] ?? '',
            'notes' => $this->form['notes'] ?? '',
        ];
        $done = $this->lossId
            ? $this->attempt(fn () => $service->update(Loss::findOrFail($this->lossId), $data, auth()->user()), 'form')
            : $this->attempt(fn () => $service->create($data, auth()->user()), 'form');

        if ($done) {
            $this->editing = false;
            unset($this->losses, $this->groups, $this->totals, $this->current);
            $this->dispatch('toast', message: 'Prejuízo salvo.', type: 'success');
        }
    }

    public function delete(Losses $service): void
    {
        if ($this->attempt(fn () => $service->delete(Loss::findOrFail($this->lossId), auth()->user()))) {
            $this->editing = false;
            unset($this->losses, $this->groups, $this->totals, $this->current);
            $this->dispatch('toast', message: 'Prejuízo excluído.', type: 'success');
        }
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->lossId = null;
        $this->confirmDelete = false;
        unset($this->current);
    }
};
?>

@php
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Prejuízos
        </h1>
        <button type="button" wire:click="create" class="{{ $btnPrimary }} ml-auto" data-test="novo-prejuizo"><i class="fas fa-plus"></i> Novo prejuízo</button>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        <x-finance-tile label="Este mês" :value="$money($this->totals['month']['cents'])" :note="$this->totals['month']['count'].' registro(s)'" :tone="$this->totals['month']['cents'] > 0 ? 'bad' : 'plain'" />
        <x-finance-tile label="Este ano" :value="$money($this->totals['year']['cents'])" :note="$this->totals['year']['count'].' registro(s)'" />
        <x-finance-tile label="Total registrado" :value="$money($this->totals['all']['cents'])" :note="$this->totals['all']['count'].' registro(s)'" />
    </div>

    <div class="relative">
        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Peça, causa, observação" class="{{ $inputClass }} pl-9" data-test="busca">
    </div>

    <x-finance-month-nav :month="$month" />

    @forelse ($this->groups as $group)
        <section class="space-y-2" wire:key="g-{{ md5($group['label']) }}">
            <h2 class="flex items-baseline gap-2 font-display font-bold uppercase tracking-wide text-sm text-gray-500 dark:text-gray-400">
                {{ $group['label'] }} <span class="ml-auto font-sans text-gray-700 dark:text-gray-200">{{ $money($group['cents']) }}</span>
            </h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($group['items'] as $loss)
                    <button type="button" wire:click="edit({{ $loss->id }})" class="w-full text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="l-{{ $loss->id }}" data-test="prejuizo-{{ $loss->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold flex flex-wrap items-center gap-1">
                                <span class="break-words">{{ $loss->item }}</span>
                                <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ $loss->cause->label() }}</span>
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ $loss->notes ?: '—' }}</p>
                            <p class="text-xs text-gray-500">{{ $loss->occurred_on->format('d/m/Y') }}</p>
                        </div>
                        <p class="font-display font-bold text-lg">{{ $money($loss->amount_cents) }}</p>
                    </button>
                @endforeach
            </div>
        </section>
    @empty
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">
            {{ Loss::query()->exists() ? 'Nenhum prejuízo com esse filtro.' : 'Nenhum prejuízo lançado. Toque em "Novo prejuízo" quando precisar registrar uma peça avariada ou outra perda.' }}
        </p>
    @endforelse

    @if ($editing)
        @php($current = $this->current)
        @php($locked = (bool) $current?->isWriteOff())
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-prejuizo">
            <h2 class="font-display font-bold text-xl uppercase">{{ $current ? 'Prejuízo' : 'Novo prejuízo' }}</h2>
            @if ($locked)
                <p class="rounded-lg bg-gray-50 dark:bg-gray-900/40 text-sm p-3">
                    Baixa de um lançamento do "A receber" (inadimplência): o valor e a data não mudam e o prejuízo não se exclui.
                    <a href="{{ route('finance.receivables', ['abrir' => $current->receivable_id]) }}" wire:navigate class="text-brand-700 dark:text-brand-300 font-semibold hover:underline">Ver o lançamento</a>
                </p>
            @endif
            @error('form') <p class="text-sm text-red-600" data-test="erro-form">{{ $message }}</p> @enderror
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="p-data">Data</label>
                    <input id="p-data" type="date" wire:model="form.occurred_on" @disabled($locked) class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-valor">Valor (R$)</label>
                    <input id="p-valor" type="text" inputmode="decimal" wire:model="form.amount" @disabled($locked) placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="prejuizo-valor">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="p-item">Peça / item</label>
                    <input id="p-item" type="text" wire:model="form.item" maxlength="200" placeholder="Ex.: Compressor de ar danificado" class="{{ $inputClass }}" data-test="prejuizo-item">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="p-causa">Causa</label>
                    <select id="p-causa" wire:model="form.cause" @disabled($locked) class="{{ $inputClass }}">
                        @foreach (LossCause::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="p-obs">Observações</label>
                    <textarea id="p-obs" wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea>
                </div>
            </div>

            @if ($confirmDelete)
                <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-3 space-y-2">
                    <p class="text-sm text-red-800 dark:text-red-200">Excluir este prejuízo?</p>
                    <div class="flex flex-wrap gap-2 justify-end">
                        <button type="button" wire:click="$set('confirmDelete', false)" class="{{ $btnSecondary }}">Cancelar</button>
                        <button type="button" wire:click="delete" class="{{ $btnPrimary }} !bg-red-600 hover:!bg-red-700">Sim, excluir</button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if ($current && ! $locked)
                    <button type="button" wire:click="$set('confirmDelete', true)" class="{{ $btnSecondary }} sm:mr-auto text-red-700">Excluir</button>
                @endif
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-prejuizo"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
