<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Models\Payable;
use App\Services\Finance\PayablesByMonth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Boletos por mês (o "Por mês" do artefato): a tabela pelo mês de vencimento, de emissão ou de pagamento; tocar num
 * mês mostra os boletos dele por fornecedor, e cada boleto abre no "A pagar".
 */
new #[Title('Boletos por mês')] class extends Component
{
    use FinanceAccess;

    #[Url(as: 'base', except: 'vencimento')]
    public string $base = 'vencimento';

    /** Mês aberto no detalhe (AAAA-MM, ou "sem-data"); vazio = nenhum. */
    #[Url(as: 'mes', except: '')]
    public string $selected = '';

    public function mount(): void
    {
        $this->base = PayablesByMonth::base($this->base);
    }

    public function updatedBase(): void
    {
        $this->base = PayablesByMonth::base($this->base);
        $this->selected = '';
        unset($this->table, $this->detail, $this->summary);
    }

    public function select(string $month): void
    {
        $this->selected = $this->selected === $month ? '' : $month;
        unset($this->detail);
    }

    #[Computed]
    public function table(): array
    {
        return app(PayablesByMonth::class)->table($this->base);
    }

    /** O mês escolhido por fornecedor (null = nenhum mês escolhido ou mês sem boletos nesta base). */
    #[Computed]
    public function detail(): ?array
    {
        $key = $this->selected === 'sem-data' ? '' : $this->selected;
        $row = collect($this->table['rows'])->firstWhere('month', $key);

        if ($this->selected === '' || ! $row) {
            return null;
        }

        return ['row' => $row, 'suppliers' => app(PayablesByMonth::class)->detail($this->base, $key)];
    }

    #[Computed]
    public function summary(): string
    {
        return app(PayablesByMonth::class)->summary($this->base);
    }
};
?>

@php
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $rows = $this->table['rows'];
    $total = $this->table['total'];
    $key = fn (array $row) => $row['month'] === '' ? 'sem-data' : $row['month'];
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Boletos por mês
        </h1>
        <div class="ml-auto flex flex-wrap gap-2" x-data="{ copied: false }">
            <button type="button" x-on:click="navigator.clipboard.writeText(@js($this->summary)); copied = true; setTimeout(() => copied = false, 2000)" class="{{ $btnSecondary }}" @disabled($rows === []) data-test="copiar-resumo"><i class="fas fa-copy"></i> <span x-text="copied ? 'Resumo copiado' : 'Copiar resumo'"></span></button>
        </div>
    </div>

    <select wire:model.live="base" class="{{ $inputClass }} sm:w-72" aria-label="Agrupar os boletos" data-test="base">
        @foreach (PayablesByMonth::BASES as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>

    @if ($rows === [])
        <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300" data-test="sem-boletos">
            {{ Payable::query()->exists() ? 'Nenhum boleto pago ainda.' : 'Nenhum boleto lançado. Os boletos entram pelo "A pagar".' }}
        </p>
    @else
        {{-- Celular: um mês por linha (total em destaque); a tabela completa a partir de 640 px. --}}
        <div class="sm:hidden {{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="lista-meses">
            @foreach ($rows as $row)
                <button type="button" wire:click="select('{{ $key($row) }}')" wire:key="mm-{{ $key($row) }}" @class(['w-full text-left p-3 min-h-11 hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-brand-50 dark:bg-brand-900/30' => $selected === $key($row)])>
                    <span class="flex items-baseline gap-2">
                        <span class="font-semibold">{{ $row['label'] }}</span>
                        <span class="ml-auto font-display font-bold text-lg">{{ $money($row['total']) }}</span>
                    </span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['count'] }} boleto(s) · em aberto {{ $money($row['open']) }} · pagos {{ $money($row['paid']) }}</span>
                </button>
            @endforeach
            <p class="p-3 flex items-baseline gap-2 font-semibold bg-gray-50 dark:bg-gray-900/40">
                Total · {{ $total['count'] }} <span class="ml-auto font-display font-bold text-lg">{{ $money($total['total']) }}</span>
            </p>
        </div>
        <div class="hidden sm:block {{ $cardClass }} overflow-x-auto">
            <table class="w-full text-sm" data-test="tabela-meses">
                <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="text-left p-3">Mês</th>
                        <th class="text-right p-3">Boletos</th>
                        <th class="text-right p-3">Em aberto</th>
                        <th class="text-right p-3">Pagos</th>
                        <th class="text-right p-3">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:click="select('{{ $key($row) }}')" wire:key="m-{{ $key($row) }}" @class(['cursor-pointer border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-brand-50 dark:bg-brand-900/30' => $selected === $key($row)]) data-test="linha-{{ $key($row) }}">
                            <td class="p-3 font-semibold whitespace-nowrap">{{ $row['label'] }}</td>
                            <td class="p-3 text-right">{{ $row['count'] }}</td>
                            <td class="p-3 text-right whitespace-nowrap">{{ $money($row['open']) }}</td>
                            <td class="p-3 text-right whitespace-nowrap">{{ $money($row['paid']) }}</td>
                            <td class="p-3 text-right whitespace-nowrap font-semibold">{{ $money($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold bg-gray-50 dark:bg-gray-900/40" data-test="linha-total">
                        <td class="p-3">Total</td>
                        <td class="p-3 text-right">{{ $total['count'] }}</td>
                        <td class="p-3 text-right whitespace-nowrap">{{ $money($total['open']) }}</td>
                        <td class="p-3 text-right whitespace-nowrap">{{ $money($total['paid']) }}</td>
                        <td class="p-3 text-right whitespace-nowrap">{{ $money($total['total']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($detail = $this->detail)
            <section class="space-y-3" data-test="detalhe-mes">
                <h2 class="flex items-baseline gap-2 font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ $detail['row']['label'] }} por fornecedor <span class="ml-auto font-sans text-gray-700 dark:text-gray-200">{{ $money($detail['row']['total']) }}</span>
                </h2>
                @foreach ($detail['suppliers'] as $name => $group)
                    <div class="space-y-1" wire:key="f-{{ md5($name) }}">
                        <h3 class="flex items-baseline gap-2 text-sm font-semibold">
                            <span class="break-words">{{ $name }}</span> <span class="ml-auto">{{ $money($group['cents']) }}</span>
                        </h3>
                        <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($group['items'] as $payable)
                                @php($days = (int) now()->startOfDay()->diffInDays($payable->due_date, false))
                                <a href="{{ route('finance.payables', ['abrir' => $payable->id]) }}" wire:navigate wire:key="b-{{ $payable->id }}" class="p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40 {{ $payable->isOverdue() ? 'border-l-4 border-l-stripe' : '' }}" data-test="boleto-{{ $payable->id }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-1">
                                            <span class="break-words">{{ collect([$payable->document_number, $payable->description])->filter()->implode(' · ') ?: 'boleto' }}</span>
                                            @if ($payable->installmentLabel()) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">parcela {{ $payable->installmentLabel() }}</span> @endif
                                            @if ($payable->isPaid())
                                                <span class="{{ $pill }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200"><i class="fas fa-check mr-1"></i>pago {{ $payable->paid_on?->format('d/m/Y') }}</span>
                                            @elseif ($days < 0)
                                                <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">vencido há {{ -$days }} d</span>
                                            @elseif ($days === 0)
                                                <span class="{{ $pill }} bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">vence hoje</span>
                                            @elseif ($days <= 7)
                                                <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">em {{ $days }} d</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-gray-500">emis. {{ $payable->issue_date?->format('d/m/Y') ?? '—' }} · venc. {{ $payable->due_date->format('d/m/Y') }}</p>
                                    </div>
                                    <p class="font-display font-bold text-lg">{{ $money($payable->amount_cents) }}</p>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">Toque em um mês para ver os boletos dele por fornecedor.</p>
        @endif
    @endif
</div>
