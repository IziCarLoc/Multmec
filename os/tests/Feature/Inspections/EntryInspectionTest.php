<?php

namespace Tests\Feature\Inspections;

use App\Enums\MediaKind;
use App\Enums\Stage;
use App\Models\Consent;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Inspections\EntryInspection;
use App\Services\Inspections\InspectionDelivery;
use App\Services\Inspections\InspectionPdf;
use App\Services\Inspections\TechnicalChecklist;
use App\Services\Media\MediaStore;
use App\Services\Orders\StageMachine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class EntryInspectionTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function photo(int $width = 1200, int $height = 900): UploadedFile
    {
        return UploadedFile::fake()->image('foto.jpg', $width, $height);
    }

    private function store(ServiceOrder $order, MediaKind $kind, ?string $position = null, ?User $by = null, ?string $caption = null): Media
    {
        $file = $this->photo();

        return app(MediaStore::class)->storePhoto($order, $kind, $file, hash_file('sha256', $file->getRealPath()), now()->getTimestampMs(), $by ?? User::factory()->atendimento()->create(), ['position' => $position, 'caption' => $caption]);
    }

    /** Parte A completa, sem o envio ao cliente. */
    private function completePartA(ServiceOrder $order, User $giovana): void
    {
        foreach (array_keys(EntryInspection::PHOTOS) as $position) {
            $this->store($order, EntryInspection::kindFor($position), $position, $giovana);
        }

        app(EntryInspection::class)->update($order, [
            'km_in' => '87.512', 'fuel_in' => '1/2', 'dashboard_lights' => 'Nenhuma', 'belongings' => 'Nenhum',
            'has_spare_tire' => true, 'has_jack' => true, 'has_triangle' => false, 'has_wheel_wrench' => true,
            'no_visible_damage' => true, 'test_drive_authorized' => true, 'diagnosis_charged' => false,
            'approver_kind' => 'cliente',
        ], $giovana);
    }

    private function completePartB(ServiceOrder $order): void
    {
        $robson = User::factory()->mecanico()->create();
        $checklist = app(TechnicalChecklist::class);

        foreach ($checklist->itemsFor($order) as $item) {
            $checklist->answer($item, $item->is_fluid ? 'ideal' : 'ok', null, $robson);
        }
    }

    public function test_foto_sem_hash_ou_com_hash_diferente_e_recusada(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $store = app(MediaStore::class);
        $user = User::factory()->atendimento()->create();

        try {
            $store->storePhoto($order, MediaKind::Entrada, $this->photo(), null, null, $user);
            $this->fail('Sem hash não entra.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sem a conferência', $e->getMessage());
        }

        try {
            $store->storePhoto($order, MediaKind::Entrada, $this->photo(), str_repeat('a', 64), null, $user);
            $this->fail('Hash diferente não entra.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('diferente do que saiu do celular', $e->getMessage());
        }

        $this->assertSame(0, Media::query()->count());
    }

    public function test_original_fica_intacto_com_copia_carimbada_e_miniatura(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $file = $this->photo(2000, 1500);
        $original = (string) file_get_contents($file->getRealPath());

        $media = app(MediaStore::class)->storePhoto($order, MediaKind::Entrada, $file, hash('sha256', $original), now()->subHours(2)->getTimestampMs(), User::factory()->atendimento()->create(), ['position' => 'dianteira_esquerda']);

        $disk = Storage::disk('local');
        $this->assertSame($original, $disk->get($media->original_path), 'o arquivo recebido não muda');
        $this->assertSame(hash('sha256', $original), $media->original_sha256);
        $this->assertSame(hash('sha256', (string) $disk->get($media->stamped_path)), $media->stamped_sha256);
        $stamped = getimagesizefromstring((string) $disk->get($media->stamped_path));
        $this->assertSame(1600, $stamped[0], 'carimbada no máximo com 1.600 px');
        $this->assertGreaterThan(1200, $stamped[1], 'a faixa do carimbo fica embaixo da foto');
        $thumb = getimagesizefromstring((string) $disk->get($media->thumb_path));
        $this->assertLessThanOrEqual(400, max($thumb[0], $thumb[1]));
        $this->assertTrue($media->is_stale, 'foto tirada 2 horas antes do envio é antiga');
        $this->assertStringStartsWith($order->storageDir().'/', $media->original_path);
    }

    public function test_foto_so_abre_com_login(): void
    {
        $media = $this->store($this->order(Stage::EmVistoria), MediaKind::Entrada, 'dianteira_esquerda');

        $this->get(route('media.show', $media))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->mecanico()->create())->get(route('media.show', ['media' => $media, 'variant' => 'mini']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_parte_a_lista_o_que_falta_e_fica_completa(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $inspection = app(EntryInspection::class);

        $labels = array_column($inspection->missing($order), 'label');
        $this->assertContains('Km de entrada', $labels);
        $this->assertContains('Avarias marcadas na foto ou "sem avarias aparentes"', $labels);
        $this->assertCount(5, array_filter($labels, fn ($l) => str_starts_with($l, 'Foto:')));

        $this->completePartA($order, $giovana);

        $this->assertSame([], $inspection->missing($order->fresh()));
        $this->assertSame(87512, $order->fresh()->km_in);
    }

    public function test_diagnostico_cobrado_pede_o_valor(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $this->completePartA($order, $giovana);

        app(EntryInspection::class)->update($order, ['diagnosis_charged' => true], $giovana);
        $this->assertContains('Diagnóstico cobrado (sim ou não, e o valor)', array_column(app(EntryInspection::class)->missing($order->fresh()), 'label'));

        app(EntryInspection::class)->update($order, ['diagnosis_fee_cents' => 15000], $giovana);
        $this->assertSame([], app(EntryInspection::class)->missing($order->fresh()));
    }

    public function test_avaria_com_marcas_e_sem_avarias_nao_convivem(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $inspection = app(EntryInspection::class);
        $media = $this->store($order, MediaKind::Avaria, null, $giovana, 'Porta dianteira esquerda');

        $damage = $inspection->addDamage($order, 'Porta dianteira esquerda', $media, $giovana);
        $inspection->setMarks($damage, [['x' => 0.4, 'y' => 1.7, 'type' => 'Amassado', 'desc' => 'perto da maçaneta'], ['x' => '0.1', 'y' => '0.2', 'type' => 'xx']], $giovana);

        $this->assertEquals([['x' => 0.4, 'y' => 1, 'type' => 'Amassado', 'desc' => 'perto da maçaneta'], ['x' => 0.1, 'y' => 0.2, 'type' => 'Outro', 'desc' => null]], $damage->fresh()->markList());
        $this->assertFalse($order->fresh()->no_visible_damage);

        $this->expectExceptionMessage('Há avarias marcadas');
        $inspection->update($order->fresh(), ['no_visible_damage' => true], $giovana);
    }

    public function test_avaria_removida_guarda_a_foto(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $media = $this->store($order, MediaKind::Avaria, null, $giovana);
        $damage = app(EntryInspection::class)->addDamage($order, 'Capô', $media, $giovana);

        app(EntryInspection::class)->removeDamage($damage, $giovana);

        $this->assertNull($damage->fresh());
        $this->assertNotNull($media->fresh()->replaced_at);
        $this->assertTrue(Storage::disk('local')->exists($media->original_path));
    }

    public function test_mecanico_nao_mexe_na_parte_a_e_atendimento_nao_mexe_na_parte_b(): void
    {
        $order = $this->order(Stage::EmVistoria);

        try {
            app(EntryInspection::class)->update($order, ['km_in' => 1000], User::factory()->mecanico()->create());
            $this->fail('Parte A é do atendimento.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('parte A', $e->getMessage());
        }

        $item = app(TechnicalChecklist::class)->itemsFor($order)->first();
        $this->expectExceptionMessage('parte B');
        app(TechnicalChecklist::class)->answer($item, 'ok', null, User::factory()->atendimento()->create());
    }

    public function test_parte_b_troca_pede_foto_e_lampada_pede_qual(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $robson = User::factory()->mecanico()->create();
        $checklist = app(TechnicalChecklist::class);
        $items = $checklist->itemsFor($order);

        $this->assertCount(14, $items);
        $this->assertSame(['Elétrica', 'Motor', 'Suspensão'], $items->pluck('section')->unique()->values()->all());

        $this->assertSame(9, $checklist->markRemainingOk($order, $robson), 'os 5 fluidos ficam para o mecânico ver o nível');
        $lamp = $items->firstWhere('label', 'Lâmpadas');
        $oil = $items->firstWhere('label', 'Óleo do motor');
        $checklist->answer($lamp->fresh(), 'troca', null, $robson);
        $checklist->answer($oil, 'baixo', null, $robson);

        $labels = array_column($checklist->missing($order), 'label');
        $this->assertContains('Foto do item "Lâmpadas" (necessário troca)', $labels);
        $this->assertContains('Dizer qual lâmpada precisa de troca', $labels);
        $this->assertContains('Foto do item "Óleo do motor" (baixo)', $labels);
        $this->assertContains('Checklist técnico: 4 itens sem resposta', $labels);

        $this->expectExceptionMessage('Resposta inválida');
        $checklist->answer($oil->fresh(), 'troca', null, $robson);
    }

    public function test_nao_vai_para_diagnostico_sem_vistoria_completa_e_entregue_ao_cliente(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $machine = app(StageMachine::class);

        try {
            $machine->move($order, Stage::EmVistoria, Stage::EmDiagnostico, $giovana);
            $this->fail('Vistoria incompleta não avança.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Falta para avançar', $e->getMessage());
        }

        $this->completePartA($order, $giovana);
        $this->completePartB($order);

        try {
            $machine->move($order->fresh(), Stage::EmVistoria, Stage::EmDiagnostico, $giovana);
            $this->fail('Sem enviar nem assinar não avança.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('enviada ao cliente pelo WhatsApp ou assinada', $e->getMessage());
        }

        $delivery = app(InspectionDelivery::class);
        $delivery->markSent($order->fresh(), 'compartilhar', $giovana);
        $order = $order->fresh();
        $this->assertNotNull($order->inspection_sent_at);
        $this->assertTrue(Storage::disk('local')->exists($order->inspection_pdf_path));
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get($order->inspection_pdf_path)), $order->inspection_pdf_sha256);

        // Mudou a vistoria depois de enviar: precisa enviar de novo.
        app(EntryInspection::class)->update($order, ['km_in' => 87600], $giovana);

        try {
            $machine->move($order->fresh(), Stage::EmVistoria, Stage::EmDiagnostico, $giovana);
            $this->fail('Vistoria mudou depois do envio.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('de novo', $e->getMessage());
        }

        $delivery->markSent($order->fresh(), 'manual', $giovana);
        $this->assertSame(Stage::EmDiagnostico, $machine->move($order->fresh(), Stage::EmVistoria, Stage::EmDiagnostico, $giovana)->stage);
    }

    public function test_envio_guarda_o_resumo_do_pdf_e_so_o_atendimento_registra(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $this->completePartA($order, $giovana);
        $this->completePartB($order);
        $delivery = app(InspectionDelivery::class);
        $prepared = $delivery->preparePdf($order->fresh(), $giovana);

        try {
            $delivery->markSent($order->fresh(), 'manual', User::factory()->mecanico()->create(), $prepared);
            $this->fail('Só o atendimento registra o envio, mesmo com o PDF pronto.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('atendimento', $e->getMessage());
        }

        // Mudou o km entre gerar o PDF e confirmar: o envio registrado é o do PDF, então pede para enviar de novo.
        app(EntryInspection::class)->update($order->fresh(), ['km_in' => 87600], $giovana);
        $delivery->markSent($order->fresh(), 'compartilhar', $giovana, $prepared);

        $this->expectExceptionMessage('de novo');
        app(StageMachine::class)->move($order->fresh(), Stage::EmVistoria, Stage::EmDiagnostico, $giovana);
    }

    public function test_assinatura_na_tela_vale_como_entrega_e_recusa_quadro_em_branco(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $this->completePartA($order, $giovana);

        $blank = imagecreatetruecolor(400, 150);
        imagesavealpha($blank, true);
        imagefill($blank, 0, 0, imagecolorallocatealpha($blank, 0, 0, 0, 127));
        ob_start();
        imagepng($blank);
        $blankPng = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

        try {
            app(InspectionDelivery::class)->sign($order, 'Maria Souza', null, $blankPng, $giovana, '10.0.0.1', 'Teste');
            $this->fail('Assinatura em branco não vale.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Assine', $e->getMessage());
        }

        $ink = imagecreatetruecolor(400, 150);
        imagefill($ink, 0, 0, imagecolorallocate($ink, 255, 255, 255));
        imagesetthickness($ink, 4);
        imageline($ink, 20, 100, 380, 40, imagecolorallocate($ink, 3, 22, 36));
        ob_start();
        imagepng($ink);
        $signed = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

        $consent = app(InspectionDelivery::class)->sign($order, 'Maria Souza', '529.982.247-25', $signed, $giovana, '10.0.0.1', 'Teste');

        $this->assertSame(Consent::PURPOSE_ENTRY, $consent->purpose);
        $this->assertSame('***.982.247-**', $consent->maskedDocument());
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get($consent->signature_path)), $consent->signature_sha256);
        $this->assertTrue(app(EntryInspection::class)->deliveredToCustomer($order->fresh()));
        $this->assertStringContainsString('[REVISAR', $consent->legal_text);
    }

    public function test_pdf_da_vistoria_sai_com_fotos_marcas_e_checklist(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $giovana = User::factory()->atendimento()->create();
        $this->completePartA($order, $giovana);
        $this->completePartB($order);
        $media = $this->store($order, MediaKind::Avaria, null, $giovana);
        app(EntryInspection::class)->update($order, ['no_visible_damage' => false], $giovana);
        $damage = app(EntryInspection::class)->addDamage($order, 'Capô', $media, $giovana);
        app(EntryInspection::class)->setMarks($damage, [['x' => 0.5, 'y' => 0.5, 'type' => 'Risco']], $giovana);

        $pdf = app(InspectionPdf::class)->render($order->fresh());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(20_000, strlen($pdf), 'leva as fotos');

        $this->actingAs($giovana)->get(route('orders.inspection.pdf', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
