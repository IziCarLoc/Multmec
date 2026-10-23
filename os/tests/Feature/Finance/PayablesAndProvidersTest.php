<?php

namespace Tests\Feature\Finance;

use App\Enums\SupplierKind;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Services\Finance\Delinquency;
use App\Services\Finance\FinanceSummary;
use App\Services\Finance\Payables;
use App\Services\Finance\ProviderPayments;
use App\Services\Finance\Receivables;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

class PayablesAndProvidersTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    }

    public function test_parcelas_do_boleto_como_no_artefato_valor_de_cada_parcela(): void
    {
        $supplier = $this->partsSupplier();
        $dates = ['2026-11-05', '2026-12-05', '2027-01-05'];

        $created = app(Payables::class)->create(['supplier_id' => $supplier->id, 'document_number' => 'NF 1234', 'amount_cents' => '150'], $dates, $this->financeUser());

        $this->assertSame([15000, 15000, 15000], $created->pluck('amount_cents')->all());
        $this->assertSame($dates, $created->map(fn (Payable $p) => $p->due_date->toDateString())->all());
        $this->assertSame(['1/3', '2/3', '3/3'], $created->map->installmentLabel()->all());
        $this->assertSame(['NF 1234'], $created->pluck('document_number')->unique()->all());
        $this->assertSame(['aberto'], $created->pluck('status')->unique()->all());
    }

    public function test_parcelas_do_boleto_pelo_total_dividem_em_centavos_exatos(): void
    {
        $created = app(Payables::class)->create(['supplier_id' => $this->partsSupplier()->id, 'amount_cents' => '100,00'], ['2026-11-05', '2026-12-05', '2027-01-05'], $this->financeUser(), amountIsTotal: true);

        $this->assertSame([3334, 3333, 3333], $created->pluck('amount_cents')->all());
    }

    public function test_fornecedor_novo_pelo_nome_entra_no_cadastro_sem_repetir(): void
    {
        $service = app(Payables::class);
        $first = $service->create(['supplier_name' => 'Autopeças Silva', 'amount_cents' => 1000], ['2026-10-20'], $this->financeUser())->first();
        $second = $service->create(['supplier_name' => '  autopeças   silva ', 'amount_cents' => 2000], ['2026-10-21'], $this->financeUser())->first();

        $this->assertSame($first->supplier_id, $second->supplier_id);
        $this->assertSame(1, Supplier::count());
        $this->assertSame(SupplierKind::Pecas, Supplier::first()->kind);

        $this->expectException(DomainException::class);
        $service->create(['amount_cents' => 1000], ['2026-10-20'], $this->financeUser());
    }

    public function test_editar_uma_parcela_do_boleto_nao_mexe_nas_outras_e_marcar_pago(): void
    {
        $service = app(Payables::class);
        $user = $this->financeUser();
        [$first, $second] = $service->create(['supplier_id' => $this->partsSupplier()->id, 'amount_cents' => 10000], ['2026-10-10', '2026-11-10'], $user)->all();
        $before = $second->fresh()->getAttributes();

        $this->assertTrue($first->isOverdue());
        $this->assertFalse($second->isOverdue());

        $service->update($first, ['supplier_id' => $first->supplier_id, 'amount_cents' => '120', 'due_date' => '2026-10-12', 'document_number' => 'NF 9'], $user);
        $this->assertSame(12000, $first->fresh()->amount_cents);
        $this->assertEquals($before, $second->fresh()->getAttributes());
        $this->assertSame(2, Payable::count());

        $paid = $service->markPaid($first->fresh(), '2026-10-15', $user);
        $this->assertTrue($paid->isPaid());
        $this->assertSame('2026-10-15', $paid->paid_on->toDateString());
        $this->assertSame($user->id, $paid->paid_by);
        $this->assertFalse($paid->isOverdue());

        $this->expectException(DomainException::class);
        $service->markPaid($paid, '2026-10-16', $user);
    }

    public function test_cartoes_de_vencimento_do_a_pagar(): void
    {
        $this->payable(['due_date' => '2026-10-01', 'amount_cents' => 100]);
        $this->payable(['due_date' => '2026-10-15', 'amount_cents' => 200]);
        $this->payable(['due_date' => '2026-10-20', 'amount_cents' => 400]);
        $this->payable(['due_date' => '2026-11-20', 'amount_cents' => 800]);
        $this->payable(['due_date' => '2026-10-02', 'amount_cents' => 1600, 'status' => 'pago', 'paid_on' => '2026-10-02']);

        $deadlines = app(FinanceSummary::class)->payableDeadlines();

        $this->assertSame(['count' => 1, 'cents' => 100], $deadlines['vencidos']);
        $this->assertSame(['count' => 1, 'cents' => 200], $deadlines['hoje']);
        $this->assertSame(['count' => 1, 'cents' => 400], $deadlines['7dias']);
        $this->assertSame(['count' => 3, 'cents' => 700], $deadlines['mes']);
        $this->assertSame(1500, app(FinanceSummary::class)->openTotals()['payables']);
    }

    public function test_boleto_lancado_ja_pago(): void
    {
        $payable = app(Payables::class)->create(['supplier_id' => $this->partsSupplier()->id, 'amount_cents' => 5000, 'status' => 'pago', 'paid_on' => '2026-10-14'], ['2026-10-14'], $this->financeUser())->first();

        $this->assertTrue($payable->isPaid());
        $this->assertSame(5000, app(FinanceSummary::class)->month('2026-10')['paid']);
    }

    public function test_pagamento_a_prestador_so_para_quem_presta_servico(): void
    {
        $service = app(ProviderPayments::class);
        $user = $this->financeUser();

        try {
            $service->create(['supplier_id' => $this->partsSupplier()->id, 'amount_cents' => 1000, 'due_date' => '2026-10-20'], $user);
            $this->fail('Fornecedor só de peças virou prestador.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('presta serviço', $e->getMessage());
        }

        $douglas = $service->create(['supplier_name' => 'Douglas', 'order_number' => '4521', 'plate' => 'abc-1d23', 'amount_cents' => '180', 'due_date' => '2026-10-20'], $user);
        $this->assertSame(SupplierKind::Servico, $douglas->supplier->kind);
        $this->assertSame('ABC1D23', $douglas->plate);
        $this->assertSame(18000, $douglas->amount_cents);
        $this->assertSame(18000, app(FinanceSummary::class)->openTotals()['providers']);

        $both = Supplier::create(['name' => 'Retífica', 'kind' => SupplierKind::Ambos, 'active' => true]);
        $service->create(['supplier_id' => $both->id, 'amount_cents' => 5000, 'due_date' => '2026-10-10', 'paid_on' => '2026-10-11'], $user);

        $paid = $service->markPaid($douglas, '2026-10-15', $user);
        $this->assertTrue($paid->isPaid());
        $this->assertSame(18000 + 5000, app(FinanceSummary::class)->month('2026-10')['paid']);
        $this->assertSame(0, ProviderPayment::query()->open()->count());
    }

    public function test_inadimplente_soma_o_integral_em_aberto_do_cliente(): void
    {
        $service = app(Receivables::class);
        $user = $this->financeUser();
        $maria = $this->financeCustomer('Maria', '55991110001');
        $joao = $this->financeCustomer('João', '55991110002');
        $ana = $this->financeCustomer('Ana', '55991110003');

        // Maria: 3 parcelas, a primeira vencida e paga em parte; as outras ainda vão vencer. Mais um serviço futuro.
        [$p1] = $service->create(['customer_id' => $maria->id, 'order_number' => '1', 'total_cents' => 30000], ['2026-09-30', '2026-10-30', '2026-11-30'], $user)->all();
        $service->addPayment($p1, '2026-10-01', 4000, $user);
        $this->receivable(['total_cents' => 5000], '2027-01-10', $maria);
        // João: só lançamento que ainda não venceu.
        $this->receivable(['total_cents' => 9000], '2026-10-20', $joao);
        // Ana: o vencido dela já foi baixado como prejuízo.
        $service->writeOff($this->receivable(['total_cents' => 7000], '2026-09-01', $ana), $user);

        $rows = app(Delinquency::class)->customers();

        $this->assertCount(1, $rows);
        $this->assertSame('Maria', $rows[0]['name']);
        $this->assertSame(30000 - 4000 + 5000, $rows[0]['open_cents']);
        $this->assertSame(6000, $rows[0]['overdue_cents']);
        $this->assertSame(1, $rows[0]['overdue_count']);
        $this->assertSame(15, $rows[0]['days_late']);
        $this->assertSame([$p1->id], $rows[0]['overdue']->pluck('id')->all());
        $this->assertSame(31000, app(FinanceSummary::class)->openTotals()['delinquency']);
        $this->assertSame(4, Receivable::query()->open()->where('customer_id', $maria->id)->count());
    }
}
