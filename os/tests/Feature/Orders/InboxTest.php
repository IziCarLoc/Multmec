<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Models\Budget;
use App\Models\OrderItem;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Orders\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Avisos que dependem do orçamento e do apontamento (lidos em lote: o contador do menu roda em toda tela).
 */
class InboxTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private function tasks(User $user): array
    {
        return app(Inbox::class)->for($user)->map(fn (array $task) => $task['order']->number.' '.$task['task'])->all();
    }

    private function sentBudget(int $orderId, int $totalCents): Budget
    {
        return Budget::create(['service_order_id' => $orderId, 'number' => 1, 'kind' => Budget::KIND_BUDGET, 'status' => Budget::STATUS_SENT, 'snapshot' => [], 'snapshot_sha256' => str_repeat('0', 64), 'parts_cents' => 0, 'labor_cents' => $totalCents, 'total_cents' => $totalCents]);
    }

    public function test_conferencia_so_para_quem_nao_apontou_tempo(): void
    {
        $order = $this->order(Stage::EmConferencia);
        [$robson, $joao] = [User::factory()->mecanico()->create(), User::factory()->mecanico()->create()];
        TimeEntry::create(['service_order_id' => $order->id, 'user_id' => $robson->id, 'started_at' => now()->subHour(), 'ended_at' => now()]);

        $this->assertNotContains($order->number.' Conferir o serviço', $this->tasks($robson));
        $this->assertContains($order->number.' Conferir o serviço', $this->tasks($joao));
    }

    public function test_adendo_para_montar_e_acima_da_alcada_da_izi(): void
    {
        $atendimento = User::factory()->atendimento()->create();
        $gestor = User::factory()->gestor()->create();

        // Aprovada com um item novo (fora do orçamento aceito) e nada esperando resposta: montar o adendo.
        $addendum = $this->order(Stage::EmExecucao);
        $this->item($addendum, OrderItem::KIND_SERVICE, ['budget_id' => Budget::create(['service_order_id' => $addendum->id, 'number' => 1, 'kind' => Budget::KIND_BUDGET, 'status' => Budget::STATUS_APPROVED, 'snapshot' => [], 'snapshot_sha256' => str_repeat('0', 64), 'parts_cents' => 0, 'labor_cents' => 1000, 'total_cents' => 1000])->id]);
        $this->item($addendum, OrderItem::KIND_PART, ['description' => 'Achei: mangueira ressecada']);

        // Carro da Izi esperando resposta acima da alçada: aviso para o gestor.
        $izi = $this->order(Stage::AguardandoAprovacao, ['approver_kind' => 'izi']);
        $this->sentBudget($izi->id, 300_000);
        $below = $this->order(Stage::AguardandoAprovacao, ['approver_kind' => 'izi']);
        $this->sentBudget($below->id, 50_000);

        $this->assertContains($addendum->number.' Montar o adendo (item novo)', $this->tasks($atendimento));
        $this->assertContains($izi->number.' Aprovar acima da alçada da Izi (R$ 3.000,00)', $this->tasks($gestor));
        $this->assertNotContains($below->number.' Aprovar acima da alçada da Izi (R$ 500,00)', $this->tasks($gestor));
        $this->assertSame(count($this->tasks($atendimento)), app(Inbox::class)->count($atendimento));
    }
}
