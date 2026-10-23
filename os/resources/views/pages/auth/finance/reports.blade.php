<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Models\Receivable;
use App\Services\Finance\Month;
use App\Services\Finance\MonthGoal;
use App\Services\Finance\Profit;
use App\Services\Finance\Reports;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Relatórios (a tela do financeiro em Node): avisos, próximos 7 dias, mês a mês, tamanho das OS, clientes que mais
 * faturam, por mecânico, orçamentos sem resposta e a planilha dos lançamentos. Lucro, margem e acréscimo sobre o
 * custo só para o gestor (o atendimento nem recebe esses números).
 */
new #[Title('Relatórios')] class extends Component
{
    use FinanceAccess;

    #[Url(as: 'de', except: '')]
    public string $from = '';

    #[Url(as: 'ate', except: '')]
    public string $to = '';

    public function mount(): void
    {
        [$this->from, $this->to] = $this->period();
    }

    /** @return array{0: string, 1: string} período (padrão: os últimos 6 meses até hoje) */
    public function period(): array
    {
        $valid = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $from = $valid($this->from) ? $this->from : Month::shift(Month::current(), -5).'-01';
        $to = $valid($this->to) ? $this->to : today()->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            unset($this->analysis);
        }
    }

    #[Computed]
    public function alerts(): array
    {
        return app(Reports::class)->alerts();
    }

    #[Computed]
    public function agenda(): array
    {
        return app(Reports::class)->agenda();
    }

    #[Computed]
    public function series(): array
    {
        $rows = array_reverse(app(MonthGoal::class)->series(Month::current(), 13));

        return $this->canSeeProfit() ? $rows : array_map(fn (array $m) => array_diff_key($m, array_flip(['gross', 'gross_pct', 'part_profit', 'costs', 'parts', 'freight', 'provider'])), $rows);
    }

    #[Computed]
    public function analysis(): array
    {
        [$from, $to] = $this->period();

        return app(Reports::class)->orders($from, $to, $this->canSeeProfit());
    }

    #[Computed]
    public function budgets()
    {
        return app(Reports::class)->pendingBudgets(null, 30);
    }

    /** Planilha dos serviços do período (CSV que abre no Excel). Lucro só para o gestor. */
    public function export()
    {
        [$from, $to] = $this->period();
        $withProfit = $this->canSeeProfit();
        $rows = Receivable::query()->counted()->serviceBetween($from, $to)->with('customer:id,name')->orderBy('service_date')->orderBy('id')->get();
        $profit = app(Profit::class);
        $providers = $profit->providerCosts($rows);
        $cell = function (mixed $value): string {
            $text = $value === null ? '' : (string) $value;
            $text = preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text; // fórmula não roda ao abrir no Excel

            return preg_match('/[",\n;]/', $text) === 1 ? '"'.str_replace('"', '""', $text).'"' : $text;
        };
        $reais = fn (int $cents) => number_format($cents / 100, 2, ',', '');
        $head = ['OS', 'data', 'cliente', 'placa', 'veiculo', 'total', 'mao_de_obra', 'custo_pecas', 'frete', 'prestador', ...($withProfit ? ['lucro_bruto'] : []), 'recebido', 'em_aberto', 'vencimento', 'situacao', 'saldo_anterior'];

        return response()->streamDownload(function () use ($rows, $providers, $profit, $withProfit, $cell, $reais, $head) {
            echo "\u{FEFF}".implode(',', $head)."\n";

            foreach ($rows as $r) {
                $parts = $profit->partsCostOf($r);
                $line = [
                    $r->order_number, $r->serviceDay()->format('d/m/Y'), $r->customer?->name, $r->plate, $r->vehicle_model,
                    $reais($r->total_cents), $reais($r->labor_cents), $reais($parts), $reais($r->freight_cents), $reais($providers[$r->id] ?? 0),
                    ...($withProfit ? [$reais($r->opening_balance ? 0 : $profit->gross($r->total_cents, $parts, $r->freight_cents, $providers[$r->id] ?? 0))] : []),
                    $reais($r->paid_cents), $reais($r->balanceCents()), $r->due_date->format('d/m/Y'), $r->status()->label(), $r->opening_balance ? 'sim' : '',
                ];
                echo implode(',', array_map($cell, $line))."\n";
            }
        }, 'servicos-'.$from.'-a-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
};
?>

@php
    $showProfit = auth()->user()->can('gestor');
    $a = $this->analysis;
    $ag = $this->agenda;
    [$from, $to] = $this->period();
    $pct = fn (?float $v, int $d = 0) => $v === null ? '—' : number_format($v * 100, $d, ',', '.').'%';
    $th = 'p-2 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $levels = ['critico' => 'border-l-stripe bg-red-50 dark:bg-red-900/20', 'aviso' => 'border-l-brand-600 bg-brand-50 dark:bg-brand-900/20', 'info' => 'border-l-gray-300 bg-white dark:bg-gray-800'];
@endphp
<div class="space-y-5">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2 mr-auto">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Relatórios
        </h1>
        <button type="button" wire:click="export" class="{{ $btnSecondary }}" data-test="baixar-csv"><i class="fas fa-file-csv"></i> Baixar serviços (CSV)</button>
    </div>

    @if ($this->alerts !== [])
        <section class="space-y-2" data-test="avisos">
            @foreach ($this->alerts as $alert)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 border-l-4 {{ $levels[$alert['level']] ?? $levels['info'] }} p-3 text-sm" wire:key="al-{{ $loop->index }}">
                    {{ $alert['text'] }}
                    @if (isset($alert['customer_id']))
                        <a href="{{ route('finance.credit') }}" wire:navigate class="text-brand-700 dark:text-brand-300 underline whitespace-nowrap">ver no Crédito</a>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    <section class="{{ $cardClass }} p-4 space-y-3" data-test="agenda">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Próximos 7 dias</h2>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ([['A pagar', $ag['pay'], $ag['pay_total'], 'Nada a pagar.'], ['A receber de clientes a prazo', $ag['receive'], $ag['receive_total'], 'Nada vencendo.']] as [$title, $items, $total, $empty])
                <div class="space-y-1 min-w-0" wire:key="ag-{{ $loop->index }}">
                    <h3 class="font-semibold flex items-baseline gap-2">{{ $title }} <span class="ml-auto">{{ $money($total) }}</span></h3>
                    @forelse (array_slice($items, 0, 8) as $item)
                        <p @class(['flex items-baseline gap-2 text-sm', 'text-red-700 dark:text-red-400' => $item['late']])>
                            <span class="w-12 shrink-0">{{ CarbonImmutable::parse($item['date'])->format('d/m') }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $item['description'] }}</span>
                            <span class="font-semibold whitespace-nowrap">{{ $money($item['cents']) }}</span>
                        </p>
                    @empty
                        <p class="text-sm text-gray-500">{{ $empty }}</p>
                    @endforelse
                    @if (count($items) > 8)
                        <p class="text-xs text-gray-500">+ {{ count($items) - 8 }} outro(s)</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="{{ $cardClass }} p-4 space-y-3" data-test="mes-a-mes">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Mês a mês</h2>
        <p class="text-xs text-gray-500">Faturou: serviços pela data da manutenção. Recebido e pago: pela data do pagamento.</p>
        <div class="sm:hidden divide-y divide-gray-100 dark:divide-gray-700 text-sm">
            @foreach ($this->series as $m)
                <div class="py-2" wire:key="mm-{{ $m['month'] }}">
                    <p class="flex items-baseline gap-2"><span class="font-semibold">{{ Month::label($m['month']) }}</span><span class="ml-auto font-display font-bold text-lg">{{ $money($m['revenue']) }}</span></p>
                    <p class="text-xs text-gray-500">{{ $m['orders'] }} OS · ticket {{ $money($m['ticket']) }} @if ($showProfit) · lucro bruto {{ $money($m['gross']) }} ({{ $pct($m['gross_pct']) }}) @endif · recebido {{ $money($m['received']) }} · pago {{ $money($m['paid']) }}</p>
                </div>
            @endforeach
        </div>
        <div class="hidden sm:block overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-gray-200 dark:border-gray-700"><th class="{{ $th }} text-left">Mês</th><th class="{{ $th }} text-right">OS</th><th class="{{ $th }} text-right">Faturou</th><th class="{{ $th }} text-right">Ticket</th>@if ($showProfit) <th class="{{ $th }} text-right">Lucro bruto</th><th class="{{ $th }} text-right">% lucro</th> @endif <th class="{{ $th }} text-right">Recebido</th><th class="{{ $th }} text-right">Pagou</th></tr></thead>
                <tbody>
                    @foreach ($this->series as $m)
                        <tr class="border-b border-gray-100 dark:border-gray-700" wire:key="mt-{{ $m['month'] }}">
                            <td class="p-2 font-semibold whitespace-nowrap">{{ Month::label($m['month']) }}</td>
                            <td class="p-2 text-right">{{ $m['orders'] }}</td>
                            <td class="p-2 text-right whitespace-nowrap">{{ $money($m['revenue']) }}</td>
                            <td class="p-2 text-right whitespace-nowrap">{{ $money($m['ticket']) }}</td>
                            @if ($showProfit)
                                <td class="p-2 text-right whitespace-nowrap">{{ $money($m['gross']) }}</td>
                                <td class="p-2 text-right">{{ $pct($m['gross_pct']) }}</td>
                            @endif
                            <td class="p-2 text-right whitespace-nowrap">{{ $money($m['received']) }}</td>
                            <td class="p-2 text-right whitespace-nowrap">{{ $money($m['paid']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="{{ $cardClass }} p-4 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide mr-auto">Período da análise</h2>
            <div>
                <label class="{{ $labelClass }}" for="de">De</label>
                <input id="de" type="date" wire:model.live="from" class="{{ $inputClass }}" data-test="periodo-de">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="ate">Até</label>
                <input id="ate" type="date" wire:model.live="to" class="{{ $inputClass }}" data-test="periodo-ate">
            </div>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $a['orders'] }} OS de {{ CarbonImmutable::parse($from)->format('d/m/Y') }} a {{ CarbonImmutable::parse($to)->format('d/m/Y') }}: {{ $money($a['revenue']) }}.</p>
    </section>

    <section class="{{ $cardClass }} p-4 space-y-3" data-test="tamanho-os">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Tamanho das OS</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">OS pequenas ocupam a bancada e rendem pouco. Veja quanto do faturamento vem de cada faixa.</p>
        <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
            @foreach ($a['bands'] as $band)
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2" wire:key="b-{{ $loop->index }}">
                    <span class="min-w-0 flex-1">{{ $band['label'] }}</span>
                    <span class="text-gray-600 dark:text-gray-300 whitespace-nowrap">{{ $band['orders'] }} OS ({{ $pct($band['orders_pct']) }})</span>
                    <span class="font-semibold whitespace-nowrap">{{ $money($band['revenue']) }} ({{ $pct($band['revenue_pct']) }})</span>
                </div>
            @endforeach
        </div>
    </section>

    @if ($showProfit && $a['markup'] !== null)
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="acrescimo">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Acréscimo sobre o custo das peças</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">Quanto se cobra a mais que o custo, por faixa de custo das peças na OS (venda das peças = total − mão de obra − frete). Peça cara costuma ficar com acréscimo menor: confira se está de propósito.</p>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @foreach ($a['markup'] as $band)
                    <div class="flex flex-wrap items-baseline gap-x-3 py-2" wire:key="mk-{{ $loop->index }}">
                        <span class="min-w-0 flex-1">{{ $band['label'] }}</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $band['orders'] }} OS</span>
                        <span class="font-semibold w-16 text-right">{{ $pct($band['markup']) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="clientes">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Clientes que mais faturam</h2>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @forelse ($a['customers'] as $c)
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2" wire:key="c-{{ $loop->index }}">
                        <span class="min-w-0 flex-1 break-words font-semibold">{{ $c['customer'] }}</span>
                        <span class="text-gray-600 dark:text-gray-300 whitespace-nowrap">{{ $c['orders'] }} OS · {{ $pct($c['pct'], 1) }}</span>
                        <span class="font-semibold whitespace-nowrap">{{ $money($c['revenue']) }}</span>
                        @if ($showProfit)
                            <span class="w-full text-xs text-gray-500">lucro bruto {{ $money($c['gross']) }}</span>
                        @endif
                    </div>
                @empty
                    <p class="text-gray-500">Nenhuma OS no período.</p>
                @endforelse
            </div>
        </section>

        <section class="{{ $cardClass }} p-4 space-y-3" data-test="mecanicos">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Por mecânico</h2>
            <p class="text-xs text-gray-500">A OS conta para quem apontou mais tempo nela.</p>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @forelse ($a['mechanics'] as $m)
                    <div class="flex flex-wrap items-baseline gap-x-3 py-2" wire:key="m-{{ $loop->index }}">
                        <span class="min-w-0 flex-1 font-semibold">{{ $m['mechanic'] }}</span>
                        <span class="text-gray-600 dark:text-gray-300 whitespace-nowrap">{{ $m['orders'] }} OS · ticket {{ $money($m['ticket']) }}</span>
                        <span class="font-semibold whitespace-nowrap">{{ $money($m['revenue']) }}</span>
                    </div>
                @empty
                    <p class="text-gray-500">Nenhuma OS no período.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="{{ $cardClass }} p-4 space-y-3" data-test="orcamentos">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Orçamentos sem resposta ({{ $this->budgets->count() }})</h2>
        <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
            @forelse ($this->budgets as $budget)
                @php($order = $budget->serviceOrder)
                <a href="{{ route('orders.show', $order) }}" wire:navigate class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="o-{{ $budget->id }}">
                    <span class="w-20 shrink-0 text-gray-500">{{ $budget->sent_at?->format('d/m/Y') }}</span>
                    <span class="min-w-0 flex-1 break-words">{{ $order?->label() }} · {{ $order?->customer?->name }} · {{ $order?->vehicle?->brand_model }}</span>
                    <span class="font-semibold whitespace-nowrap">{{ $money($budget->total_cents) }}</span>
                </a>
            @empty
                <p class="text-gray-500">Nenhum orçamento esperando resposta.</p>
            @endforelse
        </div>
    </section>
</div>
