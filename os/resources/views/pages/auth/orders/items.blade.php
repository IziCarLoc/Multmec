<?php

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Services\Items\OrderItems;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Peças e serviços da OS. O mecânico lança (sem ver custo); o atendimento completa origem, prestador e custo.
 * Avisa a tela da OS ("order-updated") para atualizar o "Falta para avançar".
 */
new class extends Component
{
    use RunsDomainActions;

    public ServiceOrder $order;

    /** Formulário de item (novo ou em edição). */
    public bool $editing = false;

    public ?int $itemId = null;

    public string $kind = 'peca';

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(ServiceOrder $order): void
    {
        $this->order = $order;
    }

    #[Computed]
    public function items()
    {
        return $this->order->items()->with(['supplier', 'mechanic'])->get();
    }

    #[Computed]
    public function editable(): bool
    {
        $user = auth()->user();
        $stage = $this->order->refresh()->stage;

        return match (true) {
            $user->can('atendimento') => in_array($stage, [Stage::EmVistoria, Stage::EmDiagnostico, Stage::AguardandoCotacao], true),
            $user->can('oficina') => in_array($stage, [Stage::EmVistoria, Stage::EmDiagnostico], true),
            default => false,
        };
    }

    #[Computed]
    public function frequent()
    {
        return app(OrderItems::class)->frequentServices();
    }

    #[Computed]
    public function providers()
    {
        return Supplier::query()->active()->providesService()->orderBy('name')->get();
    }

    public function create(string $kind): void
    {
        $this->resetValidation();
        $this->itemId = null;
        $this->kind = $kind === 'servico' ? 'servico' : 'peca';
        $this->form = ['description' => '', 'quantity' => '1', 'priority' => 'necessario', 'hours' => '', 'part_code' => '', 'preferred_brand' => '', 'accepted_brands' => '', 'quote_detail' => '', 'requires_photos' => $this->kind === 'peca'];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $item = $this->find($id);
        $this->resetValidation();
        $this->itemId = $item->id;
        $this->kind = $item->kind;
        $this->form = [
            'description' => $item->description,
            'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 2, ',', ''), '0'), ','),
            'priority' => $item->priority,
            'hours' => $item->hours !== null ? rtrim(rtrim(number_format((float) $item->hours, 2, ',', ''), '0'), ',') : '',
            'part_code' => (string) $item->part_code,
            'preferred_brand' => (string) $item->preferred_brand,
            'accepted_brands' => (string) $item->accepted_brands,
            'quote_detail' => (string) $item->quote_detail,
            'requires_photos' => $item->requires_photos,
        ];

        if (auth()->user()->can('atendimento')) {
            $this->form += ['origin' => $item->origin?->value, 'supplier_id' => $item->supplier_id, 'cost_cents' => $item->cost_cents];
        }

        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
    }

    public function save(OrderItems $items): void
    {
        $data = $this->form;

        if ($this->kind === 'peca') {
            unset($data['hours']);
        } else {
            $data = array_diff_key($data, array_flip(['part_code', 'preferred_brand', 'accepted_brands', 'origin']));
        }

        $done = $this->itemId
            ? $this->attempt(fn () => $items->update($this->find($this->itemId), $data, auth()->user()), 'form.description')
            : $this->attempt(fn () => $items->add($this->order, $this->kind, $data, auth()->user()), 'form.description');

        if ($done) {
            $this->editing = false;
            unset($this->items, $this->frequent);
            $this->dispatch('order-updated');
        }
    }

    /** Origem da peça direto na lista (atendimento). */
    public function setOrigin(int $id, string $origin, OrderItems $items): void
    {
        if ($this->attempt(fn () => $items->update($this->find($id), ['origin' => $origin ?: null], auth()->user()))) {
            unset($this->items);
            $this->dispatch('order-updated');
        }
    }

    public function remove(int $id, OrderItems $items): void
    {
        if ($this->attempt(fn () => $items->remove($this->find($id), auth()->user()))) {
            unset($this->items);
            $this->dispatch('order-updated');
        }
    }

    private function find(int $id): OrderItem
    {
        return OrderItem::query()->where('service_order_id', $this->order->id)->findOrFail($id);
    }
};
?>

@php
    $user = auth()->user();
    $commercial = $user->can('atendimento');
@endphp

<div class="space-y-4">
    @if ($this->editable)
        <div id="novo-item" class="flex flex-wrap gap-2">
            <button type="button" wire:click="create('peca')" class="{{ $btnPrimary }}" data-test="nova-peca"><i class="fas fa-gear"></i> Peça</button>
            <button type="button" wire:click="create('servico')" class="{{ $btnPrimary }}" data-test="novo-servico"><i class="fas fa-wrench"></i> Serviço</button>
        </div>
    @endif

    @foreach (['peca' => 'Peças', 'servico' => 'Serviços'] as $group => $title)
        @php($list = $this->items->where('kind', $group))
        <section class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" wire:key="grp-{{ $group }}">
            <h2 class="p-4 font-display font-bold text-xl uppercase tracking-wide">{{ $title }} <span class="text-gray-400">({{ $list->count() }})</span></h2>
            @forelse ($list as $item)
                <div id="item-{{ $item->id }}" class="p-4 space-y-2" wire:key="it-{{ $item->id }}">
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $item->description }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ $item->quantityLabel() }}
                                @if ($item->isService()) · {{ $item->hoursLabel() ?? 'sem horas' }} @endif
                                · <span @class(['font-semibold', 'text-gray-700 dark:text-gray-200' => $item->priority === 'necessario', 'text-amber-700 dark:text-amber-300' => $item->priority === 'recomendado'])>{{ OrderItem::PRIORITIES[$item->priority] }}</span>
                                @if ($item->isPart() && ! $item->requires_photos) · <span class="text-gray-500">sem foto (fluido/consumível)</span> @endif
                            </p>
                            @if ($item->isPart() && ($item->part_code || $item->preferred_brand || $item->accepted_brands || $item->quote_detail))
                                <p class="text-xs text-gray-500">{{ collect([$item->part_code ? 'cód. '.$item->part_code : null, $item->preferred_brand ? 'prefere '.$item->preferred_brand : null, $item->accepted_brands ? 'aceita '.$item->accepted_brands : null, $item->quote_detail])->filter()->implode(' · ') }}</p>
                            @endif
                            @if ($commercial)
                                <p class="text-xs text-gray-500">
                                    @if ($item->supplier) {{ $item->isService() ? 'Prestador' : 'Fornecedor' }}: {{ $item->supplier->name }} @endif
                                    @if ($item->cost_cents !== null) · custo {{ $money($item->cost_cents) }} @endif
                                    @if ($item->brand) · {{ $item->brand }} @endif
                                    @if ($item->lead_time) · prazo {{ $item->lead_time }} @endif
                                </p>
                            @endif
                            @if ($item->mechanic) <p class="text-[11px] text-gray-400">Lançado por {{ $item->mechanic->short_name }}</p> @endif
                        </div>
                        @if ($this->editable)
                            <div class="flex gap-1">
                                <button type="button" wire:click="edit({{ $item->id }})" class="min-h-11 px-3 text-brand-600" title="Editar"><i class="fas fa-pen"></i></button>
                                <button type="button" wire:click="remove({{ $item->id }})" wire:confirm="Tirar &quot;{{ $item->description }}&quot; da OS?" class="min-h-11 px-3 text-red-600" title="Remover"><i class="fas fa-trash"></i></button>
                            </div>
                        @endif
                    </div>
                    @if ($commercial && $item->isPart() && $this->editable)
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="text-xs text-gray-500" for="origin-{{ $item->id }}">Origem</label>
                            <select id="origin-{{ $item->id }}" wire:change="setOrigin({{ $item->id }}, $event.target.value)" class="{{ $inputClass }} sm:w-72">
                                <option value="">A definir na cotação</option>
                                @foreach (PartOrigin::cases() as $origin)
                                    <option value="{{ $origin->value }}" @selected($item->origin === $origin)>{{ $origin->label() }}</option>
                                @endforeach
                            </select>
                            @if ($item->toQuote()) <span class="text-xs font-semibold text-brand-700 dark:text-brand-300">vai para a cotação</span> @endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="p-4 text-sm text-gray-500">Nenhum{{ $group === 'peca' ? 'a peça' : ' serviço' }}.</p>
            @endforelse
        </section>
    @endforeach

    @if ($editing)
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-item">
            <h2 class="font-display font-bold text-xl uppercase">{{ $itemId ? 'Editar' : 'Nova' }} {{ $kind === 'peca' ? 'peça' : 'serviço' }}</h2>
            <div>
                <label class="{{ $labelClass }}" for="description">Descrição</label>
                <input id="description" type="text" wire:model="form.description" maxlength="200" @if ($kind === 'servico') list="servicos-frequentes" @endif autocomplete="off"
                    placeholder="{{ $kind === 'peca' ? 'Ex.: pastilha de freio dianteira' : 'Ex.: troca de pastilhas dianteiras' }}" class="{{ $inputClass }}" data-test="item-descricao">
                @if ($kind === 'servico')
                    <datalist id="servicos-frequentes">
                        @foreach ($this->frequent as $service)
                            <option value="{{ $service['description'] }}">{{ $service['hours'] ? $service['hours'].' h em média' : '' }}</option>
                        @endforeach
                    </datalist>
                @endif
                @error('form.description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-3 grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="quantity">Quantidade</label>
                    <input id="quantity" type="text" inputmode="decimal" wire:model="form.quantity" class="{{ $inputClass }}" data-test="item-quantidade">
                </div>
                @if ($kind === 'servico')
                    <div>
                        <label class="{{ $labelClass }}" for="hours">Horas estimadas</label>
                        <input id="hours" type="text" inputmode="decimal" wire:model="form.hours" placeholder="Ex.: 1,5" class="{{ $inputClass }}" data-test="item-horas">
                    </div>
                @endif
            </div>
            <div>
                <p class="{{ $labelClass }}">Para o cliente</p>
                <div class="flex gap-2">
                    @foreach (OrderItem::PRIORITIES as $value => $label)
                        <label class="flex-1">
                            <input type="radio" wire:model="form.priority" value="{{ $value }}" class="peer sr-only">
                            <span class="flex min-h-11 items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-semibold peer-checked:bg-brand-600 peer-checked:border-brand-600 peer-checked:text-white cursor-pointer">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($kind === 'peca')
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="{{ $labelClass }}" for="part_code">Código</label><input id="part_code" type="text" wire:model="form.part_code" maxlength="60" class="{{ $inputClass }}"></div>
                    <div><label class="{{ $labelClass }}" for="preferred_brand">Marca preferida</label><input id="preferred_brand" type="text" wire:model="form.preferred_brand" maxlength="60" class="{{ $inputClass }}"></div>
                    <div class="sm:col-span-2"><label class="{{ $labelClass }}" for="accepted_brands">Marcas aceitas</label><input id="accepted_brands" type="text" wire:model="form.accepted_brands" maxlength="120" placeholder="Ex.: Cobreq, Fras-le" class="{{ $inputClass }}"></div>
                    <div class="sm:col-span-2"><label class="{{ $labelClass }}" for="quote_detail">Detalhe para a cotação</label><input id="quote_detail" type="text" wire:model="form.quote_detail" maxlength="255" placeholder="Ex.: lado esquerdo, com sensor" class="{{ $inputClass }}"></div>
                    <label class="sm:col-span-2 flex items-center gap-3 min-h-11"><input type="checkbox" wire:model="form.requires_photos" class="size-5 rounded border-gray-300 text-brand-600"> Fotografar a peça velha e a nova (desmarque para fluido ou consumível)</label>
                </div>
            @endif
            @if ($commercial && $itemId)
                <div class="grid gap-3 sm:grid-cols-2 border-t border-gray-100 dark:border-gray-700 pt-3">
                    @if ($kind === 'peca')
                        <div class="sm:col-span-2">
                            <label class="{{ $labelClass }}" for="origin">Origem</label>
                            <select id="origin" wire:model="form.origin" class="{{ $inputClass }}">
                                <option value="">A definir na cotação</option>
                                @foreach (PartOrigin::cases() as $origin)
                                    <option value="{{ $origin->value }}">{{ $origin->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div>
                            <label class="{{ $labelClass }}" for="supplier_id">Prestador externo</label>
                            <select id="supplier_id" wire:model="form.supplier_id" class="{{ $inputClass }}" data-test="item-prestador">
                                <option value="">Feito na oficina</option>
                                @foreach ($this->providers as $provider)
                                    <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Custo do prestador</label>
                            <div class="relative" x-data="moneyInput($wire.entangle('form.cost_cents'))">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">R$</span>
                                <input type="text" inputmode="decimal" x-on:input="onInput($event)" x-on:focus="onFocus()" x-on:blur="onBlur($event)" x-bind:value="text" class="{{ $inputClass }} pl-9" data-test="item-custo">
                            </div>
                        </div>
                    @endif
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-item"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
