<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Models\User;
use App\Services\Orders\StageMachine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class StageMachineTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private function machine(): StageMachine
    {
        return app(StageMachine::class);
    }

    public function test_avanca_e_grava_quem_quando_e_de_onde(): void
    {
        $order = $this->order(Stage::Aberta);
        $giovana = User::factory()->atendimento()->create();

        $order = $this->machine()->move($order, Stage::Aberta, Stage::EmVistoria, $giovana);

        $this->assertSame(Stage::EmVistoria, $order->stage);
        $last = $order->transitions()->get()->last();
        $this->assertSame([Stage::Aberta, Stage::EmVistoria, $giovana->id], [$last->from_stage, $last->to_stage, $last->user_id]);
        $this->assertNotNull($last->created_at);
    }

    public function test_nao_pula_etapa(): void
    {
        $order = $this->order(Stage::Aberta);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('não pode ir direto');
        $this->machine()->move($order, Stage::Aberta, Stage::EmDiagnostico, User::factory()->gestor()->create());
    }

    public function test_cada_perfil_faz_o_seu_passo(): void
    {
        $order = $this->order(Stage::Aberta);

        try {
            $this->machine()->move($order, Stage::Aberta, Stage::EmVistoria, User::factory()->mecanico()->create());
            $this->fail('Mecânico não abre a vistoria.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('perfil', $e->getMessage());
        }

        $diagnosing = $this->order(Stage::EmDiagnostico, ['diagnosis' => 'Bieleta quebrada']);
        $this->expectExceptionMessage('perfil');
        $this->machine()->move($diagnosing, Stage::EmDiagnostico, Stage::AguardandoCotacao, User::factory()->atendimento()->create());
    }

    public function test_nao_avanca_sem_os_obrigatorios(): void
    {
        $order = $this->order(Stage::EmDiagnostico, ['diagnosis' => null]);
        $this->item($order);
        $robson = User::factory()->mecanico()->create();

        try {
            $this->machine()->move($order, Stage::EmDiagnostico, Stage::AguardandoCotacao, $robson);
            $this->fail('Sem diagnóstico não vai para a cotação.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Falta para avançar: diagnóstico', $e->getMessage());
        }

        $this->assertSame(Stage::EmDiagnostico, $order->fresh()->stage);

        $order->update(['diagnosis' => 'Bieleta dianteira esquerda com folga']);
        $this->assertSame(Stage::AguardandoCotacao, $this->machine()->move($order, Stage::EmDiagnostico, Stage::AguardandoCotacao, $robson)->stage);
    }

    public function test_duas_pessoas_ao_mesmo_tempo_a_segunda_e_avisada(): void
    {
        $order = $this->order(Stage::Aberta);
        $giovana = User::factory()->atendimento()->create(['short_name' => 'Giovana']);
        $outra = User::factory()->atendimento()->create();

        $this->machine()->move($order, Stage::Aberta, Stage::EmVistoria, $giovana);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Giovana já moveu esta OS para "Em vistoria"');
        // A tela da outra pessoa ainda mostrava "Aberta".
        $this->machine()->move($order, Stage::Aberta, Stage::EmVistoria, $outra);
    }

    public function test_so_o_gestor_volta_etapa_e_com_motivo(): void
    {
        $order = $this->order(Stage::AguardandoAprovacao);

        try {
            $this->machine()->back($order, Stage::AguardandoAprovacao, Stage::EmDiagnostico, User::factory()->atendimento()->create(), 'Cliente pediu outro serviço');
            $this->fail('Atendimento não volta etapa.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Só o gestor', $e->getMessage());
        }

        $gestor = User::factory()->gestor()->create();

        try {
            $this->machine()->back($order, Stage::AguardandoAprovacao, Stage::EmDiagnostico, $gestor, '');
            $this->fail('Voltar exige motivo.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('motivo', $e->getMessage());
        }

        $order = $this->machine()->back($order, Stage::AguardandoAprovacao, Stage::EmDiagnostico, $gestor, 'Cliente pediu para olhar o freio também');
        $this->assertSame(Stage::EmDiagnostico, $order->stage);
        $this->assertTrue($order->transitions()->get()->last()->isBackward());
        $this->assertSame('Cliente pediu para olhar o freio também', $order->transitions()->get()->last()->reason);
    }

    public function test_gestor_nao_volta_para_frente_nem_tira_da_entregue(): void
    {
        $gestor = User::factory()->gestor()->create();
        $this->assertSame([], StageMachine::backTargets(Stage::Entregue));
        $this->assertNotContains(Stage::EmExecucao, StageMachine::backTargets(Stage::Aprovada));
        $this->assertContains(Stage::AguardandoAprovacao, StageMachine::backTargets(Stage::Recusada));

        $order = $this->order(Stage::Aprovada);
        $this->expectExceptionMessage('não volta');
        $this->machine()->back($order, Stage::Aprovada, Stage::EmExecucao, $gestor, 'Motivo qualquer aqui');
    }

    public function test_cancelamento_com_motivo_e_nunca_depois_de_entregue(): void
    {
        $atendimento = User::factory()->atendimento()->create();
        $order = $this->order(Stage::EmDiagnostico);

        try {
            $this->machine()->cancel($order, Stage::EmDiagnostico, $atendimento, ' ');
            $this->fail('Cancelar exige motivo.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('motivo', $e->getMessage());
        }

        $order = $this->machine()->cancel($order, Stage::EmDiagnostico, $atendimento, 'Cliente desistiu do conserto');
        $this->assertSame(Stage::Cancelada, $order->stage);
        $this->assertSame('Cliente desistiu do conserto', $order->cancel_reason);
        $this->assertNotNull($order->cancelled_at);

        $delivered = $this->order(Stage::Entregue);
        $this->expectExceptionMessage('não dá para cancelar');
        $this->machine()->cancel($delivered, Stage::Entregue, $atendimento, 'Tentando cancelar');
    }

    public function test_mecanico_nao_cancela(): void
    {
        $order = $this->order(Stage::EmExecucao);

        $this->expectExceptionMessage('Só o atendimento ou o gestor');
        $this->machine()->cancel($order, Stage::EmExecucao, User::factory()->mecanico()->create(), 'Motivo do cancelamento');
    }

    public function test_corrigir_na_conferencia_devolve_para_execucao_com_o_motivo(): void
    {
        $order = $this->order(Stage::EmConferencia);
        $conferente = User::factory()->mecanico()->create();

        try {
            $this->machine()->move($order, Stage::EmConferencia, Stage::EmExecucao, $conferente);
            $this->fail('CORRIGIR exige o motivo.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('corrigido', $e->getMessage());
        }

        $order = $this->machine()->move($order, Stage::EmConferencia, Stage::EmExecucao, $conferente, 'Abraçadeira da mangueira solta');
        $this->assertSame(Stage::EmExecucao, $order->stage);
        $this->assertSame('Abraçadeira da mangueira solta', $order->transitions()->get()->last()->reason);
    }

    public function test_entrega_so_com_o_termo_de_retirada_e_entregue_e_final(): void
    {
        $order = $this->order(Stage::ProntaRetirada);

        try {
            $this->machine()->move($order, Stage::ProntaRetirada, Stage::Entregue, User::factory()->atendimento()->create());
            $this->fail('Sem fotos de saída e termo assinado não entrega.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('termo de retirada assinado', $e->getMessage());
        }

        $order->update(['stage' => Stage::Entregue, 'delivered_at' => now()]);
        $this->assertSame([], app(StageMachine::class)->targetsFor($order, User::factory()->gestor()->create()));
    }

    public function test_botoes_mostram_so_o_que_o_perfil_faz(): void
    {
        $order = $this->order(Stage::Aprovada);

        $this->assertSame([Stage::AguardandoPecas, Stage::EmExecucao], $this->machine()->targetsFor($order, User::factory()->mecanico()->create()));
        $this->assertSame([Stage::AguardandoPecas], $this->machine()->targetsFor($order, User::factory()->atendimento()->create()));
    }
}
