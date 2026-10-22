<?php

use App\Enums\Stage;
use App\Models\ServiceOrder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Ordens de serviço')] class extends Component
{
    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** Etapa escolhida nos botões do celular ('' = todas em aberto). */
    #[Url(as: 'etapa', except: '')]
    public string $stage = '';

    #[Url(as: 'minhas', except: false)]
    public bool $mine = false;

    /** @return Collection<int, ServiceOrder> */
    #[Computed]
    public function orders(): Collection
    {
        return ServiceOrder::query()->open()
            ->with(['vehicle', 'customer', 'responsible'])
            ->search($this->search)
            ->when($this->mine, fn ($q) => $q->where('responsible_id', auth()->id()))
            ->orderBy('stage_changed_at')
            ->get();
    }

    /** @return array<string, Collection<int, ServiceOrder>> etapa => OS, na ordem do quadro */
    #[Computed]
    public function columns(): array
    {
        $grouped = $this->orders->groupBy(fn (ServiceOrder $order) => $order->stage->value);

        return collect(Stage::board())->mapWithKeys(fn (Stage $stage) => [$stage->value => $grouped->get($stage->value, collect())])->all();
    }

    public function pick(string $stage): void
    {
        $this->stage = $this->stage === $stage ? '' : $stage;
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Quadro
        </h1>
        <div class="flex gap-2 ml-auto">
            <a href="{{ route('orders.index') }}" wire:navigate class="{{ $btnSecondary }}"><i class="fas fa-list"></i> Lista</a>
            @can('atendimento')
                <a href="{{ route('orders.create') }}" wire:navigate class="{{ $btnPrimary }}" data-test="nova-os"><i class="fas fa-plus"></i> Nova OS</a>
            @endcan
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-[14rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Placa, número, cliente, modelo..." class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
        <label class="inline-flex items-center gap-2 text-sm min-h-11">
            <input type="checkbox" wire:model.live="mine" class="size-5 rounded border-gray-300 text-brand-600"> Só as minhas
        </label>
    </div>

    @if ($this->orders->isEmpty())
        <div class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">
            @if ($search !== '' || $mine)
                Nenhuma OS em aberto com esse filtro.
            @else
                Nenhuma OS em aberto.
                @can('atendimento') Toque em <strong>Nova OS</strong> para abrir a primeira. @endcan
            @endif
        </div>
    @else
        {{-- Celular: botões por etapa (com a contagem) e a lista da etapa escolhida. --}}
        <div class="lg:hidden space-y-3">
            <div class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4">
                <button type="button" wire:click="pick('')" @class(['shrink-0 rounded-full px-3 min-h-9 text-sm font-semibold border', 'bg-brand-600 text-white border-brand-600' => $stage === '', 'border-gray-300 dark:border-gray-600' => $stage !== ''])>Todas ({{ $this->orders->count() }})</button>
                @foreach ($this->columns as $value => $orders)
                    @if ($orders->isNotEmpty())
                        <button type="button" wire:click="pick('{{ $value }}')" wire:key="chip-{{ $value }}" @class(['shrink-0 rounded-full px-3 min-h-9 text-sm font-semibold border whitespace-nowrap', 'bg-brand-600 text-white border-brand-600' => $stage === $value, 'border-gray-300 dark:border-gray-600' => $stage !== $value])>{{ Stage::from($value)->label() }} ({{ $orders->count() }})</button>
                    @endif
                @endforeach
            </div>
            <div class="space-y-2">
                @foreach ($stage === '' ? $this->orders : ($this->columns[$stage] ?? collect()) as $order)
                    <x-order-card :order="$order" wire:key="m-{{ $order->id }}" />
                @endforeach
            </div>
        </div>

        {{-- Computador: uma coluna por etapa que tem OS (as vazias só ocupariam a tela). --}}
        <div class="hidden lg:flex gap-3 overflow-x-auto pb-2 items-start">
            @foreach (array_filter($this->columns, fn ($orders) => $orders->isNotEmpty()) as $value => $orders)
                <section class="w-72 shrink-0 rounded-xl bg-gray-100 dark:bg-gray-800/60 p-2" wire:key="col-{{ $value }}">
                    <h2 class="px-1 pb-2 flex items-center justify-between font-display font-bold uppercase tracking-wide text-sm text-gray-600 dark:text-gray-300">
                        {{ Stage::from($value)->label() }}
                        <span class="rounded-full bg-white dark:bg-gray-700 px-2 text-xs">{{ $orders->count() }}</span>
                    </h2>
                    <div class="space-y-2">
                        @foreach ($orders as $order)
                            <x-order-card :order="$order" wire:key="d-{{ $order->id }}" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
