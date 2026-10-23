<?php

namespace Tests\Feature\Orders;

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Consent;
use App\Models\OrderItem;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Delivery\Delivery;
use App\Services\Finance\Profit;
use App\Services\Inspections\EntryInspection;
use App\Services\Orders\StageMachine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private const CPF = '52998224725';

    private User $giovana;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->giovana = User::factory()->atendimento()->create(['short_name' => 'Giovana']);
    }

    private function delivery(): Delivery
    {
        return app(Delivery::class);
    }

    private function signature(): string
    {
        $ink = imagecreatetruecolor(400, 150);
        imagefill($ink, 0, 0, imagecolorallocate($ink, 255, 255, 255));
        imagesetthickness($ink, 4);
        imageline($ink, 20, 100, 380, 40, imagecolorallocate($ink, 3, 22, 36));
        ob_start();
        imagepng($ink);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /** Pronta para retirada: peça (custo R$ 180, preço R$ 300) e serviço do prestador (custo R$ 80, preço R$ 150). */
    private function readyOrder(): ServiceOrder
    {
        $order = $this->order(Stage::ProntaRetirada, ['km_in' => 61230, 'fuel_in' => '1/2']);
        $douglas = Supplier::create(['name' => 'Douglas', 'kind' => SupplierKind::Servico, 'whatsapp' => '51991234567', 'active' => true]);
        $this->item($order, OrderItem::KIND_PART, ['description' => 'Compressor do ar', 'origin' => PartOrigin::Original, 'price_cents' => 30000, 'cost_cents' => 18000, 'approval' => OrderItem::APPROVED]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Troca do compressor', 'supplier_id' => $douglas->id, 'cost_cents' => 8000, 'price_cents' => 15000, 'approval' => OrderItem::APPROVED]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Higienização', 'price_cents' => 5000, 'approval' => OrderItem::REFUSED]);

        return $order;
    }

    private function prepareExit(ServiceOrder $order): ServiceOrder
    {
        foreach (array_keys(EntryInspection::PHOTOS) as $position) {
            $file = UploadedFile::fake()->image('saida.jpg', 800, 600);
            $this->delivery()->storeExitPhoto($order, $position, $file, hash_file('sha256', $file->getRealPath()), null, $this->giovana);
        }

        return $this->delivery()->update($order, ['km_out' => '61.240', 'fuel_out' => '1/2', 'replaced_parts' => 'entregues'], $this->giovana);
    }

    private function deliverData(array $overrides = []): array
    {
        return $overrides + [
            'who' => 'cliente',
            'signer_name' => 'Cliente de Teste',
            'signer_document' => self::CPF,
            'signature' => $this->signature(),
            'payment_method' => 'pix',
            'due_dates' => [today()->toDateString()],
            'received_now' => true,
        ];
    }

    public function test_entrega_completa_lanca_o_a_receber_e_o_prestador(): void
    {
        $order = $this->prepareExit($this->readyOrder());
        $this->assertSame([], $this->delivery()->missing($order));

        $order = $this->delivery()->deliver($order, $this->deliverData(), $this->giovana, '10.0.0.1', 'Teste');

        $this->assertSame([Stage::Entregue, $this->giovana->id], [$order->stage, $order->delivered_by]);
        $this->assertNotNull($order->delivered_at);
        $consent = Consent::query()->where('purpose', Consent::PURPOSE_PICKUP)->firstOrFail();
        $this->assertSame(['cliente', 45000, 5], [$consent->signer_relation, $consent->snapshot['total'], count($consent->snapshot['fotos_saida'])]);

        $receivable = Receivable::query()->where('service_order_id', $order->id)->firstOrFail();
        $this->assertSame([45000, 15000, 18000, 45000], [$receivable->total_cents, $receivable->labor_cents, $receivable->parts_cost_cents, $receivable->paid_cents]);

        $provider = ProviderPayment::query()->where('service_order_id', $order->id)->firstOrFail();
        $this->assertSame([8000, 'Troca do compressor'], [$provider->amount_cents, $provider->description]);

        // Lucro bruto = 450 − 180 (peça) − 0 (frete) − 80 (prestador).
        $this->assertSame(8000, app(Profit::class)->providerCost($receivable));
        $this->assertSame(19000, app(Profit::class)->grossFor($receivable));
    }

    public function test_entrega_pede_fotos_km_pecas_e_documento_de_quem_retira(): void
    {
        $order = $this->readyOrder();
        $labels = array_column($this->delivery()->missing($order), 'label');
        $this->assertContains('Foto de saída: frente, lado esquerdo (com a placa)', $labels);
        $this->assertContains('Km de saída', $labels);
        $this->assertContains('Peças substituídas: entregues, descarte ou recusou', $labels);

        try {
            $this->delivery()->update($order, ['km_out' => '1000'], $this->giovana);
            $this->fail('Km de saída menor que o de entrada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('menor que o de entrada', $e->getMessage());
        }

        $order = $this->prepareExit($order);

        foreach ([['signer_document' => '111'], ['who' => 'autorizado', 'authorization' => '']] as $bad) {
            try {
                $this->delivery()->deliver($order, $this->deliverData($bad), $this->giovana, null, null);
                $this->fail('Documento e autorização por escrito são obrigatórios.');
            } catch (DomainException $e) {
                $this->assertMatchesRegularExpression('/documento|autorização por escrito/', $e->getMessage());
            }
        }

        $this->assertSame(Stage::ProntaRetirada, $order->fresh()->stage);
        $this->assertSame(0, Receivable::query()->count());
    }

    public function test_pagamento_em_parcelas_divide_em_centavos(): void
    {
        $order = $this->prepareExit($this->readyOrder());
        $dates = [today()->toDateString(), today()->addMonth()->toDateString(), today()->addMonths(2)->toDateString()];
        // Parcelado é a prazo: o cliente precisa de crédito (a trava do crédito na entrega está no CreditTest).
        $order->customer->update(['credit_kind' => 'frota', 'credit_limit_cents' => 100000]);

        $this->delivery()->deliver($order, $this->deliverData(['due_dates' => $dates, 'received_now' => false, 'who' => 'autorizado', 'authorization' => 'Pode entregar para o meu irmão (WhatsApp do cliente)']), $this->giovana, null, null);

        $this->assertSame([15000, 15000, 15000], Receivable::query()->orderBy('due_date')->pluck('total_cents')->all());
        $this->assertSame(0, (int) Receivable::query()->sum('paid_cents'));
        $this->assertSame('autorizado por escrito', Consent::query()->where('purpose', Consent::PURPOSE_PICKUP)->value('signer_relation'));
    }

    public function test_entrega_a_prazo_respeita_a_trava_do_credito(): void
    {
        $order = $this->prepareExit($this->readyOrder());
        $later = ['due_dates' => [today()->addDays(15)->toDateString()], 'received_now' => false];

        // Cliente sem crédito: a prazo trava antes de assinar (nada é gravado).
        try {
            $this->delivery()->deliver($order, $this->deliverData($later), $this->giovana, null, null);
            $this->fail('Cliente sem crédito não leva a prazo.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('não tem crédito a prazo', $e->getMessage());
        }

        try {
            $this->delivery()->deliver($order, $this->deliverData($later + ['credit_release' => 'Cliente conhecido']), $this->giovana, null, null);
            $this->fail('Só o gestor libera.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Só o gestor', $e->getMessage());
        }

        $this->assertSame([Stage::ProntaRetirada, 0, 0], [$order->fresh()->stage, Receivable::query()->count(), Consent::query()->where('purpose', Consent::PURPOSE_PICKUP)->count()]);

        // O gestor libera com motivo (fica na trilha) e a entrega segue.
        $gestor = User::factory()->gestor()->create();
        $this->delivery()->deliver($order, $this->deliverData($later + ['credit_release' => 'Combinou pagar na quinzena']), $gestor, null, null);

        $this->assertSame(Stage::Entregue, $order->fresh()->stage);
        $this->assertSame(today()->addDays(15)->toDateString(), Receivable::query()->value('due_date')->toDateString());
        $this->assertStringContainsString('Combinou pagar na quinzena', (string) AuditLog::query()->where('action', 'credit.released')->value('reason'));
    }

    public function test_tela_da_entrega_avisa_a_trava_e_usa_o_prazo_do_cliente(): void
    {
        $order = $this->prepareExit($this->readyOrder());
        $this->actingAs($this->giovana);

        Livewire::test('pages::auth.orders.delivery', ['order' => $order])
            ->assertDontSee('data-test="trava-credito"', false)
            ->set('receivedNow', false)
            ->set('dueDates', [today()->addDays(10)->toDateString()])
            ->assertSee('data-test="trava-credito"', false)
            ->assertSee('peça ao gestor');

        $order->customer->update(['credit_kind' => 'frota', 'credit_days' => 7, 'credit_limit_cents' => 500000]);

        Livewire::test('pages::auth.orders.delivery', ['order' => $order->fresh()])
            ->assertSet('dueDates', [today()->addDays(7)->toDateString()])
            ->assertSet('receivedNow', false)
            ->assertDontSee('data-test="trava-credito"', false);
    }

    public function test_recusada_devolve_o_carro_e_cobra_so_o_diagnostico(): void
    {
        $order = $this->order(Stage::Recusada, ['km_in' => 1000, 'fuel_in' => '1/4']);
        Budget::create(['service_order_id' => $order->id, 'number' => 1, 'kind' => Budget::KIND_BUDGET, 'status' => Budget::STATUS_REFUSED, 'snapshot' => [], 'snapshot_sha256' => str_repeat('0', 64), 'parts_cents' => 0, 'labor_cents' => 50000, 'total_cents' => 50000, 'diagnosis_fee_cents' => 8000]);

        $order = app(StageMachine::class)->move($order, Stage::Recusada, Stage::ProntaRetirada, $this->giovana);
        $this->assertSame(8000, $this->delivery()->amounts($order)['total']);

        foreach (array_keys(EntryInspection::PHOTOS) as $position) {
            $file = UploadedFile::fake()->image('saida.jpg', 800, 600);
            $this->delivery()->storeExitPhoto($order, $position, $file, hash_file('sha256', $file->getRealPath()), null, $this->giovana);
        }
        $this->delivery()->update($order, ['km_out' => 1000, 'fuel_out' => '1/4'], $this->giovana);

        $this->delivery()->deliver($order->fresh(), $this->deliverData(), $this->giovana, null, null);
        $this->assertSame([8000, 8000], [Receivable::query()->value('total_cents'), Receivable::query()->value('labor_cents')]);
    }

    public function test_nota_e_resumo_para_o_sistema_fiscal(): void
    {
        $order = $this->readyOrder();
        $order->customer->update(['document' => self::CPF]);

        $summary = $this->delivery()->fiscalSummary($order);
        $this->assertStringContainsString('SERVIÇOS (nota de serviço)', $summary);
        $this->assertStringContainsString('Troca do compressor · R$ 150,00', $summary);
        $this->assertStringContainsString('PEÇAS (nota de produto)', $summary);
        $this->assertStringContainsString('529.982.247-25', $summary);
        $this->assertStringContainsString('TOTAL: R$ 450,00', $summary);
        $this->assertStringNotContainsString('Higienização', $summary);

        $order = $this->delivery()->updateInvoice($order, 'emitida', '123', '456', $this->giovana);
        $this->assertSame(['emitida', '123', '456'], [$order->invoice_status, $order->invoice_service_number, $order->invoice_parts_number]);
    }

    public function test_pdf_da_os_e_por_mes(): void
    {
        $order = $this->readyOrder();

        $this->actingAs($this->giovana)->get(route('orders.pdf', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs(User::factory()->mecanico()->create())->get(route('orders.pdf', $order))->assertForbidden();

        $this->actingAs($this->giovana);
        Livewire::test('pages::auth.orders.monthly')->assertSee((string) $order->number)->assertSee('Por mês');
        $this->get(route('orders.monthly.pdf', ['mes' => now()->format('Y-m')]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_tela_da_entrega(): void
    {
        $order = $this->prepareExit($this->readyOrder());
        $this->actingAs($this->giovana);

        Livewire::test('pages::auth.orders.show', ['order' => $order])->assertSet('tab', 'entrega')->call('advance', 'entregue')->assertSet('tab', 'entrega');

        Livewire::test('pages::auth.orders.delivery', ['order' => $order])
            ->assertSee('R$ 450,00')
            ->assertSee('Termo de retirada')
            ->set('signerDocument', self::CPF)
            ->call('deliver', $this->signature())
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Entregue em');

        $this->assertSame(Stage::Entregue, $order->fresh()->stage);
    }
}
