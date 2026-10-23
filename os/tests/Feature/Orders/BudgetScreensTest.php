<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Models\Budget;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Orders\StageMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class BudgetScreensTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private const CPF = '52998224725';

    /** OS em orçamento: peça do estoque (sem cotar) e serviço, ainda sem preço nem condições. */
    private function quotingOrder(): ServiceOrder
    {
        $order = $this->order(Stage::AguardandoCotacao, ['diagnosis' => 'Pastilhas no fim']);
        $this->item($order, OrderItem::KIND_PART, ['description' => 'Jogo de pastilhas', 'origin' => 'estoque', 'needs_quote' => false, 'cost_cents' => 9000]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Troca das pastilhas']);

        return $order;
    }

    private function sentBudget(ServiceOrder $order, User $by): Budget
    {
        OrderItem::query()->where('service_order_id', $order->id)->update(['price_cents' => 15000]);
        $order->update(['expected_date' => now()->addDay()->toDateString(), 'payment_terms' => 'Pix na retirada']);
        app(StageMachine::class)->move($order, Stage::AguardandoCotacao, Stage::AguardandoAprovacao, $by);

        return $order->budgets()->firstOrFail();
    }

    public function test_orcamento_e_so_do_atendimento_e_a_margem_so_do_gestor(): void
    {
        $order = $this->quotingOrder();
        OrderItem::query()->where('service_order_id', $order->id)->update(['price_cents' => 15000]);

        $this->actingAs(User::factory()->mecanico()->create());
        Livewire::test('pages::auth.orders.show', ['order' => $order])->assertDontSee('data-test="aba-orcamento"', false);
        Livewire::test('pages::auth.orders.budget', ['order' => $order])->assertForbidden();

        $this->actingAs(User::factory()->atendimento()->create());
        Livewire::test('pages::auth.orders.budget', ['order' => $order])->assertSee('Montar o orçamento')->assertDontSee('margem');

        $this->actingAs(User::factory()->gestor()->create());
        Livewire::test('pages::auth.orders.budget', ['order' => $order])->assertSee('margem 40%');
    }

    public function test_pdf_enviado_nao_e_trocado_pelo_navegador(): void
    {
        $order = $this->quotingOrder();
        $this->actingAs(User::factory()->atendimento()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test('pages::auth.orders.budget', ['order' => $order])->set('preparedPath', $order->storageDir().'/qualquer.pdf');
    }

    public function test_montar_o_orcamento_pela_tela(): void
    {
        $order = $this->quotingOrder();
        [$part, $service] = $order->items()->get()->all();
        $this->actingAs($giovana = User::factory()->atendimento()->create());

        $page = Livewire::test('pages::auth.orders.budget', ['order' => $order])
            ->assertSee('Falta para enviar')
            ->set("prices.{$part->id}", '1.234,56')
            ->set("prices.{$service->id}", '200')
            ->assertSee('R$ 1.434,56')
            ->set('expectedDate', now()->addDays(2)->toDateString())
            ->set('paymentTerms', 'Pix na retirada')
            ->call('saveConditions')
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Pronto. Use');

        $this->assertSame([123456, 20000], [$part->fresh()->price_cents, $service->fresh()->price_cents]);
        $this->assertSame('Pix na retirada', $order->fresh()->payment_terms);

        // Enviar pelo Próximo passo congela; a aba passa a mostrar o orçamento enviado.
        Livewire::test('pages::auth.orders.show', ['order' => $order])->call('advance', 'aguardando_aprovacao')->assertSet('loadedStage', 'aguardando_aprovacao');
        Livewire::test('pages::auth.orders.budget', ['order' => $order->fresh()])
            ->assertSee('Pronto para enviar')
            ->assertSee('Registrar aceite')
            ->assertDontSee('Montar o orçamento');
        $page->assertOk();
    }

    public function test_registrar_aceite_pelo_proximo_passo(): void
    {
        $order = $this->quotingOrder();
        $this->actingAs($giovana = User::factory()->atendimento()->create());
        $budget = $this->sentBudget($order, $giovana);

        Livewire::test('pages::auth.orders.show', ['order' => $order->fresh()])
            ->assertSet('tab', 'orcamento')
            ->call('advance', 'aprovada')
            ->assertSet('decisionToOpen', 'aceite')
            ->assertDispatched('open-decision', kind: 'aceite');

        Livewire::test('pages::auth.orders.budget', ['order' => $order->fresh(), 'open' => 'aceite'])
            ->assertSet('deciding', 'aceite')
            ->assertSet('method', 'mensagem')
            ->set('signerDocument', self::CPF)
            ->set('messageText', 'Pode fazer')
            ->call('saveDecision', null)
            ->assertDispatched('toast', type: 'success')
            ->assertSet('deciding', null)
            ->assertSee('Aprovado');

        $this->assertSame(Stage::Aprovada, $order->fresh()->stage);
        $this->assertSame(Budget::STATUS_APPROVED, $budget->fresh()->status);
    }

    public function test_recusa_pela_tela_pede_o_motivo(): void
    {
        $order = $this->quotingOrder();
        $this->actingAs($giovana = User::factory()->atendimento()->create());
        $this->sentBudget($order, $giovana);

        Livewire::test('pages::auth.orders.budget', ['order' => $order->fresh()])
            ->call('openDecision', 'recusa')
            ->set('method', 'presencial')
            ->call('saveDecision', null)
            ->assertDispatched('toast', type: 'error')
            ->set('reason', 'Vai fazer em outra oficina')
            ->set('diagnosisFee', '80,00')
            ->call('saveDecision', null)
            ->assertDispatched('toast', type: 'success');

        $this->assertSame(Stage::Recusada, $order->fresh()->stage);
        $this->assertSame(8000, $order->budgets()->first()->diagnosis_fee_cents);
    }

    public function test_cliente_responde_pelo_link_sem_login(): void
    {
        $order = $this->quotingOrder();
        $giovana = User::factory()->atendimento()->create();
        $budget = $this->sentBudget($order, $giovana);
        $link = app(Budgets::class)->createLink($budget, $giovana);

        // Abrir (ou a prévia do WhatsApp) não muda nada.
        $this->get('/orcamento/'.$link->token)->assertOk()->assertSee('Jogo de pastilhas')->assertSee('R$ 300,00');
        $this->assertSame(Budget::STATUS_SENT, $budget->fresh()->status);
        $this->get('/orcamento/'.str_repeat('a', 43))->assertNotFound();

        [$part, $service] = $budget->items->all();

        Livewire::test('pages::public.budget', ['token' => $link->token])
            ->set('approvedItems', [$service->id])
            ->assertSee('R$ 150,00')
            ->set('signerName', 'Cliente de Teste')
            ->set('signerDocument', self::CPF)
            ->call('approve')
            ->assertSet('answered', 'aprovado')
            ->assertSee('Orçamento aprovado');

        $this->assertSame(Stage::Aprovada, $order->fresh()->stage);
        $this->assertSame([OrderItem::REFUSED, OrderItem::APPROVED], [$part->fresh()->approval, $service->fresh()->approval]);

        // Usado: a página só mostra a resposta.
        $this->get('/orcamento/'.$link->token)->assertOk()->assertSee('Orçamento aprovado')->assertDontSee('data-test="aprovar"', false);
    }

    public function test_pdf_do_orcamento_so_para_quem_ve_preco(): void
    {
        $order = $this->quotingOrder();
        $giovana = User::factory()->atendimento()->create();
        $budget = $this->sentBudget($order, $giovana);

        $this->actingAs($giovana)->get(route('orders.budget.pdf', ['order' => $order, 'budget' => $budget]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($giovana)->get(route('orders.budget.preview', $order))->assertOk();
        $this->actingAs(User::factory()->mecanico()->create())->get(route('orders.budget.pdf', ['order' => $order, 'budget' => $budget]))->assertForbidden();
    }

    public function test_mecanico_ve_a_aprovacao_sem_preco(): void
    {
        $order = $this->quotingOrder();
        $giovana = User::factory()->atendimento()->create();
        $budget = $this->sentBudget($order, $giovana);
        app(Budgets::class)->approve($budget, ['method' => 'presencial', 'items' => [$budget->items->first()->id], 'signer_name' => 'Cliente de Teste', 'signer_document' => self::CPF], $giovana);

        $this->actingAs(User::factory()->mecanico()->create());
        Livewire::test('pages::auth.orders.items', ['order' => $order->fresh()])
            ->assertSee('Aprovado')
            ->assertSee('Recusado pelo cliente')
            ->assertDontSee('R$');
    }
}
