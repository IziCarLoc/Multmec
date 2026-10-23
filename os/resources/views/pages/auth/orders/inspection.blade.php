<?php

use App\Enums\MediaKind;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Damage;
use App\Models\Media;
use App\Models\OrderChecklistItem;
use App\Models\ServiceOrder;
use App\Services\Inspections\EntryInspection;
use App\Services\Inspections\InspectionDelivery;
use App\Services\Inspections\TechnicalChecklist;
use App\Services\Media\MediaStore;
use App\Support\Phone;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Vistoria de entrada dentro da tela da OS: parte A (atendimento) e parte B (mecânico). Cada campo grava
 * na hora (celular no pátio: nada se perde). Avisa a tela da OS ("order-updated") para atualizar o "Falta
 * para avançar".
 */
new class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public ServiceOrder $order;

    /** Arquivo que acabou de subir pela câmera ($wire.upload). */
    public $upload;

    // Parte A
    public string $km = '';

    public ?string $fuel = null;

    public string $lights = '';

    public string $belongings = '';

    /** @var array<string, ?bool> */
    public array $accessories = [];

    public ?bool $testDrive = null;

    public ?bool $diagnosisCharged = null;

    public ?int $diagnosisFee = null;

    public ?string $approverKind = null;

    public string $approverName = '';

    public string $approverWhatsapp = '';

    public string $damageLocation = '';

    public ?int $markingDamage = null;

    // Entrega ao cliente: o PDF preparado e o resumo dos dados daquele momento ficam travados no servidor.
    #[Locked]
    public ?string $preparedPath = null;

    #[Locked]
    public ?string $preparedSnapshot = null;

    public bool $askSentConfirmation = false;

    public bool $signing = false;

    public string $signerName = '';

    public string $signerDocument = '';

    public function mount(ServiceOrder $order): void
    {
        $this->order = $order;
        $this->fillFromOrder();
    }

    private function fillFromOrder(): void
    {
        $order = $this->order->refresh();
        $this->km = $order->km_in !== null ? number_format($order->km_in, 0, ',', '.') : '';
        $this->fuel = $order->fuel_in;
        $this->lights = (string) $order->dashboard_lights;
        $this->belongings = (string) $order->belongings;
        $this->accessories = collect(EntryInspection::ACCESSORIES)->map(fn ($label, $field) => $order->{$field})->all();
        $this->testDrive = $order->test_drive_authorized;
        $this->diagnosisCharged = $order->diagnosis_charged;
        $this->diagnosisFee = $order->diagnosis_fee_cents ?? (Settings::int('diagnosis_fee_cents') ?: null);
        $this->approverKind = $order->approver_kind;
        $this->approverName = (string) $order->approver_name;
        $this->approverWhatsapp = Phone::format($order->approver_whatsapp);
        unset($this->photos, $this->damages, $this->items, $this->missingA, $this->delivery);
    }

    #[Computed]
    public function editableA(): bool
    {
        return $this->order->stage === Stage::EmVistoria && auth()->user()->can('atendimento');
    }

    #[Computed]
    public function editableB(): bool
    {
        return $this->order->stage === Stage::EmVistoria && auth()->user()->can('oficina');
    }

    /** @return array<string, Media> posição => foto em uso */
    #[Computed]
    public function photos(): array
    {
        return $this->order->media()->whereIn('kind', [MediaKind::Entrada->value, MediaKind::Painel->value])->get()->keyBy('position')->all();
    }

    #[Computed]
    public function damages()
    {
        return $this->order->damages()->with('media')->get();
    }

    #[Computed]
    public function items()
    {
        return $this->order->stage === Stage::EmVistoria
            ? app(TechnicalChecklist::class)->itemsFor($this->order)
            : $this->order->checklistItems()->with('media')->where('kind', 'tecnico')->get();
    }

    #[Computed]
    public function missingA(): array
    {
        return app(EntryInspection::class)->missing($this->order);
    }

    /** @return array{delivered: bool, changed: bool, signature: ?\App\Models\Consent} */
    #[Computed]
    public function delivery(): array
    {
        $inspection = app(EntryInspection::class);

        return [
            'delivered' => $inspection->deliveredToCustomer($this->order),
            'changed' => $inspection->changedAfterDelivery($this->order),
            'signature' => $this->order->consents()->where('purpose', 'entrada')->latest('id')->first(),
        ];
    }

    private function changed(): void
    {
        $this->fillFromOrder();
        $this->dispatch('order-updated');
    }

    private function saveA(array $data): void
    {
        if ($this->attempt(fn () => app(EntryInspection::class)->update($this->order, $data, auth()->user())) === null) {
            $this->fillFromOrder();

            return;
        }

        $this->changed();
    }

    /** Campo da parte A mudou na tela: grava na hora. */
    public function updated(string $name, mixed $value): void
    {
        match (true) {
            $name === 'km' => $this->saveA(['km_in' => $value]),
            $name === 'lights' => $this->saveA(['dashboard_lights' => $value]),
            $name === 'belongings' => $this->saveA(['belongings' => $value]),
            $name === 'diagnosisFee' => $this->saveA(['diagnosis_fee_cents' => $value]),
            in_array($name, ['approverName', 'approverWhatsapp'], true) && $this->approverKind === 'outro' && filled($this->approverName) && Phone::isValid($this->approverWhatsapp)
                => $this->saveA(['approver_kind' => 'outro', 'approver_name' => $this->approverName, 'approver_whatsapp' => $this->approverWhatsapp]),
            default => null,
        };
    }

    public function setFuel(string $level): void
    {
        $this->saveA(['fuel_in' => $level]);
    }

    public function setFlag(string $field, bool $value): void
    {
        if (in_array($field, [...array_keys(EntryInspection::ACCESSORIES), 'test_drive_authorized', 'diagnosis_charged', 'no_visible_damage'], true)) {
            $this->saveA([$field => $value]);
        }
    }

    public function quick(string $field, string $text): void
    {
        if (in_array($field, ['dashboard_lights', 'belongings'], true)) {
            $this->saveA([$field => $text]);
        }
    }

    public function setApprover(string $kind): void
    {
        $this->approverKind = $kind;

        if ($kind !== 'outro') {
            $this->saveA(['approver_kind' => $kind]);
        }
    }

    /**
     * A câmera mandou uma foto: `slot` diz para onde ela vai (canto do carro, avaria num local, item da parte B).
     */
    public function savePhoto(string $slot, ?string $hash, ?int $takenAt, MediaStore $store): void
    {
        if (! $this->upload) {
            return;
        }

        $this->validate(['upload' => 'image|max:15360'], ['upload.image' => 'O arquivo precisa ser uma foto.', 'upload.max' => 'A foto pode ter no máximo 15 MB.']);
        [$type, $key] = array_pad(explode(':', $slot, 2), 2, '');
        $user = auth()->user();

        $done = $this->attempt(function () use ($type, $key, $hash, $takenAt, $store, $user) {
            if ($type === 'entrada' && array_key_exists($key, EntryInspection::PHOTOS)) {
                abort_unless($this->editableA, 403);
                $previous = $this->photos[$key] ?? null;
                $store->storePhoto($this->order, EntryInspection::kindFor($key), $this->upload, $hash, $takenAt, $user, ['position' => $key, 'caption' => EntryInspection::PHOTOS[$key], 'replaces' => $previous]);

                return true;
            }

            if ($type === 'avaria') {
                abort_unless($this->editableA, 403);

                if (! in_array($key, Damage::LOCATIONS, true) && mb_strlen(trim($key)) < 3) {
                    throw new DomainException('Escolha o local da avaria antes de fotografar.');
                }

                $media = $store->storePhoto($this->order, MediaKind::Avaria, $this->upload, $hash, $takenAt, $user, ['caption' => $key]);
                $damage = app(EntryInspection::class)->addDamage($this->order, $key, $media, $user);
                $this->markingDamage = $damage->id;

                return true;
            }

            if ($type === 'item') {
                abort_unless($this->editableB, 403);
                $item = OrderChecklistItem::query()->where('service_order_id', $this->order->id)->findOrFail((int) $key);
                app(TechnicalChecklist::class)->assertCanAttachPhoto($item, $user);
                $previous = $item->media;
                $media = $store->storePhoto($this->order, MediaKind::NaoConformidade, $this->upload, $hash, $takenAt, $user, ['position' => 'item-'.$item->id, 'caption' => $item->label.' · '.mb_strtolower((string) $item->stateLabel()), 'replaces' => $previous]);
                app(TechnicalChecklist::class)->attachPhoto($item, $media, $user);

                return true;
            }

            throw new DomainException('Foto sem destino. Tente de novo.');
        });

        $this->reset('upload');

        // Sem aviso de sucesso: a miniatura no lugar da foto já mostra que entrou (vários avisos cobririam a tela).
        if ($done) {
            $this->damageLocation = '';
            $this->changed();
        }
    }

    public function saveMarks(int $damageId, array $marks): void
    {
        $damage = Damage::query()->where('service_order_id', $this->order->id)->findOrFail($damageId);

        if ($this->attempt(fn () => app(EntryInspection::class)->setMarks($damage, $marks, auth()->user()))) {
            $this->markingDamage = null;
            $this->changed();
        }
    }

    public function removeDamage(int $damageId): void
    {
        $damage = Damage::query()->where('service_order_id', $this->order->id)->findOrFail($damageId);

        if ($this->attempt(fn () => app(EntryInspection::class)->removeDamage($damage, auth()->user()))) {
            $this->changed();
        }
    }

    public function answer(int $itemId, string $state): void
    {
        $item = OrderChecklistItem::query()->where('service_order_id', $this->order->id)->findOrFail($itemId);

        if ($this->attempt(fn () => app(TechnicalChecklist::class)->answer($item, $state, null, auth()->user()))) {
            $this->changed();
        }
    }

    public function saveDetail(int $itemId, string $detail): void
    {
        $item = OrderChecklistItem::query()->where('service_order_id', $this->order->id)->findOrFail($itemId);

        if ($this->attempt(fn () => app(TechnicalChecklist::class)->detail($item, $detail, auth()->user()))) {
            $this->changed();
        }
    }

    public function markRemainingOk(): void
    {
        $count = $this->attempt(fn () => app(TechnicalChecklist::class)->markRemainingOk($this->order, auth()->user()));

        if ($count !== null) {
            $this->dispatch('toast', message: $count.' item(ns) marcados como em funcionamento. Os fluidos ficam para você ver o nível.', type: 'success');
            $this->changed();
        }
    }

    /** Primeiro toque do envio: gera e guarda o PDF e devolve o arquivo para o compartilhar do celular. */
    public function preparePdf(InspectionDelivery $delivery): ?array
    {
        $prepared = $this->attempt(fn () => $delivery->preparePdf($this->order, auth()->user()));

        if (! $prepared) {
            return null;
        }

        $this->preparedPath = $prepared['path'];
        $this->preparedSnapshot = $prepared['snapshot_sha256'];

        return [
            'b64' => base64_encode($prepared['binary']),
            'name' => 'vistoria-'.$this->order->number.'-'.$this->order->vehicle->plate.'.pdf',
            'text' => $delivery->message($this->order),
            'link' => $delivery->whatsappLink($this->order),
        ];
    }

    /** O compartilhar terminou (ou a pessoa confirmou que mandou): registra o envio com a hora do servidor. */
    public function confirmSent(string $via, InspectionDelivery $delivery): void
    {
        $prepared = null;

        if ($this->preparedPath && str_starts_with($this->preparedPath, $this->order->storageDir().'/') && Storage::disk(Media::DISK)->exists($this->preparedPath)) {
            $binary = (string) Storage::disk(Media::DISK)->get($this->preparedPath);
            preg_match('/(\d{8}-\d{6})\.pdf$/', $this->preparedPath, $m);
            $prepared = ['path' => $this->preparedPath, 'sha256' => hash('sha256', $binary), 'binary' => $binary, 'at' => CarbonImmutable::createFromFormat('Ymd-His', $m[1] ?? now()->format('Ymd-His')), 'snapshot_sha256' => $this->preparedSnapshot];
        }

        if ($this->attempt(fn () => $delivery->markSent($this->order, $via, auth()->user(), $prepared))) {
            $this->reset('preparedPath', 'preparedSnapshot', 'askSentConfirmation');
            $this->dispatch('toast', message: 'Envio da vistoria registrado.', type: 'success');
            $this->changed();
        }
    }

    public function startSigning(): void
    {
        $this->signerName = $this->order->customer->is_izi_fleet ? '' : $this->order->customer->name;
        $this->signerDocument = '';
        $this->signing = true;
    }

    public function sign(?string $dataUrl, InspectionDelivery $delivery): void
    {
        $done = $this->attempt(fn () => $delivery->sign(
            $this->order,
            $this->signerName,
            $this->signerDocument,
            (string) $dataUrl,
            auth()->user(),
            request()->ip(),
            request()->userAgent(),
        ), 'signerName');

        if ($done) {
            $this->signing = false;
            $this->dispatch('toast', message: 'Vistoria assinada.', type: 'success');
            $this->changed();
        }
    }
};
?>

@php
    $user = auth()->user();
    $yesNoButton = fn (bool $active) => 'min-h-11 px-4 rounded-lg border text-sm font-semibold '.($active ? 'bg-brand-600 border-brand-600 text-white' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800');
@endphp

<div class="space-y-4" x-data="photoCapture">
    @if ($order->stage === Stage::Aberta)
        <div class="{{ $cardClass }} p-4 text-sm text-gray-600 dark:text-gray-300">A vistoria começa quando o atendimento toca em <strong>Iniciar vistoria</strong>.</div>
    @else
        @if ($order->stage !== Stage::EmVistoria)
            <p class="text-sm text-gray-500"><i class="fas fa-lock"></i> Vistoria de entrada registrada. Para corrigir, o gestor volta a OS para "Em vistoria".</p>
        @endif

        {{-- PARTE A --}}
        <section class="{{ $cardClass }} p-4 space-y-5" data-test="parte-a">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Parte A · Atendimento</h2>
                @if ($this->missingA === [])
                    <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check"></i> Completa</span>
                @else
                    <span class="text-sm text-gray-500">Falta {{ count($this->missingA) }}</span>
                @endif
            </div>

            {{-- Fotos guiadas --}}
            <div id="fotos">
                <p class="{{ $labelClass }}">Fotos ({{ count($this->photos) }} de {{ count(EntryInspection::PHOTOS) }})</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
                    @foreach (EntryInspection::PHOTOS as $position => $label)
                        @php($photo = $this->photos[$position] ?? null)
                        <div id="foto-{{ $position }}" class="rounded-lg border {{ $photo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600' }} overflow-hidden" wire:key="slot-{{ $position }}">
                            @if ($photo)
                                <a href="{{ $photo->url() }}" target="_blank" class="block aspect-[4/3] bg-gray-100 dark:bg-gray-900"><img src="{{ $photo->url('mini') }}" alt="{{ $label }}" class="w-full h-full object-cover" loading="lazy"></a>
                            @else
                                <div class="aspect-[4/3] flex items-center justify-center bg-gray-50 dark:bg-gray-900 text-gray-400"><i class="fas fa-camera text-2xl"></i></div>
                            @endif
                            <div class="p-2 space-y-1">
                                <p class="text-xs leading-tight min-h-8">{{ $label }}</p>
                                @if ($photo?->is_stale) <p class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">Foto antiga</p> @endif
                                @if ($this->editableA)
                                    <label class="{{ $photo ? $btnSecondary : $btnPrimary }} w-full text-sm cursor-pointer" data-test="camera-{{ $position }}">
                                        <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, 'entrada:{{ $position }}')">
                                        <span x-show="busy !== 'entrada:{{ $position }}'"><i class="fas fa-camera"></i> {{ $photo ? 'Refazer' : 'Tirar foto' }}</span>
                                        <span x-show="busy === 'entrada:{{ $position }}'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                                    </label>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Painel --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div id="km">
                    <label class="{{ $labelClass }}" for="km-input">Km no painel</label>
                    <input id="km-input" type="text" inputmode="numeric" wire:model.live.blur="km" @disabled(! $this->editableA) placeholder="87.512" class="{{ $inputClass }} text-xl" data-test="km">
                </div>
                <div id="combustivel">
                    <p class="{{ $labelClass }}">Combustível</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (EntryInspection::FUEL as $level => $label)
                            <button type="button" wire:click="setFuel('{{ $level }}')" @disabled(! $this->editableA) class="{{ $yesNoButton($fuel === $level) }}" data-test="combustivel-{{ $loop->index }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div id="luzes" class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="lights">Luzes acesas no painel</label>
                    <div class="flex gap-2">
                        <input id="lights" type="text" wire:model.live.blur="lights" @disabled(! $this->editableA) maxlength="255" placeholder="Ex.: injeção, ABS, óleo" class="{{ $inputClass }}" data-test="luzes">
                        @if ($this->editableA) <button type="button" wire:click="quick('dashboard_lights', 'Nenhuma')" class="{{ $btnSecondary }} shrink-0">Nenhuma</button> @endif
                    </div>
                </div>
                <div id="objetos" class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="belongings">Objetos e acessórios deixados no carro</label>
                    <div class="flex gap-2">
                        <textarea id="belongings" wire:model.live.blur="belongings" @disabled(! $this->editableA) rows="2" placeholder="Ex.: cadeirinha, som removível, documentos no porta-luvas" class="{{ $inputClass }}" data-test="objetos"></textarea>
                        @if ($this->editableA) <button type="button" wire:click="quick('belongings', 'Nenhum')" class="{{ $btnSecondary }} shrink-0 self-start">Nenhum</button> @endif
                    </div>
                </div>
            </div>

            <div id="acessorios">
                <p class="{{ $labelClass }}">No carro</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (EntryInspection::ACCESSORIES as $field => $label)
                        <div class="flex items-center gap-2" wire:key="acc-{{ $field }}">
                            <span class="flex-1 text-sm">{{ $label }}</span>
                            <button type="button" wire:click="setFlag('{{ $field }}', true)" @disabled(! $this->editableA) class="{{ $yesNoButton($accessories[$field] === true) }}" data-test="{{ $field }}-sim">Sim</button>
                            <button type="button" wire:click="setFlag('{{ $field }}', false)" @disabled(! $this->editableA) class="{{ $yesNoButton($accessories[$field] === false) }}" data-test="{{ $field }}-nao">Não</button>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Avarias --}}
            <div id="avarias" class="space-y-2">
                <p class="{{ $labelClass }}">Avarias</p>
                @if ($this->damages->isEmpty())
                    <button type="button" wire:click="setFlag('no_visible_damage', {{ $order->no_visible_damage ? 'false' : 'true' }})" @disabled(! $this->editableA) class="{{ $yesNoButton((bool) $order->no_visible_damage) }}" data-test="sem-avarias">
                        <i class="fas {{ $order->no_visible_damage ? 'fa-square-check' : 'fa-square' }}"></i> Sem avarias aparentes
                    </button>
                @endif
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($this->damages as $damage)
                        <div class="flex gap-3 rounded-lg border border-gray-200 dark:border-gray-700 p-2" wire:key="dmg-{{ $damage->id }}">
                            @if ($damage->media)
                                <img src="{{ $damage->media->url('mini') }}" alt="{{ $damage->location }}" class="size-20 object-cover rounded" loading="lazy">
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-sm">{{ $damage->location }}</p>
                                <p class="text-xs text-gray-500">{{ count($damage->markList()) }} marca(s){{ count($damage->markList()) ? ': '.collect($damage->markList())->pluck('type')->implode(', ') : '' }}</p>
                                @if ($this->editableA)
                                    <div class="mt-1 flex gap-2">
                                        <button type="button" wire:click="$set('markingDamage', {{ $damage->id }})" class="text-sm text-brand-600 font-semibold min-h-9">Marcar na foto</button>
                                        <button type="button" wire:click="removeDamage({{ $damage->id }})" wire:confirm="Tirar esta avaria da vistoria? A foto fica guardada." class="text-sm text-red-600 min-h-9">Remover</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($this->editableA)
                    <div class="flex flex-wrap gap-2">
                        <select wire:model.live="damageLocation" class="{{ $inputClass }} sm:w-72" data-test="local-avaria">
                            <option value="">Local da avaria…</option>
                            @foreach (Damage::LOCATIONS as $location)
                                <option value="{{ $location }}">{{ $location }}</option>
                            @endforeach
                        </select>
                        @if ($damageLocation !== '')
                            <label class="{{ $btnPrimary }} cursor-pointer" data-test="camera-avaria">
                                <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, 'avaria:{{ $damageLocation }}')">
                                <span x-show="! (busy && busy.startsWith('avaria:'))"><i class="fas fa-camera"></i> Fotografar avaria</span>
                                <span x-show="busy && busy.startsWith('avaria:')" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                            </label>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Autorizações e quem aprova --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div id="test-drive">
                    <p class="{{ $labelClass }}">Autoriza test drive?</p>
                    <div class="flex gap-2">
                        <button type="button" wire:click="setFlag('test_drive_authorized', true)" @disabled(! $this->editableA) class="{{ $yesNoButton($testDrive === true) }}">Sim</button>
                        <button type="button" wire:click="setFlag('test_drive_authorized', false)" @disabled(! $this->editableA) class="{{ $yesNoButton($testDrive === false) }}">Não</button>
                    </div>
                </div>
                <div id="diagnostico-cobrado">
                    <p class="{{ $labelClass }}">Diagnóstico cobrado?</p>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button type="button" wire:click="setFlag('diagnosis_charged', true)" @disabled(! $this->editableA) class="{{ $yesNoButton($diagnosisCharged === true) }}">Sim</button>
                        <button type="button" wire:click="setFlag('diagnosis_charged', false)" @disabled(! $this->editableA) class="{{ $yesNoButton($diagnosisCharged === false) }}">Não</button>
                        @if ($diagnosisCharged && $user->can('atendimento'))
                            <div class="relative w-36" x-data="moneyInput($wire.entangle('diagnosisFee'))">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">R$</span>
                                <input type="text" inputmode="decimal" x-on:input="onInput($event)" x-on:focus="onFocus()" x-on:blur="onBlur($event); $wire.set('diagnosisFee', cents)" x-bind:value="text" @disabled(! $this->editableA) class="{{ $inputClass }} pl-9" data-test="valor-diagnostico">
                            </div>
                        @endif
                    </div>
                </div>
                <div id="aprovador" class="sm:col-span-2">
                    <p class="{{ $labelClass }}">Quem aprova o orçamento</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (EntryInspection::APPROVERS as $kind => $label)
                            @if ($kind !== 'izi' || $order->vehicle->is_izi_fleet)
                                <button type="button" wire:click="setApprover('{{ $kind }}')" @disabled(! $this->editableA) class="{{ $yesNoButton($approverKind === $kind) }}">{{ $label }}</button>
                            @endif
                        @endforeach
                    </div>
                    @if ($approverKind === 'outro')
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <input type="text" wire:model.live.blur="approverName" @disabled(! $this->editableA) placeholder="Nome de quem aprova" class="{{ $inputClass }}">
                            <input type="tel" wire:model.live.blur="approverWhatsapp" @disabled(! $this->editableA) placeholder="WhatsApp com DDD" class="{{ $inputClass }}">
                        </div>
                    @elseif ($approverKind === 'cliente')
                        <p class="mt-1 text-sm text-gray-500">{{ $order->customer->name }} · {{ $order->customer->whatsappFormatted() }}</p>
                    @elseif ($approverKind === 'izi')
                        <p class="mt-1 text-sm text-gray-500">Até {{ \App\Support\Money::brl((int) Settings::int('izi_approval_limit_cents')) }} o atendimento aprova; acima, o gestor.</p>
                    @endif
                </div>
            </div>
        </section>

        {{-- Entrega ao cliente --}}
        <section id="entrega" class="{{ $cardClass }} p-4 space-y-3" data-test="entrega">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Vistoria para o cliente</h2>
            @php($signature = $this->delivery['signature'])
            @if ($this->delivery['delivered'])
                <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check"></i>
                    @if ($order->inspection_sent_at && (! $signature || $order->inspection_sent_at->gt($signature->signed_at)))
                        Enviada pelo WhatsApp em {{ $order->inspection_sent_at->format('d/m/Y H:i') }}.
                    @else
                        Assinada na tela por {{ $signature->signer_name }} em {{ $signature->signed_at->format('d/m/Y H:i') }}.
                    @endif
                </p>
            @elseif ($this->delivery['changed'])
                <p class="text-sm font-semibold text-amber-700 dark:text-amber-300"><i class="fas fa-triangle-exclamation"></i> A vistoria mudou depois do envio ou da assinatura: envie de novo.</p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">Quando a parte A estiver completa, envie pelo WhatsApp (cliente ausente) ou peça a assinatura na tela (cliente presente).</p>
            @endif

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('orders.inspection.pdf', $order) }}" target="_blank" class="{{ $btnSecondary }}"><i class="fas fa-file-pdf"></i> Ver PDF</a>
                @if ($order->inspection_pdf_path)
                    <a href="{{ route('orders.inspection.pdf', ['order' => $order, 'enviado' => 1]) }}" target="_blank" class="{{ $btnSecondary }}"><i class="fas fa-paper-plane"></i> PDF enviado</a>
                @endif
            </div>

            @if ($this->editableA && $this->missingA === [])
                <div class="flex flex-wrap gap-2" x-data="shareDocument">
                    <button type="button" x-show="! file" x-on:click="prepare()" x-bind:disabled="busy" class="{{ $btnPrimary }}" data-test="preparar-envio">
                        <i class="fab fa-whatsapp"></i> <span x-text="busy ? 'Preparando o PDF…' : @js($this->delivery['delivered'] ? 'Enviar de novo pelo WhatsApp' : 'Enviar pelo WhatsApp')"></span>
                    </button>
                    <button type="button" x-show="file" x-cloak x-on:click="send()" class="{{ $btnPrimary }}" data-test="enviar">
                        <i class="fas fa-share-nodes"></i> PDF pronto: escolher o contato
                    </button>
                    <button type="button" wire:click="startSigning" class="{{ $btnSecondary }}" data-test="assinar"><i class="fas fa-signature"></i> Cliente presente: assinar na tela</button>
                </div>
                <p class="text-xs text-gray-500">Mandar para: {{ Phone::format(app(InspectionDelivery::class)->recipientPhone($order)) ?: 'sem WhatsApp cadastrado' }}.</p>
            @endif

            @if ($askSentConfirmation)
                <div class="rounded-lg bg-amber-50 dark:bg-amber-900/30 p-3 text-sm space-y-2">
                    <p>O PDF foi baixado e o WhatsApp abriu com a mensagem. Anexe o PDF na conversa e envie.</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="confirmSent('manual')" class="{{ $btnPrimary }}" data-test="confirmar-envio">Já enviei</button>
                        <button type="button" wire:click="$set('askSentConfirmation', false)" class="{{ $btnSecondary }}">Ainda não</button>
                    </div>
                </div>
            @endif
        </section>

        {{-- PARTE B --}}
        <section id="parte-b" class="{{ $cardClass }} p-4 space-y-4" data-test="parte-b">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Parte B · Mecânico</h2>
                @if ($this->editableB && $this->items->contains(fn ($i) => $i->state === null && ! $i->is_fluid))
                    <button type="button" wire:click="markRemainingOk" class="{{ $btnSecondary }} text-sm" data-test="restantes-ok"><i class="fas fa-check-double"></i> O resto está em funcionamento</button>
                @endif
            </div>
            @foreach ($this->items->groupBy('section') as $section => $items)
                <div class="space-y-2" wire:key="sec-{{ $section }}">
                    <p class="{{ $labelClass }}">{{ $section }}</p>
                    @foreach ($items as $item)
                        <div id="item-{{ $item->id }}" @class(['rounded-lg border p-3 space-y-2', 'border-red-300 dark:border-red-800' => $item->isNonConformity(), 'border-gray-200 dark:border-gray-700' => ! $item->isNonConformity()]) wire:key="item-{{ $item->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="flex-1 min-w-[10rem] font-semibold text-sm">{{ $item->label }}</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($item->options() as $state => $label)
                                        <button type="button" wire:click="answer({{ $item->id }}, '{{ $state }}')" @disabled(! $this->editableB)
                                            @class([
                                                'min-h-11 px-3 rounded-lg border text-sm font-semibold',
                                                'bg-emerald-600 border-emerald-600 text-white' => $item->state === $state && in_array($state, ['ok', 'ideal'], true),
                                                'bg-amber-500 border-amber-500 text-white' => $item->state === $state && $state === 'media',
                                                'bg-red-600 border-red-600 text-white' => $item->state === $state && in_array($state, ['troca', 'baixo'], true),
                                                'bg-gray-600 border-gray-600 text-white' => $item->state === $state && $state === 'na',
                                                'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800' => $item->state !== $state,
                                            ]) data-test="item-{{ $item->id }}-{{ $state }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @if ($item->isNonConformity())
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($item->media)
                                        <a href="{{ $item->media->url() }}" target="_blank"><img src="{{ $item->media->url('mini') }}" alt="" class="size-16 object-cover rounded"></a>
                                    @endif
                                    @if ($this->editableB)
                                        <label class="{{ $item->media ? $btnSecondary : $btnPrimary }} cursor-pointer text-sm">
                                            <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, 'item:{{ $item->id }}')">
                                            <span x-show="busy !== 'item:{{ $item->id }}'"><i class="fas fa-camera"></i> {{ $item->media ? 'Refazer foto' : 'Foto (obrigatória)' }}</span>
                                            <span x-show="busy === 'item:{{ $item->id }}'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                                        </label>
                                    @endif
                                    <input type="text" value="{{ $item->detail }}" wire:change="saveDetail({{ $item->id }}, $event.target.value)" @disabled(! $this->editableB) maxlength="255"
                                        placeholder="{{ $item->requires_detail ? 'Qual? (obrigatório)' : 'Detalhe (opcional)' }}" class="{{ $inputClass }} sm:flex-1">
                                </div>
                            @endif
                            @if ($item->answered_by)
                                <p class="text-[11px] text-gray-400">{{ $item->answerer?->short_name }} · {{ $item->answered_at?->format('d/m H:i') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif

    {{-- Marcar avarias na foto --}}
    @if ($markingDamage && ($markDamage = $this->damages->firstWhere('id', $markingDamage)))
        <x-modal close="$set('markingDamage', null)" size="3xl" scroll padding="tight" data-test="janela-marcas">
            <div x-data="damageMarker(@js($markDamage->markList()), @js(Damage::TYPES))" class="space-y-3">
                <h2 class="font-display font-bold text-xl uppercase">{{ $markDamage->location }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Toque na foto em cima de cada avaria. Depois escolha o tipo de cada marca.</p>
                <div class="relative select-none">
                    <img x-ref="img" src="{{ $markDamage->media->url() }}" alt="" class="w-full rounded" x-on:click="place($event)">
                    <template x-for="(mark, i) in marks" :key="i">
                        <span class="absolute -translate-x-1/2 -translate-y-1/2 size-7 rounded-full bg-stripe text-white text-sm font-bold flex items-center justify-center ring-2 ring-white pointer-events-none" :style="`left: ${mark.x * 100}%; top: ${mark.y * 100}%`" x-text="i + 1"></span>
                    </template>
                </div>
                <template x-for="(mark, i) in marks" :key="'f' + i">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="size-7 shrink-0 rounded-full bg-stripe text-white text-sm font-bold flex items-center justify-center" x-text="i + 1"></span>
                        <select x-model="mark.type" class="{{ $inputClass }} w-36">
                            <template x-for="t in types" :key="t"><option :value="t" x-text="t"></option></template>
                        </select>
                        <input type="text" x-model="mark.desc" maxlength="120" placeholder="Descrição (opcional)" class="{{ $inputClass }} flex-1 min-w-[8rem]">
                        <button type="button" x-on:click="remove(i)" class="min-h-11 px-3 text-red-600"><i class="fas fa-trash"></i></button>
                    </div>
                </template>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('markingDamage', null)" class="{{ $btnSecondary }}">Fechar</button>
                    <button type="button" x-on:click="$wire.saveMarks({{ $markDamage->id }}, marks)" class="{{ $btnPrimary }}" data-test="salvar-marcas"><i class="fas fa-check"></i> Salvar marcas</button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- Assinatura na tela --}}
    @if ($signing)
        <x-modal close="$set('signing', false)" size="lg" scroll data-test="janela-assinatura">
            <div x-data="signaturePad" class="space-y-3">
                <h2 class="font-display font-bold text-xl uppercase">Assinatura da entrada</h2>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-xs whitespace-pre-line text-gray-700 dark:text-gray-200 max-h-40 overflow-y-auto">{{ Settings::text('entry') }}</div>
                <div>
                    <label class="{{ $labelClass }}" for="signerName">Nome de quem assina</label>
                    <input id="signerName" type="text" wire:model="signerName" maxlength="120" class="{{ $inputClass }}" data-test="nome-assinante">
                    @error('signerName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="signerDocument">CPF (opcional na entrada)</label>
                    <x-document-input id="signerDocument" model="signerDocument" />
                </div>
                <div class="relative rounded-lg bg-white border-2 border-dashed border-gray-300 overflow-hidden">
                    <canvas x-ref="canvas" class="w-full block" style="height: 180px; touch-action: none;"
                        x-on:pointerdown.prevent="start($event)" x-on:pointermove.prevent="move($event)"
                        x-on:pointerup.prevent="end()" x-on:pointerleave="end()" x-on:pointercancel="end()"></canvas>
                    <span x-show="! dirty" class="absolute inset-x-0 bottom-3 text-center text-xs text-gray-400 pointer-events-none">Assine com o dedo</span>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" x-on:click="clear()" class="{{ $btnSecondary }}"><i class="fas fa-eraser"></i> Limpar</button>
                    <button type="button" wire:click="$set('signing', false)" class="{{ $btnSecondary }}">Cancelar</button>
                    <button type="button" x-on:click="$wire.sign(dataUrl())" class="{{ $btnPrimary }}" data-test="confirmar-assinatura"><i class="fas fa-check"></i> Confirmar</button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
