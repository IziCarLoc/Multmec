<?php

namespace Tests\Feature\Orders;

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Models\Consent;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\StageTransition;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Execution\Execution;
use App\Services\Execution\FinalCheck;
use App\Services\Execution\WorkLog;
use App\Services\Items\OrderItems;
use App\Services\Orders\StageGate;
use App\Services\Orders\StageMachine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ExecutionTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private User $giovana;

    private User $robson;

    private User $joao;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->giovana = User::factory()->atendimento()->create(['short_name' => 'Giovana']);
        $this->robson = User::factory()->mecanico()->create(['short_name' => 'Robson']);
        $this->joao = User::factory()->mecanico()->create(['short_name' => 'João']);
    }

    private function machine(): StageMachine
    {
        return app(StageMachine::class);
    }

    private function labels(array $missing): array
    {
        return array_column($missing, 'label');
    }

    private function photo(): array
    {
        $file = UploadedFile::fake()->image('foto.jpg', 800, 600);

        return [$file, hash_file('sha256', $file->getRealPath())];
    }

    /** OS aprovada (pelo fluxo de verdade): uma peça (origem dada) e um serviço. */
    private function approvedOrder(?PartOrigin $origin = PartOrigin::Original, array $attributes = []): ServiceOrder
    {
        $order = $this->order(Stage::AguardandoCotacao, $attributes + ['diagnosis' => 'Pastilhas no fim', 'expected_date' => now()->addDay()->toDateString(), 'payment_terms' => 'Pix', 'test_drive_authorized' => true]);
        $this->item($order, OrderItem::KIND_PART, ['description' => 'Jogo de pastilhas', 'origin' => $origin, 'needs_quote' => false, 'price_cents' => 20000]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Troca das pastilhas', 'price_cents' => 15000]);
        $this->machine()->move($order, Stage::AguardandoCotacao, Stage::AguardandoAprovacao, $this->giovana);
        $budget = $order->budgets()->firstOrFail();
        app(Budgets::class)->approve($budget, ['method' => 'presencial', 'items' => $budget->items->pluck('id')->all(), 'signer_name' => 'Cliente de Teste', 'signer_document' => '52998224725'], $this->giovana);

        return $order->fresh();
    }

    private function part(ServiceOrder $order): OrderItem
    {
        return $order->items()->where('kind', OrderItem::KIND_PART)->firstOrFail();
    }

    /** Em execução, com a peça chegada e o Robson trabalhando. */
    private function inExecution(?PartOrigin $origin = PartOrigin::Original): ServiceOrder
    {
        $order = $this->approvedOrder($origin);
        $this->machine()->move($order, $order->stage, Stage::EmExecucao, $this->robson);

        return $order->fresh();
    }

    /** Tudo pronto para concluir: peça recebida, fotos, apontamento pausado. */
    private function completeWork(ServiceOrder $order): ServiceOrder
    {
        $execution = app(Execution::class);
        $part = $this->part($order);

        if ($part->received_at === null && $part->origin !== PartOrigin::Estoque) {
            $execution->markReceived($part, true, $this->giovana);
        }

        foreach (['velha', 'nova'] as $which) {
            [$file, $hash] = $this->photo();
            $execution->storePartPhoto($part->fresh(), $which, $file, $hash, null, $this->robson);
        }

        if (app(WorkLog::class)->isRunning($order, $this->robson)) {
            app(WorkLog::class)->pause($order, $this->robson);
        }

        return $order->fresh();
    }

    public function test_nao_entra_em_execucao_sem_aceite(): void
    {
        $order = $this->order(Stage::Aprovada);

        $this->assertContains('Aceite do orçamento', $this->labels(app(StageGate::class)->missing($order, Stage::EmExecucao)));
        $this->expectException(DomainException::class);
        $this->machine()->move($order, Stage::Aprovada, Stage::EmExecucao, $this->robson);
    }

    public function test_aprovada_com_peca_por_chegar_vai_sozinha_para_aguardando_pecas(): void
    {
        $waiting = $this->approvedOrder(PartOrigin::Original);
        $this->assertSame(Stage::AguardandoPecas, $waiting->stage);
        $this->assertStringContainsString('Automático', StageTransition::query()->where('service_order_id', $waiting->id)->where('to_stage', Stage::AguardandoPecas->value)->value('reason'));

        $stock = $this->approvedOrder(PartOrigin::Estoque);
        $this->assertSame(Stage::Aprovada, $stock->stage);
    }

    public function test_iniciar_servico_abre_o_apontamento_e_so_um_por_vez(): void
    {
        $first = $this->inExecution(PartOrigin::Estoque);
        $this->assertTrue(app(WorkLog::class)->isRunning($first, $this->robson));

        // Começou outra OS: a primeira pausa sozinha.
        $second = $this->inExecution(PartOrigin::Estoque);
        $this->assertFalse(app(WorkLog::class)->isRunning($first, $this->robson));
        $this->assertTrue(app(WorkLog::class)->isRunning($second, $this->robson));

        app(WorkLog::class)->pause($second, $this->robson);
        $this->assertSame(0, TimeEntry::query()->open()->count());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O apontamento é do mecânico');
        app(WorkLog::class)->start($second, $this->giovana);
    }

    public function test_concluir_pede_peca_recebida_fotos_torque_adendo_e_apontamento_fechado(): void
    {
        $order = $this->inExecution();
        $part = $this->part($order);
        $order->update(['has_wheel_work' => true]);

        $labels = $this->labels(app(StageGate::class)->missing($order->fresh(), Stage::EmConferencia));
        $this->assertContains('Peça recebida (ou não usada): Jogo de pastilhas', $labels);
        $this->assertContains('Foto da peça velha: Jogo de pastilhas', $labels);
        $this->assertContains('Foto da peça nova: Jogo de pastilhas', $labels);
        $this->assertContains('Torque das rodas (Nm) e nº do torquímetro', $labels);
        $this->assertContains('Apontamento aberto: Robson (pausar)', $labels);

        // "Achei outra coisa": vira item novo, que precisa ir em adendo antes de concluir.
        $found = app(OrderItems::class)->add($order->fresh(), OrderItem::KIND_SERVICE, ['description' => 'Troca do fluido de freio', 'hours' => 0.5], $this->robson);
        $this->assertContains('Item novo sem adendo enviado: Troca do fluido de freio', $this->labels(app(Execution::class)->missing($order->fresh())));
        app(OrderItems::class)->remove($found, $this->robson);

        $order = $this->completeWork($order);

        try {
            app(Execution::class)->update($order, ['torque_nm' => 5000], $this->robson);
            $this->fail('Torque absurdo é recusado.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Torque fora do normal', $e->getMessage());
        }

        app(Execution::class)->update($order, ['torque_nm' => '110', 'torque_wrench' => 'T-01'], $this->robson);
        $this->assertSame([], app(StageGate::class)->missing($order->fresh(), Stage::EmConferencia));

        $order = $this->machine()->move($order->fresh(), Stage::EmExecucao, Stage::EmConferencia, $this->robson);
        $this->assertSame([1, $this->robson->id], [$order->final_check_round, $order->work_finished_by]);
        $this->assertSame($part->id, $part->media()->first()->os_item_id);
    }

    public function test_quem_apontou_tempo_nao_confere(): void
    {
        $order = $this->machine()->move($this->completeWork($this->inExecution()), Stage::EmExecucao, Stage::EmConferencia, $this->robson);

        try {
            app(FinalCheck::class)->start($order, 'expresso', $this->robson);
            $this->fail('Quem executou não confere.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('não confere o serviço dela', $e->getMessage());
        }

        $this->assertContains('Quem conferir não pode ter apontado tempo nesta OS', $this->labels(app(StageGate::class)->missing($order, Stage::ProntaRetirada, $this->robson)));

        $items = app(FinalCheck::class)->start($order, 'expresso', $this->joao);
        $this->assertGreaterThan(5, $items->count());
        $this->assertTrue($items->every(fn ($i) => $i->round === 1));
    }

    public function test_corrigir_devolve_para_a_execucao_e_a_conferencia_recomeca(): void
    {
        $order = $this->machine()->move($this->completeWork($this->inExecution()), Stage::EmExecucao, Stage::EmConferencia, $this->robson);
        $check = app(FinalCheck::class);
        $items = $check->start($order, 'expresso', $this->joao);

        try {
            $check->answer($items->first(), 'corrigir', '', $this->joao);
            $this->fail('CORRIGIR pede o que corrigir.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('o que precisa corrigir', $e->getMessage());
        }

        $check->answer($items->first(), 'corrigir', 'Pastilha esquerda raspando', $this->joao);
        $this->assertContains('Há CORRIGIR: use "Corrigir (volta para execução)"', $this->labels(app(StageGate::class)->missing($order->fresh(), Stage::ProntaRetirada, $this->joao)));

        $order = $this->machine()->move($order->fresh(), Stage::EmConferencia, Stage::EmExecucao, $this->joao, 'Pastilha esquerda raspando');
        $this->assertSame(Stage::EmExecucao, $order->stage);
        $this->assertSame('Pastilha esquerda raspando', StageTransition::query()->latest('id')->value('reason'));

        // Corrigiu e concluiu de novo: rodada 2, sem modelo escolhido; as respostas da 1ª ficam guardadas.
        $order = $this->machine()->move($order, Stage::EmExecucao, Stage::EmConferencia, $this->robson);
        $this->assertSame([2, null], [$order->final_check_round, $order->final_check_template]);
        $this->assertCount(0, $check->items($order));
        $this->assertSame('corrigir', $items->first()->fresh()->state);
    }

    public function test_liberar_pede_rodas_e_test_drive_e_grava_o_aceite_do_conferente(): void
    {
        $order = $this->inExecution();
        app(Execution::class)->update($order, ['has_wheel_work' => true, 'torque_nm' => 110, 'torque_wrench' => 'T-01', 'requires_test_drive' => true], $this->robson);
        $order = $this->machine()->move($this->completeWork($order->fresh()), Stage::EmExecucao, Stage::EmConferencia, $this->robson);
        $check = app(FinalCheck::class);

        foreach ($check->start($order, 'completo', $this->joao) as $item) {
            $check->answer($item, 'ok', null, $this->joao);
        }

        $labels = $this->labels($check->missing($order->fresh(), $this->joao));
        $this->assertContains('Foto da roda com a listra: dianteira esquerda', $labels);
        $this->assertContains('Test drive (km, hora, quem dirigiu e trajeto)', $labels);

        foreach (array_keys(FinalCheck::WHEELS) as $wheel) {
            [$file, $hash] = $this->photo();
            $check->storeWheelPhoto($order, $wheel, $file, $hash, null, $this->joao);
        }

        try {
            $check->recordTestDrive($order, ['km_before' => 1000, 'km_after' => 900, 'started_at' => now()->subMinutes(20)->toDateTimeString(), 'ended_at' => now()->toDateTimeString(), 'driver_id' => $this->joao->id, 'route' => 'Volta da quadra'], $this->joao);
            $this->fail('Km de volta menor que o de saída.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Confira os km', $e->getMessage());
        }

        $check->recordTestDrive($order, ['km_before' => '61.230', 'km_after' => '61.236', 'started_at' => now()->subMinutes(20)->toDateTimeString(), 'ended_at' => now()->toDateTimeString(), 'driver_id' => $this->joao->id, 'route' => 'Av. Liberdade até a rótula e volta'], $this->joao);
        $this->assertSame([], $check->missing($order->fresh(), $this->joao));

        $order = $this->machine()->move($order->fresh(), Stage::EmConferencia, Stage::ProntaRetirada, $this->joao);
        $this->assertSame([Stage::ProntaRetirada, 61236], [$order->stage, $order->km_out]);

        $consent = Consent::query()->where('purpose', Consent::PURPOSE_FINAL_CHECK)->firstOrFail();
        $this->assertSame([$this->joao->name, 'completo', 4], [$consent->signer_name, $consent->snapshot['modelo'], count($consent->snapshot['rodas'])]);
    }

    public function test_apontamento_esquecido_fecha_a_noite_e_fica_marcado(): void
    {
        $order = $this->inExecution(PartOrigin::Estoque);
        $this->assertSame(1, app(WorkLog::class)->closeForgotten());
        $entry = TimeEntry::query()->where('service_order_id', $order->id)->firstOrFail();
        $this->assertTrue($entry->auto_closed);
        $this->assertNotNull($entry->ended_at);
    }

    public function test_telas_da_execucao_e_da_conferencia(): void
    {
        $order = $this->inExecution();
        $part = $this->part($order);

        $this->actingAs($this->robson);
        Livewire::test('pages::auth.orders.execution', ['order' => $order])
            ->assertSee('Pausar')
            ->call('pause')
            ->assertSee('Iniciar')
            ->assertSee('esperando chegar')
            ->assertDontSee('R$');

        $this->actingAs($this->giovana);
        Livewire::test('pages::auth.orders.execution', ['order' => $order->fresh()])
            ->call('toggleReceived', $part->id)
            ->assertSee('recebida');
        $this->assertNotNull($part->fresh()->received_at);

        $this->actingAs($this->robson);
        [$file] = $this->photo();
        Livewire::test('pages::auth.orders.execution', ['order' => $order->fresh()])
            ->set('upload', $file)
            ->call('savePhoto', 'velha:'.$part->id, hash_file('sha256', $file->getRealPath()), null)
            ->assertDispatched('order-updated');
        $this->assertSame(1, $part->media()->count());

        $order = $this->machine()->move($this->completeWork($order->fresh()), Stage::EmExecucao, Stage::EmConferencia, $this->robson);
        Livewire::test('pages::auth.orders.final-check', ['order' => $order])->assertSee('não apontou tempo');

        $this->actingAs($this->joao);
        Livewire::test('pages::auth.orders.final-check', ['order' => $order])
            ->call('start', 'expresso')
            ->assertSee('Todos os itens aprovados foram feitos')
            ->assertDontSee('não apontou tempo');

        Livewire::test('pages::auth.orders.show', ['order' => $order->fresh()])->assertSet('tab', 'conferencia')->assertSee('Liberar para retirada');
    }
}
