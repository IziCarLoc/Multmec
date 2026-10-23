<?php

namespace Tests\Feature\Orders;

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Models\Budget;
use App\Models\Consent;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\StageTransition;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Items\OrderItems;
use App\Services\Orders\Inbox;
use App\Services\Orders\StageMachine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class BudgetsTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private const CPF = '52998224725';

    private User $giovana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giovana = User::factory()->atendimento()->create(['short_name' => 'Giovana']);
    }

    private function budgets(): Budgets
    {
        return app(Budgets::class);
    }

    /** OS com orçamento pronto para enviar: peça do estoque (R$ 300,00 × 2) e serviço (R$ 250,00). */
    private function readyOrder(array $attributes = [], ?PartOrigin $origin = PartOrigin::Estoque): ServiceOrder
    {
        $order = $this->order(Stage::AguardandoCotacao, $attributes + ['diagnosis' => 'Amortecedores vazando', 'expected_date' => now()->addDays(2)->toDateString(), 'payment_terms' => 'Pix na retirada']);
        $this->item($order, OrderItem::KIND_PART, ['description' => 'Amortecedor dianteiro', 'quantity' => 2, 'origin' => $origin, 'needs_quote' => false, 'price_cents' => 30000, 'cost_cents' => 18000, 'supplier_id' => null]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Troca dos amortecedores', 'price_cents' => 25000]);

        return $order;
    }

    private function send(ServiceOrder $order, ?User $by = null): Budget
    {
        app(StageMachine::class)->move($order, Stage::AguardandoCotacao, Stage::AguardandoAprovacao, $by ?? $this->giovana);

        return $order->budgets()->reorder('number', 'desc')->firstOrFail();
    }

    private function approveData(Budget $budget, array $overrides = []): array
    {
        return $overrides + [
            'method' => 'mensagem',
            'items' => $budget->items->pluck('id')->all(),
            'signer_name' => 'Cliente de Teste',
            'signer_document' => self::CPF,
            'message_text' => 'Pode fazer tudo.',
        ];
    }

    public function test_enviar_congela_o_orcamento_com_hash_e_os_itens_travam(): void
    {
        $order = $this->readyOrder();
        $budget = $this->send($order);

        $this->assertSame([1, Budget::KIND_BUDGET, Budget::STATUS_SENT], [$budget->number, $budget->kind, $budget->status]);
        $this->assertSame([60000, 25000, 85000], [$budget->parts_cents, $budget->labor_cents, $budget->total_cents]);
        $this->assertSame(Budgets::hash($budget->snapshot), $budget->snapshot_sha256);
        $this->assertCount(2, $budget->snapshot['itens']);
        $this->assertSame('Pix na retirada', $budget->snapshot['pagamento']);
        $this->assertSame(2, OrderItem::query()->where('budget_id', $budget->id)->count());

        try {
            app(OrderItems::class)->update($budget->items->first(), ['price_cents' => 1], $this->giovana);
            $this->fail('Item que foi para o cliente não muda.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('já foi para o cliente', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O orçamento está com o cliente');
        app(OrderItems::class)->add($order->fresh(), OrderItem::KIND_SERVICE, ['description' => 'Alinhamento', 'hours' => 1], $this->giovana);
    }

    public function test_aceite_de_parte_dos_itens_por_mensagem(): void
    {
        $order = $this->readyOrder();
        $budget = $this->send($order);
        $service = $budget->items->firstWhere('kind', OrderItem::KIND_SERVICE);

        try {
            $this->budgets()->approve($budget, $this->approveData($budget, ['signer_document' => '']), $this->giovana);
            $this->fail('Aceite pede o CPF ou CNPJ.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('CPF ou CNPJ', $e->getMessage());
        }

        $budget = $this->budgets()->approve($budget, $this->approveData($budget, ['items' => [$service->id], 'message_text' => 'Só a mão de obra, a peça eu trago.']), $this->giovana, '10.0.0.1', 'Teste');

        $this->assertSame(Stage::Aprovada, $order->fresh()->stage);
        $this->assertSame([Budget::STATUS_APPROVED, 25000, 'mensagem'], [$budget->status, $budget->approved_total_cents, $budget->decision_method]);
        $this->assertSame(OrderItem::APPROVED, $service->fresh()->approval);
        $this->assertSame(OrderItem::REFUSED, OrderItem::query()->where('budget_id', $budget->id)->where('kind', OrderItem::KIND_PART)->value('approval'));
        $this->assertSame('Aprovado em parte', $budget->statusLabel());

        $consent = $budget->consent;
        $this->assertSame([Consent::PURPOSE_APPROVAL, Consent::METHOD_MESSAGE, 'Só a mão de obra, a peça eu trago.'], [$consent->purpose, $consent->method, $consent->message_text]);
        $this->assertSame($budget->snapshot_sha256, $consent->snapshot['orcamento_sha256']);
        $this->assertSame(self::CPF, $order->customer->fresh()->document);
        $this->assertSame($this->giovana->id, StageTransition::query()->where('to_stage', Stage::Aprovada->value)->value('user_id'));
    }

    public function test_nada_aprovado_e_recusa(): void
    {
        $budget = $this->send($this->readyOrder());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Nenhum item aprovado');
        $this->budgets()->approve($budget, $this->approveData($budget, ['items' => []]), $this->giovana);
    }

    public function test_peca_nao_original_precisa_da_autorizacao_do_cliente(): void
    {
        $budget = $this->send($this->readyOrder([], PartOrigin::Paralela));
        $this->assertTrue($budget->snapshot['tem_nao_original']);

        try {
            $this->budgets()->approve($budget, $this->approveData($budget), $this->giovana);
            $this->fail('Peça paralela pede autorização.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('autorizou a peça não original', $e->getMessage());
        }

        $budget = $this->budgets()->approve($budget, $this->approveData($budget, ['non_original' => true]), $this->giovana);
        $this->assertTrue($budget->non_original_authorized);
    }

    public function test_alcada_da_izi_ate_o_limite_o_atendimento_acima_o_gestor(): void
    {
        $izi = $this->customer(['name' => 'Izi Car Locadora', 'is_izi_fleet' => true]);
        $order = $this->readyOrder(['approver_kind' => 'izi']);
        $order->update(['customer_id' => $izi->id]);
        OrderItem::query()->where('service_order_id', $order->id)->where('kind', OrderItem::KIND_SERVICE)->update(['price_cents' => 120000]);
        $budget = $this->send($order);
        $this->assertSame(180000, $budget->total_cents);

        $gestor = User::factory()->gestor()->create();
        $this->assertTrue(app(Inbox::class)->for($gestor)->contains(fn ($task) => str_contains($task['task'], 'acima da alçada')));

        try {
            $this->budgets()->approve($budget, ['method' => 'alcada', 'items' => $budget->items->pluck('id')->all()], $this->giovana);
            $this->fail('Acima da alçada só o gestor aprova.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('quem aprova é o gestor', $e->getMessage());
        }

        // Só o serviço (R$ 1.200,00) cabe na alçada do atendimento.
        $service = $budget->items->firstWhere('kind', OrderItem::KIND_SERVICE);
        $budget = $this->budgets()->approve($budget, ['method' => 'alcada', 'items' => [$service->id]], $this->giovana);
        $this->assertSame([Budget::STATUS_APPROVED, 120000], [$budget->status, $budget->approved_total_cents]);
        $this->assertSame(Consent::METHOD_AUTHORITY, $budget->consent->method);

        // Cliente comum não tem alçada.
        $other = $this->send($this->readyOrder());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Alçada é só para carro da frota Izi');
        $this->budgets()->approve($other, ['method' => 'alcada', 'items' => $other->items->pluck('id')->all()], $gestor);
    }

    public function test_recusa_com_motivo_e_valor_do_diagnostico(): void
    {
        $order = $this->readyOrder();
        $budget = $this->send($order);

        try {
            $this->budgets()->refuse($budget, ['method' => 'mensagem', 'reason' => '', 'signer_name' => 'Cliente de Teste', 'message_text' => 'não'], $this->giovana);
            $this->fail('Recusa pede o motivo.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('motivo da recusa', $e->getMessage());
        }

        $budget = $this->budgets()->refuse($budget, ['method' => 'mensagem', 'reason' => 'Achou caro, vai pensar', 'diagnosis_fee_cents' => 8000, 'signer_name' => 'Cliente de Teste', 'message_text' => 'Vou deixar para depois'], $this->giovana);

        $this->assertSame(Stage::Recusada, $order->fresh()->stage);
        $this->assertSame([Budget::STATUS_REFUSED, 8000, 'Achou caro, vai pensar'], [$budget->status, $budget->diagnosis_fee_cents, $budget->refusal_reason]);
        $this->assertSame(2, OrderItem::query()->where('budget_id', $budget->id)->where('approval', OrderItem::REFUSED)->count());
        $this->assertSame('Achou caro, vai pensar', StageTransition::query()->where('to_stage', Stage::Recusada->value)->value('reason'));
        $this->assertSame(Consent::PURPOSE_REFUSAL, $budget->consent->purpose);
    }

    public function test_link_do_cliente_vale_uma_vez_dentro_da_validade(): void
    {
        $order = $this->readyOrder();
        $budget = $this->send($order);
        $link = $this->budgets()->createLink($budget, $this->giovana);
        $token = $link->token;

        $budget->refresh();
        $this->assertNotNull($budget->sent_at);
        $this->assertSame(today()->addDays(10)->toDateString(), $budget->valid_until->toDateString());
        $this->assertSame($link->id, $this->budgets()->findLink($token)->id);
        $this->assertNull($this->budgets()->findLink('x'.$token));

        // Visitar (inclusive a prévia do WhatsApp) não muda nada.
        $this->budgets()->viewed($link);
        $this->assertSame([1, Budget::STATUS_SENT], [$link->fresh()->views, $budget->fresh()->status]);

        $this->budgets()->answerByLink($link, true, ['items' => $budget->items->pluck('id')->all(), 'signer_name' => 'Cliente de Teste', 'signer_document' => self::CPF], '200.1.2.3', 'Android');

        $this->assertSame(Stage::Aprovada, $order->fresh()->stage);
        $this->assertNotNull($link->fresh()->used_at);
        $consent = $budget->fresh()->consent;
        $this->assertSame([Consent::METHOD_LINK, '200.1.2.3', 'Android', null], [$consent->method, $consent->ip, $consent->user_agent, $consent->recorded_by]);
        $this->assertNull(StageTransition::query()->where('to_stage', Stage::Aprovada->value)->value('user_id'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('já foi respondido');
        $this->budgets()->answerByLink($link->fresh(), false, ['reason' => 'mudei de ideia', 'signer_name' => 'Cliente'], null, null);
    }

    public function test_link_vencido_ou_de_carro_da_izi_nao_serve(): void
    {
        $budget = $this->send($this->readyOrder());
        $link = $this->budgets()->createLink($budget, $this->giovana);

        $this->travel(11)->days();

        try {
            $this->budgets()->answerByLink($link, true, ['items' => $budget->items->pluck('id')->all(), 'signer_name' => 'Cliente', 'signer_document' => self::CPF], null, null);
            $this->fail('Link vencido não vale.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('não vale mais', $e->getMessage());
        }

        $this->travelBack();
        $iziBudget = $this->send($this->readyOrder(['approver_kind' => 'izi']));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('alçada, não pelo link');
        $this->budgets()->createLink($iziBudget, $this->giovana);
    }

    public function test_validade_conta_do_primeiro_envio(): void
    {
        $budget = $this->send($this->readyOrder());
        $this->assertNull($budget->valid_until);

        $budget = $this->budgets()->markSent($budget, 'manual', $this->giovana);
        $first = $budget->valid_until->toDateString();
        $this->travel(3)->days();
        $budget = $this->budgets()->markSent($budget, 'compartilhar', $this->giovana);

        $this->assertSame($first, $budget->valid_until->toDateString());
        $this->assertSame('compartilhar', $budget->sent_via);
    }

    public function test_item_novo_depois_do_aceite_vira_adendo(): void
    {
        $order = $this->readyOrder();
        $budget = $this->send($order);
        $this->budgets()->approve($budget, $this->approveData($budget), $this->giovana);
        $order->refresh();

        $robson = User::factory()->mecanico()->create();
        $found = app(OrderItems::class)->add($order, OrderItem::KIND_SERVICE, ['description' => 'Troca da bieleta', 'hours' => 0.5], $robson);
        $this->assertContains('Preço: Troca da bieleta', array_column($this->budgets()->missing($order), 'label'));
        $this->assertNotContains('Previsão de entrega', array_column($this->budgets()->missing($order), 'label'));

        app(OrderItems::class)->update($found, ['price_cents' => 9000], $this->giovana);
        $addendum = $this->budgets()->sendAddendum($order, $this->giovana);

        $this->assertSame([2, Budget::KIND_ADDENDUM, 9000, 'Adendo 2'], [$addendum->number, $addendum->kind, $addendum->total_cents, $addendum->title()]);
        $this->assertSame([$found->id], $addendum->items->pluck('id')->all());
        $this->assertTrue(app(Inbox::class)->for($this->giovana)->contains(fn ($task) => $task['task'] === 'Cobrar o aceite do adendo'));

        $this->budgets()->approve($addendum, $this->approveData($addendum, ['message_text' => 'ok, pode trocar']), $this->giovana);
        $this->assertSame(Stage::Aprovada, $order->fresh()->stage);
        $this->assertSame(OrderItem::APPROVED, $found->fresh()->approval);
    }

    public function test_gestor_volta_a_etapa_e_o_orcamento_reabre(): void
    {
        $order = $this->readyOrder();
        $budget = $this->send($order);
        $link = $this->budgets()->createLink($budget, $this->giovana);
        $gestor = User::factory()->gestor()->create();

        app(StageMachine::class)->back($order->fresh(), Stage::AguardandoAprovacao, Stage::AguardandoCotacao, $gestor, 'Cliente pediu para trocar a marca');

        $this->assertSame(Budget::STATUS_CANCELLED, $budget->fresh()->status);
        $this->assertNotNull($link->fresh()->revoked_at);
        $this->assertSame(0, OrderItem::query()->where('service_order_id', $order->id)->whereNotNull('budget_id')->count());

        app(OrderItems::class)->update($order->items()->first(), ['price_cents' => 28000], $this->giovana);
        $again = $this->send($order->fresh());
        $this->assertSame([2, Budget::KIND_BUDGET, 81000], [$again->number, $again->kind, $again->total_cents]);

        // Recusado e o cliente mudou de ideia: volta a esperar a resposta.
        $this->budgets()->refuse($again, ['method' => 'presencial', 'reason' => 'Sem dinheiro agora', 'signer_name' => 'Cliente de Teste'], $this->giovana);
        app(StageMachine::class)->back($order->fresh(), Stage::Recusada, Stage::AguardandoAprovacao, $gestor, 'Cliente voltou e quer fazer');
        $this->assertSame(Budget::STATUS_SENT, $again->fresh()->status);
        $this->assertSame(0, OrderItem::query()->where('budget_id', $again->id)->whereNotNull('approval')->count());
    }

    public function test_mensagem_e_resumo_do_cliente_sem_custo(): void
    {
        $budget = $this->send($this->readyOrder());
        $budget = $this->budgets()->markSent($budget, 'manual', $this->giovana);

        $message = $this->budgets()->message($budget, 'https://exemplo.test/orcamento/abc');
        $summary = $this->budgets()->summary($budget);

        $this->assertStringContainsString('total R$ 850,00', $message);
        $this->assertStringContainsString('https://exemplo.test/orcamento/abc', $message);
        $this->assertStringContainsString('Amortecedor dianteiro · 2 un · Estoque · R$ 600,00', $summary);
        $this->assertStringContainsString('*Total: R$ 850,00*', $summary);
        $this->assertStringNotContainsString('180,00', $summary);
    }
}
