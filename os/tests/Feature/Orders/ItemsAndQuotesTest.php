<?php

namespace Tests\Feature\Orders;

use App\Enums\MediaKind;
use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Enums\SupplierKind;
use App\Models\OrderItem;
use App\Models\QuoteResponse;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Items\OrderItems;
use App\Services\Media\MediaStore;
use App\Services\Orders\StageGate;
use App\Services\Orders\StageMachine;
use App\Services\Quotes\Quotations;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ItemsAndQuotesTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private function supplier(SupplierKind $kind = SupplierKind::Pecas, array $attributes = []): Supplier
    {
        return Supplier::create($attributes + ['name' => 'Auto Peças '.fake()->lastName(), 'kind' => $kind, 'whatsapp' => '51991234567', 'active' => true]);
    }

    private function labels(array $missing): array
    {
        return array_column($missing, 'label');
    }

    public function test_mecanico_lanca_item_sem_custo_nem_fornecedor(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $robson = User::factory()->mecanico()->create();

        $item = app(OrderItems::class)->add($order, OrderItem::KIND_PART, ['description' => '  Pastilha   de freio dianteira ', 'quantity' => '2'], $robson);

        $this->assertSame('Pastilha de freio dianteira', $item->description);
        $this->assertSame($robson->id, $item->mechanic_id);
        $this->assertTrue($item->toQuote());
        $this->assertTrue($item->requires_photos);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Custo, preço, origem e fornecedor são lançados pelo atendimento.');
        app(OrderItems::class)->update($item, ['cost_cents' => 5000], $robson);
    }

    public function test_quem_mexe_nos_itens_em_cada_etapa(): void
    {
        $items = app(OrderItems::class);
        $robson = User::factory()->mecanico()->create();
        $giovana = User::factory()->atendimento()->create();

        $quoting = $this->order(Stage::AguardandoCotacao);
        $part = $this->item($quoting);

        try {
            $items->update($part, ['quantity' => '2'], $robson);
            $this->fail('Na cotação o mecânico não muda o item.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('No orçamento, quem corrige os itens é o atendimento', $e->getMessage());
        }

        $this->assertSame('2.00', $items->update($part, ['quantity' => '2'], $giovana)->quantity);

        $budget = $this->order(Stage::AguardandoAprovacao);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O orçamento está com o cliente. Item novo entra depois do aceite, como adendo');
        $items->add($budget, OrderItem::KIND_SERVICE, ['description' => 'Alinhamento', 'hours' => '1'], $giovana);
    }

    public function test_peca_do_cliente_ou_do_estoque_nao_vai_para_a_cotacao(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $part = $this->item($order);
        $giovana = User::factory()->atendimento()->create();

        $part = app(OrderItems::class)->update($part, ['origin' => 'cliente'], $giovana);
        $this->assertSame(PartOrigin::Cliente, $part->origin);
        $this->assertFalse($part->toQuote());

        $part = app(OrderItems::class)->update($part, ['origin' => 'paralela'], $giovana);
        $this->assertTrue($part->toQuote());
        $this->assertTrue(PartOrigin::Paralela->needsAuthorization());
    }

    public function test_prestador_externo_no_servico(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $service = $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Carga de gás do ar-condicionado']);
        $giovana = User::factory()->atendimento()->create();

        try {
            app(OrderItems::class)->update($service, ['supplier_id' => $this->supplier(SupplierKind::Pecas, ['name' => 'Só Peças'])->id], $giovana);
            $this->fail('Fornecedor só de peças não presta serviço.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Só Peças não está cadastrado como prestador', $e->getMessage());
        }

        $douglas = $this->supplier(SupplierKind::Servico, ['name' => 'Douglas']);
        app(OrderItems::class)->update($service, ['supplier_id' => $douglas->id], $giovana);
        $order->update(['stage' => Stage::AguardandoCotacao]);

        $this->assertContains('Custo do prestador: Carga de gás do ar-condicionado', $this->labels(app(Quotations::class)->missing($order)));

        app(OrderItems::class)->update($service->fresh(), ['cost_cents' => 18000], $giovana);
        $this->assertSame([], app(Quotations::class)->missing($order));
    }

    public function test_servicos_frequentes_viram_lista_com_horas_medias(): void
    {
        foreach ([1, 2, 1.5] as $hours) {
            $this->item($this->order(Stage::EmDiagnostico), OrderItem::KIND_SERVICE, ['description' => 'Troca de pastilhas dianteiras', 'hours' => $hours]);
        }
        $this->item($this->order(Stage::EmDiagnostico), OrderItem::KIND_SERVICE, ['description' => 'Alinhamento', 'hours' => 0.5]);
        $this->item($this->order(Stage::EmDiagnostico), OrderItem::KIND_PART, ['description' => 'Pastilha']);

        $frequent = app(OrderItems::class)->frequentServices();

        $this->assertCount(2, $frequent);
        $this->assertSame(['description' => 'Troca de pastilhas dianteiras', 'count' => 3, 'hours' => 1.5], $frequent->first());
    }

    public function test_diagnostico_pede_horas_e_sempre_vai_para_o_orcamento(): void
    {
        $order = $this->order(Stage::EmDiagnostico, ['diagnosis' => 'Pastilhas no fim e disco riscado']);
        $robson = User::factory()->mecanico()->create();
        $gate = app(StageGate::class);
        $machine = app(StageMachine::class);

        $this->assertContains('Ao menos uma peça ou serviço', $this->labels($gate->missing($order, Stage::AguardandoCotacao)));
        $this->assertSame([Stage::AguardandoCotacao], $machine->targetsFor($order, $robson));

        // Só serviço (nada a cotar): o mecânico também manda para o orçamento, que o atendimento monta.
        $service = $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Troca de pastilhas', 'hours' => null]);
        $this->assertSame([Stage::AguardandoCotacao], $machine->targetsFor($order, $robson));
        $this->assertSame(['Horas estimadas: Troca de pastilhas'], $this->labels($gate->missing($order, Stage::AguardandoCotacao)));

        $service->update(['hours' => 1.5]);
        $this->assertSame(Stage::AguardandoCotacao, $machine->move($order, Stage::EmDiagnostico, Stage::AguardandoCotacao, $robson)->stage);
        $this->assertSame('Em orçamento', Stage::AguardandoCotacao->label());
    }

    public function test_mensagem_da_cotacao_nunca_leva_dados_do_cliente(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $order->customer->update(['name' => 'Maria Joaquina Teste', 'whatsapp' => '51998887766', 'document' => '52998224725', 'email' => 'maria@exemplo.com']);
        $order->vehicle->update(['chassis' => '9BGKS48U0LG123456', 'year' => '2020', 'engine' => '1.0']);
        $part = $this->item($order, OrderItem::KIND_PART, ['description' => 'Bieleta dianteira esquerda', 'part_code' => '52098765', 'preferred_brand' => 'Nakata']);
        $supplier = $this->supplier(SupplierKind::Pecas, ['whatsapp' => '51991234567']);

        $quotations = app(Quotations::class);
        $request = $quotations->create($order, [$part->id], [$supplier->id], 'hoje até 17h', 'boleto 28 dias', null, User::factory()->atendimento()->create());
        $message = $quotations->message($request);

        $this->assertStringContainsString('*Cotação COT-'.now()->year.'-0001*', $message);
        $this->assertStringContainsString($order->vehicle->plateFormatted(), $message);
        $this->assertStringContainsString('9BGKS48U0LG123456', $message);
        $this->assertStringContainsString('Bieleta dianteira esquerda · qtd 1 · cód. 52098765 · marca preferida Nakata', $message);
        $this->assertStringContainsString('Prazo máximo de entrega: hoje até 17h', $message);

        foreach (['Maria', 'Joaquina', '98887766', '99888-7766', '52998224725', '529.982.247-25', 'maria@exemplo.com'] as $private) {
            $this->assertStringNotContainsString($private, $message);
        }

        $link = $quotations->whatsappLink($request, $supplier);
        $this->assertStringStartsWith('https://wa.me/5551991234567?text=', $link);
        $this->assertStringNotContainsString('Maria', urldecode($link));
    }

    public function test_codigo_da_cotacao_segue_a_sequencia_do_ano(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $supplier = $this->supplier();
        $giovana = User::factory()->atendimento()->create();
        $quotations = app(Quotations::class);
        $year = now()->year;

        $first = $quotations->create($order, [$this->item($order)->id], [$supplier->id], null, null, null, $giovana);
        $second = $quotations->create($order, [$this->item($order)->id], [$supplier->id], null, null, null, $giovana);

        $this->assertSame("COT-{$year}-0001", $first->code);
        $this->assertSame("COT-{$year}-0002", $second->code);
        $this->assertSame('COT-'.($year + 1).'-0001', $quotations->nextCode($year + 1));
    }

    public function test_mecanico_nao_cota(): void
    {
        $order = $this->order(Stage::EmDiagnostico);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A cotação é feita pelo atendimento.');
        app(Quotations::class)->create($order, [$this->item($order)->id], [$this->supplier()->id], null, null, null, User::factory()->mecanico()->create());
    }

    public function test_respostas_e_vencedor_preenchem_o_item_e_liberam_o_orcamento(): void
    {
        $order = $this->order(Stage::AguardandoCotacao);
        $part = $this->item($order, OrderItem::KIND_PART, ['description' => 'Amortecedor dianteiro', 'quantity' => 2]);
        $other = $this->item($this->order(Stage::AguardandoCotacao));
        [$a, $b, $c] = [$this->supplier(), $this->supplier(), $this->supplier()];
        $giovana = User::factory()->atendimento()->create(['short_name' => 'Giovana']);
        $quotations = app(Quotations::class);

        $request = $quotations->create($order, [$part->id], [$a->id, $b->id, $c->id], null, null, null, $giovana);
        $this->assertContains('Vencedor da cotação: Amortecedor dianteiro', $this->labels(app(StageGate::class)->missing($order, Stage::AguardandoAprovacao)));

        $send = $quotations->markSent($request, $a, $giovana);
        $this->assertNotNull($send->sent_at);
        $this->assertSame($giovana->id, $send->sent_by);

        $quotations->saveResponse($request, $a, $part, ['status' => 'disponivel', 'brand' => 'Cofap', 'origin' => 'original', 'cost_cents' => 32000, 'lead_time' => 'hoje'], $giovana);
        $cheaper = $quotations->saveResponse($request, $b, $part, ['status' => 'encomenda', 'brand' => 'Nakata', 'origin' => 'paralela', 'cost_cents' => 25000, 'lead_time' => '2 dias'], $giovana);
        $none = $quotations->saveResponse($request, $c, $part, ['status' => 'nao_tem', 'cost_cents' => 100], $giovana);
        $this->assertNull($none->cost_cents);

        try {
            $quotations->saveResponse($request, $a, $other, ['status' => 'disponivel'], $giovana);
            $this->fail('Item de fora da cotação não recebe resposta.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('não está nesta cotação', $e->getMessage());
        }

        try {
            $quotations->chooseWinner($none, $giovana);
            $this->fail('Quem não tem a peça não vence.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('precisa ter o preço', $e->getMessage());
        }

        $item = $quotations->chooseWinner($cheaper, $giovana);
        $this->assertSame([$b->id, 25000, 'Nakata', '2 dias', PartOrigin::Paralela], [$item->supplier_id, $item->cost_cents, $item->brand, $item->lead_time, $item->origin]);

        // Trocar de ideia: só um vencedor por item.
        $quotations->chooseWinner(QuoteResponse::query()->where(['supplier_id' => $a->id, 'os_item_id' => $part->id])->firstOrFail(), $giovana);
        $this->assertSame(1, QuoteResponse::query()->where('os_item_id', $part->id)->where('is_winner', true)->count());
        $this->assertSame([$a->id, 32000, PartOrigin::Original], [$part->fresh()->supplier_id, $part->fresh()->cost_cents, $part->fresh()->origin]);

        // Cotação pronta: falta só o orçamento (preço, previsão e pagamento).
        $this->assertSame(['Preço: Amortecedor dianteiro', 'Previsão de entrega', 'Forma de pagamento'], $this->labels(app(StageGate::class)->missing($order, Stage::AguardandoAprovacao)));
        app(OrderItems::class)->update($part->fresh(), ['price_cents' => 45000], $giovana);
        $order->update(['expected_date' => now()->addDays(2)->toDateString(), 'payment_terms' => 'Pix na retirada']);

        $this->assertSame([], app(StageGate::class)->missing($order, Stage::AguardandoAprovacao));
        $this->assertSame(Stage::AguardandoAprovacao, app(StageMachine::class)->move($order, Stage::AguardandoCotacao, Stage::AguardandoAprovacao, $giovana)->stage);
        $this->assertSame(90000, $order->budgets()->firstOrFail()->total_cents);

        // Depois do aceite, trocar o vencedor não mexe no item que foi para o cliente (origem e custo do aceite).
        $budget = $order->budgets()->firstOrFail();
        app(Budgets::class)->approve($budget, ['method' => 'presencial', 'items' => $budget->items->pluck('id')->all(), 'signer_name' => 'Cliente de Teste', 'signer_document' => '52998224725'], $giovana);

        try {
            $quotations->chooseWinner($cheaper->fresh(), $giovana);
            $this->fail('Item do orçamento aceito não troca de vencedor.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('já foi para o cliente', $e->getMessage());
        }

        $this->assertSame([$a->id, 32000, PartOrigin::Original], [$part->fresh()->supplier_id, $part->fresh()->cost_cents, $part->fresh()->origin]);
    }

    public function test_video_sem_hash_diferente_grande_ou_que_nao_e_video_e_recusado(): void
    {
        Storage::fake('local');
        $order = $this->order(Stage::EmDiagnostico);
        $robson = User::factory()->mecanico()->create();
        $store = app(MediaStore::class);
        $video = fn (int $kb = 500, string $mime = 'video/mp4') => UploadedFile::fake()->create('barulho.mp4', $kb, $mime);

        $cases = [
            'hash' => [$video(), null],
            'diferente' => [$video(), str_repeat('a', 64)],
            '20 MB' => [$video(21 * 1024), null],
            'não é um vídeo' => [$video(500, 'image/jpeg'), null],
        ];

        foreach ($cases as $expected => [$file, $hash]) {
            try {
                $store->storeVideo($order, $file, $hash, null, $robson);
                $this->fail('Devia recusar: '.$expected);
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $file = $video();
        $media = $store->storeVideo($order, $file, hash_file('sha256', $file->getRealPath()), now()->getTimestampMs(), $robson, 'Diagnóstico');

        $this->assertSame(MediaKind::Video, $media->kind);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $media->original_sha256);
        Storage::disk('local')->assertExists($media->original_path);
    }
}
