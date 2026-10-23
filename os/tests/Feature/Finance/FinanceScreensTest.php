<?php

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\Loss;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\Receivables;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

class FinanceScreensTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    }

    public function test_atendimento_nao_ve_lucro_nem_margem_em_lugar_nenhum(): void
    {
        $this->financeFixtures();
        $receivable = Receivable::query()->orderBy('id')->first();
        $atendimento = User::factory()->atendimento()->create();

        foreach (['finance', 'finance.receivables', 'finance.payables', 'finance.delinquents', 'finance.losses', 'finance.providers'] as $route) {
            $this->actingAs($atendimento)->get(route($route))->assertOk()->assertDontSee('Lucro')->assertDontSee('lucro')->assertDontSee('argem');
        }

        Livewire::withQueryParams(['mes' => 'todos'])->actingAs($atendimento)->test('pages::auth.finance.receivables')
            ->call('edit', $receivable->id)
            ->assertSee('Custo da peça')
            ->assertDontSee('Lucro')->assertDontSee('lucro')
            ->call('create')
            ->set('form.total', '1.000')->set('form.parts_cost', '400')
            ->assertDontSee('Lucro')->assertDontSee('data-test="previa-lucro"', false);

        Livewire::actingAs($atendimento)->test('pages::auth.finance.dashboard')
            ->call('selectMonth', '2026-10')
            ->assertDontSee('Lucro')->assertDontSee('lucro');
    }

    public function test_gestor_ve_lucro_no_painel_na_lista_e_no_formulario(): void
    {
        $this->financeFixtures();
        $receivable = Receivable::query()->orderBy('id')->first();
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor)->get(route('finance'))->assertOk()->assertSee('Lucro bruto')->assertSeeInOrder(['Por mês', 'Lucro bruto']);
        $this->actingAs($gestor)->get(route('finance.receivables', ['mes' => 'todos', 'situacao' => 'todos']))->assertOk()->assertSee('lucro R$ 580,00');

        Livewire::actingAs($gestor)->test('pages::auth.finance.receivables')
            ->call('edit', $receivable->id)
            ->assertSee('Lucro da peça')->assertSee('Lucro bruto')
            ->call('create')
            ->set('form.total', '1.000')->set('form.parts_cost', '400')->set('form.labor', '500')->set('form.freight', '20')
            ->assertSee('data-test="previa-lucro"', false)->assertSee('R$ 80,00')->assertSee('R$ 580,00');
    }

    public function test_tela_a_receber_lanca_parcelas_e_valor_digitado(): void
    {
        $customer = $this->financeCustomer();
        $this->actingAs($this->financeUser());

        Livewire::test('pages::auth.finance.receivables')
            ->call('create')
            ->set('form.order_number', '4521')
            ->call('pickCustomer', $customer->id)
            ->set('form.total', '100')
            ->set('installments', true)
            ->set('installmentCount', 3)
            ->assertSeeInOrder(['Parcela 1', 'R$ 33,34', 'Parcela 2', 'R$ 33,33', 'Parcela 3', 'R$ 33,33'])
            ->set('dueDates', ['2026-11-10', '2026-12-10', ''])
            ->call('save')
            ->assertHasErrors('form')
            ->set('dueDates', ['2026-11-10', '2026-12-10', '2027-01-10'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertDispatched('toast');

        $this->assertSame([3334, 3333, 3333], Receivable::orderBy('id')->pluck('total_cents')->all());

        Livewire::test('pages::auth.finance.receivables')
            ->call('create')
            ->set('form.order_number', '4522')
            ->call('pickCustomer', $customer->id)
            ->set('form.total', '1.234')
            ->set('form.due_date', '2026-10-30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(123400, Receivable::query()->where('order_number', '4522')->value('total_cents'));
    }

    public function test_tela_a_receber_cadastra_cliente_novo_e_recusa_sem_valor(): void
    {
        $this->actingAs($this->financeUser());

        Livewire::test('pages::auth.finance.receivables')
            ->call('create')
            ->set('form.order_number', '9')
            ->set('newCustomer', true)
            ->set('form.customer_name', 'Cliente Balcão')
            ->set('form.customer_whatsapp', '(55) 99111-2233')
            ->call('save')
            ->assertHasErrors('form')
            ->assertSee('Informe o valor total a cobrar.')
            ->set('form.total', '250')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Cliente Balcão', Receivable::first()->customer->name);
    }

    public function test_tela_a_receber_pagamento_parcial_recebido_e_prejuizo(): void
    {
        $user = $this->financeUser();
        $overdue = $this->receivable(['total_cents' => 100000], '2026-10-01');
        $this->actingAs($user);

        $screen = Livewire::test('pages::auth.finance.receivables')
            ->call('edit', $overdue->id)
            ->assertSet('paymentAmount', '1.000,00')
            ->set('paymentDate', '2026-10-10')
            ->set('paymentAmount', '2.000')
            ->call('addPayment')
            ->assertHasErrors('payment')
            ->set('paymentAmount', '300')
            ->call('addPayment')
            ->assertHasNoErrors()
            ->assertSet('paymentAmount', '700,00')
            ->assertSee('Pago até agora:')->assertSee('R$ 300,00')->assertSee('R$ 700,00')
            ->assertSee('Lançar como prejuízo')
            ->set('confirm', 'baixa')
            ->assertSee('Dar baixa no saldo em aberto (R$ 700,00)')
            ->call('writeOff')
            ->assertSet('editing', false);

        $loss = Loss::first();
        $this->assertSame(70000, $loss->amount_cents);
        $this->assertSame($loss->id, $overdue->fresh()->loss_id);

        $screen->call('edit', $overdue->id)
            ->assertSee('Baixado como prejuízo em 15/10/2026')
            ->assertDontSee('data-test="salvar-receber"', false)
            ->assertDontSee('data-test="lancar-prejuizo"', false);

        $paid = $this->receivable(['total_cents' => 5000]);
        $screen->call('edit', $paid->id)->set('paymentDate', '2026-10-15')->call('receiveRemaining');
        $this->assertTrue($paid->fresh()->status()->value === 'recebido');
    }

    public function test_cartao_do_painel_abre_a_lista_filtrada(): void
    {
        $late = $this->receivable(['total_cents' => 1000], '2026-10-01', $this->financeCustomer('Atrasado da Silva', '55990000001'));
        $this->receivable(['total_cents' => 2000], '2026-12-01', $this->financeCustomer('Futuro de Souza', '55990000002'));
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor)->get(route('finance'))
            ->assertSee(route('finance.receivables', ['prazo' => 'vencidos']))
            ->assertSee(route('finance.payables', ['prazo' => 'hoje']));

        Livewire::withQueryParams(['prazo' => 'vencidos'])->actingAs($gestor)->test('pages::auth.finance.receivables')
            ->assertSee('Atrasado da Silva')
            ->assertDontSee('Futuro de Souza')
            ->call('setWindow', 'vencidos')
            ->assertSet('window', '')
            ->call('setMonth', 'todos')
            ->assertSee('Futuro de Souza');

        Livewire::withQueryParams(['abrir' => (string) $late->id])->actingAs($gestor)->test('pages::auth.finance.receivables')
            ->assertSet('editing', true)
            ->assertSet('receivableId', $late->id);
    }

    public function test_painel_mostra_o_mes_tocado_na_tabela(): void
    {
        $user = $this->financeUser();
        $receivable = $this->receivable(['total_cents' => 100000], '2026-09-10');
        app(Receivables::class)->addPayment($receivable, '2026-09-12', 45600, $user);
        $this->payable(['status' => 'pago', 'paid_on' => '2026-10-02', 'amount_cents' => 12300]);

        Livewire::actingAs($user)->test('pages::auth.finance.dashboard')
            ->assertSee('out/2026')
            ->assertSee('R$ 123,00')
            ->call('selectMonth', '2026-09')
            ->assertSet('month', '2026-09')
            ->assertSeeHtml('data-test="mes-movimento">set/2026')
            ->assertSee('R$ 456,00')
            ->call('shiftMonth', 1)
            ->assertSet('month', '')
            ->assertSeeHtml('data-test="mes-movimento">out/2026');
    }

    public function test_tela_a_pagar_parcelas_fornecedor_novo_e_marcar_pago(): void
    {
        $this->actingAs($this->financeUser());

        Livewire::test('pages::auth.finance.payables')
            ->call('create')
            ->set('form.supplier_id', 'novo')
            ->assertSet('newSupplier', true)
            ->set('form.supplier_name', 'Distribuidora Nova')
            ->set('form.document_number', 'NF 555')
            ->set('form.amount', '150')
            ->set('installments', true)
            ->set('installmentCount', 2)
            ->assertSee('Valor de cada parcela (R$)')
            ->set('dueDates', ['2026-10-20', '2026-11-20'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([15000, 15000], Payable::orderBy('id')->pluck('amount_cents')->all());
        $this->assertSame('Distribuidora Nova', Payable::first()->supplier->name);

        $first = Payable::orderBy('id')->first();
        Livewire::test('pages::auth.finance.payables')
            ->assertSee('Distribuidora Nova')
            ->call('markPaid', $first->id)
            ->assertDispatched('toast');

        $this->assertTrue($first->fresh()->isPaid());
        $this->assertSame('2026-10-15', $first->fresh()->paid_on->toDateString());
        $this->assertFalse(Payable::orderBy('id')->skip(1)->first()->isPaid());
    }

    public function test_tela_prestadores_filtra_por_prestador(): void
    {
        $douglas = $this->serviceProvider('Douglas');
        $eletricista = $this->serviceProvider('Eletricista');
        ProviderPayment::create(['supplier_id' => $douglas->id, 'order_number' => '111', 'description' => 'Troca de embreagem', 'amount_cents' => 18000, 'due_date' => '2026-10-20']);
        ProviderPayment::create(['supplier_id' => $eletricista->id, 'order_number' => '222', 'description' => 'Chicote do farol', 'amount_cents' => 9000, 'due_date' => '2026-10-21']);
        $this->actingAs($this->financeUser());

        Livewire::test('pages::auth.finance.providers')
            ->assertSee('Troca de embreagem')->assertSee('Chicote do farol')
            ->set('providerFilter', (string) $douglas->id)
            ->assertSee('Troca de embreagem')->assertDontSee('Chicote do farol')
            ->call('create')
            ->set('form.order_number', '333')
            ->set('form.description', 'Revisão do câmbio')
            ->set('form.amount', '250')
            ->set('form.due_date', '2026-10-25')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($douglas->id, ProviderPayment::query()->where('order_number', '333')->value('supplier_id'));

        $parts = Supplier::create(['name' => 'Só Peças', 'kind' => 'pecas', 'active' => true]);
        Livewire::test('pages::auth.finance.providers')
            ->call('create')
            ->set('form.supplier_id', (string) $parts->id)
            ->set('form.amount', '10')
            ->call('save')
            ->assertHasErrors('form');
    }

    public function test_tela_inadimplentes_lista_o_integral_e_baixa_o_vencido(): void
    {
        $maria = $this->financeCustomer('Maria Devedora', '55991110001');
        $late = $this->receivable(['total_cents' => 10000], '2026-10-01', $maria);
        $this->receivable(['total_cents' => 5000], '2026-12-01', $maria);
        $this->actingAs($this->financeUser());

        Livewire::test('pages::auth.finance.delinquents')
            ->assertSee('Maria Devedora')
            ->assertSee('R$ 150,00')
            ->assertSee('14 dia(s) em atraso')
            ->call('toggle', (string) $maria->id)
            ->assertSee('OS 4521')
            ->call('writeOff', $late->id)
            ->assertDispatched('toast')
            ->assertSee('Nenhum cliente inadimplente no momento.');

        $this->assertSame(10000, Loss::first()->amount_cents);
    }

    public function test_tela_prejuizos_lanca_e_nao_exclui_baixa_de_a_receber(): void
    {
        $user = $this->financeUser();
        $this->actingAs($user);

        Livewire::test('pages::auth.finance.losses')
            ->call('create')
            ->set('form.item', 'Compressor de ar danificado')
            ->set('form.amount', '1.234,50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Compressor de ar danificado');

        $this->assertSame(123450, Loss::first()->amount_cents);

        $loss = app(Receivables::class)->writeOff($this->receivable(['total_cents' => 3000], '2026-10-01'), $user);

        Livewire::test('pages::auth.finance.losses')
            ->call('edit', $loss->id)
            ->assertSee('Ver o lançamento')
            ->call('delete')
            ->assertDispatched('toast', type: 'error');

        $this->assertNotNull($loss->fresh());
    }

    public function test_endereco_do_cliente_no_cadastro(): void
    {
        $customer = Customer::create(['name' => 'Cliente Endereço', 'whatsapp' => '55991234567']);
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.registry.index')
            ->call('editCustomer', $customer->id)
            ->set('form.address', 'Rua das Flores, 123 - Centro')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Rua das Flores, 123 - Centro', $customer->fresh()->address);
    }
}
