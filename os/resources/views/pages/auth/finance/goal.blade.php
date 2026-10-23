<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Services\Finance\MoneyRules;
use App\Services\Finance\Month;
use App\Services\Finance\MonthGoal;
use App\Support\BusinessDays;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Meta do mês (só o gestor): faturado × meta com o ritmo por dia útil e a projeção, a cascata do mês até o
 * "disponível para os sócios", o ponto de equilíbrio, o caixa, o simulador (OS × ticket) e o mês a mês. As regras
 * do dinheiro (meta, percentuais, feriados, saldo do banco) ficam no fim da tela; em branco = "preencher".
 */
new #[Title('Meta do mês')] class extends Component
{
    use RunsDomainActions;

    /** AAAA-MM; vazio = mês corrente. */
    #[Url(as: 'mes', except: '')]
    public string $month = '';

    /** @var array<string, mixed> regras como texto digitado */
    public array $rules = [];

    public string $simOrders = '';

    public string $simTicket = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gestor'), 403);
    }

    public function mount(MoneyRules $rules): void
    {
        $this->fillRules($rules->values());
        $base = $this->simulatorBase();
        $this->simOrders = $base['orders'] ? (string) $base['orders'] : '';
        $this->simTicket = $base['ticket'] ? number_format($base['ticket'] / 100, 2, ',', '.') : '';
    }

    public function selected(): string
    {
        return Month::isValid($this->month) ? $this->month : Month::current();
    }

    public function shiftMonth(int $months): void
    {
        $month = Month::shift($this->selected(), $months > 0 ? 1 : -1);
        $this->month = $month === Month::current() ? '' : $month;
        $this->forget();
    }

    public function currentMonth(): void
    {
        $this->month = '';
        $this->forget();
    }

    #[Computed]
    public function thermometer(): array
    {
        return app(MonthGoal::class)->thermometer($this->selected());
    }

    #[Computed]
    public function cascade(): array
    {
        return app(MonthGoal::class)->cascade($this->selected());
    }

    #[Computed]
    public function breakEven(): array
    {
        return app(MonthGoal::class)->breakEven($this->selected());
    }

    #[Computed]
    public function cash(): array
    {
        return app(MonthGoal::class)->cash();
    }

    #[Computed]
    public function series(): array
    {
        return app(MonthGoal::class)->series($this->selected(), 6);
    }

    #[Computed]
    public function missing(): array
    {
        return app(MoneyRules::class)->missing();
    }

    /** Simulador com a margem e os custos fixos do mês corrente (como o Node). */
    #[Computed]
    public function simulation(): array
    {
        $even = app(MonthGoal::class)->breakEven(Month::current(), 0);
        $orders = (int) preg_replace('/\D/', '', $this->simOrders);
        $ticket = max(0, Money::parseBr($this->simTicket) ?? 0);

        return MonthGoal::simulate($orders, $ticket, (int) (app(MoneyRules::class)->values()['target_cents'] ?? 0), $even['margin_pct'], $even['fixed'])
            + ['margin_pct' => $even['margin_pct'], 'fixed' => $even['fixed']];
    }

    public function saveRules(MoneyRules $rules): void
    {
        $this->resetErrorBag('rules');
        $input = $this->rules;

        if ($this->attempt(fn () => $rules->save($input, auth()->user()), 'rules')) {
            $this->fillRules($rules->values());
            $this->forget();
            $this->dispatch('toast', message: 'Regras do dinheiro salvas.', type: 'success');
        }
    }

    /** Ponto de partida do simulador: a média dos últimos 3 meses fechados com OS. @return array{orders: int, ticket: int} */
    private function simulatorBase(): array
    {
        $closed = collect(app(MonthGoal::class)->series(Month::shift(Month::current(), -1), 3))->filter(fn (array $m) => $m['orders'] > 0);

        if ($closed->isEmpty()) {
            return ['orders' => 0, 'ticket' => 0];
        }

        return ['orders' => (int) round($closed->avg('orders')), 'ticket' => (int) round($closed->sum('revenue') / $closed->sum('orders'))];
    }

    private function fillRules(array $v): void
    {
        $money = fn (?int $cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '.');
        $pct = fn (?float $value) => $value === null ? '' : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

        $this->rules = [
            'target' => $money($v['target_cents']),
            'withdrawal' => $money($v['withdrawal_cents']),
            'tax_pct' => $pct($v['tax_pct']),
            'card_fee_pct' => $pct($v['card_fee_pct']),
            'reserve_pct' => $pct($v['reserve_pct']),
            'contribution_margin_pct' => $pct($v['contribution_margin_pct']),
            'saturday_weight' => (string) $v['saturday_weight'],
            'movable_holidays' => $v['movable_holidays'],
            'extra_holidays' => implode(', ', array_map(fn ($d) => \Carbon\CarbonImmutable::parse($d)->format('d/m/Y'), $v['extra_holidays'])),
            'cash_opening' => $v['cash_opening_date'] === null ? '' : $money($v['cash_opening_cents'] ?? 0),
            'cash_opening_date' => (string) $v['cash_opening_date'],
        ];
    }

    private function forget(): void
    {
        unset($this->thermometer, $this->cascade, $this->breakEven, $this->cash, $this->series, $this->missing, $this->simulation);
    }
};
?>

@php
    $t = $this->thermometer;
    $c = $this->cascade;
    $eq = $this->breakEven;
    $cash = $this->cash;
    $sim = $this->simulation;
    $selected = $this->selected();
    $small = 'inline-flex items-center justify-center min-h-11 min-w-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700';
    $days = fn (float $d) => rtrim(rtrim(number_format($d, 1, ',', ''), '0'), ',');
    $pct = fn (?float $v, int $decimals = 0) => $v === null ? '—' : number_format($v * 100, $decimals, ',', '.').'%';
    $base = max(1, $c['revenue']);
    $row = 'flex items-baseline gap-2 text-sm';
@endphp
<div class="space-y-5">
    <x-finance-nav />

    <div class="flex flex-wrap items-center gap-2">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2 w-full sm:w-auto sm:mr-auto">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Meta do mês
        </h1>
        <button type="button" wire:click="shiftMonth(-1)" class="{{ $small }}" aria-label="Mês anterior"><i class="fas fa-chevron-left"></i></button>
        <span class="font-display font-bold text-lg uppercase tracking-wide min-w-[6.5rem] text-center" data-test="mes-meta">{{ Month::label($selected) }}</span>
        <button type="button" wire:click="shiftMonth(1)" class="{{ $small }}" aria-label="Próximo mês"><i class="fas fa-chevron-right"></i></button>
        @if ($selected !== Month::current())
            <button type="button" wire:click="currentMonth" class="{{ $small }}">Mês atual</button>
        @endif
    </div>

    @if ($this->missing !== [])
        <div class="rounded-xl border border-brand-200 dark:border-brand-800 bg-brand-50 dark:bg-brand-900/30 p-4 text-sm space-y-1" data-test="preencher">
            <p class="font-semibold"><i class="fas fa-pen-to-square"></i> Preencher nas regras do dinheiro: {{ implode(', ', $this->missing) }}.</p>
            <p class="text-gray-600 dark:text-gray-300">Sem eles, as contas abaixo contam zero no lugar do que falta. <a href="#regras" class="text-brand-700 dark:text-brand-300 underline">Ir para as regras</a></p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Termômetro --}}
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="termometro">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Faturado no mês</h2>
            <p>
                <span class="font-display font-extrabold text-3xl" data-test="faturado">{{ $money($t['revenue']) }}</span>
                <span class="text-gray-600 dark:text-gray-300">{{ $t['target'] ? 'de '.$money($t['target']).' ('.$pct($t['pct']).')' : '· meta a preencher' }}</span>
            </p>
            @if ($t['target'])
                <div class="relative h-4 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden" role="img" aria-label="Faturado em relação à meta">
                    <div class="h-full bg-brand-600" style="width: {{ min(100, round(($t['pct'] ?? 0) * 100, 1)) }}%"></div>
                    @if ($t['expected'])
                        <div class="absolute inset-y-0 w-0.5 bg-gray-900 dark:bg-white" style="left: {{ min(100, round($t['expected'] / $t['target'] * 100, 1)) }}%" title="Onde deveria estar hoje"></div>
                    @endif
                </div>
                @if ($t['expected'])
                    <p class="text-xs text-gray-500">O traço marca onde o faturado deveria estar hoje: {{ $money($t['expected']) }}.</p>
                @endif
            @endif
            <div class="space-y-1.5">
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Dias úteis</span><span class="ml-auto font-semibold">{{ $days($t['days_passed']) }} de {{ $days($t['days_total']) }}{{ $t['current'] ? ' · faltam '.$days($t['days_left']) : '' }}</span></p>
                @if ($t['target_per_day'])
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Meta por dia útil</span><span class="ml-auto font-semibold">{{ $money($t['target_per_day']) }}/dia</span></p>
                @endif
                @if ($t['early'])
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Projeção do mês</span><span class="ml-auto font-semibold">ainda é cedo</span></p>
                @else
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Ritmo</span><span class="ml-auto font-semibold" data-test="ritmo">{{ $money($t['rhythm']) }}/dia útil</span></p>
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">{{ $t['past'] ? 'Fechou o mês' : 'Projeção do mês' }}</span><span @class(['ml-auto font-display font-bold text-lg', 'text-red-700 dark:text-red-400' => $t['target'] && $t['projection'] < $t['target']]) data-test="projecao">{{ $money($t['projection']) }}</span></p>
                @endif
                @if ($t['needed_per_day'] !== null)
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Precisa nos {{ $days($t['days_left']) }} dias úteis que faltam</span><span class="ml-auto font-display font-bold text-lg text-brand-700 dark:text-brand-300" data-test="falta-por-dia">{{ $money($t['needed_per_day']) }}/dia</span></p>
                @endif
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">OS no mês · ticket médio</span><span class="ml-auto font-semibold">{{ $t['orders'] }} · {{ $money($t['ticket']) }}</span></p>
                @if (! $t['early'] && $t['ticket_needed'])
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Com {{ $t['orders_projected'] }} OS, o ticket para a meta é</span><span class="ml-auto font-semibold">{{ $money($t['ticket_needed']) }}</span></p>
                @endif
                @if ($t['orders_needed'])
                    <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Com o ticket de hoje, a meta pede</span><span class="ml-auto font-semibold">{{ $t['orders_needed'] }} OS</span></p>
                @endif
            </div>
            <p class="text-xs text-gray-500">Faturado = serviços do A receber pela data da manutenção (sem o recebimento pela matriz e sem o saldo anterior).</p>
        </section>

        {{-- Caixa --}}
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="caixa">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Caixa</h2>
            @if (! $cash['configured'])
                <p class="text-sm text-gray-600 dark:text-gray-300">Informe o saldo do banco de um dia (nas regras, "Saldo do banco em") e o sistema passa a mostrar o caixa real a partir dele.</p>
                <a href="#regras" class="{{ $btnSecondary }}">Informar saldo</a>
            @else
                <p @class(['font-display font-extrabold text-3xl', 'text-red-700 dark:text-red-400' => $cash['balance'] < 0]) data-test="saldo-caixa">{{ $money($cash['balance']) }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">Desde {{ \Carbon\CarbonImmutable::parse($cash['since'])->format('d/m/Y') }}: entrou {{ $money($cash['in']) }} de clientes, saiu {{ $money($cash['out']) }} em boletos e prestadores.</p>
                @if ($eq['fixed'] > 0)
                    <p class="text-sm text-gray-600 dark:text-gray-300">Cobre {{ number_format($cash['balance'] / $eq['fixed'], 1, ',', '.') }} mês(es) de folha e custos fixos (a meta é pelo menos 1).</p>
                @endif
            @endif
        </section>
    </div>

    {{-- Cascata --}}
    <section class="{{ $cardClass }} p-4 space-y-3" data-test="cascata">
        <h2 class="font-display font-bold text-xl uppercase tracking-wide">Para onde vai o dinheiro do mês</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">Competência: serviços do mês menos o que eles custam e as contas fixas que vencem no mês.</p>
        @php
            $lines = [
                ['Faturamento', $c['revenue'], 'total', ''],
                ['Peças, frete e prestadores', $c['costs'], 'sub', $c['estimated'] > 0 ? 'inclui '.$money($c['estimated']).' estimados em '.$c['no_cost_orders'].' OS sem custo informado ('.$pct($c['cmv_pct']).', '.$c['cmv_basis'].')' : ''],
                ['Impostos e maquininha', $c['taxes'], 'sub', $c['missing'] !== [] ? 'preencher: '.implode(', ', $c['missing']) : ''],
                ['Sobra depois dos custos da venda', $c['contribution'], 'total', ''],
                ['Folha e comissões', $c['payroll'], 'sub', ''],
                ['Custos fixos e outros', $c['fixed'] + $c['other'], 'sub', $c['tax_bills'] > 0 ? 'contas de imposto do mês ('.$money($c['tax_bills']).') ficam fora: o imposto da cascata é o % do faturamento' : ''],
                ['Resultado do mês', $c['result'], 'total', ''],
                ['Reserva da oficina', $c['reserve'], 'sub', ''],
                ['Disponível para os sócios', $c['partners'], 'final', ($c['withdrawal_target'] !== null ? 'meta de retirada '.$money($c['withdrawal_target']).' · ' : '').'já retirado '.$money($c['withdrawn'])],
            ];
        @endphp
        <div class="space-y-3">
            @foreach ($lines as [$label, $value, $kind, $note])
                <div wire:key="cas-{{ $loop->index }}" @if ($kind === 'final') data-test="disponivel-socios" @endif>
                    <div class="flex items-baseline gap-2">
                        <span @class(['text-sm', 'font-semibold' => $kind !== 'sub', 'text-gray-600 dark:text-gray-300' => $kind === 'sub'])>{{ $label }}</span>
                        <span @class(['ml-auto whitespace-nowrap', 'font-display font-bold text-lg' => $kind !== 'sub', 'text-red-700 dark:text-red-400' => $value < 0])>{{ $kind === 'sub' ? '− '.$money($value) : $money($value) }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                        <div @class(['h-full', 'bg-gray-400 dark:bg-gray-500' => $kind === 'sub' && $value >= 0, 'bg-brand-600' => $kind !== 'sub' && $value >= 0, 'bg-stripe' => $value < 0]) style="width: {{ min(100, round(abs($value) / $base * 100, 1)) }}%"></div>
                    </div>
                    @if ($note !== '')
                        <p class="text-xs text-gray-500">{{ $note }}</p>
                    @endif
                </div>
                @if ($loop->index === 3 && $c['no_bills'])
                    <p class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3 text-sm"><b>Sem contas fixas neste mês</b> · o resultado ainda não desconta folha e custos fixos. Cadastre em <a href="{{ route('finance.recurring') }}" wire:navigate class="text-brand-600 hover:underline">Contas fixas</a>.</p>
                @endif
            @endforeach
        </div>

        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm space-y-1" data-test="equilibrio">
            @if ($eq['break_even'])
                @php
                    $passed = $c['revenue'] >= $eq['break_even'];
                @endphp
                <p class="font-semibold">{{ $passed ? 'Passou do ponto de equilíbrio: daqui para a frente, cada real que entra é sobra.' : 'Ainda não cobriu os custos fixos do mês.' }}</p>
                <p class="text-gray-600 dark:text-gray-300">Equilíbrio {{ $money($eq['break_even']) }} = folha, fixos e outros ({{ $money($eq['fixed']) }}) ÷ {{ $pct($eq['margin_pct'], 1) }} de margem de contribuição ({{ $eq['margin_basis'] }}).</p>
                @if ($eq['withdrawal'] > 0 && $eq['with_withdrawal'])
                    <p class="text-gray-600 dark:text-gray-300">Para retirar {{ $money($eq['withdrawal']) }}: faturar {{ $money($eq['with_withdrawal']) }}.</p>
                @endif
            @else
                <p class="text-gray-600 dark:text-gray-300">Cadastre a folha e os custos fixos em <a href="{{ route('finance.recurring') }}" wire:navigate class="text-brand-600 hover:underline">Contas fixas</a> para calcular o ponto de equilíbrio.</p>
            @endif
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Simulador --}}
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="simulador">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Simulador: como chegar na meta</h2>
            <p class="text-xs text-gray-500">Margem de contribuição {{ $pct($sim['margin_pct'], 1) }} · folha e fixos do mês {{ $money($sim['fixed']) }}. Ponto de partida: a média dos últimos meses fechados.</p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $labelClass }}" for="sim-os">OS por mês</label>
                    <input id="sim-os" type="number" min="0" inputmode="numeric" wire:model.live.debounce.400ms="simOrders" class="{{ $inputClass }}" data-test="sim-os">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="sim-ticket">Ticket médio (R$)</label>
                    <input id="sim-ticket" type="text" inputmode="decimal" wire:model.live.debounce.400ms="simTicket" class="{{ $inputClass }}" data-test="sim-ticket">
                </div>
            </div>
            <div class="space-y-1.5">
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Faturamento</span><span class="ml-auto font-semibold" data-test="sim-faturamento">{{ $money($sim['revenue']) }}</span></p>
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Sobra depois dos custos fixos</span><span @class(['ml-auto font-display font-bold text-lg', 'text-red-700 dark:text-red-400' => $sim['surplus'] < 0]) data-test="sim-sobra">{{ $money($sim['surplus']) }}</span></p>
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Falta para a meta</span><span class="ml-auto font-semibold">{{ $money($sim['missing']) }}</span></p>
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">Ticket que fecha a meta com essas OS</span><span class="ml-auto font-semibold">{{ $sim['ticket_for_target'] !== null ? $money($sim['ticket_for_target']) : '—' }}</span></p>
                <p class="{{ $row }}"><span class="text-gray-600 dark:text-gray-300">OS que fecham a meta com esse ticket</span><span class="ml-auto font-semibold">{{ $sim['orders_for_target'] ?? '—' }}</span></p>
            </div>
        </section>

        {{-- Mês a mês --}}
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="serie">
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Últimos 6 meses</h2>
            @php
                $top = max(1, (int) ($t['target'] ?? 0), ...array_column($this->series, 'revenue'));
            @endphp
            <div class="relative flex items-end gap-2 h-40 border-b border-gray-200 dark:border-gray-700" role="img" aria-label="Faturamento dos últimos 6 meses">
                @if ($t['target'])
                    <div class="absolute inset-x-0 border-t-2 border-dashed border-gray-400" style="bottom: {{ round($t['target'] / $top * 100, 1) }}%" title="Meta"></div>
                @endif
                @foreach ($this->series as $m)
                    <div class="flex-1 flex flex-col justify-end h-full" wire:key="s-{{ $m['month'] }}">
                        <div @class(['rounded-t', 'bg-brand-600' => $m['month'] === $selected, 'bg-brand-300' => $m['month'] !== $selected]) style="height: {{ round($m['revenue'] / $top * 100, 1) }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-2 text-[11px] text-gray-500 text-center">
                @foreach ($this->series as $m)
                    <span class="flex-1" wire:key="sl-{{ $m['month'] }}">{{ substr(Month::label($m['month']), 0, 3) }}</span>
                @endforeach
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @foreach (array_reverse($this->series) as $m)
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5" wire:key="st-{{ $m['month'] }}">
                        <span class="w-20 font-semibold">{{ Month::label($m['month']) }}</span>
                        <span class="flex-1 text-gray-600 dark:text-gray-300">{{ $m['orders'] }} OS · ticket {{ $money($m['ticket']) }} · lucro bruto {{ $money($m['gross']) }}</span>
                        <span class="font-semibold">{{ $money($m['revenue']) }}</span>
                    </div>
                @endforeach
            </div>
            <a href="{{ route('finance.reports') }}" wire:navigate class="text-sm text-brand-600 hover:underline">Mês a mês completo em Relatórios</a>
        </section>
    </div>

    {{-- Regras do dinheiro --}}
    <form id="regras" wire:submit="saveRules" class="{{ $cardClass }} p-4 space-y-4 scroll-mt-20" data-test="regras">
        <div>
            <h2 class="font-display font-bold text-xl uppercase tracking-wide">Regras do dinheiro</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">Em branco = preencher. Percentuais sobre o faturamento: confirme imposto com o contador e maquininha com a proposta dela.</p>
        </div>
        @error('rules') <p class="text-sm text-red-600" data-test="erro-regras">{{ $message }}</p> @enderror
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="g-meta">Meta de faturamento do mês (R$)</label>
                <input id="g-meta" type="text" inputmode="decimal" wire:model="rules.target" placeholder="preencher" class="{{ $inputClass }}" data-test="regra-meta">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-ret">Retirada desejada dos sócios (R$/mês)</label>
                <input id="g-ret" type="text" inputmode="decimal" wire:model="rules.withdrawal" placeholder="preencher" class="{{ $inputClass }}" data-test="regra-retirada">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-imp">Impostos sobre a venda (%)</label>
                <input id="g-imp" type="text" inputmode="decimal" wire:model="rules.tax_pct" placeholder="preencher" class="{{ $inputClass }}" data-test="regra-imposto">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-maq">Taxas de maquininha (%)</label>
                <input id="g-maq" type="text" inputmode="decimal" wire:model="rules.card_fee_pct" placeholder="preencher" class="{{ $inputClass }}" data-test="regra-maquininha">
                <p class="mt-1 text-xs text-gray-500">Média sobre todo o faturamento.</p>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-res">Reserva da oficina (%)</label>
                <input id="g-res" type="text" inputmode="decimal" wire:model="rules.reserve_pct" placeholder="preencher" class="{{ $inputClass }}" data-test="regra-reserva">
                <p class="mt-1 text-xs text-gray-500">Guardado todo mês, antes da parte dos sócios.</p>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-mc">Margem de contribuição fixa (%)</label>
                <input id="g-mc" type="text" inputmode="decimal" wire:model="rules.contribution_margin_pct" placeholder="calcular" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-gray-500">Em branco: 100% − custo das peças medido − imposto − maquininha − reserva.</p>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-sab">Sábado conta como dia útil?</label>
                <select id="g-sab" wire:model="rules.saturday_weight" class="{{ $inputClass }}">
                    <option value="0">Não</option>
                    <option value="0.5">Meio dia</option>
                    <option value="1">Dia inteiro</option>
                </select>
            </div>
            <fieldset>
                <legend class="{{ $labelClass }}">Feriados da Páscoa em que a oficina fecha</legend>
                @foreach (BusinessDays::MOVABLE as $key => $label)
                    <label class="flex items-center gap-3 min-h-11" wire:key="mv-{{ $key }}"><input type="checkbox" wire:model="rules.movable_holidays" value="{{ $key }}" class="size-5 rounded border-gray-300 text-brand-600"> {{ $label }}</label>
                @endforeach
                <p class="text-xs text-gray-500">Os nacionais de data fixa (Tiradentes, Finados, Natal…) sempre contam.</p>
            </fieldset>
            <div class="sm:col-span-2">
                <label class="{{ $labelClass }}" for="g-fer">Outros dias sem expediente (estaduais, municipais, pontes)</label>
                <textarea id="g-fer" wire:model="rules.extra_holidays" rows="2" placeholder="dd/mm/aaaa, separados por vírgula" class="{{ $inputClass }}" data-test="regra-feriados"></textarea>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-cxd">Saldo do banco em (data)</label>
                <input id="g-cxd" type="date" wire:model="rules.cash_opening_date" class="{{ $inputClass }}" data-test="regra-caixa-data">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="g-cx">Saldo nessa data (R$)</label>
                <input id="g-cx" type="text" inputmode="decimal" wire:model="rules.cash_opening" placeholder="0,00" class="{{ $inputClass }}" data-test="regra-caixa">
            </div>
        </div>
        <div class="flex justify-end"><button type="submit" class="{{ $btnPrimary }}" data-test="salvar-regras"><i class="fas fa-check"></i> Salvar regras</button></div>
    </form>
</div>
