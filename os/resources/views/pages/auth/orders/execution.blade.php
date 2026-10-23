<?php

use App\Enums\MediaKind;
use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Services\Execution\Execution;
use App\Services\Execution\WorkLog;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Execução: apontamento (Iniciar/Pausar), peças aprovadas (recebida, não usada, foto da velha e da nova),
 * rodas e torque, test drive exigido e lacres. O "Falta para concluir" fica no Próximo passo da OS.
 */
new class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public ServiceOrder $order;

    /** Foto que acabou de subir ($wire.upload). */
    public $upload;

    public bool $hasWheelWork = false;

    public string $torqueNm = '';

    public string $torqueWrench = '';

    public bool $requiresTestDrive = false;

    public string $sealNumber = '';

    public function mount(ServiceOrder $order): void
    {
        $this->order = $order;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->order->refresh();
        $this->hasWheelWork = (bool) $this->order->has_wheel_work;
        $this->torqueNm = (string) $this->order->torque_nm;
        $this->torqueWrench = (string) $this->order->torque_wrench;
        $this->requiresTestDrive = (bool) $this->order->requires_test_drive;
    }

    private function changed(): void
    {
        unset($this->parts, $this->seals, $this->totals, $this->open);
        $this->order->refresh();
        $this->dispatch('order-updated');
    }

    #[Computed]
    public function parts()
    {
        return app(Execution::class)->approvedParts($this->order);
    }

    #[Computed]
    public function seals()
    {
        return $this->order->media()->where('kind', MediaKind::Lacre->value)->whereNull('replaced_at')->get();
    }

    #[Computed]
    public function totals()
    {
        return app(WorkLog::class)->totals($this->order);
    }

    #[Computed]
    public function open()
    {
        return app(WorkLog::class)->open($this->order);
    }

    public function inExecution(): bool
    {
        return $this->order->stage === Stage::EmExecucao;
    }

    public function editable(): bool
    {
        return in_array($this->order->stage, Execution::STAGES, true);
    }

    public function start(WorkLog $log): void
    {
        if ($this->attempt(fn () => $log->start($this->order, auth()->user()))) {
            $this->dispatch('toast', message: 'Apontamento iniciado.', type: 'success');
            $this->changed();
        }
    }

    public function pause(WorkLog $log): void
    {
        if ($this->attempt(fn () => $log->pause($this->order, auth()->user()))) {
            $this->dispatch('toast', message: 'Apontamento pausado.', type: 'success');
            $this->changed();
        }
    }

    public function toggleReceived(int $id, Execution $execution): void
    {
        $item = $this->find($id);

        if ($this->attempt(fn () => $execution->markReceived($item, $item->received_at === null, auth()->user()))) {
            $this->changed();
        }
    }

    public function toggleNotUsed(int $id, Execution $execution): void
    {
        $item = $this->find($id);

        if ($this->attempt(fn () => $execution->markNotUsed($item, ! $item->not_used, auth()->user()))) {
            $this->changed();
        }
    }

    /** Rodas, torque e test drive gravam ao mudar (ou ao sair do campo). */
    public function updated(string $property): void
    {
        $data = match ($property) {
            'hasWheelWork' => ['has_wheel_work' => $this->hasWheelWork],
            'torqueNm' => ['torque_nm' => $this->torqueNm],
            'torqueWrench' => ['torque_wrench' => $this->torqueWrench],
            'requiresTestDrive' => ['requires_test_drive' => $this->requiresTestDrive],
            default => null,
        };

        if ($data === null) {
            return;
        }

        if ($this->attempt(fn () => app(Execution::class)->update($this->order, $data, auth()->user()), $property)) {
            $this->changed();
        } else {
            $this->fillForm();
        }
    }

    /** Foto: "velha:12", "nova:12" (peça) ou "lacre" (com o número digitado). */
    public function savePhoto(string $slot, ?string $hash, ?int $takenAt, Execution $execution): void
    {
        if (! $this->upload) {
            return;
        }

        $this->validate(['upload' => 'image|max:15360'], ['upload.image' => 'O arquivo precisa ser uma foto.', 'upload.max' => 'A foto pode ter no máximo 15 MB.']);
        [$type, $key] = array_pad(explode(':', $slot, 2), 2, '');
        $user = auth()->user();

        $done = $this->attempt(fn () => match ($type) {
            'velha', 'nova' => $execution->storePartPhoto($this->find((int) $key), $type, $this->upload, $hash, $takenAt, $user),
            'lacre' => $execution->storeSeal($this->order, $this->sealNumber, $this->upload, $hash, $takenAt, $user),
            default => null,
        });

        $this->reset('upload');

        if ($done) {
            if ($type === 'lacre') {
                $this->sealNumber = '';
            }

            $this->changed();
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
    $mechanic = $user->can('oficina');
    $running = $this->open->firstWhere('user_id', $user->id);
    $duration = fn (int $minutes) => intdiv($minutes, 60) > 0 ? intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'min' : '') : $minutes.' min';
@endphp

<div class="space-y-4" x-data="photoCapture">
    {{-- Apontamento --}}
    <section id="apontamento" class="{{ $cardClass }} p-4 space-y-3" data-test="apontamento">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Apontamento</h2>
            @if ($mechanic && $this->inExecution())
                @if ($running)
                    <button type="button" wire:click="pause" class="{{ $btnSecondary }}" data-test="pausar"><i class="fas fa-pause"></i> Pausar ({{ $running->started_at->format('H:i') }})</button>
                @else
                    <button type="button" wire:click="start" class="{{ $btnPrimary }}" data-test="iniciar"><i class="fas fa-play"></i> Iniciar</button>
                @endif
            @endif
        </div>
        @if ($order->stage === Stage::Aprovada || $order->stage === Stage::AguardandoPecas)
            <p class="text-sm text-gray-600 dark:text-gray-300">Para começar, toque em "Iniciar serviço" no Próximo passo: o apontamento começa junto.</p>
        @endif
        @forelse ($this->totals as $row)
            <div class="flex flex-wrap items-center gap-2 text-sm" wire:key="t-{{ $row['user']->id }}">
                <span class="font-semibold flex-1">{{ $row['user']->short_name }}</span>
                @if ($this->open->contains('user_id', $row['user']->id)) <span class="text-emerald-700 dark:text-emerald-300 font-semibold"><i class="fas fa-circle fa-xs"></i> trabalhando</span> @endif
                @if ($row['auto_closed']) <span class="text-amber-700 dark:text-amber-300 text-xs font-semibold" title="Ficou aberto e foi fechado à noite: conferir o horário">fechado automático</span> @endif
                <span>{{ $duration($row['minutes']) }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">Nenhum apontamento ainda.</p>
        @endforelse
    </section>

    {{-- Peças aprovadas --}}
    <section class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="pecas-execucao">
        <h2 class="p-4 font-display font-bold text-xl uppercase tracking-wide">Peças aprovadas</h2>
        @forelse ($this->parts as $part)
            @php($old = $part->media->where('kind', MediaKind::PecaVelha)->sortByDesc('id')->first())
            @php($new = $part->media->where('kind', MediaKind::PecaNova)->sortByDesc('id')->first())
            <div id="peca-{{ $part->id }}" class="p-4 space-y-3" wire:key="p-{{ $part->id }}">
                <div class="flex flex-wrap items-start gap-2">
                    <div class="min-w-0 w-full sm:w-auto sm:flex-1">
                        <p @class(['font-semibold', 'line-through text-gray-400' => $part->not_used])>{{ $part->description }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $part->quantityLabel() }}{{ $part->brand ? ' · '.$part->brand : '' }}{{ $part->origin ? ' · '.$part->origin->shortLabel() : '' }}
                            ·
                            @if ($part->not_used)
                                <span class="font-semibold">não usada</span>
                            @elseif ($part->received_at)
                                <span class="text-emerald-700 dark:text-emerald-300 font-semibold">recebida {{ $part->received_at->format('d/m H:i') }}</span>
                            @elseif ($part->origin === PartOrigin::Estoque)
                                <span>do estoque</span>
                            @else
                                <span class="text-amber-700 dark:text-amber-300 font-semibold">esperando chegar{{ $part->lead_time ? ' (prazo '.$part->lead_time.')' : '' }}</span>
                            @endif
                        </p>
                    </div>
                    @if ($this->editable())
                        <div class="flex flex-wrap gap-1">
                            @if ($part->origin !== PartOrigin::Estoque && ! $part->not_used)
                                <button type="button" wire:click="toggleReceived({{ $part->id }})" class="{{ $part->received_at ? $btnSecondary : $btnPrimary }} text-sm" data-test="recebida-{{ $part->id }}"><i class="fas fa-box"></i> {{ $part->received_at ? 'Desfazer recebida' : 'Chegou' }}</button>
                            @endif
                            <button type="button" wire:click="toggleNotUsed({{ $part->id }})" wire:confirm="{{ $part->not_used ? 'Voltar a usar esta peça?' : 'Marcar que esta peça não foi usada no serviço?' }}" class="{{ $btnSecondary }} text-sm" data-test="nao-usada-{{ $part->id }}">{{ $part->not_used ? 'Vai usar' : 'Não usada' }}</button>
                        </div>
                    @endif
                </div>
                @if (! $part->not_used)
                    @if ($part->requires_photos)
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (['velha' => [$old, 'Peça velha'], 'nova' => [$new, 'Peça nova']] as $which => [$photo, $label])
                                <div class="rounded-lg border {{ $photo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600' }} overflow-hidden" wire:key="ph-{{ $part->id }}-{{ $which }}">
                                    @if ($photo)
                                        <a href="{{ $photo->url() }}" target="_blank" class="block aspect-[4/3] bg-gray-100 dark:bg-gray-900"><img src="{{ $photo->url('mini') }}" alt="{{ $label }}" class="w-full h-full object-cover" loading="lazy"></a>
                                    @else
                                        <div class="aspect-[4/3] flex items-center justify-center bg-gray-50 dark:bg-gray-900 text-gray-400"><i class="fas fa-camera text-2xl"></i></div>
                                    @endif
                                    <div class="p-2 space-y-1">
                                        <p class="text-xs">{{ $label }}</p>
                                        @if ($mechanic && $this->inExecution())
                                            <label class="{{ $photo ? $btnSecondary : $btnPrimary }} w-full text-sm cursor-pointer" data-test="foto-{{ $which }}-{{ $part->id }}">
                                                <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, '{{ $which }}:{{ $part->id }}')">
                                                <span x-show="busy !== '{{ $which }}:{{ $part->id }}'"><i class="fas fa-camera"></i> {{ $photo ? 'Refazer' : 'Tirar foto' }}</span>
                                                <span x-show="busy === '{{ $which }}:{{ $part->id }}'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                                            </label>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-gray-500">Sem foto (fluido ou consumível).</p>
                    @endif
                @endif
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500">Nenhuma peça aprovada.</p>
        @endforelse
    </section>

    {{-- Rodas, torque e test drive --}}
    <section id="torque" class="{{ $cardClass }} p-4 space-y-3" data-test="rodas">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Rodas e test drive</h2>
        <fieldset @disabled(! $this->editable()) class="space-y-3">
            <label class="flex items-center gap-3 min-h-11">
                <input type="checkbox" wire:model.live="hasWheelWork" class="size-5 rounded border-gray-300 text-brand-600" data-test="mexeu-roda">
                Mexi em roda (tirou ou apertou roda)
            </label>
            @if ($hasWheelWork)
                <div class="grid gap-3 grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="torqueNm">Torque aplicado (Nm)</label>
                        <input id="torqueNm" type="text" inputmode="numeric" wire:model.live.blur="torqueNm" placeholder="o valor que você usou" @disabled(! auth()->user()->can('oficina')) class="{{ $inputClass }}" data-test="torque-nm">
                        @error('torqueNm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="torqueWrench">Nº do torquímetro</label>
                        <input id="torqueWrench" type="text" wire:model.live.blur="torqueWrench" maxlength="30" @disabled(! auth()->user()->can('oficina')) class="{{ $inputClass }}" data-test="torquimetro">
                    </div>
                </div>
                <p class="text-xs text-gray-500">Use o torque da especificação do carro e marque os parafusos com a listra depois de apertar. A conferência fotografa cada roda.</p>
            @endif
            <label class="flex items-center gap-3 min-h-11">
                <input type="checkbox" wire:model.live="requiresTestDrive" class="size-5 rounded border-gray-300 text-brand-600" data-test="exige-test-drive">
                Este serviço exige test drive (freio, suspensão, direção, rodas)
            </label>
            @if ($requiresTestDrive && ! $order->test_drive_authorized)
                <p class="text-sm font-semibold text-amber-700 dark:text-amber-300"><i class="fas fa-triangle-exclamation"></i> O cliente não autorizou test drive na entrada: o atendimento precisa pedir a autorização.</p>
            @endif
        </fieldset>
    </section>

    {{-- Lacres --}}
    <section class="{{ $cardClass }} p-4 space-y-3" data-test="lacres">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Lacres</h2>
        @if ($this->seals->isNotEmpty())
            <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                @foreach ($this->seals as $seal)
                    <a href="{{ $seal->url() }}" target="_blank" class="block" wire:key="s-{{ $seal->id }}">
                        <img src="{{ $seal->url('mini') }}" alt="" class="aspect-square w-full object-cover rounded">
                        <span class="text-xs">nº {{ $seal->position }}</span>
                    </a>
                @endforeach
            </div>
        @endif
        @if ($mechanic && $this->inExecution())
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[8rem]">
                    <label class="{{ $labelClass }}" for="sealNumber">Nº do lacre</label>
                    <input id="sealNumber" type="text" wire:model="sealNumber" maxlength="30" class="{{ $inputClass }}" data-test="numero-lacre">
                </div>
                <label class="{{ $btnSecondary }} cursor-pointer" data-test="foto-lacre">
                    <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, 'lacre')">
                    <span x-show="busy !== 'lacre'"><i class="fas fa-camera"></i> Foto do lacre</span>
                    <span x-show="busy === 'lacre'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                </label>
            </div>
        @elseif ($this->seals->isEmpty())
            <p class="text-sm text-gray-500">Nenhum lacre.</p>
        @endif
    </section>

    @if ($this->inExecution())
        <div class="{{ $cardClass }} p-4 flex flex-wrap items-center gap-2 text-sm">
            <span class="flex-1">Achou outra coisa no serviço? Lance em "Peças e serviços": vira adendo e o cliente aprova antes.</span>
            <button type="button" wire:click="$parent.$set('tab', 'itens')" class="{{ $btnSecondary }}" data-test="achei-outra-coisa"><i class="fas fa-plus"></i> Lançar item</button>
        </div>
    @endif
</div>
