<?php

use App\Enums\MediaKind;
use App\Enums\Role;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\OrderChecklistItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Execution\FinalCheck;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Conferência (portão 2), por quem não apontou tempo na OS: modelo Expresso ou Completo, cada item OK / Não se
 * aplica / CORRIGIR, foto de cada roda com a listra, test drive. Liberar e CORRIGIR ficam no Próximo passo.
 */
new class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public ServiceOrder $order;

    public $upload;

    /** O que corrigir, por item: [itemId => texto]. */
    public array $details = [];

    public ?int $correcting = null;

    // Test drive
    public string $kmBefore = '';

    public string $kmAfter = '';

    public string $startedAt = '';

    public string $endedAt = '';

    public string $driverId = '';

    public string $route = '';

    public function mount(ServiceOrder $order): void
    {
        $this->order = $order;
        $this->kmBefore = (string) ($order->test_drive_km_before ?? $order->km_in ?? '');
        $this->kmAfter = (string) $order->test_drive_km_after;
        $this->startedAt = (string) $order->test_drive_started_at?->format('Y-m-d\TH:i');
        $this->endedAt = (string) $order->test_drive_ended_at?->format('Y-m-d\TH:i');
        $this->driverId = (string) ($order->test_drive_driver_id ?? auth()->id());
        $this->route = (string) $order->test_drive_route;
    }

    private function changed(): void
    {
        unset($this->items, $this->wheels);
        $this->order->refresh();
        $this->dispatch('order-updated');
    }

    #[Computed]
    public function items()
    {
        return app(FinalCheck::class)->items($this->order);
    }

    #[Computed]
    public function wheels()
    {
        return $this->order->media()->where('kind', MediaKind::Roda->value)->whereNull('replaced_at')->get()->keyBy('position');
    }

    #[Computed]
    public function drivers()
    {
        return User::query()->forPicker()->get();
    }

    public function canCheck(): bool
    {
        return $this->order->stage === Stage::EmConferencia && app(FinalCheck::class)->canCheck($this->order, auth()->user());
    }

    public function start(string $template, FinalCheck $check): void
    {
        if ($this->attempt(fn () => $check->start($this->order, $template, auth()->user()))) {
            $this->changed();
        }
    }

    public function answer(int $id, string $state, FinalCheck $check): void
    {
        $item = OrderChecklistItem::query()->where('service_order_id', $this->order->id)->findOrFail($id);

        if ($state === 'corrigir' && trim((string) ($this->details[$id] ?? '')) === '') {
            $this->correcting = $id;

            return;
        }

        if ($this->attempt(fn () => $check->answer($item, $state, $this->details[$id] ?? null, auth()->user()))) {
            $this->correcting = null;
            $this->changed();
        }
    }

    public function savePhoto(string $slot, ?string $hash, ?int $takenAt, FinalCheck $check): void
    {
        if (! $this->upload) {
            return;
        }

        $this->validate(['upload' => 'image|max:15360'], ['upload.image' => 'O arquivo precisa ser uma foto.', 'upload.max' => 'A foto pode ter no máximo 15 MB.']);
        [, $wheel] = array_pad(explode(':', $slot, 2), 2, '');
        $done = $this->attempt(fn () => $check->storeWheelPhoto($this->order, $wheel, $this->upload, $hash, $takenAt, auth()->user()));
        $this->reset('upload');

        if ($done) {
            $this->changed();
        }
    }

    public function saveTestDrive(FinalCheck $check): void
    {
        $done = $this->attempt(fn () => $check->recordTestDrive($this->order, [
            'km_before' => $this->kmBefore,
            'km_after' => $this->kmAfter,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'driver_id' => $this->driverId,
            'route' => $this->route,
        ], auth()->user()), 'route');

        if ($done) {
            $this->dispatch('toast', message: 'Test drive registrado.', type: 'success');
            $this->changed();
        }
    }
};
?>

@php
    $canCheck = $this->canCheck();
    $items = $this->items;
@endphp

<div class="space-y-4" x-data="photoCapture">
    @if ($order->stage === Stage::EmConferencia && ! $canCheck)
        <div class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/30 p-4 text-sm" data-test="nao-confere">
            <i class="fas fa-user-shield"></i> Quem confere é um mecânico que <strong>não apontou tempo</strong> nesta OS. Você vê a conferência, mas não responde.
        </div>
    @endif

    {{-- Modelo --}}
    <section id="modelo" class="{{ $cardClass }} p-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Conferência{{ $order->final_check_round > 1 ? ' · rodada '.$order->final_check_round : '' }}</h2>
            @if ($order->final_check_template)
                <span class="rounded bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-semibold uppercase">{{ FinalCheck::TEMPLATES[$order->final_check_template] }}</span>
                @if ($order->finalChecker) <span class="text-xs text-gray-500">por {{ $order->finalChecker->short_name }}</span> @endif
            @endif
        </div>
        @if ($canCheck && $items->whereNotNull('state')->isEmpty())
            <div class="grid grid-cols-2 gap-2">
                @foreach (FinalCheck::TEMPLATES as $key => $label)
                    <button type="button" wire:click="start('{{ $key }}')" class="{{ $order->final_check_template === $key ? $btnPrimary : $btnSecondary }}" data-test="modelo-{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="text-xs text-gray-500">Expresso: serviço simples (óleo, filtro, lâmpada). Completo: freio, suspensão, motor, rodas. Os itens estão em Ajustes, para revisar.</p>
        @elseif ($items->isEmpty())
            <p class="text-sm text-gray-500">A conferência começa quando o mecânico conclui o serviço.</p>
        @endif
    </section>

    @if ($items->isNotEmpty())
        <section class="{{ $cardClass }} p-4 space-y-4" data-test="itens-conferencia">
            @foreach ($items->groupBy('section') as $section => $rows)
                <div class="space-y-2" wire:key="sec-{{ \Illuminate\Support\Str::slug($section) }}">
                    <p class="{{ $labelClass }}">{{ $section }}</p>
                    @foreach ($rows as $item)
                        <div id="conf-{{ $item->id }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-2" wire:key="c-{{ $item->id }}">
                            <p class="text-sm font-semibold">{{ $item->label }}</p>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach (FinalCheck::STATES as $state => $label)
                                    <button type="button" wire:click="answer({{ $item->id }}, '{{ $state }}')" @disabled(! $canCheck)
                                        @class([
                                            'min-h-11 rounded-lg border text-sm font-semibold disabled:opacity-60',
                                            'bg-emerald-600 border-emerald-600 text-white' => $item->state === $state && $state === 'ok',
                                            'bg-gray-600 border-gray-600 text-white' => $item->state === $state && $state === 'na',
                                            'bg-red-600 border-red-600 text-white' => $item->state === $state && $state === 'corrigir',
                                            'border-gray-300 dark:border-gray-600' => $item->state !== $state,
                                        ]) data-test="conf-{{ $state }}-{{ $item->id }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            @if ($correcting === $item->id || $item->state === 'corrigir')
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" wire:model="details.{{ $item->id }}" maxlength="255" placeholder="{{ $item->detail ?: 'O que precisa corrigir?' }}" class="{{ $inputClass }} flex-1 min-w-[10rem]" data-test="corrigir-texto-{{ $item->id }}">
                                    @if ($canCheck && $correcting === $item->id)
                                        <button type="button" wire:click="answer({{ $item->id }}, 'corrigir')" class="{{ $btnPrimary }} bg-red-600 hover:bg-red-700" data-test="corrigir-salvar-{{ $item->id }}">Marcar CORRIGIR</button>
                                    @endif
                                </div>
                                @if ($item->detail) <p class="text-xs text-red-700 dark:text-red-300">Corrigir: {{ $item->detail }}</p> @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif

    @if ($order->has_wheel_work)
        <section id="rodas" class="{{ $cardClass }} p-4 space-y-3" data-test="fotos-rodas">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Rodas com a listra</h2>
            <p class="text-xs text-gray-500">Torque registrado: {{ $order->torque_nm ? $order->torque_nm.' Nm' : '—' }}{{ $order->torque_wrench ? ' · torquímetro '.$order->torque_wrench : '' }}.</p>
            <div class="grid grid-cols-2 gap-2">
                @foreach (FinalCheck::WHEELS as $key => $label)
                    @php($photo = $this->wheels[$key] ?? null)
                    <div class="rounded-lg border {{ $photo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600' }} overflow-hidden" wire:key="w-{{ $key }}">
                        @if ($photo)
                            <a href="{{ $photo->url() }}" target="_blank" class="block aspect-[4/3] bg-gray-100 dark:bg-gray-900"><img src="{{ $photo->url('mini') }}" alt="{{ $label }}" class="w-full h-full object-cover" loading="lazy"></a>
                        @else
                            <div class="aspect-[4/3] flex items-center justify-center bg-gray-50 dark:bg-gray-900 text-gray-400"><i class="fas fa-camera text-2xl"></i></div>
                        @endif
                        <div class="p-2 space-y-1">
                            <p class="text-xs">{{ $label }}</p>
                            @if ($canCheck)
                                <label class="{{ $photo ? $btnSecondary : $btnPrimary }} w-full text-sm cursor-pointer" data-test="roda-{{ $key }}">
                                    <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, 'roda:{{ $key }}')">
                                    <span x-show="busy !== 'roda:{{ $key }}'"><i class="fas fa-camera"></i> {{ $photo ? 'Refazer' : 'Tirar foto' }}</span>
                                    <span x-show="busy === 'roda:{{ $key }}'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                                </label>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($order->requires_test_drive)
        <section id="test-drive" class="{{ $cardClass }} p-4 space-y-3" data-test="test-drive">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Test drive</h2>
            @if (! $order->test_drive_authorized)
                <p class="text-sm font-semibold text-amber-700 dark:text-amber-300"><i class="fas fa-triangle-exclamation"></i> O cliente não autorizou test drive na entrada. Fale com ele antes de sair com o carro.</p>
            @endif
            <form wire:submit="saveTestDrive" class="space-y-3">
                <fieldset @disabled(! $canCheck || ! $order->test_drive_authorized) class="grid gap-3 grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="kmBefore">Km na saída</label>
                        <input id="kmBefore" type="text" inputmode="numeric" wire:model="kmBefore" class="{{ $inputClass }}" data-test="km-antes">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="kmAfter">Km na volta</label>
                        <input id="kmAfter" type="text" inputmode="numeric" wire:model="kmAfter" class="{{ $inputClass }}" data-test="km-depois">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="startedAt">Saiu</label>
                        <input id="startedAt" type="datetime-local" wire:model="startedAt" class="{{ $inputClass }}" data-test="saiu">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="endedAt">Voltou</label>
                        <input id="endedAt" type="datetime-local" wire:model="endedAt" class="{{ $inputClass }}" data-test="voltou">
                    </div>
                    <div class="col-span-2">
                        <label class="{{ $labelClass }}" for="driverId">Quem dirigiu</label>
                        <select id="driverId" wire:model="driverId" class="{{ $inputClass }}">
                            @foreach ($this->drivers as $driver)
                                <option value="{{ $driver->id }}">{{ $driver->short_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="{{ $labelClass }}" for="route">Trajeto</label>
                        <input id="route" type="text" wire:model="route" maxlength="200" placeholder="Ex.: volta da quadra, Av. Liberdade até a rótula" class="{{ $inputClass }}" data-test="trajeto">
                        @error('route') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </fieldset>
                @if ($canCheck && $order->test_drive_authorized)
                    <div class="flex items-center justify-end gap-2">
                        @if ($order->test_drive_ended_at) <span class="text-sm text-emerald-700 dark:text-emerald-300 mr-auto"><i class="fas fa-circle-check"></i> {{ $order->test_drive_km_after - $order->test_drive_km_before }} km rodados</span> @endif
                        <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-test-drive"><i class="fas fa-check"></i> Salvar test drive</button>
                    </div>
                @endif
            </form>
        </section>
    @endif
</div>
