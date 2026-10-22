<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Orders\Inbox;
use App\Services\Registry\IziFleetImport;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class RegistryAndInboxTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    /** Como sai do Gestão de Frota (Veículos → Exportar): BOM, ponto e vírgula. */
    private function gestaoCsv(): string
    {
        return "\xEF\xBB\xBF".implode("\n", [
            '#;Modelo;Fabricação/Modelo;Placa;Chassi;Renavam;Documentos;Diária aplicativo;Diária eventual;Investidor;Nome no documento;CPF/CNPJ no documento;Entrada na Izi;Saída da Izi;Criado em',
            '1;Chevrolet Prisma 1.4;2019/2020;IZN4C21;9BGKS69T0LG000001;123;ok;97,00;120,00;Antônio Claudemiro;-;-;01/03/2024;-;01/03/2024 10:00',
            '2;Fiat Mobi;2021/2021;ABC1234;-;456;ok;90,00;110,00;Izi Car (frota própria);-;-;01/05/2024;-;01/05/2024 10:00',
            '3;VW Gol;2015/2016;OLD9A99;-;789;ok;80,00;100,00;Fulano;-;-;01/01/2023;10/02/2025 09:00;01/01/2023 10:00',
            '4;Placa ruim;2015;XX1;-;1;ok;0;0;-;-;-;-;-;-',
        ]);
    }

    public function test_importa_a_frota_izi_do_csv_do_gestao(): void
    {
        $import = app(IziFleetImport::class);
        $preview = $import->preview($this->gestaoCsv());

        $this->assertSame(['IZN4C21', 'ABC1234'], array_column($preview['rows'], 'plate'));
        $this->assertSame('Antônio Claudemiro', $preview['rows'][0]['investor_name']);
        $this->assertNull($preview['rows'][1]['investor_name'], 'carro próprio da Izi não tem investidor');
        $this->assertCount(2, $preview['skipped'], 'saiu da Izi e placa inválida ficam de fora');
        $this->assertSame(0, Vehicle::query()->count(), 'a prévia não grava');

        $this->assertSame(2, $import->apply($this->gestaoCsv()));
        $this->assertSame(2, $import->apply($this->gestaoCsv()), 'rodar de novo atualiza, não duplica');

        $prisma = Vehicle::byPlate('IZN-4C21');
        $this->assertTrue($prisma->is_izi_fleet);
        $this->assertSame('2019/2020', $prisma->year);
        $this->assertTrue($prisma->customer->is_izi_fleet);
        $this->assertSame(2, Vehicle::query()->count());
    }

    public function test_csv_sem_coluna_placa_e_recusado(): void
    {
        $this->expectExceptionMessage('coluna "Placa"');
        app(IziFleetImport::class)->preview("Nome;Telefone\nFulano;55999");
    }

    public function test_whatsapp_aceita_qualquer_formato_e_monta_o_link(): void
    {
        $this->assertSame('55991974167', Phone::digits('+55 (55) 99197-4167'));
        $this->assertSame('(55) 99197-4167', Phone::format('55991974167'));
        $this->assertSame('https://wa.me/5555991974167?text=Ol%C3%A1', Phone::whatsappLink('55 99197-4167', 'Olá'));
        $this->assertNull(Phone::whatsappLink('1234'));
    }

    public function test_avisos_mostram_o_passo_de_cada_perfil(): void
    {
        $aberta = $this->order(Stage::Aberta);
        $diagnostico = $this->order(Stage::EmDiagnostico);
        $robson = User::factory()->mecanico()->create();
        $conferirDoRobson = $this->order(Stage::EmConferencia, ['responsible_id' => $robson->id]);
        $conferirDeOutro = $this->order(Stage::EmConferencia);

        $inbox = app(Inbox::class);

        $atendimento = $inbox->for(User::factory()->atendimento()->create())->pluck('order.id')->all();
        $this->assertSame([$aberta->id], $atendimento);

        $mecanico = $inbox->for($robson)->pluck('order.id')->all();
        $this->assertContains($diagnostico->id, $mecanico);
        $this->assertContains($conferirDeOutro->id, $mecanico);
        $this->assertNotContains($conferirDoRobson->id, $mecanico, 'não confere o próprio serviço');
    }

    public function test_gestor_ve_as_os_paradas(): void
    {
        $parada = $this->order(Stage::AguardandoAprovacao, ['stage_changed_at' => now()->subDays(5)]);
        $this->order(Stage::AguardandoAprovacao);

        $items = app(Inbox::class)->for(User::factory()->gestor()->create());

        $this->assertSame([$parada->id], $items->pluck('order.id')->all());
        $this->assertStringContainsString('Parada há 5 dias', $items->first()['task']);
    }
}
