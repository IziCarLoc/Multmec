<?php

namespace App\Services\Finance;

use App\Enums\CreditKind;
use App\Models\Budget;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\TimeEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Relatórios do Financeiro (a tela `relatorios` do Node com `analiseOs`, `agenda` e `alertas`), com os dados do
 * sistema novo. Lucro, margem e acréscimo sobre o custo só saem com `$withProfit` (o gestor): o atendimento nunca
 * recebe esses números.
 */
final class Reports
{
    /** Faixas do tamanho da OS (centavos): OS pequena ocupa a bancada e rende pouco. */
    private const TICKET_BANDS = [[0, 15000], [15000, 30000], [30000, 60000], [60000, 100000], [100000, 200000], [200000, 500000], [500000, null]];

    /** Faixas do custo das peças na OS (centavos), para o acréscimo. */
    private const COST_BANDS = [[0, 10000], [10000, 30000], [30000, 70000], [70000, 150000], [150000, null]];

    public function __construct(private Revenue $revenue, private Credit $credit) {}

    /**
     * Análise das OS do período (o `analiseOs` do Node): faixas de tamanho, clientes, mecânicos e, para o gestor,
     * o acréscimo sobre o custo das peças e o lucro por cliente.
     *
     * @return array<string, mixed>
     */
    public function orders(string $from, string $to, bool $withProfit): array
    {
        $orders = $this->revenue->orders($from, $to)->filter(fn (array $o) => $o['total'] > 0)->values();
        $total = (int) $orders->sum('total');
        $count = $orders->count();
        $label = fn (int $a, ?int $b) => $b === null ? 'acima de '.Money::brl($a) : Money::brl($a).' a '.Money::brl($b);

        $bands = array_map(function (array $band) use ($orders, $total, $count, $label) {
            $in = $orders->filter(fn (array $o) => $o['total'] >= $band[0] && ($band[1] === null || $o['total'] < $band[1]));
            $revenue = (int) $in->sum('total');

            return ['label' => $label($band[0], $band[1]), 'orders' => $in->count(), 'revenue' => $revenue, 'orders_pct' => $count ? $in->count() / $count : 0, 'revenue_pct' => $total ? $revenue / $total : 0];
        }, self::TICKET_BANDS);

        $customers = $orders->groupBy(fn (array $o) => $o['customer'])
            ->map(fn (Collection $g, string $name) => ['customer' => $name, 'orders' => $g->count(), 'revenue' => (int) $g->sum('total'), 'gross' => (int) $g->sum('gross')])
            ->sortByDesc('revenue')->take(15)->values()
            ->map(fn (array $c) => $c + ['pct' => $total ? $c['revenue'] / $total : 0])
            ->map(fn (array $c) => $withProfit ? $c : array_diff_key($c, ['gross' => true]))
            ->all();

        $mechanics = $this->mechanics($orders);

        $markup = $withProfit ? array_map(function (array $band) use ($orders, $label) {
            $in = $orders->filter(fn (array $o) => $o['parts'] > 0 && $o['parts'] >= $band[0] && ($band[1] === null || $o['parts'] < $band[1]));
            $cost = (int) $in->sum('parts');
            $sale = (int) $in->sum(fn (array $o) => $o['total'] - $o['labor'] - $o['freight']);

            return ['label' => $label($band[0], $band[1]), 'orders' => $in->count(), 'markup' => $cost > 0 ? $sale / $cost - 1 : null];
        }, self::COST_BANDS) : null;

        return ['from' => $from, 'to' => $to, 'orders' => $count, 'revenue' => $total, 'bands' => $bands, 'customers' => $customers, 'mechanics' => $mechanics, 'markup' => $markup];
    }

    /**
     * Próximos dias (a `agenda` do Node): o que vence para pagar (boletos e prestadores, inclusive os atrasados) e
     * o que vence para receber de cliente a prazo.
     *
     * @return array{pay: list<array<string, mixed>>, receive: list<array<string, mixed>>, pay_total: int, receive_total: int, pay_overdue: int}
     */
    public function agenda(?CarbonImmutable $today = null, int $days = 7): array
    {
        $today ??= CarbonImmutable::today();
        $until = $today->addDays($days)->toDateString();
        $todayKey = $today->toDateString();

        $pay = Payable::query()->open()->where('due_date', '<=', $until)->with('supplier:id,name')->orderBy('due_date')->get()
            ->map(fn (Payable $p) => ['date' => $p->due_date->toDateString(), 'description' => trim(($p->supplier?->name ?? '').' · '.($p->description ?: $p->document_number ?: 'boleto'), ' ·'), 'cents' => $p->amount_cents, 'late' => $p->due_date->toDateString() < $todayKey])
            ->concat(ProviderPayment::query()->open()->where('due_date', '<=', $until)->with('supplier:id,name')->orderBy('due_date')->get()
                ->map(fn (ProviderPayment $p) => ['date' => $p->due_date->toDateString(), 'description' => trim(($p->supplier?->name ?? '').' · prestador'.($p->order_number ? ' · OS '.$p->order_number : ''), ' ·'), 'cents' => $p->amount_cents, 'late' => $p->due_date->toDateString() < $todayKey]))
            ->sortBy('date')->values();

        $receive = Receivable::query()->open()->counted()->where('due_date', '<=', $until)
            ->whereHas('customer', fn ($q) => $q->where('credit_kind', '!=', CreditKind::Avulso->value)->orWhere('credit_limit_cents', '>', 0))
            ->with('customer:id,name')->orderBy('due_date')->get()
            ->map(fn (Receivable $r) => ['date' => $r->due_date->toDateString(), 'description' => ($r->customer?->name ?? '').' · '.$r->reference(), 'cents' => $r->balanceCents(), 'late' => $r->isOverdue($today)])
            ->values();

        return [
            'pay' => $pay->all(),
            'receive' => $receive->all(),
            'pay_total' => (int) $pay->sum('cents'),
            'receive_total' => (int) $receive->sum('cents'),
            'pay_overdue' => (int) $pay->where('late', true)->sum('cents'),
        ];
    }

    /**
     * Avisos (os `alertas` do Node), do mais grave ao mais leve: cliente travado, contas vencidas, cliente avulso com
     * valor em aberto (um aviso só), OS do mês sem custo de peça e orçamentos sem resposta.
     *
     * @return list<array{level: string, kind: string, text: string, customer_id?: int}>
     */
    public function alerts(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $list = [];

        foreach ($this->credit->portfolio($today) as $s) {
            if ($s['status'] === Credit::LOCKED) {
                $list[] = ['level' => 'critico', 'kind' => 'cliente_travado', 'text' => $s['customer']->name.': '.$s['reason'].'. Deve '.Money::brl($s['exposure']).' (vencido '.Money::brl($s['overdue']).').', 'customer_id' => $s['customer']->id];
            }
        }

        $agenda = $this->agenda($today, 7);

        if ($agenda['pay_overdue'] > 0) {
            $list[] = ['level' => 'critico', 'kind' => 'contas_atrasadas', 'text' => 'Contas a pagar vencidas: '.Money::brl($agenda['pay_overdue']).'.'];
        }

        $walkIn = Receivable::query()->open()->counted()
            ->whereHas('customer', fn ($q) => $q->where('credit_kind', CreditKind::Avulso->value)->where('credit_limit_cents', 0))
            ->get(['id', 'total_cents', 'paid_cents', 'written_off_at']);

        if ($walkIn->isNotEmpty()) {
            $list[] = ['level' => 'aviso', 'kind' => 'avulso_em_aberto', 'text' => $walkIn->count().' lançamento(s) de clientes avulsos sem recebimento registrado ('.Money::brl((int) $walkIn->sum(fn (Receivable $r) => $r->balanceCents())).'). Confirme se já pagaram e dê baixa no A receber.'];
        }

        $month = $this->revenue->month(Month::current($today));

        if ($month['no_cost_orders'] > 0) {
            $list[] = ['level' => 'aviso', 'kind' => 'os_sem_custo', 'text' => $month['no_cost_orders'].' OS do mês sem custo de peças informado: complete o custo no A receber.'];
        }

        $budgets = $this->pendingBudgets($today->subDays(30));

        if ($budgets->isNotEmpty()) {
            $list[] = ['level' => 'info', 'kind' => 'orcamentos', 'text' => $budgets->count().' orçamento(s) dos últimos 30 dias sem resposta ('.Money::brl((int) $budgets->sum('total_cents')).'). Ligue hoje.'];
        }

        return $list;
    }

    /**
     * Orçamentos enviados ao cliente e ainda sem resposta (os mais novos primeiro).
     *
     * @return Collection<int, Budget>
     */
    public function pendingBudgets(?CarbonImmutable $sentSince = null, int $limit = 100): Collection
    {
        return Budget::query()->where('status', Budget::STATUS_SENT)->whereNotNull('sent_at')
            ->when($sentSince, fn ($q) => $q->where('sent_at', '>=', $sentSince->startOfDay()))
            ->with(['serviceOrder.customer:id,name', 'serviceOrder.vehicle:id,plate,brand_model'])
            ->orderByDesc('sent_at')->limit($limit)->get();
    }

    /**
     * Por mecânico: a OS vai para quem apontou mais tempo nela (OS sem apontamento ou importada: "não informado").
     *
     * @param  Collection<int, array<string, mixed>>  $orders
     * @return list<array{mechanic: string, orders: int, revenue: int, ticket: int}>
     */
    private function mechanics(Collection $orders): array
    {
        $ids = $orders->pluck('service_order_id')->filter()->unique()->values();
        $owner = [];

        if ($ids->isNotEmpty()) {
            $minutes = [];

            foreach (TimeEntry::query()->whereIn('service_order_id', $ids)->with('user:id,name,short_name')->get() as $entry) {
                $name = $entry->user?->short_name ?: ($entry->user?->name ?? '');
                $minutes[$entry->service_order_id][$name] = ($minutes[$entry->service_order_id][$name] ?? 0) + $entry->minutes();
            }

            foreach ($minutes as $orderId => $byName) {
                arsort($byName);
                $owner[$orderId] = (string) array_key_first($byName);
            }
        }

        return $orders->groupBy(fn (array $o) => ($o['service_order_id'] ? ($owner[$o['service_order_id']] ?? '') : '') ?: '(não informado)')
            ->map(fn (Collection $g, string $name) => ['mechanic' => $name, 'orders' => $g->count(), 'revenue' => (int) $g->sum('total'), 'ticket' => (int) round($g->sum('total') / $g->count())])
            ->sortByDesc('revenue')->values()->all();
    }
}
