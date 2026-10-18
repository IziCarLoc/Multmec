<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Services\Media\HostingProbe;
use App\Services\Media\ImageTools;
use App\Services\Media\PhotoStamper;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_teste_de_envio_confere_o_hash_do_celular_com_o_do_servidor(): void
    {
        $content = str_repeat('vídeo de teste ', 1000);
        $file = UploadedFile::fake()->createWithContent('video.mp4', $content);

        Livewire::actingAs(User::factory()->gestor()->create())->test('pages::auth.settings.health')
            ->set('testFile', $file)
            ->call('verifyUpload', hash('sha256', $content), strlen($content), 1200)
            ->assertSet('uploadResult.ok', true)
            ->assertSet('testFile', null);
    }

    public function test_hash_diferente_ou_ausente_e_acusado(): void
    {
        $content = 'conteúdo';

        $component = Livewire::actingAs(User::factory()->gestor()->create())->test('pages::auth.settings.health')
            ->set('testFile', UploadedFile::fake()->createWithContent('foto.jpg', $content))
            ->call('verifyUpload', hash('sha256', 'outro conteúdo'), strlen($content), 100)
            ->assertSet('uploadResult.ok', false);

        $component->set('testFile', UploadedFile::fake()->createWithContent('foto.jpg', $content))
            ->call('verifyUpload', null, strlen($content), 100)
            ->assertSet('uploadResult.ok', false);
    }

    public function test_carimbo_acrescenta_a_faixa_embaixo_sem_cobrir_a_foto(): void
    {
        $image = imagecreatetruecolor(1600, 1200);
        $stamped = app(PhotoStamper::class)->stamp($image, 'OS 1250  ABC1D23  PEÇA VELHA', '18/10/2026 10:00:00', 'PEÇA VELHA');

        $this->assertSame(1600, imagesx($stamped));
        $this->assertSame(1200 + 96, imagesy($stamped), 'faixa de 6% da largura');
        // A marca da esquerda tem a cor da peça velha (ferrugem), como no v12.
        $this->assertSame(PhotoStamper::KIND_COLORS['PEÇA VELHA'], array_values(array_slice(imagecolorsforindex($stamped, imagecolorat($stamped, 2, 1250)), 0, 3)));
    }

    public function test_foto_grande_demais_e_recusada_antes_de_abrir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Cabeçalho PNG de 10000 × 10000 (100 MP) sem os pixels: recusado sem decodificar.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 10000, 10000)."\x08\x02\x00\x00\x00".pack('N', 0);
        ImageTools::fromString($png);
    }

    public function test_pdf_com_fotos_gera_um_pdf(): void
    {
        $result = app(HostingProbe::class)->pdfWithPhotos(2);

        $this->assertStringStartsWith('%PDF', $result['pdf']);
        $this->assertGreaterThan(0, $result['peak_mb']);
    }

    public function test_agendador_parado_aparece_como_problema(): void
    {
        $this->assertFalse(SystemHeartbeat::schedulerIsAlive());

        SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
        $this->assertTrue(SystemHeartbeat::schedulerIsAlive());

        $this->travel(11)->minutes();
        $this->assertFalse(SystemHeartbeat::schedulerIsAlive());
    }

    public function test_limites_do_php_sao_lidos_em_bytes(): void
    {
        $this->assertSame(25 * 1024 * 1024, SystemHealth::bytes('25M'));
        $this->assertSame(1024 ** 3, SystemHealth::bytes('1G'));
        $this->assertSame(-1, SystemHealth::bytes('-1'));
        $this->assertSame('1,5 MB', SystemHealth::humanBytes(1572864));
    }

    public function test_conferir_deploy_passa_com_o_banco_migrado(): void
    {
        $this->artisan('sistema:conferir-deploy')->expectsOutputToContain('Deploy conferido.')->assertSuccessful();
    }
}
