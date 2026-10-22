<?php

namespace Tests\Feature\Orders;

use App\Enums\MediaKind;
use App\Enums\Stage;
use App\Enums\SupplierKind;
use App\Models\Media;
use App\Models\OrderItem;
use App\Models\QuoteRequest;
use App\Models\QuoteResponse;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ItemsAndQuoteScreensTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    public function test_mecanico_lanca_peca_e_servico_pela_tela_sem_ver_custo(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $douglas = Supplier::create(['name' => 'Douglas', 'kind' => SupplierKind::Servico, 'whatsapp' => '51991234567', 'active' => true]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Carga de gás', 'supplier_id' => $douglas->id, 'cost_cents' => 18000]);
        $this->actingAs(User::factory()->mecanico()->create());

        Livewire::test('pages::auth.orders.items', ['order' => $order])
            ->call('create', 'peca')
            ->set('form.description', 'Jogo de pastilhas dianteiras')
            ->call('save')
            ->assertDispatched('order-updated')
            ->call('create', 'servico')
            ->set('form.description', 'Troca de pastilhas dianteiras')
            ->set('form.hours', '1,5')
            ->call('save')
            ->assertSee('Jogo de pastilhas dianteiras')
            ->assertSee('1,5 h')
            ->assertDontSee('R$')
            ->assertDontSee('Douglas')
            ->assertDontSee('Origem');

        $this->assertSame(1.5, (float) OrderItem::query()->where('description', 'Troca de pastilhas dianteiras')->value('hours'));
    }

    public function test_atendimento_ve_o_custo_e_define_a_origem(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $douglas = Supplier::create(['name' => 'Douglas', 'kind' => SupplierKind::Servico, 'whatsapp' => '51991234567', 'active' => true]);
        $this->item($order, OrderItem::KIND_SERVICE, ['description' => 'Carga de gás', 'supplier_id' => $douglas->id, 'cost_cents' => 18000]);
        $part = $this->item($order);
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.orders.items', ['order' => $order])
            ->assertSee('Prestador: Douglas')
            ->assertSee('R$ 180,00')
            ->assertSee('vai para a cotação')
            ->call('setOrigin', $part->id, 'estoque')
            ->assertDispatched('order-updated')
            ->assertDontSee('vai para a cotação');

        $this->assertFalse($part->fresh()->toQuote());
    }

    public function test_cotacao_e_so_do_atendimento(): void
    {
        $order = $this->order(Stage::EmDiagnostico);
        $this->actingAs(User::factory()->mecanico()->create());

        Livewire::test('pages::auth.orders.show', ['order' => $order])->assertDontSee('data-test="aba-cotacao"', false);
        Livewire::test('pages::auth.orders.quote', ['order' => $order])->assertForbidden();
    }

    public function test_cotacao_pela_tela_ate_o_vencedor(): void
    {
        $order = $this->order(Stage::AguardandoCotacao);
        $order->customer->update(['name' => 'Maria Joaquina Teste']);
        $part = $this->item($order, OrderItem::KIND_PART, ['description' => 'Amortecedor dianteiro']);
        $supplier = Supplier::create(['name' => 'Casa do Amortecedor', 'kind' => SupplierKind::Pecas, 'whatsapp' => '51991234567', 'active' => true]);
        $this->actingAs(User::factory()->atendimento()->create());
        $code = 'COT-'.now()->year.'-0001';

        $page = Livewire::test('pages::auth.orders.quote', ['order' => $order])
            ->assertSet('selectedItems', [$part->id])
            ->set('selectedSuppliers', [$supplier->id])
            ->set('maxDelivery', 'hoje até 17h')
            ->call('createRequest')
            ->assertDispatched('toast', type: 'success')
            ->assertSee($code)
            ->assertSee('Casa do Amortecedor')
            ->assertSee('wa.me/5551991234567', false)
            ->assertDontSee('Maria Joaquina');

        $request = QuoteRequest::query()->where('code', $code)->firstOrFail();

        $page->call('markSent', $request->id, $supplier->id)
            ->set("responses.{$request->id}.{$part->id}.{$supplier->id}.cost", '1.234,50')
            ->call('chooseWinner', $request->id, $part->id, $supplier->id)
            ->assertDispatched('order-updated')
            ->assertSee('vencedor');

        $response = QuoteResponse::query()->firstOrFail();
        $this->assertSame(['disponivel', 123450, true], [$response->status, $response->cost_cents, $response->is_winner]);
        $this->assertNotNull($request->sends()->first()->sent_at);
        $this->assertSame([$supplier->id, 123450], [$part->fresh()->supplier_id, $part->fresh()->cost_cents]);
    }

    public function test_video_do_diagnostico_pela_tela(): void
    {
        Storage::fake('local');
        $order = $this->order(Stage::EmDiagnostico);
        $file = UploadedFile::fake()->create('barulho.mp4', 800, 'video/mp4');
        $hash = hash_file('sha256', $file->getRealPath());

        $this->actingAs(User::factory()->atendimento()->create());
        Livewire::test('pages::auth.orders.show', ['order' => $order])
            ->set('tab', 'problema')
            ->assertDontSee('Gravar vídeo')
            ->set('upload', $file)
            ->call('saveVideo', $hash, null)
            ->assertDispatched('toast', type: 'error');
        $this->assertSame(0, Media::query()->count());

        $this->actingAs(User::factory()->mecanico()->create());
        Livewire::test('pages::auth.orders.show', ['order' => $order])
            ->set('tab', 'problema')
            ->assertSee('Gravar vídeo')
            ->set('upload', $file)
            ->call('saveVideo', $hash, null)
            ->assertDispatched('toast', type: 'success')
            ->assertSee('SHA-256 '.substr($hash, 0, 12));

        $this->assertSame(MediaKind::Video, Media::query()->firstOrFail()->kind);
    }
}
