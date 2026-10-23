<?php

namespace Tests\Feature\Finance;

use App\Enums\Stage;
use App\Models\Budget;
use App\Models\Payable;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Finance\Receivables;
use App\Services\Finance\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Relatórios: o `analiseOs`, a `agenda` e os `alertas` do financeiro em Node com os dados do sistema; o atendimento
 * nunca vê lucro, margem nem acréscimo.
 */
class ReportsTest extends TestCase
{
    use BuildsFinance;
    use BuildsOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    }

    private function sale(string $date, int $total, array $data = [], ?string $name = null): void
    {
        static $n = 0;
        $n++;
        $customer = $this->financeCustomer($name ?? 'Cliente '.$n, '5599200'.str_pad((string) $n, 4, '0', STR_PAD_LEFT));
        app(Receivables::class)->create($data + ['customer_id' => $customer->id, 'order_number' => (string) (300 + $n), 'service_date' => $date, 'total_cents' => $total], [$date], $this->financeUser());
    }

    public function test_analise_das_os_faixas_clientes_mecanicos_e_acrescimo(): void
    {
        $this->sale('2026-10-01', 10_000);
        $this->sale('2026-10-02', 20_000);
        $this->sale('2026-10-03', 50_000, ['labor_cents' => 10_000, 'parts_cost_cents' => 20_000], 'Frota Grande');
        $this->sale('2026-10-04', 300_000, ['labor_cents' => 100_000, 'parts_cost_cents' => 100_000], 'Frota Grande');
        $this->sale('2026-08-01', 99_000); // fora do período

        $order = $this->order(Stage::Entregue);
        $mechanic = User::factory()->mecanico()->create(['short_name' => 'Robson Teste']);
        TimeEntry::create(['service_order_id' => $order->id, 'user_id' => $mechanic->id, 'started_at' => now()->subHours(3), 'ended_at' => now()->subHour()]);
        $this->sale('2026-10-05', 40_000, ['service_order_id' => $order->id, 'labor_cents' => 40_000]);

        $a = app(Reports::class)->orders('2026-10-01', '2026-10-31', true);

        $this->assertSame(5, $a['orders']);
        $this->assertSame(420_000, $a['revenue']);
        $this->assertSame([1, 1, 2, 0, 0, 1, 0], array_column($a['bands'], 'orders'));
        $this->assertSame('R$ 0,00 a R$ 150,00', $a['bands'][0]['label']);
        $this->assertSame('Frota Grande', $a['customers'][0]['customer']);
        $this->assertSame(350_000, $a['customers'][0]['revenue']);
        $this->assertArrayHasKey('gross', $a['customers'][0]);
        $this->assertSame(['Robson Teste', 40_000], [collect($a['mechanics'])->firstWhere('mechanic', 'Robson Teste')['mechanic'], collect($a['mechanics'])->firstWhere('mechanic', 'Robson Teste')['revenue']]);
        $this->assertSame(4, collect($a['mechanics'])->firstWhere('mechanic', '(não informado)')['orders']);
        // Peças de R$ 200 vendidas por R$ 400 (total − mão de obra − frete): 100% de acréscimo.
        $this->assertEqualsWithDelta(1.0, $a['markup'][1]['markup'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $a['markup'][3]['markup'], 1e-9);

        $forAtendimento = app(Reports::class)->orders('2026-10-01', '2026-10-31', false);
        $this->assertNull($forAtendimento['markup']);
        $this->assertArrayNotHasKey('gross', $forAtendimento['customers'][0]);
    }

    public function test_agenda_e_avisos(): void
    {
        $lessor = $this->financeCustomer('Locadora Atrasada', '55993330001');
        $this->receivable(['total_cents' => 10_000], '2026-10-18', $lessor);
        $this->receivable(['total_cents' => 80_000], '2026-09-01', $lessor);   // 44 dias: travada
        $this->receivable(['total_cents' => 5_000], '2026-10-15', $this->walkInCustomer());
        $this->payable(['amount_cents' => 12_000, 'due_date' => '2026-10-10']);
        $this->payable(['amount_cents' => 7_000, 'due_date' => '2026-10-20']);
        $this->payable(['amount_cents' => 9_999, 'due_date' => '2026-11-30']);
        $this->providerPayment(['amount_cents' => 3_000, 'due_date' => '2026-10-16']);
        $this->sale('2026-10-05', 90_000, ['labor_cents' => 10_000]); // sem custo de peça
        $order = $this->order(Stage::AguardandoAprovacao);
        Budget::create(['service_order_id' => $order->id, 'number' => 1, 'kind' => Budget::KIND_BUDGET, 'status' => Budget::STATUS_SENT, 'snapshot' => [], 'snapshot_sha256' => str_repeat('0', 64), 'parts_cents' => 0, 'labor_cents' => 45_000, 'total_cents' => 45_000, 'sent_at' => now()->subDays(3)]);

        $agenda = app(Reports::class)->agenda();
        $this->assertSame(12_000 + 7_000 + 3_000, $agenda['pay_total']);
        $this->assertSame(12_000, $agenda['pay_overdue']);
        $this->assertSame(80_000 + 10_000 + 90_000, $agenda['receive_total']);   // clientes a prazo; o avulso fica fora

        $alerts = collect(app(Reports::class)->alerts())->keyBy('kind');
        $this->assertSame('critico', $alerts['cliente_travado']['level']);
        $this->assertStringContainsString('Locadora Atrasada', $alerts['cliente_travado']['text']);
        $this->assertStringContainsString('R$ 120,00', $alerts['contas_atrasadas']['text']);
        $this->assertStringContainsString('1 lançamento(s) de clientes avulsos', $alerts['avulso_em_aberto']['text']);
        $this->assertStringContainsString('1 OS do mês sem custo', $alerts['os_sem_custo']['text']);
        $this->assertStringContainsString('R$ 450,00', $alerts['orcamentos']['text']);
    }

    public function test_tela_relatorios_sem_lucro_para_o_atendimento(): void
    {
        $this->sale('2026-10-05', 50_000, ['labor_cents' => 10_000, 'parts_cost_cents' => 20_000], 'Cliente Relatório');
        $atendimento = User::factory()->atendimento()->create();

        $this->actingAs($atendimento)->get(route('finance.reports'))->assertOk()
            ->assertSee('Cliente Relatório')->assertSee('Tamanho das OS')
            ->assertDontSee('Lucro')->assertDontSee('lucro')->assertDontSee('argem')->assertDontSee('Acréscimo');

        $csv = base64_decode(data_get(Livewire::actingAs($atendimento)->test('pages::auth.finance.reports')->call('export')->assertFileDownloaded()->effects, 'download.content'));
        $this->assertStringContainsString('Cliente Relatório', $csv);
        $this->assertStringNotContainsString('lucro', $csv);

        $this->actingAs($this->financeUser())->get(route('finance.reports'))->assertOk()->assertSee('Lucro bruto')->assertSee('Acréscimo sobre o custo das peças');
        $csv = base64_decode(data_get(Livewire::actingAs($this->financeUser())->test('pages::auth.finance.reports')->set('from', '2026-10-01')->set('to', '2026-10-31')->call('export')->assertFileDownloaded('servicos-2026-10-01-a-2026-10-31.csv')->effects, 'download.content'));
        $this->assertStringContainsString('lucro_bruto', $csv);
        $this->assertStringContainsString('"200,00","0,00","0,00","300,00"', $csv);   // peças, frete, prestador e lucro bruto (500 − 200)

        $this->actingAs(User::factory()->mecanico()->create())->get(route('finance.reports'))->assertForbidden();
        $this->assertSame(0, Payable::query()->count());
    }
}
