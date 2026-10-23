<?php

use App\Enums\MediaKind;
use App\Enums\PaymentMethod;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Consent;
use App\Models\Receivable;
use App\Models\ServiceOrder;
use App\Services\Budgets\Budgets;
use App\Services\Delivery\Delivery;
use App\Services\Finance\Credit;
use App\Services\Inspections\EntryInspection;
use App\Support\Settings;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Entrega (atendimento): fotos de saída ao lado das de entrada, km e combustível de saída, peças substituídas,
 * pagamento (vira o "A receber") e o termo de retirada assinado na tela. Nota fiscal e resumo para o sistema
 * fiscal (a nota não trava a OS).
 */
new class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public ServiceOrder $order;

    public $upload;

    public string $kmOut = '';

    public string $fuelOut = '';

    public string $replacedParts = '';

    // Pagamento
    public string $paymentMethod = 'pix';

    public int $installments = 1;

    /** @var list<string> */
    public array $dueDates = [];

    public bool $receivedNow = true;

    /** Gestor: motivo para liberar a prazo cliente travado, sem crédito ou acima do limite. */
    public string $creditRelease = '';

    // Termo
    public string $who = 'cliente';

    public string $signerName = '';

    public string $signerDocument = '';

    public string $authorization = '';

    // Nota
    public string $invoiceStatus = 'pendente';

    public string $invoiceService = '';

    public string $invoiceParts = '';

    public function mount(ServiceOrder $order): void
    {
        abort_unless(auth()->user()->can('atendimento'), 403);
        $this->order = $order;
        $this->kmOut = (string) ($order->km_out ?? $order->test_drive_km_after ?? '');
        $this->fuelOut = (string) $order->fuel_out;
        $this->replacedParts = (string) $order->replaced_parts;
        $this->dueDates = [today()->toDateString()];

        // Cliente com crédito e prazo: o vencimento já vem no prazo dele (a prazo, não pago na hora).
        if ($order->customer->hasCredit() && $order->customer->credit_days > 0) {
            $this->dueDates = [today()->addDays($order->customer->credit_days)->toDateString()];
            $this->receivedNow = false;
        }
        $this->signerName = $order->customer->is_izi_fleet ? '' : $order->customer->name;
        $this->signerDocument = (string) ($order->customer->is_izi_fleet ? '' : $order->customer->document);
        $this->invoiceStatus = (string) $order->invoice_status ?: 'pendente';
        $this->invoiceService = (string) $order->invoice_service_number;
        $this->invoiceParts = (string) $order->invoice_parts_number;
    }

    private function changed(): void
    {
        unset($this->exitPhotos, $this->missing);
        $this->order->refresh();
        $this->dispatch('order-updated');
    }

    #[Computed]
    public function entryPhotos()
    {
        return $this->order->media()->whereIn('kind', [MediaKind::Entrada->value, MediaKind::Painel->value])->whereNull('replaced_at')->get()->keyBy('position');
    }

    #[Computed]
    public function exitPhotos()
    {
        return $this->order->media()->where('kind', MediaKind::Saida->value)->whereNull('replaced_at')->get()->keyBy('position');
    }

    #[Computed]
    public function missing(): array
    {
        return app(Delivery::class)->missing($this->order);
    }

    #[Computed]
    public function pickup(): ?Consent
    {
        return $this->order->consents()->where('purpose', Consent::PURPOSE_PICKUP)->latest('id')->first();
    }

    /** A trava do crédito para o pagamento como está na tela (nulo = pode). */
    #[Computed]
    public function creditWarning(): ?string
    {
        $total = app(Delivery::class)->amounts($this->order)['total'];

        return app(Credit::class)->warning($this->order->customer, $total, $total > 0 && Credit::isOnCredit($this->dueDates, $this->receivedNow));
    }

    #[Computed]
    public function receivables()
    {
        return Receivable::query()->where('service_order_id', $this->order->id)->orderBy('due_date')->get();
    }

    public function ready(): bool
    {
        return $this->order->stage === Stage::ProntaRetirada;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['kmOut', 'fuelOut', 'replacedParts'], true)) {
            $field = ['kmOut' => 'km_out', 'fuelOut' => 'fuel_out', 'replacedParts' => 'replaced_parts'][$property];

            if ($this->attempt(fn () => app(Delivery::class)->update($this->order, [$field => $this->{$property}], auth()->user()), $property)) {
                $this->changed();
            }
        }

        if ($property === 'installments') {
            $this->installments = max(1, min(12, $this->installments));
            $first = $this->dueDates[0] ?? today()->toDateString();
            $this->dueDates = collect(range(0, $this->installments - 1))->map(fn ($i) => $this->dueDates[$i] ?? \Carbon\CarbonImmutable::parse($first)->addMonths($i)->toDateString())->all();
            $this->receivedNow = $this->installments === 1 && $this->receivedNow;
        }
    }

    public function savePhoto(string $slot, ?string $hash, ?int $takenAt, Delivery $delivery): void
    {
        if (! $this->upload) {
            return;
        }

        $this->validate(['upload' => 'image|max:15360'], ['upload.image' => 'O arquivo precisa ser uma foto.', 'upload.max' => 'A foto pode ter no máximo 15 MB.']);
        [, $position] = array_pad(explode(':', $slot, 2), 2, '');
        $done = $this->attempt(fn () => $delivery->storeExitPhoto($this->order, $position, $this->upload, $hash, $takenAt, auth()->user()));
        $this->reset('upload');

        if ($done) {
            $this->changed();
        }
    }

    public function deliver(?string $signature, Delivery $delivery): void
    {
        $done = $this->attempt(fn () => $delivery->deliver($this->order, [
            'who' => $this->who,
            'signer_name' => $this->signerName,
            'signer_document' => $this->signerDocument,
            'authorization' => $this->authorization,
            'signature' => $signature,
            'payment_method' => $this->paymentMethod,
            'due_dates' => $this->dueDates,
            'received_now' => $this->receivedNow,
            'credit_release' => $this->creditRelease,
        ], auth()->user(), request()->ip(), request()->userAgent()));

        if ($done) {
            $this->dispatch('toast', message: 'Entrega registrada. O valor foi lançado no A receber.', type: 'success');
            $this->changed();
            unset($this->pickup, $this->receivables);
        }
    }

    public function saveInvoice(Delivery $delivery): void
    {
        if ($this->attempt(fn () => $delivery->updateInvoice($this->order, $this->invoiceStatus, $this->invoiceService, $this->invoiceParts, auth()->user()))) {
            $this->dispatch('toast', message: 'Nota fiscal salva.', type: 'success');
            $this->order->refresh();
        }
    }
};
?>

@php
    $delivery = app(Delivery::class);
    $amounts = $delivery->amounts($order);
    $ready = $this->ready();
@endphp

<div class="space-y-4" x-data="photoCapture">
    @if ($order->stage === Stage::Entregue && $this->pickup)
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 dark:bg-emerald-900/30 p-4 text-sm space-y-1" data-test="entregue">
            <p class="font-semibold"><i class="fas fa-circle-check"></i> Entregue em {{ $order->delivered_at?->format('d/m/Y H:i') }} a {{ $this->pickup->signer_name }} ({{ $this->pickup->maskedDocument() }}{{ $this->pickup->signer_relation === 'autorizado por escrito' ? ', autorizado por escrito' : '' }}).</p>
            @if ($this->receivables->isNotEmpty())
                <p>A receber: {{ $this->receivables->count() > 1 ? $this->receivables->count().' parcelas, ' : '' }}{{ $money((int) $this->receivables->sum('total_cents')) }} · <a href="{{ route('finance.receivables') }}" class="text-brand-600 hover:underline">ver no Financeiro</a></p>
            @endif
        </div>
    @endif

    {{-- Fotos de saída ao lado das de entrada --}}
    <section class="{{ $cardClass }} p-4 space-y-3" data-test="fotos-saida">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Entrada × saída</h2>
        <div class="space-y-3">
            @foreach (EntryInspection::PHOTOS as $position => $label)
                @php($in = $this->entryPhotos[$position] ?? null)
                @php($out = $this->exitPhotos[$position] ?? null)
                <div id="saida-{{ $position }}" class="space-y-1" wire:key="s-{{ $position }}">
                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $label }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['Entrada' => $in, 'Saída' => $out] as $caption => $photo)
                            <div class="rounded-lg border {{ $photo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600' }} overflow-hidden">
                                @if ($photo)
                                    <a href="{{ $photo->url() }}" target="_blank" class="block aspect-[4/3] bg-gray-100 dark:bg-gray-900"><img src="{{ $photo->url('mini') }}" alt="{{ $caption }}" class="w-full h-full object-cover" loading="lazy"></a>
                                @else
                                    <div class="aspect-[4/3] flex items-center justify-center bg-gray-50 dark:bg-gray-900 text-gray-400 text-xs">{{ $caption === 'Entrada' ? 'sem foto' : '' }}<i class="fas fa-camera text-2xl {{ $caption === 'Entrada' ? 'hidden' : '' }}"></i></div>
                                @endif
                                <p class="px-2 py-1 text-[11px] text-gray-500">{{ $caption }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if ($ready)
                        <label class="{{ $out ? $btnSecondary : $btnPrimary }} w-full text-sm cursor-pointer" data-test="camera-saida-{{ $position }}">
                            <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="take($event, 'saida:{{ $position }}')">
                            <span x-show="busy !== 'saida:{{ $position }}'"><i class="fas fa-camera"></i> {{ $out ? 'Refazer a foto de saída' : 'Tirar a foto de saída' }}</span>
                            <span x-show="busy === 'saida:{{ $position }}'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando…</span>
                        </label>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Saída --}}
    <section class="{{ $cardClass }} p-4 space-y-3">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Saída</h2>
        <fieldset @disabled(! $ready) class="space-y-3">
            <div>
                <label class="{{ $labelClass }}" for="kmOut">Km de saída (entrada: {{ $order->km_in ? number_format($order->km_in, 0, ',', '.') : '—' }})</label>
                <input id="kmOut" type="text" inputmode="numeric" wire:model.live.blur="kmOut" class="{{ $inputClass }}" data-test="km-saida">
                @error('kmOut') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div id="fuelOut">
                <p class="{{ $labelClass }}">Combustível de saída (entrada: {{ EntryInspection::FUEL[$order->fuel_in] ?? '—' }})</p>
                <div class="grid grid-cols-5 gap-1">
                    @foreach (EntryInspection::FUEL as $value => $label)
                        <label>
                            <input type="radio" wire:model.live="fuelOut" value="{{ $value }}" class="peer sr-only">
                            <span class="flex min-h-11 items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-semibold peer-checked:bg-brand-600 peer-checked:border-brand-600 peer-checked:text-white cursor-pointer" data-test="combustivel-saida-{{ $loop->index }}">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($delivery->approvedItems($order)->contains(fn ($i) => $i->isPart()))
                <div id="replacedParts">
                    <p class="{{ $labelClass }}">Peças substituídas</p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach (Delivery::REPLACED_PARTS as $value => $label)
                            <label>
                                <input type="radio" wire:model.live="replacedParts" value="{{ $value }}" class="peer sr-only">
                                <span class="flex min-h-11 items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 px-2 text-center text-sm font-semibold peer-checked:bg-brand-600 peer-checked:border-brand-600 peer-checked:text-white cursor-pointer" data-test="pecas-{{ $value }}">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </fieldset>
    </section>

    @if ($ready)
        {{-- Pagamento --}}
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="pagamento-entrega">
            <div class="flex items-baseline gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Pagamento</h2>
                <span class="text-lg font-bold">{{ $money($amounts['total']) }}</span>
            </div>
            <p class="text-xs text-gray-500">{{ $amounts['parts'] > 0 ? 'Peças '.$money($amounts['parts']).' · ' : '' }}{{ $order->stage === Stage::ProntaRetirada && $delivery->approvedItems($order)->isEmpty() ? 'Diagnóstico (orçamento recusado)' : 'Mão de obra '.$money($amounts['labor']) }}. Vai para o A receber na entrega.</p>
            @if ($amounts['total'] > 0)
                <div class="grid gap-3 grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="paymentMethod">Forma</label>
                        <select id="paymentMethod" wire:model="paymentMethod" class="{{ $inputClass }}">
                            @foreach (PaymentMethod::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="installments">Parcelas</label>
                        <input id="installments" type="number" min="1" max="12" wire:model.live.blur="installments" class="{{ $inputClass }}" data-test="parcelas">
                    </div>
                </div>
                <div class="grid gap-2 grid-cols-2 sm:grid-cols-3">
                    @foreach ($dueDates as $i => $date)
                        <div wire:key="d-{{ $i }}">
                            <label class="{{ $labelClass }}" for="due-{{ $i }}">{{ $installments > 1 ? ($i + 1).'ª parcela' : 'Vencimento' }}</label>
                            <input id="due-{{ $i }}" type="date" wire:model.live="dueDates.{{ $i }}" class="{{ $inputClass }}">
                        </div>
                    @endforeach
                </div>
                @if ($installments === 1)
                    <label class="flex items-center gap-3 min-h-11"><input type="checkbox" wire:model.live="receivedNow" class="size-5 rounded border-gray-300 text-brand-600" data-test="pago-agora"> O cliente pagou agora</label>
                @endif
                @if ($order->customer->hasCredit())
                    <p class="text-xs text-gray-500">{{ $order->customer->name }}: crédito a prazo de {{ $order->customer->credit_days }} dia(s), limite {{ $money($order->customer->credit_limit_cents) }}.</p>
                @endif
                @if ($this->creditWarning)
                    <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 p-3 text-sm space-y-2" data-test="trava-credito">
                        <p class="font-semibold text-red-800 dark:text-red-200"><i class="fas fa-lock"></i> {{ $this->creditWarning }}</p>
                        @can('gestor')
                            <label class="{{ $labelClass }}" for="creditRelease">Motivo para liberar a prazo (fica na trilha de auditoria)</label>
                            <input id="creditRelease" type="text" wire:model="creditRelease" maxlength="180" placeholder="Ex.: combinou pagar na sexta" class="{{ $inputClass }}" data-test="motivo-liberacao">
                        @else
                            <p class="text-gray-700 dark:text-gray-200">Receba à vista (vencimento hoje e "O cliente pagou agora") ou peça ao gestor para liberar em Financeiro › Crédito.</p>
                        @endcan
                    </div>
                @endif
            @endif
        </section>

        {{-- Termo de retirada --}}
        <section id="termo" class="{{ $cardClass }} p-4 space-y-3" data-test="termo">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Termo de retirada</h2>
            @if ($this->missing !== [])
                <ul class="space-y-1 text-sm">
                    @foreach ($this->missing as $item)
                        <li class="text-red-700 dark:text-red-300"><i class="fas fa-circle-exclamation"></i> {{ $item['label'] }}</li>
                    @endforeach
                </ul>
            @else
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-xs whitespace-pre-line text-gray-700 dark:text-gray-200 max-h-40 overflow-y-auto">{{ Settings::text('pickup') }}

{{ app(Budgets::class)->warrantyText() }}</div>
                <div>
                    <p class="{{ $labelClass }}">Quem está retirando</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (Delivery::WHO as $value => $label)
                            <label>
                                <input type="radio" wire:model.live="who" value="{{ $value }}" class="peer sr-only">
                                <span class="flex min-h-11 items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 px-2 text-center text-sm font-semibold peer-checked:bg-brand-600 peer-checked:border-brand-600 peer-checked:text-white cursor-pointer" data-test="quem-{{ $value }}">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="signerName">Nome</label>
                        <input id="signerName" type="text" wire:model="signerName" maxlength="120" class="{{ $inputClass }}" data-test="nome-retirada">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="signerDocument">CPF ou CNPJ (documento conferido)</label>
                        <x-document-input id="signerDocument" model="signerDocument" />
                    </div>
                </div>
                @if ($who === 'autorizado')
                    <div>
                        <label class="{{ $labelClass }}" for="authorization">Autorização por escrito do cliente</label>
                        <textarea id="authorization" wire:model="authorization" rows="2" placeholder="Cole a mensagem do cliente autorizando, ou descreva o documento apresentado." class="{{ $inputClass }}" data-test="autorizacao"></textarea>
                    </div>
                @endif
                <div x-data="signaturePad" class="space-y-3" wire:key="pad-retirada">
                    <div class="relative rounded-lg bg-white border-2 border-dashed border-gray-300 overflow-hidden">
                        <canvas x-ref="canvas" class="w-full block" style="height: 180px; touch-action: none;"
                            x-on:pointerdown.prevent="start($event)" x-on:pointermove.prevent="move($event)"
                            x-on:pointerup.prevent="end()" x-on:pointerleave="end()" x-on:pointercancel="end()"></canvas>
                        <span x-show="! dirty" class="absolute inset-x-0 bottom-3 text-center text-xs text-gray-400 pointer-events-none">Assinatura de quem retira</span>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" x-on:click="clear()" class="{{ $btnSecondary }}"><i class="fas fa-eraser"></i> Limpar</button>
                        <button type="button" x-on:click="$wire.deliver(dataUrl())" class="{{ $btnPrimary }}" data-test="registrar-entrega"><i class="fas fa-key"></i> Registrar entrega</button>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- Nota fiscal (não trava a OS) --}}
    <section class="{{ $cardClass }} p-4 space-y-3" data-test="nota">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Nota fiscal</h2>
            <div x-data="{ copied: false }">
                <button type="button" x-on:click="navigator.clipboard.writeText(@js($delivery->fiscalSummary($order))); copied = true; setTimeout(() => copied = false, 2000)" class="{{ $btnSecondary }} text-sm" data-test="resumo-fiscal"><i class="fas fa-copy"></i> <span x-text="copied ? 'Copiado' : 'Copiar resumo para o sistema fiscal'"></span></button>
            </div>
        </div>
        <form wire:submit="saveInvoice" class="grid gap-3 sm:grid-cols-4 items-end">
            <div>
                <label class="{{ $labelClass }}" for="invoiceStatus">Situação</label>
                <select id="invoiceStatus" wire:model="invoiceStatus" class="{{ $inputClass }}">
                    <option value="pendente">Pendente</option>
                    <option value="emitida">Emitida</option>
                </select>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="invoiceService">Nº nota de serviço</label>
                <input id="invoiceService" type="text" wire:model="invoiceService" maxlength="30" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="invoiceParts">Nº nota de peças</label>
                <input id="invoiceParts" type="text" wire:model="invoiceParts" maxlength="30" class="{{ $inputClass }}">
            </div>
            <button type="submit" class="{{ $btnSecondary }}"><i class="fas fa-check"></i> Salvar</button>
        </form>
    </section>
</div>
