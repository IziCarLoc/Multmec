<?php

use App\Enums\DueWindow;
use App\Livewire\Concerns\FinanceAccess;
use App\Services\Finance\FinanceSummary;
use App\Services\Finance\Month;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Painel do Financeiro: vencimentos (cartões que abrem a lista filtrada), o que está em aberto, o movimento do mês
 * e a tabela "Por mês". Pago, recebido e prejuízo contam na data em que aconteceram, não no vencimento.
 * Lucro só para o gestor.
 */
new #[Title('Financeiro')] class extends Component
{
    use FinanceAccess;

    /** Mês do "Movimento" (AAAA-MM); vazio = mês corrente. */
    #[Url(as: 'mes', except: '')]
    public string $month = '';

    public function selectMonth(string $month): void
    {
        $this->month = Month::isValid($month) && $month !== Month::current() ? $month : '';
    }

    public function shiftMonth(int $months): void
    {
        $this->selectMonth(Month::shift($this->selected(), $months > 0 ? 1 : -1));
    }

    public function selected(): string
    {
        return Month::isValid($this->month) ? $this->month : Month::current();
    }

    #[Computed]
    public function payableDeadlines(): array
    {
        return app(FinanceSummary::class)->payableDeadlines();
    }

    #[Computed]
    public function receivableDeadlines(): array
    {
        return app(FinanceSummary::class)->receivableDeadlines();
    }

    #[Computed]
    public function openTotals(): array
    {
        return app(FinanceSummary::class)->openTotals();
    }

    /** Linhas do "Por mês"; para o atendimento o lucro nem sai do servidor. */
    #[Computed]
    public function months(): array
    {
        $rows = app(FinanceSummary::class)->months();

        return $this->canSeeProfit() ? $rows : array_map(fn (array $row) => array_diff_key($row, ['profit' => true]), $rows);
    }

    #[Computed]
    public function movement(): array
    {
        $month = $this->selected();

        foreach ($this->months as $row) {
            if ($row['month'] === $month) {
                return $row;
            }
        }

        return ['month' => $month, 'paid' => 0, 'received' => 0, 'loss' => 0, 'profit' => 0, 'matrix' => 0, 'balance' => 0];
    }
};
?>

@php
    $showProfit = auth()->user()->can('gestor');
    $totals = $this->openTotals;
    $movement = $this->movement;
    $selected = $this->selected();
    $sum = fn (string $key) => array_sum(array_column($this->months, $key));
    $small = 'inline-flex items-center justify-center min-h-11 min-w-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700';
@endphp
<div class="space-y-5">
    <x-finance-nav />

    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>Financeiro
    </h1>

    <section class="space-y-2" data-test="vencimentos">
        <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Boletos a pagar</h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
            @foreach (DueWindow::cases() as $case)
                @php($d = $this->payableDeadlines[$case->value])
                <x-finance-tile :label="$case->label()" :value="$money($d['cents'])" :note="$d['count'].' boleto(s)'" :tone="$case === DueWindow::Vencidos && $d['count'] > 0 ? 'bad' : 'plain'" :href="route('finance.payables', ['prazo' => $case->value])" wire:key="pg-{{ $case->value }}" data-test="painel-pagar-{{ $case->value }}" />
            @endforeach
        </div>
        <h2 class="pt-2 font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">A receber</h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
            @foreach (DueWindow::cases() as $case)
                @php($d = $this->receivableDeadlines[$case->value])
                <x-finance-tile :label="$case->label()" :value="$money($d['cents'])" :note="$d['count'].' lançamento(s)'" :tone="$case === DueWindow::Vencidos && $d['count'] > 0 ? 'bad' : 'plain'" :href="route('finance.receivables', ['prazo' => $case->value])" wire:key="rc-{{ $case->value }}" data-test="painel-receber-{{ $case->value }}" />
            @endforeach
        </div>
    </section>

    <section class="space-y-2">
        <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Em aberto agora</h2>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-2">
            <x-finance-tile label="A pagar (boletos)" :value="$money($totals['payables'])" note="a fornecedores" :href="route('finance.payables')" data-test="total-a-pagar" />
            <x-finance-tile label="Prestadores" :value="$money($totals['providers'])" note="a pagar por OS realizada" :href="route('finance.providers')" />
            <x-finance-tile label="A receber" :value="$money($totals['receivables'])" note="serviços ainda não pagos" tone="brand" :href="route('finance.receivables')" data-test="total-a-receber" />
            <x-finance-tile label="Inadimplência" :value="$money($totals['delinquency'])" note="em aberto de quem tem vencido" :tone="$totals['delinquency'] > 0 ? 'bad' : 'plain'" :href="route('finance.delinquents')" data-test="total-inadimplencia" />
            <x-finance-tile label="A receber pela matriz" :value="$money($totals['matrix'])" note="registro — não soma no saldo" data-test="total-matriz" />
            <x-finance-tile label="Saldo projetado" :value="$money($totals['projected'])" note="a receber − a pagar − prestadores" :tone="$totals['projected'] < 0 ? 'bad' : 'brand'" data-test="saldo-projetado" />
        </div>
    </section>

    <section class="space-y-2" data-test="movimento">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-auto">Movimento de <span data-test="mes-movimento">{{ Month::label($selected) }}</span></h2>
            <button type="button" wire:click="shiftMonth(-1)" class="{{ $small }}" aria-label="Mês anterior"><i class="fas fa-chevron-left"></i></button>
            <button type="button" wire:click="shiftMonth(1)" class="{{ $small }}" aria-label="Próximo mês"><i class="fas fa-chevron-right"></i></button>
            @if ($selected !== Month::current())
                <button type="button" wire:click="selectMonth('')" class="{{ $small }}">Mês atual</button>
            @endif
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-2">
            <x-finance-tile label="Pago" :value="$money($movement['paid'])" note="boletos e prestadores pagos no mês" data-test="mov-pago" />
            <x-finance-tile label="Recebido" :value="$money($movement['received'])" note="pagamentos de clientes no mês" tone="brand" data-test="mov-recebido" />
            <x-finance-tile label="Prejuízo" :value="$money($movement['loss'])" note="lançado no mês" :tone="$movement['loss'] > 0 ? 'bad' : 'plain'" data-test="mov-prejuizo" />
            @if ($showProfit)
                <x-finance-tile label="Lucro bruto" :value="$money($movement['profit'])" note="do que já foi recebido" :tone="$movement['profit'] < 0 ? 'bad' : 'brand'" data-test="mov-lucro" />
            @endif
            <x-finance-tile label="Receita matriz" :value="$money($movement['matrix'])" note="registro — não soma no saldo" data-test="mov-matriz" />
            <x-finance-tile label="Saldo do mês" :value="$money($movement['balance'])" note="recebido − pago − prejuízo" :tone="$movement['balance'] < 0 ? 'bad' : 'brand'" data-test="mov-saldo" />
        </div>
    </section>

    <section class="space-y-2">
        <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Por mês</h2>
        {{-- Celular: um mês por linha (saldo em destaque); a tabela completa a partir de 640 px. --}}
        <div class="sm:hidden {{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700" data-test="lista-por-mes">
            @foreach ($this->months as $row)
                <button type="button" wire:click="selectMonth('{{ $row['month'] }}')" wire:key="mm-{{ $row['month'] }}" @class(['w-full text-left p-3 min-h-11 hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-brand-50 dark:bg-brand-900/30' => $row['month'] === $selected])>
                    <span class="flex items-baseline gap-2">
                        <span class="font-semibold">{{ Month::label($row['month']) }}</span>
                        <span @class(['ml-auto font-display font-bold text-lg', 'text-red-700 dark:text-red-400' => $row['balance'] < 0])>{{ $money($row['balance']) }}</span>
                    </span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        Pago {{ $money($row['paid']) }} · Recebido {{ $money($row['received']) }} · Prejuízo {{ $money($row['loss']) }} @if ($showProfit) · Lucro bruto {{ $money($row['profit']) }} @endif @if ($row['matrix'] > 0) · Matriz {{ $money($row['matrix']) }} @endif
                    </span>
                </button>
            @endforeach
            <p class="p-3 flex items-baseline gap-2 font-semibold bg-gray-50 dark:bg-gray-900/40">
                Total <span @class(['ml-auto font-display font-bold text-lg', 'text-red-700 dark:text-red-400' => $sum('balance') < 0])>{{ $money($sum('balance')) }}</span>
            </p>
        </div>
        <div class="hidden sm:block {{ $cardClass }} overflow-x-auto">
            <table class="w-full text-sm" data-test="tabela-por-mes">
                <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="text-left p-3">Mês</th>
                        <th class="text-right p-3">Pago</th>
                        <th class="text-right p-3">Recebido</th>
                        <th class="text-right p-3">Prejuízo</th>
                        @if ($showProfit) <th class="text-right p-3">Lucro bruto</th> @endif
                        <th class="text-right p-3">Receita matriz</th>
                        <th class="text-right p-3">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->months as $row)
                        <tr wire:click="selectMonth('{{ $row['month'] }}')" wire:key="m-{{ $row['month'] }}" @class(['cursor-pointer border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-brand-50 dark:bg-brand-900/30' => $row['month'] === $selected]) data-test="linha-{{ $row['month'] }}">
                            <td class="p-3 font-semibold whitespace-nowrap">{{ Month::label($row['month']) }}</td>
                            <td class="p-3 text-right whitespace-nowrap">{{ $money($row['paid']) }}</td>
                            <td class="p-3 text-right whitespace-nowrap">{{ $money($row['received']) }}</td>
                            <td class="p-3 text-right whitespace-nowrap">{{ $money($row['loss']) }}</td>
                            @if ($showProfit) <td @class(['p-3 text-right whitespace-nowrap', 'text-red-700 dark:text-red-400' => $row['profit'] < 0])>{{ $money($row['profit']) }}</td> @endif
                            <td class="p-3 text-right whitespace-nowrap text-gray-500">{{ $money($row['matrix']) }}</td>
                            <td @class(['p-3 text-right whitespace-nowrap font-semibold', 'text-red-700 dark:text-red-400' => $row['balance'] < 0])>{{ $money($row['balance']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold bg-gray-50 dark:bg-gray-900/40">
                        <td class="p-3">Total</td>
                        <td class="p-3 text-right whitespace-nowrap">{{ $money($sum('paid')) }}</td>
                        <td class="p-3 text-right whitespace-nowrap">{{ $money($sum('received')) }}</td>
                        <td class="p-3 text-right whitespace-nowrap">{{ $money($sum('loss')) }}</td>
                        @if ($showProfit) <td class="p-3 text-right whitespace-nowrap">{{ $money($sum('profit')) }}</td> @endif
                        <td class="p-3 text-right whitespace-nowrap text-gray-500">{{ $money($sum('matrix')) }}</td>
                        <td @class(['p-3 text-right whitespace-nowrap', 'text-red-700 dark:text-red-400' => $sum('balance') < 0])>{{ $money($sum('balance')) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Pago, recebido e prejuízo consideram a data em que o boleto foi pago, o recebimento confirmado ou o prejuízo lançado — não a data de vencimento.
            "Receita matriz" é só um registro dos pagamentos de lançamentos marcados como "recebimento pela matriz" — fica visível aqui, mas não entra {{ $showProfit ? 'no saldo, no lucro nem no "a receber"' : 'no saldo nem no "a receber"' }}.
            Toque em um mês da tabela para ver os totais dele acima.
        </p>
    </section>
</div>
