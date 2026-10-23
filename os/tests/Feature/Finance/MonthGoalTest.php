<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseCategory;
use App\Models\RecurringBill;
use App\Models\User;
use App\Services\Finance\Credit;
use App\Services\Finance\MoneyRules;
use App\Services\Finance\MonthGoal;
use App\Services\Finance\Receivables;
use App\Services\Finance\RecurringBills;
use App\Services\Finance\Revenue;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Meta do mês: os vetores do `termometro`, da `cascata`, do `pontoEquilibrio`, do `caixa` e do `simulador` do
 * financeiro em Node (app/test/finance.test.js), em centavos, com o faturamento vindo do A receber.
 */
class MonthGoalTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private function goal(): MonthGoal
    {
        return app(MonthGoal::class);
    }

    private int $sequence = 0;

    /** Serviço lançado pelo A receber (o mesmo caminho da tela), cada um de um cliente a prazo diferente. */
    private function sale(string $date, int $total, array $data = []): void
    {
        $this->sequence++;
        $customer = $this->financeCustomer('Cliente '.$this->sequence, '5599100'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT));

        app(Receivables::class)->create($data + [
            'customer_id' => $customer->id,
            'order_number' => (string) (100 + $this->sequence),
            'service_date' => $date,
            'total_cents' => $total,
        ], [$date], $this->financeUser());
    }

    public function test_termometro_ritmo_projecao_e_quanto_falta_por_dia_util(): void
    {
        // Março/2026 não tem feriado nacional: 22 dias úteis, como o outubro sem feriados do teste do Node.
        Settings::set('finance.target_cents', 10_000_000);
        $this->sale('2026-03-02', 400_000);
        $this->sale('2026-03-03', 600_000);

        $t = $this->goal()->thermometer('2026-03', CarbonImmutable::parse('2026-03-03'));

        $this->assertSame(22.0, $t['days_total']);
        $this->assertSame(2.0, $t['days_passed']);
        $this->assertSame(500_000, $t['rhythm']);
        $this->assertSame(11_000_000, $t['projection']);
        $this->assertSame(450_000, $t['needed_per_day']);          // faltam 90.000 em 20 dias úteis
        $this->assertSame(2, $t['orders']);
        $this->assertSame(500_000, $t['ticket']);
        $this->assertTrue($t['early']);                            // 2 dias: a projeção ainda é ruído
    }

    public function test_termometro_usa_os_feriados_dos_ajustes(): void
    {
        Settings::set('finance.target_cents', 10_000_000);
        $this->assertSame(21.0, $this->goal()->thermometer('2026-10', CarbonImmutable::parse('2026-10-02'))['days_total']); // 12/10

        Settings::set('finance.extra_holidays', ['2026-10-30']);
        Settings::set('finance.saturday_weight', 0.5);
        $this->assertSame(20.0 + 2.5, $this->goal()->thermometer('2026-10', CarbonImmutable::parse('2026-10-02'))['days_total']);
    }

    public function test_cascata_e_equilibrio_com_as_contas_fixas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00'));
        $user = $this->financeUser();
        $supplier = $this->partsSupplier('Fornecedor da Folha');
        RecurringBill::create(['supplier_id' => $supplier->id, 'description' => 'Salários', 'category' => ExpenseCategory::Folha, 'due_day' => 5, 'amount_cents' => 2_000_000, 'first_month' => '2026-10']);
        RecurringBill::create(['supplier_id' => $supplier->id, 'description' => 'Aluguel', 'category' => ExpenseCategory::Fixo, 'due_day' => 10, 'amount_cents' => 500_000, 'first_month' => '2026-10']);
        $this->assertSame(2, app(RecurringBills::class)->generate('2026-10', $user));
        $this->assertSame(0, app(RecurringBills::class)->generate('2026-10', $user));

        $this->sale('2026-10-02', 10_000_000, ['labor_cents' => 2_500_000, 'parts_cost_cents' => 3_000_000]);
        app(MoneyRules::class)->save(['tax_pct' => '6', 'card_fee_pct' => '2', 'reserve_pct' => '3', 'withdrawal' => '10.000'], $user);

        $c = $this->goal()->cascade('2026-10');
        $this->assertSame(2_000_000, $c['payroll']);
        $this->assertSame(500_000, $c['fixed']);
        $this->assertSame(800_000, $c['taxes']);
        $this->assertSame(10_000_000 - 3_000_000 - 800_000, $c['contribution']);
        $this->assertSame(6_200_000 - 2_500_000, $c['result']);
        $this->assertSame(3_700_000 - 300_000, $c['partners']);
        $this->assertSame([], $c['missing']);

        $eq = $this->goal()->breakEven('2026-10', 1_000_000);
        $this->assertSame(2_500_000, $eq['fixed']);
        $this->assertEqualsWithDelta(1 - 0.33 - 0.06 - 0.02 - 0.03, $eq['margin_pct'], 1e-9);
        $this->assertSame((int) round(2_500_000 / 0.56), $eq['break_even']);
        $this->assertGreaterThan($eq['break_even'], $eq['with_withdrawal']);
    }

    public function test_os_sem_custo_de_peca_e_sinalizada_e_a_cascata_estima_o_custo(): void
    {
        $this->sale('2026-09-10', 100_000, ['labor_cents' => 20_000]);

        $r = app(Revenue::class)->month('2026-09');
        $this->assertSame(1, $r['no_cost_orders']);

        $c = $this->goal()->cascade('2026-09');
        $this->assertSame(33_000, $c['estimated']);               // 33% padrão de 1.000
        $this->assertSame(33_000, $c['costs']);
        $this->assertSame(['impostos (%)', 'maquininha (%)', 'reserva (%)'], $c['missing']);
    }

    public function test_faturamento_conta_parcelas_como_uma_os_sem_matriz_e_sem_saldo_anterior(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
        $customer = $this->financeCustomer();
        app(Receivables::class)->create(['customer_id' => $customer->id, 'order_number' => '10', 'service_date' => '2026-10-05', 'total_cents' => 90_000, 'parts_cost_cents' => 30_000], ['2026-10-05', '2026-11-05', '2026-12-05'], $this->financeUser());
        $this->receivable(['order_number' => '11', 'service_date' => '2026-10-06', 'total_cents' => 50_000, 'received_by_matrix' => true], null, $customer);
        app(Credit::class)->setOpeningBalance($customer, '700,00', '2026-10-01', $this->financeUser());

        $r = app(Revenue::class)->month('2026-10');
        $this->assertSame(1, $r['orders']);
        $this->assertSame(90_000, $r['revenue']);
        $this->assertSame(60_000, $r['gross']);
    }

    public function test_caixa_so_conta_dinheiro_de_verdade_e_pede_a_data_do_saldo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
        $customer = $this->financeCustomer();
        $first = $this->receivable(['total_cents' => 100_000], '2026-10-01', $customer);
        $second = $this->receivable(['total_cents' => 40_000], '2026-10-02', $customer);
        app(Receivables::class)->addPayment($first, '2026-10-01', 100_000, $this->financeUser())->update(['legacy_id' => 'planilha:1:pago']);
        app(Receivables::class)->addPayment($second, '2026-10-02', 40_000, $this->financeUser());

        $this->assertFalse($this->goal()->cash()['configured']);

        app(MoneyRules::class)->save(['cash_opening_date' => '2026-09-30', 'cash_opening' => '100'], $this->financeUser());
        $cash = $this->goal()->cash();
        $this->assertSame(40_000, $cash['in']);
        $this->assertSame(50_000, $cash['balance']);

        $this->payable(['status' => 'pago', 'paid_on' => '2026-10-02', 'amount_cents' => 5_000]);
        $this->assertSame(45_000, $this->goal()->cash()['balance']);
    }

    public function test_simulador(): void
    {
        $s = MonthGoal::simulate(80, 85_000, 10_000_000, 0.5, 2_000_000);

        $this->assertSame(6_800_000, $s['revenue']);
        $this->assertSame(3_400_000, $s['contribution']);
        $this->assertSame(1_400_000, $s['surplus']);
        $this->assertSame(3_200_000, $s['missing']);
        $this->assertSame(125_000, $s['ticket_for_target']);
        $this->assertSame(118, $s['orders_for_target']);
    }

    public function test_regras_do_dinheiro_conferem_o_que_e_digitado(): void
    {
        $rules = app(MoneyRules::class);
        $user = $this->financeUser();

        foreach ([['tax_pct' => '120'], ['tax_pct' => '50', 'card_fee_pct' => '30', 'reserve_pct' => '20'], ['extra_holidays' => '31/02/2026'], ['cash_opening' => '100']] as $bad) {
            try {
                $rules->save($bad, $user);
                $this->fail('Devia recusar: '.json_encode($bad));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $rules->save(['target' => '100.000', 'tax_pct' => '9,5', 'extra_holidays' => "24/12/2026\n2026-12-31, 24/12/2026", 'movable_holidays' => ['carnaval', 'inventado']], $user);
        $v = $rules->values();
        $this->assertSame(10_000_000, $v['target_cents']);
        $this->assertSame(9.5, $v['tax_pct']);
        $this->assertSame(['2026-12-24', '2026-12-31'], $v['extra_holidays']);
        $this->assertSame(['carnaval'], $v['movable_holidays']);

        $this->expectException(DomainException::class);
        $rules->save(['target' => '1'], User::factory()->atendimento()->create());
    }

    public function test_tela_meta_so_do_gestor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        $gestor = $this->financeUser();
        $atendimento = User::factory()->atendimento()->create();
        $this->sale('2026-10-05', 250_000, ['labor_cents' => 100_000, 'parts_cost_cents' => 80_000]);

        $this->actingAs($atendimento)->get(route('finance.goal'))->assertForbidden();
        Livewire::actingAs($atendimento)->test('pages::auth.finance.goal')->assertForbidden();
        $this->actingAs($atendimento)->get(route('finance'))->assertOk()->assertDontSee('Meta do mês');

        $this->actingAs($gestor)->get(route('finance'))->assertOk()->assertSee('Meta do mês');
        Livewire::actingAs($gestor)->test('pages::auth.finance.goal')
            ->assertSee('R$ 2.500,00')
            ->assertSee('Preencher nas regras do dinheiro')
            ->set('rules.target', '100.000')
            ->set('rules.tax_pct', '6')
            ->set('rules.card_fee_pct', '2')
            ->set('rules.reserve_pct', '3')
            ->set('rules.withdrawal', '10.000')
            ->set('rules.cash_opening_date', '2026-10-01')
            ->set('rules.cash_opening', '1.000')
            ->call('saveRules')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertDontSee('Preencher nas regras do dinheiro')
            ->assertSee('de R$ 100.000,00')
            ->set('simOrders', '80')
            ->set('simTicket', '850')
            ->assertSee('R$ 68.000,00')
            ->call('shiftMonth', -1)
            ->assertSee('set/2026')
            ->set('rules.tax_pct', 'abc')
            ->call('saveRules')
            ->assertHasErrors('rules');

        $this->assertSame(10_000_000, Settings::int('finance.target_cents'));
    }
}
