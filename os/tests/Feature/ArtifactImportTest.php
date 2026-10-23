<?php

namespace Tests\Feature;

use App\Enums\LossCause;
use App\Enums\PaymentMethod;
use App\Enums\SupplierKind;
use App\Models\Customer;
use App\Models\Loss;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Import\ArtifactImport;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ArtifactImportTest extends TestCase
{
    use RefreshDatabase;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gestor = User::factory()->gestor()->create();
        $this->actingAs($this->gestor);
    }

    private function import(): ArtifactImport
    {
        return app(ArtifactImport::class);
    }

    /** Exportação de mentira, no formato do "Exportar tudo" (dados inventados). */
    private function export(array $changes = []): string
    {
        $financeiro = [
            'fornecedores' => [
                'f1' => ['nome' => 'Auto Peças Teste', 'cnpj' => '11.222.333/0001-81', 'telefone' => '', 'contato' => 'Junior', 'pagamento' => '28 dias', 'obs' => ''],
            ],
            'boletos' => [
                'b1' => ['fornecedorId' => 'f1', 'doc' => '4512 · parcela 1/2', 'desc' => '', 'emissao' => '2026-10-01', 'vencimento' => '2026-10-31', 'valor' => 150.5, 'status' => 'aberto', 'pagoEm' => '', 'obs' => '23790.00000 linha'],
                'b2' => ['fornecedorId' => 'f1', 'doc' => '4512 · parcela 2/2', 'desc' => '', 'emissao' => '2026-10-01', 'vencimento' => '2026-11-30', 'valor' => 150.5, 'status' => 'aberto', 'pagoEm' => '', 'obs' => ''],
                'b3' => ['fornecedorId' => 'f1', 'doc' => '7788', 'desc' => 'Óleo do estoque', 'emissao' => '2026-09-01', 'vencimento' => '2026-09-15', 'valor' => 80, 'status' => 'pago', 'pagoEm' => '2026-09-14', 'obs' => ''],
                'b4' => ['fornecedorId' => 'sumiu', 'doc' => '9999', 'desc' => '', 'emissao' => '', 'vencimento' => '2026-11-01', 'valor' => 10, 'status' => 'aberto', 'pagoEm' => '', 'obs' => ''],
            ],
            'receber' => [
                'r1' => ['cliente' => 'Fulano de Teste', 'telefone' => '', 'placa' => 'ABC1D23', 'modelo' => 'ONIX', 'os' => '1001', 'dataManutencao' => '2026-09-10', 'custoPeca' => 180, 'valorMo' => 150, 'valorFrete' => 20, 'valor' => 450.5, 'clienteTrouxePeca' => false, 'formaPagamento' => 'pix', 'vencimento' => '2026-09-10', 'recebidoEm' => '2026-09-12', 'status' => 'recebido', 'obs' => 'C/ MATHEUS', 'lucroBruto' => 1],
                'r2' => ['cliente' => 'IZICAR', 'telefone' => '', 'placa' => 'ABC1234', 'modelo' => 'MOBI', 'os' => '1002', 'dataManutencao' => '2026-10-01', 'custoPeca' => 0, 'valorMo' => 300, 'valorFrete' => 0, 'valor' => 300, 'clienteTrouxePeca' => false, 'formaPagamento' => 'boleto', 'vencimento' => '2026-10-20', 'recebidoEm' => '', 'status' => 'aberto', 'obs' => ''],
                'r3' => ['cliente' => 'fulano de teste', 'telefone' => '', 'placa' => 'XYZ', 'modelo' => 'GOL', 'os' => '1003', 'dataManutencao' => '2026-10-05', 'custoPeca' => 50, 'valorMo' => 100, 'valorFrete' => 0, 'valor' => 200, 'clienteTrouxePeca' => true, 'formaPagamento' => 'cartao', 'vencimento' => '2026-10-05', 'recebidoEm' => '', 'status' => 'aberto', 'obs' => '', 'recebimentoMatriz' => true],
                'r4' => ['cliente' => 'Beltrano', 'telefone' => '', 'placa' => 'DEF4G56', 'modelo' => 'HB20', 'os' => '1004', 'dataManutencao' => '2026-10-06', 'custoPeca' => 0, 'valorMo' => 400, 'valorFrete' => 0, 'valor' => 400, 'clienteTrouxePeca' => false, 'formaPagamento' => 'dinheiro', 'vencimento' => '2026-10-06', 'recebidoEm' => '', 'status' => 'aberto', 'obs' => '', 'pagamentos' => [['data' => '2026-10-07', 'valor' => 100]]],
            ],
            'pagamentosDouglas' => [
                'd1' => ['os' => '07', 'descricao' => 'Ar-condicionado', 'placa' => 'ABC1D23', 'valor' => 120, 'vencimento' => '2026-10-10', 'pagoEm' => '', 'status' => 'aberto', 'obs' => ''],
            ],
            'prejuizos' => [
                'p1' => ['data' => '2026-10-02', 'valor' => 35, 'item' => 'Lâmpada quebrada na troca', 'causa' => 'avariada', 'obs' => ''],
            ],
            'clientes' => [],
        ];
        $os = ['os' => [], 'config' => ['oficina' => ['nome' => 'OFICINA DE TESTE']]];

        foreach ($changes as $path => $value) {
            data_set($financeiro, $path, $value);
        }

        return json_encode(['formato' => 'multmec-artefatos-v1', 'artefatos' => ['financeiro' => $financeiro, 'os' => $os]]);
    }

    public function test_previa_mostra_tudo_e_nao_grava_nada(): void
    {
        $preview = $this->import()->preview($this->export(), $this->gestor);

        $this->assertSame(hash('sha256', $this->export()), $preview['sha256']);
        $this->assertSame([3, 38100], [$preview['sections']['boletos']['new'], $preview['sections']['boletos']['cents']]);
        $this->assertStringContainsString('Boleto 9999: o fornecedor dele não está no arquivo', $preview['sections']['boletos']['problems'][0]);
        $this->assertSame(4, $preview['sections']['receber']['new']);
        $this->assertSame([1, 1, 1], [$preview['sections']['pagamentosDouglas']['new'], $preview['sections']['prejuizos']['new'], $preview['sections']['fornecedores']['new'] - 1]);

        $this->assertSame([0, []], [$preview['sections']['os']['new'], $preview['sections']['os']['problems']]);

        $this->assertSame(0, Payable::query()->count() + Receivable::query()->count() + Supplier::query()->count() + Customer::query()->count());
    }

    public function test_importa_com_parcelas_recebimentos_izi_e_prestador(): void
    {
        $this->import()->apply($this->export(), $this->gestor);

        $supplier = Supplier::query()->where('name', 'Auto Peças Teste')->firstOrFail();
        $this->assertSame(['11222333000181', '28 dias', 'Contato: Junior'], [$supplier->document, $supplier->payment_terms, $supplier->notes]);

        [$first, $second] = Payable::query()->where('document_number', '4512')->orderBy('installment_number')->get()->all();
        $this->assertSame([15050, '1/2', '2/2'], [$first->amount_cents, $first->installmentLabel(), $second->installmentLabel()]);
        // UUID versão 5 com a variante do padrão: o MariaDB (produção) recusa outro formato na coluna uuid.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->installment_group);
        $this->assertSame($first->installment_group, $second->installment_group);
        $this->assertSame([Payable::STATUS_PAID, '2026-09-14'], [Payable::query()->where('legacy_id', 'boletos:b3')->value('status'), Payable::query()->where('legacy_id', 'boletos:b3')->first()->paid_on->toDateString()]);

        $received = Receivable::query()->where('legacy_id', 'receber:r1')->firstOrFail();
        $this->assertSame([45050, 18000, 15000, 2000, 45050, '2026-09-12'], [$received->total_cents, $received->parts_cost_cents, $received->labor_cents, $received->freight_cents, $received->paid_cents, $received->received_on->toDateString()]);
        $this->assertSame([PaymentMethod::Pix, 'ONIX'], [$received->payment_method, $received->vehicle->brand_model]);

        $izi = Receivable::query()->where('legacy_id', 'receber:r2')->firstOrFail();
        $this->assertTrue($izi->customer->is_izi_fleet);
        $this->assertTrue($izi->vehicle->is_izi_fleet);
        $this->assertStringContainsString('Cliente no artefato: IZICAR', $izi->notes);

        // Mesmo cliente com outra grafia; placa inválida fica só no lançamento; peça trazida zera o custo.
        $matrix = Receivable::query()->where('legacy_id', 'receber:r3')->firstOrFail();
        $this->assertSame($received->customer_id, $matrix->customer_id);
        $this->assertSame([true, true, 0, null, 'XYZ'], [$matrix->received_by_matrix, $matrix->customer_brought_part, $matrix->parts_cost_cents, $matrix->vehicle_id, $matrix->plate]);

        $partial = Receivable::query()->where('legacy_id', 'receber:r4')->firstOrFail();
        $this->assertSame([10000, 'parcial'], [$partial->paid_cents, $partial->status()->value]);

        $douglas = ProviderPayment::query()->firstOrFail();
        $this->assertSame(['Douglas', SupplierKind::Servico, 12000, '07'], [$douglas->supplier->name, $douglas->supplier->kind, $douglas->amount_cents, $douglas->order_number]);

        $loss = Loss::query()->firstOrFail();
        $this->assertSame([3500, LossCause::Avariada], [$loss->amount_cents, $loss->cause]);
        $this->assertSame(2, Vehicle::query()->whereIn('plate', ['ABC1D23', 'DEF4G56'])->count());
    }

    public function test_importar_de_novo_nao_duplica_e_traz_as_baixas_novas(): void
    {
        $this->import()->apply($this->export(), $this->gestor);
        $counts = fn () => [Payable::query()->count(), Receivable::query()->count(), ProviderPayment::query()->count(), Loss::query()->count(), Supplier::query()->count(), Customer::query()->count(), Vehicle::query()->count()];
        $before = $counts();

        // Depois da primeira importação o artefato continuou em uso: um boleto pago, um recebido e o Douglas pago.
        $result = $this->import()->apply($this->export([
            'boletos.b1.status' => 'pago', 'boletos.b1.pagoEm' => '2026-10-30',
            'receber.r2.status' => 'recebido', 'receber.r2.recebidoEm' => '2026-10-21',
            'pagamentosDouglas.d1.status' => 'pago', 'pagamentosDouglas.d1.pagoEm' => '2026-10-11',
        ]), $this->gestor);

        $this->assertSame($before, $counts());
        $this->assertSame([1, 2], [$result['sections']['boletos']['updated'], $result['sections']['boletos']['existing']]);
        $this->assertSame([1, 0], [$result['sections']['receber']['updated'], $result['sections']['receber']['new']]);
        $this->assertSame('2026-10-30', Payable::query()->where('legacy_id', 'boletos:b1')->first()->paid_on->toDateString());
        $this->assertSame('recebido', Receivable::query()->where('legacy_id', 'receber:r2')->first()->status()->value);
        $this->assertTrue(ProviderPayment::query()->firstOrFail()->isPaid());
    }

    public function test_os_do_v12_ficam_no_artefato_e_sao_avisadas(): void
    {
        $preview = $this->import()->preview(json_encode(['colecoes' => ['os' => ['os1' => ['numero' => '1190'], 'os2' => ['numero' => '1191']]]]), $this->gestor);

        $this->assertStringContainsString('2 OS do v12', $preview['sections']['os']['problems'][0]);
    }

    public function test_arquivo_errado_e_avisado(): void
    {
        foreach (['isto não é json', '{"qualquer": 1}'] as $bad) {
            try {
                $this->import()->preview($bad, $this->gestor);
                $this->fail('Arquivo errado tem que ser recusado.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Exportar tudo', $e->getMessage());
            }
        }
    }

    public function test_tela_de_importacao(): void
    {
        $file = UploadedFile::fake()->createWithContent('multmec-artefatos.json', $this->export());

        Livewire::test('pages::auth.settings.import')
            ->set('file', $file)
            ->assertSee('Prévia')
            ->assertSee('A pagar (boletos)')
            ->assertSee('R$ 381,00')
            ->call('apply')
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Importado');

        $this->assertSame(3, Payable::query()->count());
        $this->actingAs(User::factory()->atendimento()->create())->get(route('settings.import'))->assertForbidden();
    }
}
