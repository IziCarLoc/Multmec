<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Finance\Credit;
use App\Services\Finance\Receivables;
use App\Services\Finance\Reports;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Crédito a prazo: os vetores de `situacaoCliente`, `receberDoCliente`, `textoCobranca`, `carteira` e `alertas` do
 * financeiro em Node, em centavos, e a trava que vale no A receber (a da entrega está no DeliveryTest).
 */
class CreditTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private function credit(): Credit
    {
        return app(Credit::class);
    }

    private function lessor(int $limitCents, string $name = 'Locadora Exemplo'): Customer
    {
        return Customer::create(['name' => $name, 'whatsapp' => '55991230000', 'credit_kind' => 'locadora', 'credit_days' => 7, 'credit_limit_cents' => $limitCents]);
    }

    /** Lançamento pelo serviço, "hoje" = a data do serviço. */
    private function service(Customer $customer, string $date, int $total, string $due, string $number): Receivable
    {
        $this->travelTo(CarbonImmutable::parse($date.' 10:00'));

        return app(Receivables::class)->create(['customer_id' => $customer->id, 'order_number' => $number, 'service_date' => $date, 'total_cents' => $total, 'plate' => 'ABC1D23'], [$due], $this->financeUser())->first();
    }

    public function test_recebimento_quita_primeiro_o_saldo_anterior_e_depois_o_mais_antigo(): void
    {
        $customer = $this->lessor(500_000);
        $this->service($customer, '2026-09-10', 80_000, '2026-09-17', '1');
        $this->service($customer, '2026-09-20', 60_000, '2026-09-27', '2');
        $this->credit()->setOpeningBalance($customer, '2.000', '2026-09-01', $this->financeUser());
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00'));

        $applied = $this->credit()->receive($customer, '2.500', '2026-09-25', $this->financeUser());

        $this->assertSame([200_000, 50_000], array_column($applied, 'cents'));
        $this->assertTrue($applied[0]['receivable']->opening_balance);
        $open = $this->credit()->openItems($customer);
        $this->assertSame([['1', 30_000], ['2', 60_000]], $open->map(fn (Receivable $r) => [$r->order_number, $r->balanceCents()])->all());

        $this->expectException(DomainException::class);
        $this->credit()->receive($customer, '900,01', '2026-09-25', $this->financeUser());
    }

    public function test_cliente_trava_ao_passar_do_atraso_maximo_ou_do_limite(): void
    {
        $customer = $this->lessor(300_000);
        $this->service($customer, '2026-09-01', 200_000, '2026-09-08', '10');

        $this->assertSame(Credit::OK, $this->credit()->situation($customer, CarbonImmutable::parse('2026-09-05'))['status']);
        $this->assertSame(Credit::ATTENTION, $this->credit()->situation($customer, CarbonImmutable::parse('2026-09-12'))['status']);   // 4 dias de atraso
        $late = $this->credit()->situation($customer, CarbonImmutable::parse('2026-09-30'));                                           // 22 dias
        $this->assertSame(Credit::LOCKED, $late['status']);
        $this->assertSame(200_000, $late['bands']['de_16_a_30']);
        $this->assertSame(200_000, $late['overdue']);

        $this->service($customer, '2026-09-29', 150_000, '2026-09-29', '11');                                                          // à vista: passa
        $over = $this->credit()->situation($customer, CarbonImmutable::parse('2026-09-29'));
        $this->assertSame(Credit::LOCKED, $over['status']);                                                                            // 3.500 > limite 3.000
        $this->assertStringContainsString('limite', $over['reason']);
    }

    public function test_texto_de_cobranca_lista_o_aberto_e_o_vencido_sem_juros(): void
    {
        $customer = $this->lessor(500_000);
        $this->service($customer, '2026-09-01', 123_450, '2026-09-08', '77');

        $text = $this->credit()->collectionText($customer, CarbonImmutable::parse('2026-09-20'));

        $this->assertStringContainsString('OS 77', $text);
        $this->assertStringContainsString('1.234,50', $text);
        $this->assertStringContainsString('vencida há 12 dia', $text);
        $this->assertStringContainsString('*Total em aberto: R$ 1.234,50*', $text);
        $this->assertStringContainsString('Limite combinado: R$ 5.000,00', $text);
        $this->assertDoesNotMatchRegularExpression('/juros|multa/i', $text);
    }

    public function test_avulso_sem_recebimento_vira_um_aviso_so_e_fica_fora_da_carteira(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
        $this->receivable(['total_cents' => 30_000], '2026-09-01', $this->walkInCustomer('Ana Exemplo', '55990000011'));
        $this->receivable(['total_cents' => 20_000], '2026-09-02', $this->walkInCustomer('Beto Exemplo', '55990000012'));

        $alerts = app(Reports::class)->alerts();

        $this->assertCount(0, array_filter($alerts, fn ($a) => $a['kind'] === 'cliente_travado'));
        $this->assertCount(1, array_filter($alerts, fn ($a) => $a['kind'] === 'avulso_em_aberto'));
        $this->assertCount(0, $this->credit()->portfolio());
    }

    public function test_a_prazo_sem_credito_trava_no_a_receber_e_o_gestor_libera_com_motivo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        $service = app(Receivables::class);
        $walkIn = $this->walkInCustomer();
        $atendimento = User::factory()->atendimento()->create();
        $data = ['customer_id' => $walkIn->id, 'order_number' => '500', 'total_cents' => 30_000];

        $this->assertCount(1, $service->create($data, ['2026-10-15'], $atendimento));                                  // à vista
        $this->assertCount(1, $service->create($data + ['received_on' => '2026-10-15'], ['2026-10-25'], $atendimento)); // pago na hora

        foreach ([['2026-10-25'], ['2026-10-15', '2026-11-15']] as $dates) {
            try {
                $service->create($data, $dates, $atendimento);
                $this->fail('A prazo sem crédito devia travar.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('não tem crédito a prazo', $e->getMessage());
            }
        }

        try {
            $service->create($data + ['credit_release' => 'Cliente antigo, combinou'], ['2026-10-25'], $atendimento);
            $this->fail('Só o gestor libera.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Só o gestor', $e->getMessage());
        }

        $released = $service->create($data + ['credit_release' => 'Cliente antigo, combinou pagar dia 25'], ['2026-10-25'], $this->financeUser());
        $this->assertCount(1, $released);
        $log = AuditLog::query()->where('action', 'credit.released')->sole();
        $this->assertStringContainsString('combinou pagar dia 25', $log->reason);
        $this->assertSame([$released->first()->id], $log->changes['lancamentos']);

        // Recebimento pela matriz não passa pela trava (a cobrança é da matriz).
        $this->assertCount(1, $service->create($data + ['received_by_matrix' => true], ['2026-11-30'], $atendimento));

        // Adiar o vencimento é vender a prazo.
        $this->expectException(DomainException::class);
        $service->update(Receivable::query()->where('due_date', '2026-10-15')->firstOrFail(), $data + ['due_date' => '2026-11-15'], $atendimento);
    }

    public function test_limite_e_liberacao_com_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        $service = app(Receivables::class);
        $atendimento = User::factory()->atendimento()->create();
        $customer = $this->lessor(100_000, 'Frota Exemplo');
        $service->create(['customer_id' => $customer->id, 'order_number' => '1', 'total_cents' => 80_000], ['2026-10-22'], $atendimento);

        try {
            $service->create(['customer_id' => $customer->id, 'order_number' => '2', 'total_cents' => 30_000], ['2026-10-22'], $atendimento);
            $this->fail('Passaria do limite.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('passaria do limite', $e->getMessage());
            $this->assertStringContainsString('R$ 800,00', $e->getMessage());
        }

        try {
            $this->credit()->release($customer, '2026-10-20', 'Fechamento na sexta', $atendimento);
            $this->fail('Só o gestor libera.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->credit()->release($customer->fresh(), '2026-10-20', 'Fechamento combinado na sexta', $this->financeUser());
        $this->assertNull($this->credit()->warning($customer->fresh(), 30_000, true));
        $this->assertCount(1, $service->create(['customer_id' => $customer->id, 'order_number' => '2', 'total_cents' => 30_000], ['2026-10-22'], $atendimento));
        $this->assertStringContainsString('Liberação do gestor até 20/10/2026', AuditLog::query()->where('action', 'credit.released')->sole()->reason);

        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00'));
        $this->assertNotNull($this->credit()->warning($customer->fresh(), 10_000, true));
    }

    public function test_extrato_com_saldo_corrido_e_politica_de_credito_so_do_gestor(): void
    {
        $customer = $this->lessor(500_000);
        $first = $this->service($customer, '2026-09-10', 80_000, '2026-09-17', '1');
        $this->credit()->setOpeningBalance($customer, '200', '2026-09-01', $this->financeUser());
        app(Receivables::class)->addPayment($first, '2026-09-20', 30_000, $this->financeUser());

        $rows = $this->credit()->statement($customer);
        $this->assertSame(['saldo', 'os', 'pagamento'], array_column($rows, 'kind'));
        $this->assertSame([20_000, 100_000, 70_000], array_column($rows, 'balance'));

        $this->credit()->updateTerms($customer, ['kind' => 'locadora', 'days' => '7', 'limit' => '5.000,00'], User::factory()->atendimento()->create()); // nada mudou
        $this->expectException(DomainException::class);
        $this->credit()->updateTerms($customer, ['kind' => 'frota', 'days' => '30', 'limit' => '9.000'], User::factory()->atendimento()->create());
    }

    public function test_tela_credito(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        $customer = $this->lessor(300_000);
        $this->receivable(['total_cents' => 250_000, 'order_number' => '88'], '2026-09-20', $customer);
        $atendimento = User::factory()->atendimento()->create();

        Livewire::actingAs($atendimento)->test('pages::auth.finance.credit')
            ->assertSee('Locadora Exemplo')
            ->assertSee('TRAVADO')
            ->assertSee('atraso de 25 dias')
            ->assertDontSee('Saldo anterior')
            ->call('open', 'extrato', $customer->id)
            ->assertSee('OS 88')
            ->assertSee('vencida há 25 dia(s)')
            ->call('open', 'receber', $customer->id)
            ->set('amount', '1.000')
            ->call('receive')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->call('open', 'saldo', $customer->id)
            ->set('amount', '100')
            ->call('saveOpening')
            ->assertHasErrors('dialog');

        $this->assertSame(100_000, Receivable::query()->where('order_number', '88')->value('paid_cents'));

        $walkIn = $this->walkInCustomer('Transportes Exemplo', '55990000099');
        Livewire::actingAs($this->financeUser())->test('pages::auth.finance.credit')
            ->set('addSearch', 'Transportes')
            ->assertSee('Transportes Exemplo')
            ->call('open', 'credito', $walkIn->id)
            ->set('kind', 'frota')
            ->set('days', '30')
            ->set('limit', '2.000')
            ->call('saveTerms')
            ->assertHasNoErrors()
            ->assertSee('Transportes Exemplo')
            ->call('open', 'liberar', $customer->id)
            ->set('date', '2026-10-17')
            ->set('reason', 'Combinou acerto na sexta')
            ->call('release')
            ->assertSee('liberado até 17/10')
            ->set('lockDays', '30')
            ->set('warningPct', '90')
            ->call('saveRules')
            ->assertHasNoErrors();

        $this->assertSame([30, 200_000], [$walkIn->fresh()->credit_days, $walkIn->fresh()->credit_limit_cents]);
        // Deve 1.500 de 3.000 e a trava passou para 30 dias: com 25 dias de atraso fica só em atenção.
        $this->assertSame(Credit::ATTENTION, $this->credit()->situation($customer->fresh())['status']);
    }

    public function test_cadastro_do_cliente_com_credito(): void
    {
        $customer = $this->walkInCustomer('Frota Nova', '55990000077');

        Livewire::actingAs($this->financeUser())->test('pages::auth.registry.index')
            ->call('editCustomer', $customer->id)
            ->assertSee('Crédito a prazo')
            ->set('form.credit_kind', 'frota')
            ->set('form.credit_days', '15')
            ->set('form.credit_limit', '3.000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['frota', 15, 300_000], [$customer->fresh()->credit_kind->value, $customer->fresh()->credit_days, $customer->fresh()->credit_limit_cents]);
        $this->assertTrue(AuditLog::query()->where('action', 'customer.credit_updated')->exists());

        Livewire::actingAs(User::factory()->atendimento()->create())->test('pages::auth.registry.index')
            ->call('editCustomer', $customer->id)
            ->assertSee('são definidos pelo gestor')
            ->set('form.credit_limit', '90.000')
            ->call('save')
            ->assertHasErrors('form.credit_limit');

        $this->assertSame(300_000, $customer->fresh()->credit_limit_cents);
    }
}
