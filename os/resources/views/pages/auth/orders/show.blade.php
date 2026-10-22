<?php

use App\Enums\Role;
use App\Enums\Stage;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\StageGate;
use App\Services\Orders\StageMachine;
use App\Support\Phone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public ServiceOrder $order;

    /** Vídeo que acabou de subir ($wire.upload). */
    public $upload;

    /** Etapa que a tela está mostrando: vai junto em cada ação (duas pessoas ao mesmo tempo). */
    public string $loadedStage = '';

    #[Url(as: 'aba', except: 'resumo')]
    public string $tab = 'resumo';

    // Resumo (atendimento)
    public string $brandModel = '';

    public string $color = '';

    public string $year = '';

    public string $customerName = '';

    public string $customerWhatsapp = '';

    public string $entryDate = '';

    public string $expectedDate = '';

    // Problema
    public string $complaint = '';

    public string $diagnosis = '';

    // Janelas: passo que pede motivo, voltar etapa, cancelar
    public ?string $reasonFor = null;

    public string $reason = '';

    public string $backTo = '';

    public function mount(ServiceOrder $order): void
    {
        $this->order = $order;
        $this->fillFromOrder();

        // Na etapa da vistoria, a tela já abre nela.
        if ($order->stage === Stage::EmVistoria && ! request()->has('aba')) {
            $this->tab = 'vistoria';
        }
    }

    /** A vistoria (componente de dentro) mudou algo: atualiza o "Falta para avançar". */
    #[On('order-updated')]
    public function refreshOrder(): void
    {
        $this->fillFromOrder();
    }

    public function rendering($view): void
    {
        $view->title($this->order->label().' · '.($this->order->vehicle?->plate ?? ''));
    }

    private function fillFromOrder(): void
    {
        $this->order->refresh()->load(['vehicle', 'customer', 'responsible', 'unit']);
        $this->loadedStage = $this->order->stage->value;
        $this->brandModel = (string) $this->order->vehicle->brand_model;
        $this->color = (string) $this->order->vehicle->color;
        $this->year = (string) $this->order->vehicle->year;
        $this->customerName = (string) $this->order->customer->name;
        $this->customerWhatsapp = $this->order->customer->whatsappFormatted();
        $this->entryDate = (string) $this->order->entry_date?->toDateString();
        $this->expectedDate = (string) $this->order->expected_date?->toDateString();
        $this->complaint = (string) $this->order->complaint;
        $this->diagnosis = (string) $this->order->diagnosis;
        unset($this->targets, $this->missing, $this->transitions);
    }

    /** @return list<Stage> */
    #[Computed]
    public function targets(): array
    {
        return app(StageMachine::class)->targetsFor($this->order, auth()->user());
    }

    /** "Falta para avançar" de cada caminho que esta pessoa pode fazer. */
    #[Computed]
    public function missing(): array
    {
        return collect($this->targets)->mapWithKeys(fn (Stage $to) => [$to->value => app(StageGate::class)->missing($this->order, $to)])->all();
    }

    #[Computed]
    public function transitions()
    {
        return $this->order->transitions()->with('user')->get();
    }

    #[Computed]
    public function mechanics()
    {
        return User::query()->forPicker(Role::Mecanico)->get();
    }

    /** Passos que pedem o motivo antes (recusa e CORRIGIR). */
    private function needsReason(Stage $to): bool
    {
        return $to === Stage::Recusada || ($this->order->stage === Stage::EmConferencia && $to === Stage::EmExecucao);
    }

    public function advance(string $to, StageMachine $machine): void
    {
        $target = Stage::from($to);

        if ($this->needsReason($target) && $this->reasonFor !== $to) {
            $this->reasonFor = $to;
            $this->reason = '';

            return;
        }

        $done = $this->attempt(fn () => $machine->move($this->order, Stage::from($this->loadedStage), $target, auth()->user(), $this->needsReason($target) ? $this->reason : null));
        $this->afterAction($done, 'OS em "'.$target->label().'".');

        if ($done && $target === Stage::EmVistoria) {
            $this->tab = 'vistoria';
        }
    }

    public function openBack(): void
    {
        $this->reasonFor = 'voltar';
        $this->reason = '';
        $this->backTo = StageMachine::backTargets($this->order->stage) === [] ? '' : collect(StageMachine::backTargets($this->order->stage))->last()->value;
    }

    public function goBack(StageMachine $machine): void
    {
        $done = $this->attempt(fn () => $machine->back($this->order, Stage::from($this->loadedStage), Stage::from($this->backTo), auth()->user(), $this->reason));
        $this->afterAction($done, 'OS voltou para "'.Stage::from($this->backTo)->label().'".');
    }

    public function openCancel(): void
    {
        $this->reasonFor = 'cancelar';
        $this->reason = '';
    }

    public function cancelOrder(StageMachine $machine): void
    {
        $done = $this->attempt(fn () => $machine->cancel($this->order, Stage::from($this->loadedStage), auth()->user(), $this->reason));
        $this->afterAction($done, 'OS cancelada.');
    }

    public function closeReason(): void
    {
        $this->reasonFor = null;
    }

    private function afterAction(mixed $done, string $message): void
    {
        if ($done) {
            $this->reasonFor = null;
            $this->dispatch('toast', message: $message, type: 'success');
        }

        // Deu certo ou outra pessoa já tinha movido: a tela mostra a etapa de agora.
        $this->fillFromOrder();
    }

    public function saveDetails(OrderEditor $editor): void
    {
        $done = $this->attempt(fn () => $editor->details($this->order, [
            'brand_model' => $this->brandModel,
            'color' => $this->color,
            'year' => $this->year,
            'customer_name' => $this->customerName,
            'customer_whatsapp' => $this->customerWhatsapp,
            'entry_date' => $this->entryDate,
            'expected_date' => $this->expectedDate ?: null,
        ], auth()->user()));

        if ($done) {
            $this->dispatch('toast', message: 'Dados salvos.', type: 'success');
            $this->fillFromOrder();
        }
    }

    public function saveProblem(OrderEditor $editor): void
    {
        $user = auth()->user();
        $done = $this->attempt(fn () => $editor->problem(
            $this->order,
            $user->can('atendimento') ? $this->complaint : null,
            $user->can('oficina') ? $this->diagnosis : null,
            $user,
        ));

        if ($done) {
            $this->dispatch('toast', message: 'Salvo.', type: 'success');
            $this->fillFromOrder();
        }
    }

    #[Computed]
    public function videos()
    {
        return $this->order->media()->where('kind', \App\Enums\MediaKind::Video->value)->with('user')->get();
    }

    /** Vídeo do diagnóstico (mecânico): até 20 MB, com o hash conferido. */
    public function saveVideo(?string $hash, ?int $takenAt, \App\Services\Media\MediaStore $store): void
    {
        if (! $this->upload) {
            return;
        }

        $this->validate(['upload' => 'file|max:20480'], ['upload.max' => 'O vídeo passa de 20 MB.']);
        $user = auth()->user();

        $done = $this->attempt(function () use ($hash, $takenAt, $store, $user) {
            if (! $user->can('oficina') || ! in_array($this->order->stage, [Stage::EmDiagnostico, Stage::EmExecucao], true)) {
                throw new DomainException('Vídeo é do mecânico, no diagnóstico ou na execução.');
            }

            return $store->storeVideo($this->order, $this->upload, $hash, $takenAt, $user, 'Diagnóstico');
        });

        $this->reset('upload');

        if ($done) {
            unset($this->videos);
            $this->dispatch('toast', message: 'Vídeo guardado.', type: 'success');
        }
    }

    public function setResponsible(mixed $userId, OrderEditor $editor): void
    {
        $userId = (int) $userId ?: null;

        if ($this->attempt(fn () => $editor->responsible($this->order, $userId, auth()->user()))) {
            $this->dispatch('toast', message: 'Responsável atualizado.', type: 'success');
            $this->fillFromOrder();
        }
    }
};
?>

@php
    $user = auth()->user();
    $editable = ! $order->stage->isFinal();
    $tabs = array_filter([
        'resumo' => 'Resumo',
        'vistoria' => 'Vistoria',
        'problema' => 'Diagnóstico',
        'itens' => 'Peças e serviços',
        'cotacao' => $user->can('atendimento') ? 'Cotação' : null,
        'historico' => 'Histórico',
    ]);
@endphp

<div class="space-y-4">
    <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Quadro</a>

    {{-- Cabeçalho: placa, número, etapa, carro e cliente. --}}
    <div class="{{ $cardClass }} p-4">
        <div class="flex flex-wrap items-start gap-4">
            <x-plate :plate="$order->vehicle->plate" size="lg" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide leading-none">{{ $order->label() }}</h1>
                    <x-stage-badge :stage="$order->stage" data-test="etapa" />
                    @if ($order->vehicle->is_izi_fleet)
                        <span class="rounded bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide">Frota Izi</span>
                    @endif
                </div>
                <p class="mt-1 text-gray-700 dark:text-gray-200">{{ $order->vehicle->description() ?: 'Modelo não informado' }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $order->customer->name }}
                    @can('atendimento')
                        @if ($link = Phone::whatsappLink($order->customer->whatsapp))
                            · <a href="{{ $link }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline"><i class="fab fa-whatsapp"></i> {{ $order->customer->whatsappFormatted() }}</a>
                        @endif
                    @endcan
                    @if ($order->vehicle->investor_name) · investidor {{ $order->vehicle->investor_name }} @endif
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Entrada {{ $order->entry_date?->format('d/m/Y') }}
                    @if ($order->expected_date) · previsão {{ $order->expected_date->format('d/m/Y') }} @endif
                    · {{ $order->unit->name }}
                </p>
            </div>
        </div>
    </div>

    {{-- Próximo passo: botões do que esta pessoa pode fazer e o "Falta para avançar" de cada um. --}}
    @if ($editable)
        <div class="{{ $cardClass }} p-4 space-y-3" data-test="proximo-passo">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Próximo passo</h2>
                @if ($user->isGestor() && StageMachine::backTargets($order->stage) !== [])
                    <button type="button" wire:click="openBack" class="{{ $btnSecondary }}" data-test="voltar-etapa"><i class="fas fa-rotate-left"></i> Voltar etapa</button>
                @endif
                @can('atendimento')
                    <button type="button" wire:click="openCancel" class="{{ $btnSecondary }} text-red-700 dark:text-red-300" data-test="cancelar-os"><i class="fas fa-ban"></i> Cancelar OS</button>
                @endcan
            </div>

            @forelse ($this->targets as $target)
                @php($missing = $this->missing[$target->value] ?? [])
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3" wire:key="t-{{ $target->value }}">
                    <button type="button" wire:click="advance('{{ $target->value }}')" @disabled($missing !== []) class="{{ $btnPrimary }} w-full sm:w-auto" data-test="avancar-{{ $target->value }}">
                        <i class="fas fa-arrow-right"></i> {{ StageMachine::actionLabel($order->stage, $target) }}
                    </button>
                    @if ($missing !== [])
                        <p class="mt-2 text-sm font-semibold text-gray-700 dark:text-gray-200">Falta para avançar:</p>
                        <ul class="mt-1 space-y-1 text-sm" data-test="falta">
                            @foreach ($missing as $item)
                                <li><button type="button" wire:click="$set('tab', '{{ $item['tab'] }}')" x-on:click="setTimeout(() => document.getElementById('{{ $item['field'] }}')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 400)" class="text-left text-red-700 dark:text-red-300 hover:underline"><i class="fas fa-circle-exclamation"></i> {{ $item['label'] }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-300">Nada para você nesta etapa. Quem faz o próximo passo vê nos <a href="{{ route('inbox') }}" wire:navigate class="text-brand-600 hover:underline">avisos</a>.</p>
            @endforelse
        </div>
    @elseif ($order->stage === Stage::Cancelada)
        <div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 text-sm">
            <strong>Cancelada</strong> em {{ $order->cancelled_at?->format('d/m/Y H:i') }}: {{ $order->cancel_reason }}
        </div>
    @else
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-4 text-sm">
            <strong>Entregue</strong> em {{ $order->delivered_at?->format('d/m/Y H:i') }}. Defeito depois da entrega vira uma OS nova de retorno em garantia.
        </div>
    @endif

    {{-- Abas --}}
    {{-- No celular as abas rolam de lado; a aba aberta fica à vista. --}}
    <div class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700" role="tablist" x-data x-init="const a = $el.querySelector('[aria-selected=true]'); if (a && a.offsetLeft + a.offsetWidth > $el.clientWidth) $el.scrollLeft = a.offsetLeft - 16">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['px-4 min-h-11 font-display font-semibold uppercase tracking-wide border-b-[3px] whitespace-nowrap', 'border-brand-600 text-brand-700 dark:text-brand-300' => $tab === $key, 'border-transparent text-gray-500' => $tab !== $key]) data-test="aba-{{ $key }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'resumo')
        <form wire:submit="saveDetails" class="{{ $cardClass }} p-4 space-y-4">
            @php($canEdit = $editable && $user->can('atendimento'))
            <fieldset @disabled(! $canEdit) class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <label class="{{ $labelClass }}" for="brandModel">Marca e modelo</label>
                        <input id="brandModel" type="text" wire:model="brandModel" maxlength="80" class="{{ $inputClass }}">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="color">Cor</label>
                        <input id="color" type="text" wire:model="color" maxlength="30" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="year">Ano</label>
                        <input id="year" type="text" wire:model="year" maxlength="9" class="{{ $inputClass }}">
                    </div>
                </div>
                @unless ($order->customer->is_izi_fleet)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}" for="customerName">Cliente</label>
                            <input id="customerName" type="text" wire:model="customerName" maxlength="120" class="{{ $inputClass }}">
                        </div>
                        @can('atendimento')
                            <div>
                                <label class="{{ $labelClass }}" for="customerWhatsapp">WhatsApp</label>
                                <input id="customerWhatsapp" type="tel" wire:model="customerWhatsapp" class="{{ $inputClass }}">
                            </div>
                        @endcan
                    </div>
                @endunless
                <div class="grid gap-4 grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="entryDate">Entrada</label>
                        <input id="entryDate" type="date" wire:model="entryDate" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="expectedDate">Previsão de entrega</label>
                        <input id="expectedDate" type="date" wire:model="expectedDate" class="{{ $inputClass }}">
                    </div>
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}"><i class="fas fa-check"></i> Salvar</button></div>
            @endif

            <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                <p class="{{ $labelClass }}">Responsável pelo conserto</p>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold">{{ $order->responsible?->short_name ?? 'Ninguém ainda' }}</span>
                    @if ($editable)
                        @can('atendimento')
                            <select wire:change="setResponsible($event.target.value)" class="{{ $inputClass }} sm:w-56" data-test="responsavel">
                                <option value="">Escolher mecânico…</option>
                                @foreach ($this->mechanics as $mechanic)
                                    <option value="{{ $mechanic->id }}" @selected($order->responsible_id === $mechanic->id)>{{ $mechanic->short_name }}</option>
                                @endforeach
                            </select>
                        @elsecan('oficina')
                            @if ($order->responsible_id !== $user->id)
                                <button type="button" wire:click="setResponsible({{ $user->id }})" class="{{ $btnSecondary }}" data-test="assumir"><i class="fas fa-hand"></i> Assumir</button>
                            @endif
                        @endcan
                    @endif
                </div>
            </div>
        </form>
    @elseif ($tab === 'vistoria')
        <livewire:pages::auth.orders.inspection :order="$order" :key="'vistoria-'.$order->id.'-'.$order->stage->value" />
    @elseif ($tab === 'itens')
        <livewire:pages::auth.orders.items :order="$order" :key="'itens-'.$order->id.'-'.$order->stage->value" />
    @elseif ($tab === 'cotacao' && $user->can('atendimento'))
        <livewire:pages::auth.orders.quote :order="$order" :key="'cotacao-'.$order->id.'-'.$order->stage->value" />
    @elseif ($tab === 'problema')
        <form wire:submit="saveProblem" class="{{ $cardClass }} p-4 space-y-4">
            <div>
                <label class="{{ $labelClass }}" for="complaint">Queixa (nas palavras do cliente)</label>
                <textarea id="complaint" wire:model="complaint" rows="3" @disabled(! $editable || ! $user->can('atendimento')) class="{{ $inputClass }}"></textarea>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="diagnosis">Diagnóstico (em linguagem de cliente)</label>
                <textarea id="diagnosis" wire:model="diagnosis" rows="4" @disabled(! $editable || ! $user->can('oficina')) placeholder="O que foi encontrado e por que precisa trocar ou consertar." class="{{ $inputClass }}" data-test="diagnostico"></textarea>
            </div>
            @if ($editable)
                <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}" data-test="salvar-problema"><i class="fas fa-check"></i> Salvar</button></div>
            @endif
        </form>

        <section class="{{ $cardClass }} p-4 space-y-3" x-data="photoCapture" data-test="videos">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Vídeos</h2>
                @if ($user->can('oficina') && in_array($order->stage, [Stage::EmDiagnostico, Stage::EmExecucao], true))
                    <label class="{{ $btnSecondary }} cursor-pointer" data-test="camera-video">
                        <input type="file" accept="video/*" capture="environment" class="sr-only" x-on:change="takeVideo($event)">
                        <span x-show="busy !== 'video'"><i class="fas fa-video"></i> Gravar vídeo (até 20 MB)</span>
                        <span x-show="busy === 'video'" x-cloak><i class="fas fa-spinner fa-spin"></i> Enviando <span x-text="progress + '%'"></span></span>
                    </label>
                @endif
            </div>
            @forelse ($this->videos as $video)
                <div class="space-y-1" wire:key="v-{{ $video->id }}">
                    <div class="relative overflow-hidden rounded-lg bg-black">
                        <video controls playsinline preload="metadata" src="{{ $video->url() }}" class="w-full max-h-[60vh]"></video>
                        {{-- OS, placa e hora do servidor por cima (o arquivo não é alterado). --}}
                        <div class="pointer-events-none absolute left-2 top-2 rounded bg-black/70 px-2 py-1 font-mono text-[11px] text-white">{{ $order->label() }} · {{ $order->vehicle->plateFormatted() }} · {{ $video->received_at->format('d/m/Y H:i:s') }}</div>
                    </div>
                    <p class="text-xs text-gray-500">{{ $video->user?->short_name }} · {{ $video->size < 1048576 ? max(1, round($video->size / 1024)).' KB' : number_format($video->size / 1048576, 1, ',', '.').' MB' }} · <a href="{{ $video->url('original') }}" class="text-brand-600 hover:underline">baixar</a> · SHA-256 {{ substr($video->original_sha256, 0, 12) }}…</p>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum vídeo. Vídeo gravado no iPhone pode não tocar no computador: use "baixar".</p>
            @endforelse
        </section>
    @else
        <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="historico">
            @foreach ($this->transitions as $transition)
                <div class="p-3 text-sm flex flex-wrap gap-x-3 gap-y-1" wire:key="h-{{ $transition->id }}">
                    <span class="text-gray-500 w-32 shrink-0">{{ $transition->created_at?->format('d/m/Y H:i') }}</span>
                    <span class="flex-1 min-w-0">
                        @if ($transition->from_stage)
                            {{ $transition->from_stage->label() }} <i class="fas fa-arrow-right text-xs text-gray-400"></i>
                        @else
                            Aberta
                        @endif
                        @if ($transition->from_stage) <strong>{{ $transition->to_stage->label() }}</strong> @endif
                        @if ($transition->isBackward()) <span class="text-amber-700 dark:text-amber-300">(voltou)</span> @endif
                        @if ($transition->reason) <span class="block text-gray-600 dark:text-gray-300">Motivo: {{ $transition->reason }}</span> @endif
                    </span>
                    <span class="text-gray-500">{{ $transition->user?->short_name ?? 'sistema' }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Janelas que pedem motivo --}}
    @if ($reasonFor === 'voltar')
        <x-modal close="closeReason" form="goBack" data-test="janela-voltar">
            <h2 class="font-display font-bold text-xl uppercase">Voltar etapa</h2>
            <div>
                <label class="{{ $labelClass }}" for="backTo">Para</label>
                <select id="backTo" wire:model="backTo" class="{{ $inputClass }}">
                    @foreach (StageMachine::backTargets($order->stage) as $back)
                        <option value="{{ $back->value }}">{{ $back->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="reason">Motivo</label>
                <textarea id="reason" wire:model="reason" rows="3" class="{{ $inputClass }}" data-test="motivo"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="closeReason" class="{{ $btnSecondary }}">Desistir</button>
                <button type="submit" class="{{ $btnPrimary }}">Voltar a OS</button>
            </div>
        </x-modal>
    @elseif ($reasonFor === 'cancelar')
        <x-modal close="closeReason" form="cancelOrder" data-test="janela-cancelar">
            <h2 class="font-display font-bold text-xl uppercase">Cancelar {{ $order->label() }}</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">A OS fica no histórico como cancelada. Não dá para desfazer.</p>
            <div>
                <label class="{{ $labelClass }}" for="reason">Motivo</label>
                <textarea id="reason" wire:model="reason" rows="3" class="{{ $inputClass }}" data-test="motivo"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="closeReason" class="{{ $btnSecondary }}">Desistir</button>
                <button type="submit" class="inline-flex items-center justify-center gap-2 min-h-11 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold">Cancelar a OS</button>
            </div>
        </x-modal>
    @elseif ($reasonFor !== null)
        <x-modal close="closeReason" form="advance('{{ $reasonFor }}')" data-test="janela-motivo">
            <h2 class="font-display font-bold text-xl uppercase">{{ StageMachine::actionLabel($order->stage, Stage::from($reasonFor)) }}</h2>
            <div>
                <label class="{{ $labelClass }}" for="reason">{{ $reasonFor === 'recusada' ? 'Motivo da recusa' : 'O que precisa ser corrigido' }}</label>
                <textarea id="reason" wire:model="reason" rows="3" class="{{ $inputClass }}" data-test="motivo"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="closeReason" class="{{ $btnSecondary }}">Desistir</button>
                <button type="submit" class="{{ $btnPrimary }}">Confirmar</button>
            </div>
        </x-modal>
    @endif
</div>
