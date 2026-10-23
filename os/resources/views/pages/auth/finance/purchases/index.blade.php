<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\BankSlip;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\Occurrences;
use App\Services\Purchases\PurchaseSettings;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Conferência de compras: tudo o que precisa de atenção (doc 09 §3), da ocorrência mais grave para a menos. O
 * atendimento vê e resolve o que é de lançar; aceitar ("Está certo, conferi") é do gestor, com o motivo.
 */
new #[Title('Conferência de compras')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    /** Severidades com a lista inteira aberta (o resto mostra as 40 primeiras). */
    public array $showAll = [];

    public ?string $acceptKey = null;

    public string $acceptReason = '';

    #[Computed]
    public function occurrences(): array
    {
        return app(Occurrences::class)->all();
    }

    #[Computed]
    public function summary(): array
    {
        return app(Occurrences::class)->summary($this->occurrences);
    }

    #[Computed]
    public function numbers(): array
    {
        $today = CarbonImmutable::today();

        return [
            'open_count' => BankSlip::query()->where('status', BankSlip::OPEN)->count(),
            'open_cents' => (int) BankSlip::query()->where('status', BankSlip::OPEN)->sum('amount_cents'),
            'week_count' => BankSlip::query()->where('status', BankSlip::OPEN)->where('due_date', '<=', $today->addDays(7)->toDateString())->count(),
            'week_cents' => (int) BankSlip::query()->where('status', BankSlip::OPEN)->where('due_date', '<=', $today->addDays(7)->toDateString())->sum('amount_cents'),
            'month_count' => PurchaseInvoice::query()->chargeable()->whereBetween('issue_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->count(),
            'month_cents' => (int) PurchaseInvoice::query()->chargeable()->whereBetween('issue_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->sum('total_cents'),
            'linked_to_orders' => (int) PurchaseAllocation::query()->where('destination', 'os')->sum('cost_cents'),
            'empty' => ! PurchaseInvoice::query()->exists() && ! BankSlip::query()->exists(),
            'workshop' => PurchaseSettings::workshopCnpjs() !== [],
        ];
    }

    #[Computed]
    public function current(): ?array
    {
        return $this->acceptKey ? collect($this->occurrences)->firstWhere('key', $this->acceptKey) : null;
    }

    public function startAccept(string $key): void
    {
        $this->acceptKey = $key;
        $this->acceptReason = '';
        $this->resetErrorBag();
    }

    public function closeAccept(): void
    {
        $this->acceptKey = null;
    }

    public function accept(Occurrences $occurrences): void
    {
        $this->resetErrorBag();
        if ($this->attempt(fn () => $occurrences->accept((string) $this->acceptKey, $this->acceptReason, auth()->user()), 'acceptReason')) {
            $this->acceptKey = null;
            $this->refresh();
            $this->dispatch('toast', message: 'Conferido: o motivo ficou no histórico.', type: 'success');
        }
    }

    public function undoAccept(string $key, Occurrences $occurrences): void
    {
        if ($this->attempt(fn () => $occurrences->undoAcceptance($key, auth()->user()))) {
            $this->refresh();
            $this->dispatch('toast', message: 'Aceite desfeito: a ocorrência voltou para a lista.', type: 'success');
        }
    }

    public function expand(string $severity): void
    {
        $this->showAll[$severity] = true;
    }

    private function refresh(): void
    {
        unset($this->occurrences, $this->summary, $this->current, $this->numbers);
    }
};
?>

@php
    $gestor = auth()->user()->isGestor();
    $open = collect($this->occurrences)->where('accepted', null);
    $accepted = collect($this->occurrences)->filter(fn ($o) => $o['accepted']);
    $groups = ['alta' => 'Graves: resolva antes de pagar', 'media' => 'Para conferir', 'baixa' => 'Detalhes'];
    $link = fn (array $o) => match ($o['entity']) {
        'boleto' => [route('finance.purchases.slips', ['boleto' => $o['id']]), 'Abrir boleto'],
        'nota' => [route('finance.purchases.invoices', ['nota' => $o['id']]), 'Abrir nota'],
        'item' => [route('finance.purchases.invoices', ['nota' => $o['invoices'][0] ?? null]), 'Abrir nota'],
        'fornecedor' => [route('finance.purchases.suppliers'), 'Ver fornecedores'],
        'dda', 'dda_arquivo' => $gestor ? [route('finance.purchases.dda'), 'Abrir o DDA'] : null,
        'acerto' => $gestor ? [route('finance.purchases.group'), 'Abrir o acerto'] : null,
        'pagar' => [route('finance.payables'), 'Abrir o A pagar'],
        'config' => $gestor ? [route('settings'), 'Abrir Ajustes'] : null,
        default => null,
    };
@endphp
<div class="space-y-4">
    <x-finance-nav />
    <x-purchases-nav />

    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>Conferência de compras
    </h1>

    @if ($this->numbers['empty'])
        <section class="{{ $cardClass }} p-4 space-y-2" data-test="como-comecar">
            <h2 class="font-display font-bold text-xl uppercase">Como começar</h2>
            <ol class="list-decimal pl-5 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                <li>Em <b>Notas</b>, importe os XML que os fornecedores mandam (ou lance a nota sem XML, com a chave da DANFE).</li>
                <li>Em <b>Boletos</b>, cadastre cada boleto que chegar (cole a linha digitável ou o texto do boleto). O sistema procura a nota.</li>
                <li>Boleto sem nota fica GRAVE aqui e o pagamento é travado no A pagar até o gestor resolver.</li>
                <li>Em cada nota, diga em qual OS cada peça foi aplicada: assim o custo da OS vem da nota.</li>
            </ol>
        </section>
    @endif

    @unless ($this->numbers['workshop'])
        <p class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/30 dark:border-red-800 p-3 text-sm text-red-800 dark:text-red-200" data-test="sem-cnpj-oficina">
            <i class="fas fa-triangle-exclamation mr-1"></i>O CNPJ da oficina não está informado: o sistema não confere o destinatário das notas nem o pagador dos boletos.
            {{ $gestor ? 'Informe em Ajustes > Oficina e numeração.' : 'Peça ao gestor para informar em Ajustes.' }}
        </p>
    @endunless

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
        <x-finance-tile label="Graves" :value="$this->summary['alta']" note="resolver antes de pagar" :tone="$this->summary['alta'] ? 'bad' : 'plain'" data-test="cartao-graves" />
        <x-finance-tile label="Para conferir" :value="$this->summary['media']" note="na semana" />
        <x-finance-tile label="Boletos em aberto" :value="$money($this->numbers['open_cents'])" :note="$this->numbers['open_count'].' boleto(s)'" :href="route('finance.purchases.slips')" />
        <x-finance-tile label="Dinheiro em risco" :value="$money($this->summary['at_risk_cents'])" note="boletos com problema grave" :tone="$this->summary['at_risk_cents'] ? 'bad' : 'plain'" />
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Vencem em 7 dias: {{ $this->numbers['week_count'] }} boleto(s), {{ $money($this->numbers['week_cents']) }}.
        Notas do mês: {{ $this->numbers['month_count'] }}, {{ $money($this->numbers['month_cents']) }}.
        Custo de peças já ligado às OS pelas notas: {{ $money($this->numbers['linked_to_orders']) }}.
    </p>

    @foreach ($groups as $severity => $label)
        @php($list = $open->where('severity', $severity)->values())
        @continue($list->isEmpty())
        <section class="space-y-2" wire:key="sev-{{ $severity }}">
            <h2 class="font-display font-bold uppercase tracking-wide text-sm text-gray-500 dark:text-gray-400">{{ $label }} ({{ $list->count() }})</h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
                @foreach ($list->take(($showAll[$severity] ?? false) ? $list->count() : 40) as $o)
                    <x-purchase-occurrence :occurrence="$o" wire:key="oc-{{ md5($o['key']) }}">
                        @if ($target = $link($o))
                            <a href="{{ $target[0] }}" wire:navigate class="{{ $btnSecondary }} !min-h-9 text-sm">{{ $target[1] }}</a>
                        @endif
                        @if ($gestor)
                            <button type="button" wire:click="startAccept('{{ $o['key'] }}')" class="{{ $btnSecondary }} !min-h-9 text-sm" data-test="aceitar-{{ $o['type'] }}"><i class="fas fa-check"></i> Está certo, conferi</button>
                        @endif
                    </x-purchase-occurrence>
                @endforeach
            </div>
            @if ($list->count() > 40 && ! ($showAll[$severity] ?? false))
                <button type="button" wire:click="expand('{{ $severity }}')" class="{{ $btnSecondary }}">Mostrar as outras {{ $list->count() - 40 }}</button>
            @endif
        </section>
    @endforeach

    @if ($open->isEmpty())
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300" data-test="tudo-conferido"><i class="fas fa-check mr-1"></i>Tudo conferido: nenhuma ocorrência aberta.</p>
    @endif

    @if ($accepted->isNotEmpty())
        <details class="{{ $cardClass }} p-3">
            <summary class="cursor-pointer font-semibold min-h-11 flex items-center">Conferidas e aceitas ({{ $accepted->count() }})</summary>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($accepted->take(50) as $o)
                    <x-purchase-occurrence :occurrence="$o" wire:key="ac-{{ md5($o['key']) }}">
                        @if ($gestor)
                            <button type="button" wire:click="undoAccept('{{ $o['key'] }}')" wire:confirm="Desfazer o aceite? A ocorrência volta para a lista." class="{{ $btnSecondary }} !min-h-9 text-sm">Desfazer</button>
                        @endif
                    </x-purchase-occurrence>
                @endforeach
            </div>
        </details>
    @endif

    @if ($acceptKey && ($occurrence = $this->current))
        <x-modal close="closeAccept" form="accept" size="lg" data-test="janela-aceite">
            <h2 class="font-display font-bold text-xl uppercase">Está certo, conferi</h2>
            <p class="font-semibold">{{ $occurrence['title'] }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $occurrence['detail'] }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">O aceite vale só para este fato: se ele mudar (outro valor, outro boleto), a ocorrência volta. O motivo fica no histórico.</p>
            <div>
                <label class="{{ $labelClass }}" for="accept-reason">Motivo (pelo menos 10 letras)</label>
                <textarea id="accept-reason" wire:model="acceptReason" rows="3" class="{{ $inputClass }}" placeholder="Ex.: liguei no fornecedor (telefone do cadastro) e ele confirmou" data-test="motivo-aceite"></textarea>
                @error('acceptReason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeAccept" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}" data-test="confirmar-aceite"><i class="fas fa-check"></i> Aceitar</button>
            </div>
        </x-modal>
    @endif
</div>
