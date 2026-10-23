<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseCategory;
use App\Models\AuditLog;
use App\Models\Payable;
use App\Models\RecurringBill;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\RecurringBills;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Contas fixas: o `gerarRecorrentes` do Node (uma conta por modelo e mês, nunca duplicada) e a tela.
 */
class RecurringBillsTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    }

    private function service(): RecurringBills
    {
        return app(RecurringBills::class);
    }

    private function bill(array $data = []): RecurringBill
    {
        return $this->service()->save(null, $data + [
            'supplier_name' => 'Imobiliária Exemplo',
            'description' => 'Aluguel',
            'category' => ExpenseCategory::Fixo->value,
            'due_day' => '20',
            'amount' => '5.000',
        ], $this->financeUser());
    }

    public function test_gera_uma_vez_por_mes_no_dia_do_modelo(): void
    {
        $rent = $this->bill();
        $salary = $this->bill(['supplier_name' => 'Folha Exemplo', 'description' => 'Salários', 'category' => 'folha', 'due_day' => '31', 'amount' => '20.000', 'first_month' => '2026-11']);
        $this->assertSame('2026-10', $rent->first_month);           // dia 20 ainda não passou

        $this->assertSame(3, $this->service()->generateDue());     // aluguel out e nov, salário só a partir de nov
        $this->assertSame(0, $this->service()->generateDue());
        $this->assertSame(0, $this->service()->generate('2026-10'));

        $this->assertSame(['2026-10-20', '2026-11-20'], Payable::query()->where('recurring_bill_id', $rent->id)->orderBy('due_date')->pluck('due_date')->map->toDateString()->all());
        $november = Payable::query()->where('recurring_bill_id', $salary->id)->sole();
        $this->assertSame(['2026-11-30', 2_000_000, ExpenseCategory::Folha, '2026-11'], [$november->due_date->toDateString(), $november->amount_cents, $november->category, $november->competence]);
        $this->assertSame('Folha Exemplo', $november->supplier->name);
    }

    public function test_dia_que_ja_passou_comeca_no_mes_seguinte_e_fevereiro_vence_no_ultimo_dia(): void
    {
        $this->assertSame('2026-11', RecurringBills::firstMonthFor(10));
        $this->assertSame('2026-10', RecurringBills::firstMonthFor(15));

        $bill = $this->bill(['due_day' => '31', 'first_month' => '2027-02']);
        $this->travelTo(CarbonImmutable::parse('2027-02-01 06:00'));
        $this->service()->generateDue();

        $this->assertSame('2027-02-28', Payable::query()->where('recurring_bill_id', $bill->id)->orderBy('due_date')->value('due_date')->toDateString());
    }

    public function test_editar_o_modelo_nao_mexe_nas_contas_geradas_e_a_excluida_nao_volta(): void
    {
        $bill = $this->bill();
        $this->service()->generateDue();
        $october = Payable::query()->where('competence', '2026-10')->sole();

        $this->service()->save($bill, ['supplier_id' => $bill->supplier_id, 'description' => 'Aluguel novo', 'category' => 'fixo', 'due_day' => '25', 'amount' => '6.000'], $this->financeUser());
        $this->assertSame([500_000, 'Aluguel'], [$october->fresh()->amount_cents, $october->fresh()->description]);

        $october->delete();
        $this->assertSame(0, $this->service()->generateDue());
        $this->assertNull(Payable::query()->where('competence', '2026-10')->first());

        $this->travelTo(CarbonImmutable::parse('2026-11-02 05:00'));
        $this->assertSame(1, $this->service()->generateDue());      // dezembro, já com o valor novo
        $this->assertSame(600_000, Payable::query()->where('competence', '2026-12')->value('amount_cents'));
    }

    public function test_pausada_nao_gera_e_conferencias_do_modelo(): void
    {
        $bill = $this->bill();
        $this->service()->toggle($bill, $this->financeUser());
        $this->assertSame(0, $this->service()->generateDue());

        foreach ([['description' => ''], ['category' => 'inventada'], ['due_day' => '32'], ['amount' => '0'], ['supplier_name' => '']] as $bad) {
            try {
                $this->bill($bad);
                $this->fail('Devia recusar: '.json_encode($bad));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, Supplier::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'recurring_bill.toggled')->exists());
    }

    public function test_agendador_gera_todo_dia(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->description === 'contas-fixas');
        $this->assertCount(1, $events);

        $this->bill();
        $events->first()->run(app());
        $this->assertSame(2, Payable::query()->whereNotNull('recurring_bill_id')->count());
    }

    public function test_tela_atendimento_cadastra_e_gera(): void
    {
        $this->actingAs(User::factory()->atendimento()->create());
        $supplier = $this->partsSupplier('Contador Exemplo');

        Livewire::test('pages::auth.finance.recurring')
            ->assertSee('Nenhuma conta fixa ainda')
            ->call('create')
            ->set('form.description', 'Contador')
            ->set('form.supplier_id', (string) $supplier->id)
            ->set('form.category', 'fixo')
            ->set('form.due_day', '28')
            ->set('form.amount', '850')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Contador')
            ->assertSee('R$ 850,00')
            ->call('generate')
            ->assertDispatched('toast', message: 'Nada novo: as contas do mês já estavam no A pagar.');

        $this->assertSame(2, Payable::query()->where('supplier_id', $supplier->id)->count());

        Livewire::test('pages::auth.finance.recurring')
            ->call('create')
            ->call('save')
            ->assertHasErrors('form');

        $this->actingAs(User::factory()->mecanico()->create())->get(route('finance.recurring'))->assertForbidden();
    }
}
