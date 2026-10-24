<?php

namespace Tests\Feature\Finance;

use App\Models\Payable;
use App\Models\User;
use App\Services\Finance\PayablesByMonth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * O que veio do artefato do Financeiro e faltava: "Por mês" dos boletos (vencimento, emissão ou pagamento, detalhe
 * por fornecedor, "Copiar resumo") e os totais de boletos na lista de fornecedores.
 */
class PayablesByMonthTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    }

    /** Dois fornecedores: outubro com um pago e um em aberto, novembro em aberto; um sem data de emissão. */
    private function boletos(): array
    {
        $alfa = $this->partsSupplier('Alfa Peças');
        $beta = $this->partsSupplier('Beta Distribuidora');

        return [
            'alfa' => $alfa,
            'beta' => $beta,
            'pago' => $this->payable(['supplier_id' => $alfa->id, 'document_number' => 'NF 10', 'issue_date' => '2026-09-20', 'due_date' => '2026-10-05', 'amount_cents' => 10000, 'status' => Payable::STATUS_PAID, 'paid_on' => '2026-10-04']),
            'vencido' => $this->payable(['supplier_id' => $beta->id, 'document_number' => 'NF 20', 'issue_date' => '2026-09-25', 'due_date' => '2026-10-10', 'amount_cents' => 25050]),
            'novembro' => $this->payable(['supplier_id' => $alfa->id, 'document_number' => 'NF 30', 'due_date' => '2026-11-10', 'amount_cents' => 5000]),
        ];
    }

    public function test_tabela_por_vencimento_emissao_e_pagamento(): void
    {
        $this->boletos();
        $service = app(PayablesByMonth::class);

        $byDue = $service->table('vencimento');
        $this->assertSame(['2026-11', '2026-10'], array_column($byDue['rows'], 'month'));
        $this->assertSame(['count' => 2, 'open' => 25050, 'paid' => 10000, 'total' => 35050], array_intersect_key($byDue['rows'][1], array_flip(['count', 'open', 'paid', 'total'])));
        $this->assertSame(['count' => 3, 'open' => 30050, 'paid' => 10000, 'total' => 40050], $byDue['total']);

        // Emissão: o boleto sem data de emissão fica em "sem data".
        $byIssue = $service->table('emissao');
        $this->assertSame(['2026-09', ''], array_column($byIssue['rows'], 'month'));
        $this->assertSame('sem data', $byIssue['rows'][1]['label']);

        // Pagamento: só os pagos, pelo mês em que foram pagos.
        $byPayment = $service->table('pagamento');
        $this->assertSame(['2026-10'], array_column($byPayment['rows'], 'month'));
        $this->assertSame(['count' => 1, 'open' => 0, 'paid' => 10000, 'total' => 10000], $byPayment['total']);

        // Base desconhecida volta para o vencimento.
        $this->assertSame($byDue, $service->table('qualquer'));
    }

    public function test_detalhe_do_mes_por_fornecedor_e_resumo_para_copiar(): void
    {
        $this->boletos();
        $service = app(PayablesByMonth::class);

        $detail = $service->detail('vencimento', '2026-10');
        $this->assertSame(['Alfa Peças', 'Beta Distribuidora'], $detail->keys()->all());
        $this->assertSame(25050, $detail['Beta Distribuidora']['cents']);
        $this->assertSame(['NF 10'], $detail['Alfa Peças']['items']->pluck('document_number')->all());

        $summary = $service->summary('vencimento');
        $this->assertStringStartsWith("CONTAS A PAGAR - MULTMEC\nPor mês de vencimento\nMês | Boletos | Em aberto | Pagos | Total\n", $summary);
        $this->assertStringContainsString('| 2 | R$ 250,50 | R$ 100,00 | R$ 350,50', $summary);
        $this->assertStringEndsWith('Total | 3 | R$ 300,50 | R$ 100,00 | R$ 400,50', $summary);
    }

    public function test_tela_troca_a_base_abre_o_mes_e_o_boleto_abre_no_a_pagar(): void
    {
        $data = $this->boletos();
        $this->actingAs(User::factory()->atendimento()->create());

        $this->get(route('finance.payables.monthly'))->assertOk()->assertSee('Boletos por mês')->assertSee('Copiar resumo');

        Livewire::test('pages::auth.finance.payables-monthly')
            ->assertSee('Toque em um mês para ver os boletos dele por fornecedor.')
            ->call('select', '2026-10')
            ->assertSet('selected', '2026-10')
            ->assertSee('Beta Distribuidora')
            ->assertSee('vencido há 5 d')
            ->assertSee(route('finance.payables', ['abrir' => $data['vencido']->id]), false)
            ->call('select', '2026-10')
            ->assertSet('selected', '')
            ->call('select', '2026-11')
            ->set('base', 'emissao')
            ->assertSet('selected', '')
            ->call('select', 'sem-data')
            ->assertSee('NF 30')
            ->set('base', 'nada')
            ->assertSet('base', 'vencimento');

        Livewire::withQueryParams(['abrir' => (string) $data['vencido']->id])
            ->test('pages::auth.finance.payables')
            ->assertSet('editing', true)
            ->assertSet('payableId', $data['vencido']->id)
            ->assertSet('openId', '');
    }

    public function test_fornecedores_mostram_o_em_aberto_e_os_vencidos_e_vem_primeiro_quem_deve_mais(): void
    {
        $data = $this->boletos();
        $this->partsSupplier('Aaa Sem Boleto');
        $this->actingAs(User::factory()->atendimento()->create());

        $screen = Livewire::test('pages::auth.suppliers.index');

        $this->assertSame(
            ['Beta Distribuidora', 'Alfa Peças', 'Aaa Sem Boleto'],
            $screen->instance()->suppliers->pluck('name')->all()
        );
        $this->assertSame(['open' => 25050, 'open_count' => 1, 'count' => 1, 'overdue' => 1], $screen->instance()->payableStats[$data['beta']->id]);
        $this->assertSame(['open' => 5000, 'open_count' => 1, 'count' => 2, 'overdue' => 0], $screen->instance()->payableStats[$data['alfa']->id]);

        $screen->assertSee('1 vencido')->assertSee('R$ 250,50')->assertSee('1 em aberto · 2 no total');

        // A janela do fornecedor leva aos boletos dele no "A pagar" (todos, de todos os meses).
        $screen->call('edit', $data['alfa']->id)
            ->assertSee(route('finance.payables', ['fornecedor' => $data['alfa']->id, 'situacao' => 'todos', 'mes' => 'todos']));
    }
}
