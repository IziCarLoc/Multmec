<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Orders\StageMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class OrderPagesTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    public function test_quadro_lista_e_avisos_abrem_para_todos_os_perfis(): void
    {
        $order = $this->order(Stage::EmDiagnostico);

        foreach ([User::factory()->gestor()->create(), User::factory()->atendimento()->create(), User::factory()->mecanico()->create()] as $user) {
            $this->actingAs($user);
            $this->get(route('dashboard'))->assertOk()->assertSee($order->label());
            $this->get(route('orders.index'))->assertOk()->assertSee($order->label());
            $this->get(route('inbox'))->assertOk();
            $this->get(route('orders.show', $order))->assertOk()->assertSee('Em diagnóstico');
        }
    }

    public function test_mecanico_nao_abre_os_nem_ve_cadastros(): void
    {
        $this->actingAs(User::factory()->mecanico()->create());

        $this->get(route('orders.create'))->assertForbidden();
        $this->get(route('registry'))->assertForbidden();
        $this->get(route('suppliers'))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee('Nova OS');
    }

    public function test_atendimento_abre_a_os_pela_tela(): void
    {
        $this->unit();
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.orders.create')
            ->set('plate', 'izn4c21')
            ->set('number', '1190')
            ->set('brandModel', 'Prisma 1.4')
            ->set('customerName', 'Maria Souza')
            ->set('customerWhatsapp', '55 99111-2233')
            ->set('complaint', 'Luz da injeção acesa')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = ServiceOrder::query()->sole();
        $this->assertSame(1190, $order->number);
        $this->assertSame('IZN4C21', $order->vehicle->plate);
    }

    public function test_placa_conhecida_traz_o_carro_e_o_cliente(): void
    {
        $old = $this->order(Stage::Entregue);
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.orders.create')
            ->set('plate', $old->vehicle->plate)
            ->assertSet('brandModel', 'Onix 1.0')
            ->assertSet('customerId', $old->customer_id)
            ->assertSee('Carro já conhecido')
            ->assertSee($old->label());
    }

    public function test_numero_repetido_aparece_como_aviso(): void
    {
        $existing = $this->order(Stage::Aberta);
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.orders.create')
            ->set('plate', 'ABC1D23')
            ->set('number', (string) $existing->number)
            ->set('customerName', 'Fulano')
            ->set('customerWhatsapp', '55991112233')
            ->set('complaint', 'Revisão')
            ->call('save')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(1, ServiceOrder::query()->count());
    }

    public function test_avancar_pela_tela_e_falta_para_avancar(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $this->item($order);
        $this->actingAs(User::factory()->mecanico()->create());

        Livewire::test('pages::auth.orders.show', ['order' => $order])
            ->assertSee('Falta para avançar')
            ->assertSee('Diagnóstico (em linguagem de cliente)')
            ->set('diagnosis', 'Bieleta dianteira esquerda com folga')
            ->call('saveProblem')
            ->assertDontSee('Falta para avançar')
            ->call('advance', 'aguardando_cotacao')
            ->assertSet('loadedStage', 'aguardando_cotacao');

        $this->assertSame(Stage::AguardandoCotacao, $order->fresh()->stage);
    }

    public function test_tela_desatualizada_e_avisada_e_atualizada(): void
    {
        $order = $this->order(Stage::Aberta);
        $giovana = User::factory()->atendimento()->create(['short_name' => 'Giovana']);
        $this->actingAs(User::factory()->atendimento()->create());

        $page = Livewire::test('pages::auth.orders.show', ['order' => $order]);
        app(StageMachine::class)->move($order, Stage::Aberta, Stage::EmVistoria, $giovana);

        $page->call('advance', 'em_vistoria')
            ->assertDispatched('toast', type: 'error')
            ->assertSet('loadedStage', 'em_vistoria');
    }

    public function test_cancelar_pede_motivo_e_corrigir_pede_o_que_corrigir(): void
    {
        $order = $this->order(Stage::EmConferencia);
        $this->actingAs(User::factory()->mecanico()->create());

        Livewire::test('pages::auth.orders.show', ['order' => $order])
            ->call('advance', 'em_execucao')
            ->assertSet('reasonFor', 'em_execucao')
            ->set('reason', 'Faltou apertar a abraçadeira')
            ->call('advance', 'em_execucao')
            ->assertSet('reasonFor', null);

        $this->assertSame(Stage::EmExecucao, $order->fresh()->stage);

        $this->actingAs(User::factory()->atendimento()->create());
        Livewire::test('pages::auth.orders.show', ['order' => $order->fresh()])
            ->call('openCancel')
            ->set('reason', 'Cliente levou o carro embora')
            ->call('cancelOrder');

        $this->assertSame(Stage::Cancelada, $order->fresh()->stage);
    }

    public function test_mecanico_assume_a_os(): void
    {
        $order = $this->order(Stage::Aprovada);
        $robson = User::factory()->mecanico()->create();
        $this->actingAs($robson);

        Livewire::test('pages::auth.orders.show', ['order' => $order])->call('setResponsible', $robson->id);

        $this->assertSame($robson->id, $order->fresh()->responsible_id);
    }

    public function test_busca_por_placa_numero_e_cliente(): void
    {
        $order = $this->order(Stage::EmExecucao);
        $other = $this->order(Stage::EmExecucao);
        $this->actingAs(User::factory()->gestor()->create());

        Livewire::test('pages::auth.orders.index')
            ->set('search', (string) $order->number)
            ->assertSee($order->label())
            ->assertDontSee($other->label())
            ->set('search', $order->vehicle->plate)
            ->assertSee($order->label())
            ->set('filter', 'todas')
            ->set('search', 'Cliente de Teste')
            ->assertSee($other->label());
    }
}
