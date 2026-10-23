<?php

namespace Tests\Feature\Finance;

use App\Enums\LossCause;
use App\Enums\ReceivableStatus;
use App\Models\Customer;
use App\Models\Loss;
use App\Models\Receivable;
use App\Models\Vehicle;
use App\Services\Finance\Delinquency;
use App\Services\Finance\FinanceSummary;
use App\Services\Finance\Installments;
use App\Services\Finance\Losses;
use App\Services\Finance\Profit;
use App\Services\Finance\Receivables;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

class ReceivablesTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private Receivables $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        $this->service = app(Receivables::class);
    }

    public function test_divisao_em_parcelas_nao_perde_nem_sobra_centavo(): void
    {
        $this->assertSame([3334, 3333, 3333], Installments::split(10000, 3));
        $this->assertSame([5000, 5000], Installments::split(10000, 2));
        $this->assertSame([34, 33, 33], Installments::split(100, 3));
        $this->assertSame(123457, array_sum(Installments::split(123457, 7)));
    }

    public function test_parcelas_dividem_total_pecas_mao_de_obra_e_frete_com_vencimento_proprio(): void
    {
        $customer = $this->financeCustomer();
        $dates = ['2026-11-10', '2026-12-10', '2027-01-10'];

        $created = $this->service->create([
            'customer_id' => $customer->id, 'order_number' => '4521',
            'total_cents' => 10000, 'parts_cost_cents' => 1000, 'labor_cents' => 5000, 'freight_cents' => 100,
        ], $dates, $this->financeUser());

        $this->assertCount(3, $created);
        $this->assertSame([3334, 3333, 3333], $created->pluck('total_cents')->all());
        $this->assertSame(1000, $created->sum('parts_cost_cents'));
        $this->assertSame(5000, $created->sum('labor_cents'));
        $this->assertSame(100, $created->sum('freight_cents'));
        $this->assertSame($dates, $created->map(fn (Receivable $r) => $r->due_date->toDateString())->all());
        $this->assertSame(['1/3', '2/3', '3/3'], $created->map->installmentLabel()->all());
        $this->assertCount(1, $created->pluck('installment_group')->unique());
        $this->assertNotNull($created->first()->installment_group);
    }

    public function test_valor_digitado_vale_pelo_money_parse_br(): void
    {
        $this->assertSame(123400, Money::parseBr('1.234'));

        $receivable = $this->receivable(['total_cents' => '1.234', 'parts_cost_cents' => '12.5', 'labor_cents' => '1.000,99', 'freight_cents' => '']);

        $this->assertSame(123400, $receivable->total_cents);
        $this->assertSame(1250, $receivable->parts_cost_cents);
        $this->assertSame(100099, $receivable->labor_cents);
        $this->assertSame(0, $receivable->freight_cents);
    }

    public function test_editar_uma_parcela_nao_apaga_nem_recria_as_outras(): void
    {
        $customer = $this->financeCustomer();
        $created = $this->service->create(['customer_id' => $customer->id, 'order_number' => '10', 'total_cents' => 30000], ['2026-11-01', '2026-12-01', '2027-01-01'], $this->financeUser());
        [$first, $second, $third] = $created->all();
        $before = [$first->fresh()->getAttributes(), $third->fresh()->getAttributes()];

        $this->service->update($second, ['customer_id' => $customer->id, 'order_number' => '10', 'total_cents' => '120', 'due_date' => '2026-12-20'], $this->financeUser());

        $this->assertSame(3, Receivable::count());
        $this->assertSame([$first->id, $second->id, $third->id], Receivable::orderBy('id')->pluck('id')->all());
        $this->assertSame(12000, $second->fresh()->total_cents);
        $this->assertSame('2026-12-20', $second->fresh()->due_date->toDateString());
        $this->assertSame('2/3', $second->fresh()->installmentLabel());
        $this->assertEquals($before, [$first->fresh()->getAttributes(), $third->fresh()->getAttributes()]);
    }

    public function test_pagamentos_parciais_e_integral_com_data(): void
    {
        $receivable = $this->receivable(['total_cents' => 100000]);
        $user = $this->financeUser();

        $this->service->addPayment($receivable, '2026-10-16', 30000, $user);
        $receivable->refresh();
        $this->assertSame(ReceivableStatus::Parcial, $receivable->status());
        $this->assertSame(70000, $receivable->balanceCents());
        $this->assertNull($receivable->received_on);

        try {
            $this->service->addPayment($receivable, '2026-10-17', 70001, $user);
            $this->fail('Pagamento acima do saldo passou.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('saldo em aberto', $e->getMessage());
        }

        $this->service->receiveRemaining($receivable, '2026-10-20', $user);
        $receivable->refresh();
        $this->assertSame(ReceivableStatus::Recebido, $receivable->status());
        $this->assertSame('2026-10-20', $receivable->received_on->toDateString());
        $this->assertSame([30000, 70000], $receivable->payments->pluck('amount_cents')->all());

        $this->service->removePayment($receivable->payments->last(), $user);
        $receivable->refresh();
        $this->assertSame(ReceivableStatus::Parcial, $receivable->status());
        $this->assertNull($receivable->received_on);

        $this->expectException(DomainException::class);
        $this->service->update($receivable, ['customer_id' => $receivable->customer_id, 'order_number' => '1', 'total_cents' => 20000, 'due_date' => '2026-10-25'], $user);
    }

    public function test_parcial_vencido_conta_como_vencido(): void
    {
        $receivable = $this->receivable(['total_cents' => 100000], '2026-10-10');
        $this->service->addPayment($receivable, '2026-10-12', 30000, $this->financeUser());
        $receivable->refresh();

        $this->assertSame(ReceivableStatus::Parcial, $receivable->status());
        $this->assertTrue($receivable->isOverdue());
        $this->assertSame(5, $receivable->daysLate());
        $this->assertSame(1, Receivable::query()->overdue()->count());

        $deadlines = app(FinanceSummary::class)->receivableDeadlines();
        $this->assertSame(['count' => 1, 'cents' => 70000], $deadlines['vencidos']);
        $this->assertSame(['count' => 1, 'cents' => 70000], $deadlines['mes']);
        $this->assertSame(['count' => 0, 'cents' => 0], $deadlines['hoje']);

        $this->assertSame(70000, app(Delinquency::class)->totalCents());
    }

    public function test_janelas_de_vencimento(): void
    {
        $this->receivable(['total_cents' => 1000], '2026-10-15');
        $this->receivable(['total_cents' => 2000], '2026-10-22');
        $this->receivable(['total_cents' => 4000], '2026-10-23');
        $this->receivable(['total_cents' => 8000], '2026-11-02');
        $received = $this->receivable(['total_cents' => 16000], '2026-10-01');
        $this->service->receiveRemaining($received, '2026-10-01', $this->financeUser());

        $deadlines = app(FinanceSummary::class)->receivableDeadlines();

        $this->assertSame(['count' => 0, 'cents' => 0], $deadlines['vencidos']);
        $this->assertSame(['count' => 1, 'cents' => 1000], $deadlines['hoje']);
        $this->assertSame(['count' => 1, 'cents' => 2000], $deadlines['7dias']);
        $this->assertSame(['count' => 3, 'cents' => 7000], $deadlines['mes']);
    }

    public function test_lancar_como_prejuizo_cria_o_prejuizo_com_o_saldo_e_liga_os_dois(): void
    {
        $customer = $this->financeCustomer('João Caloteiro');
        $receivable = $this->receivable(['total_cents' => 100000, 'plate' => 'abc-1d23'], '2026-09-30', $customer);
        $this->service->addPayment($receivable, '2026-10-01', 30000, $this->financeUser());

        $loss = $this->service->writeOff($receivable, $this->financeUser());
        $receivable->refresh();

        $this->assertSame(70000, $loss->amount_cents);
        $this->assertSame(LossCause::Inadimplencia, $loss->cause);
        $this->assertSame('2026-10-15', $loss->occurred_on->toDateString());
        $this->assertSame($receivable->id, $loss->receivable_id);
        $this->assertStringContainsString('João Caloteiro', $loss->item);
        $this->assertSame($loss->id, $receivable->loss_id);
        $this->assertNotNull($receivable->written_off_at);
        $this->assertSame(ReceivableStatus::Prejuizo, $receivable->status());
        $this->assertSame(0, $receivable->balanceCents());
        $this->assertFalse($receivable->isOverdue());

        $summary = app(FinanceSummary::class);
        $this->assertSame(0, $summary->openTotals()['receivables']);
        $this->assertSame(0, $summary->openTotals()['delinquency']);
        $october = $summary->month('2026-10');
        $this->assertSame(70000, $october['loss']);
        $this->assertSame(30000, $october['received']);
        $this->assertSame(30000 - 70000, $october['balance']);
    }

    public function test_baixa_como_prejuizo_e_uma_transacao_so(): void
    {
        $receivable = $this->receivable(['total_cents' => 50000], '2026-10-01');
        Receivable::updating(function () {
            throw new RuntimeException('falha ao gravar o lançamento');
        });

        try {
            $this->service->writeOff($receivable, $this->financeUser());
            $this->fail('A falha não apareceu.');
        } catch (RuntimeException) {
        }

        Receivable::flushEventListeners();
        $this->assertSame(0, Loss::count());
        $this->assertNull($receivable->fresh()->written_off_at);
        $this->assertNull($receivable->fresh()->loss_id);
    }

    public function test_lancamento_baixado_nunca_reabre(): void
    {
        $user = $this->financeUser();
        $receivable = $this->receivable(['total_cents' => 100000], '2026-10-01');
        $payment = $this->service->addPayment($receivable, '2026-10-02', 10000, $user);
        $loss = $this->service->writeOff($receivable, $user);

        $attempts = [
            'editar' => fn () => $this->service->update($receivable, ['customer_id' => $receivable->customer_id, 'order_number' => '1', 'total_cents' => 100000, 'due_date' => '2026-12-01'], $user),
            'pagar' => fn () => $this->service->addPayment($receivable, '2026-10-15', 1000, $user),
            'receber o resto' => fn () => $this->service->receiveRemaining($receivable, '2026-10-15', $user),
            'remover pagamento' => fn () => $this->service->removePayment($payment, $user),
            'excluir' => fn () => $this->service->delete($receivable, $user),
            'baixar de novo' => fn () => $this->service->writeOff($receivable, $user),
            'excluir o prejuízo' => fn () => app(Losses::class)->delete($loss, $user),
            'mudar o valor do prejuízo' => fn () => app(Losses::class)->update($loss, ['occurred_on' => '2026-10-15', 'amount_cents' => 1, 'item' => 'x', 'cause' => 'outro'], $user),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("\"{$label}\" passou num lançamento baixado.");
            } catch (DomainException) {
            }
        }

        $receivable->refresh();
        $this->assertSame(ReceivableStatus::Prejuizo, $receivable->status());
        $this->assertSame(10000, $receivable->paid_cents);
        $this->assertSame(1, $receivable->payments()->count());
        $this->assertSame(90000, $loss->fresh()->amount_cents);

        // Item e observação do prejuízo podem ser corrigidos.
        app(Losses::class)->update($loss, ['occurred_on' => '2026-10-15', 'amount_cents' => 90000, 'item' => 'OS 4521 — calote', 'cause' => 'outro', 'notes' => 'cliente sumiu'], $user);
        $this->assertSame('OS 4521 — calote', $loss->fresh()->item);
        $this->assertSame(LossCause::Inadimplencia, $loss->fresh()->cause);
    }

    public function test_so_vencido_e_nao_matriz_vira_prejuizo(): void
    {
        $future = $this->receivable([], '2026-10-15');
        $matrix = $this->receivable(['received_by_matrix' => true], '2026-10-01');

        foreach ([$future, $matrix] as $receivable) {
            try {
                $this->service->writeOff($receivable, $this->financeUser());
                $this->fail('Baixa indevida passou.');
            } catch (DomainException) {
            }
        }

        $this->assertSame(0, Loss::count());
    }

    public function test_recebimento_pela_matriz_nao_conta_no_saldo_lucro_nem_a_receber(): void
    {
        $user = $this->financeUser();
        $normal = $this->receivable(['total_cents' => 100000, 'parts_cost_cents' => 40000, 'freight_cents' => 0, 'labor_cents' => 60000]);
        $matrix = $this->receivable(['total_cents' => 50000, 'parts_cost_cents' => 10000, 'received_by_matrix' => true]);
        $matrixOverdue = $this->receivable(['total_cents' => 7000, 'received_by_matrix' => true], '2026-10-01', $this->financeCustomer('Cliente da matriz', '55993334444'));
        $this->service->addPayment($normal, '2026-10-05', 50000, $user);
        $this->service->addPayment($matrix, '2026-10-06', 20000, $user);

        $summary = app(FinanceSummary::class);
        $totals = $summary->openTotals();
        $this->assertSame(50000, $totals['receivables']);
        $this->assertSame(30000 + 7000, $totals['matrix']);
        $this->assertSame(0, $totals['delinquency']);
        $this->assertSame(['count' => 0, 'cents' => 0], $summary->receivableDeadlines()['vencidos']);

        $october = $summary->month('2026-10');
        $this->assertSame(50000, $october['received']);
        $this->assertSame(20000, $october['matrix']);
        // Lucro bruto do normal = 100.000 − 40.000 = 60.000; metade recebida = 30.000. A matriz não entra.
        $this->assertSame(30000, $october['profit']);
        $this->assertSame(50000, $october['balance']);
        $this->assertTrue($matrixOverdue->fresh()->isOverdue());
    }

    public function test_painel_conta_na_data_do_pagamento_nao_no_vencimento(): void
    {
        $user = $this->financeUser();
        $receivable = $this->receivable(['total_cents' => 100000], '2026-09-20');
        $this->service->addPayment($receivable, '2026-09-25', 40000, $user);
        $this->service->addPayment($receivable, '2026-10-02', 60000, $user);
        $this->payable(['due_date' => '2026-09-10', 'status' => 'pago', 'paid_on' => '2026-10-05', 'amount_cents' => 25000]);
        $this->payable(['due_date' => '2026-09-10', 'amount_cents' => 99900]);
        $this->providerPayment(['due_date' => '2026-09-01', 'paid_on' => '2026-10-07', 'amount_cents' => 18000]);
        Loss::create(['occurred_on' => '2026-10-03', 'amount_cents' => 5000, 'item' => 'Peça avariada', 'cause' => 'avariada']);

        $summary = app(FinanceSummary::class);
        $months = collect($summary->months())->keyBy('month');

        $this->assertSame(['2026-10', '2026-09'], $months->keys()->all());
        $this->assertSame(['paid' => 43000, 'received' => 60000, 'loss' => 5000, 'balance' => 60000 - 43000 - 5000], array_intersect_key($months['2026-10'], array_flip(['paid', 'received', 'loss', 'balance'])));
        $this->assertSame(40000, $months['2026-09']['received']);
        $this->assertSame(0, $months['2026-09']['paid']);
        $this->assertSame(99900, $summary->openTotals()['payables']);
        $this->assertSame(0, $summary->openTotals()['providers']);

        // Mês sem movimento: o corrente sempre aparece.
        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00'));
        $this->assertSame('2026-12', $summary->months()[0]['month']);
        $this->assertSame(0, $summary->month('2026-12')['balance']);
    }

    public function test_formulas_de_lucro(): void
    {
        $profit = app(Profit::class);

        // lucro bruto = total − peças − frete − prestador
        $this->assertSame(53000, $profit->gross(100000, 40000, 2000, 5000));
        // lucro da peça = (total − mão de obra) − peças − frete
        $this->assertSame(8000, $profit->part(100000, 50000, 40000, 2000));

        $receivable = $this->receivable(['total_cents' => 100000, 'parts_cost_cents' => 40000, 'labor_cents' => 50000, 'freight_cents' => 2000]);
        $this->assertSame(58000, $profit->grossFor($receivable));
        $this->assertSame(8000, $profit->partFor($receivable));
        $this->assertSame(0, $profit->providerCost($receivable));
        $this->assertSame(29000, $profit->realizedBy($receivable, 50000));

        // Cliente trouxe a peça: sem custo de peça.
        $brought = $this->receivable(['total_cents' => 30000, 'parts_cost_cents' => 99999, 'labor_cents' => 30000, 'freight_cents' => 0, 'customer_brought_part' => true]);
        $this->assertSame(0, $brought->parts_cost_cents);
        $this->assertSame(30000, $profit->grossFor($brought));
        $this->assertSame(0, $profit->partFor($brought));
    }

    public function test_cliente_novo_entra_pelo_whatsapp_e_placa_liga_o_carro(): void
    {
        $vehicle = Vehicle::create(['plate' => 'ABC1234', 'brand_model' => 'Fiat Strada']);

        // Vencimento hoje (à vista): cliente novo não tem crédito a prazo (a trava está em CreditTest).
        $first = $this->service->create(['customer_name' => 'Pedro Novo', 'customer_whatsapp' => '(55) 99876-5432', 'order_number' => '77', 'total_cents' => 5000, 'plate' => 'abc-1c34'], ['2026-10-15'], $this->financeUser())->first();
        $second = $this->service->create(['customer_name' => 'Pedro', 'customer_whatsapp' => '55998765432', 'order_number' => '78', 'total_cents' => 5000], ['2026-10-15'], $this->financeUser())->first();

        $this->assertSame(1, Customer::query()->where('whatsapp', '55998765432')->count());
        $this->assertSame($first->customer_id, $second->customer_id);
        $this->assertSame($vehicle->id, $first->vehicle_id);
        $this->assertSame('Fiat Strada', $first->vehicle_model);
    }

    public function test_conferencias_do_lancamento(): void
    {
        $customer = $this->financeCustomer();
        $user = $this->financeUser();
        $cases = [
            'sem valor' => [['customer_id' => $customer->id, 'order_number' => '1', 'total_cents' => 0], ['2026-10-20'], 'valor total'],
            'sem cliente' => [['order_number' => '1', 'total_cents' => 100], ['2026-10-20'], 'cliente'],
            'sem OS' => [['customer_id' => $customer->id, 'total_cents' => 100], ['2026-10-20'], 'número da OS'],
            'sem vencimento' => [['customer_id' => $customer->id, 'order_number' => '1', 'total_cents' => 100], [], 'vencimento'],
            'data inválida' => [['customer_id' => $customer->id, 'order_number' => '1', 'total_cents' => 100], ['2026-02-30'], 'inválida'],
            'parcelas demais' => [['customer_id' => $customer->id, 'order_number' => '1', 'total_cents' => 10000], array_fill(0, 61, '2026-10-20'), 'parcelas'],
            'negativo' => [['customer_id' => $customer->id, 'order_number' => '1', 'total_cents' => 100, 'freight_cents' => -1], ['2026-10-20'], 'negativo'],
        ];

        foreach ($cases as $label => [$data, $dates, $message]) {
            try {
                $this->service->create($data, $dates, $user);
                $this->fail("\"{$label}\" passou.");
            } catch (DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, Receivable::count());
    }

    public function test_lancamento_ja_recebido_e_exclusao(): void
    {
        $user = $this->financeUser();
        $paid = $this->receivable(['total_cents' => 15000, 'received_on' => '2026-10-14']);

        $this->assertSame(ReceivableStatus::Recebido, $paid->status());
        $this->assertSame('2026-10-14', $paid->received_on->toDateString());

        try {
            $this->service->delete($paid, $user);
            $this->fail('Excluiu lançamento com pagamento.');
        } catch (DomainException) {
        }

        $open = $this->receivable();
        $this->service->delete($open, $user);
        $this->assertNull($open->fresh());
    }
}
