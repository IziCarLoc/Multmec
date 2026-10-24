<?php

namespace Tests\Feature\Finance;

use App\Models\Loss;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\User;
use Database\Seeders\FinanceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

class FinanceAccessTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    public static function telas(): array
    {
        return [
            'painel' => ['finance', 'pages::auth.finance.dashboard'],
            'a receber' => ['finance.receivables', 'pages::auth.finance.receivables'],
            'a pagar' => ['finance.payables', 'pages::auth.finance.payables'],
            'boletos por mês' => ['finance.payables.monthly', 'pages::auth.finance.payables-monthly'],
            'inadimplentes' => ['finance.delinquents', 'pages::auth.finance.delinquents'],
            'prejuízos' => ['finance.losses', 'pages::auth.finance.losses'],
            'prestadores' => ['finance.providers', 'pages::auth.finance.providers'],
            'contas fixas' => ['finance.recurring', 'pages::auth.finance.recurring'],
            'crédito' => ['finance.credit', 'pages::auth.finance.credit'],
            'relatórios' => ['finance.reports', 'pages::auth.finance.reports'],
        ];
    }

    #[DataProvider('telas')]
    public function test_gestor_e_atendimento_abrem_e_o_mecanico_leva_403(string $route, string $component): void
    {
        $this->financeFixtures();

        $this->actingAs(User::factory()->gestor()->create())->get(route($route))->assertOk()->assertSee('Financeiro');
        $this->actingAs(User::factory()->atendimento()->create())->get(route($route))->assertOk();

        $mecanico = User::factory()->mecanico()->create();
        $this->actingAs($mecanico)->get(route($route))->assertForbidden();
        Livewire::actingAs($mecanico)->test($component)->assertForbidden();
    }

    public function test_lancamentos_de_exemplo_rodam_de_novo_sem_duplicar(): void
    {
        User::factory()->gestor()->create(['email' => 'gabriel@exemplo.test']);

        $this->seed(FinanceDemoSeeder::class);
        $counts = [Receivable::count(), Payable::count(), ProviderPayment::count(), Loss::count()];
        $this->seed(FinanceDemoSeeder::class);

        $this->assertSame($counts, [Receivable::count(), Payable::count(), ProviderPayment::count(), Loss::count()]);
        $this->assertGreaterThan(0, Receivable::query()->overdue()->count());
        $this->assertSame(1, Receivable::query()->writtenOff()->count());
    }

    public function test_menu_financeiro_so_para_gestor_e_atendimento(): void
    {
        $this->actingAs(User::factory()->gestor()->create())->get('/')->assertOk()->assertSee(route('finance'));
        $this->actingAs(User::factory()->atendimento()->create())->get('/')->assertOk()->assertSee(route('finance'));
        $this->actingAs(User::factory()->mecanico()->create())->get('/')->assertOk()->assertDontSee(route('finance'));
    }
}
