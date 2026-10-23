<?php

namespace Tests\Feature\Inspections;

use App\Enums\Stage;
use App\Models\Damage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class InspectionScreenTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_tela_da_os_em_vistoria_abre_na_aba_vistoria_para_os_dois_perfis(): void
    {
        $order = $this->order(Stage::EmVistoria);

        foreach ([User::factory()->atendimento()->create(), User::factory()->mecanico()->create()] as $user) {
            $this->actingAs($user)->get(route('orders.show', $order))
                ->assertOk()
                ->assertSee('Parte A · Atendimento')
                ->assertSee('Parte B · Mecânico')
                ->assertSee('Óleo do motor');
        }
    }

    public function test_foto_pela_camera_com_hash_entra_e_sem_hash_e_recusada(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $this->actingAs(User::factory()->atendimento()->create());
        $file = UploadedFile::fake()->image('foto.jpg', 1200, 900);
        $hash = hash_file('sha256', $file->getRealPath());

        Livewire::test('pages::auth.orders.inspection', ['order' => $order])
            ->set('upload', $file)
            ->call('savePhoto', 'entrada:dianteira_esquerda', $hash, now()->getTimestampMs())
            ->assertDispatched('order-updated')
            ->set('upload', UploadedFile::fake()->image('outra.jpg', 800, 600))
            ->call('savePhoto', 'entrada:painel', null, null)
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(['dianteira_esquerda'], Media::query()->pluck('position')->all());
    }

    public function test_refazer_foto_guarda_a_anterior(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $this->actingAs(User::factory()->atendimento()->create());
        $page = Livewire::test('pages::auth.orders.inspection', ['order' => $order]);

        foreach ([1, 2] as $_) {
            $file = UploadedFile::fake()->image('foto.jpg', 1000, 750);
            $page->set('upload', $file)->call('savePhoto', 'entrada:painel', hash_file('sha256', $file->getRealPath()), null);
        }

        $this->assertSame(2, Media::query()->count());
        $this->assertSame(1, $order->media()->count(), 'só a nova fica em uso');
        $this->assertNotNull(Media::query()->orderBy('id')->first()->replaced_at);
    }

    public function test_avaria_pela_tela_com_marcas(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $this->actingAs(User::factory()->atendimento()->create());
        $file = UploadedFile::fake()->image('avaria.jpg', 1000, 750);

        $page = Livewire::test('pages::auth.orders.inspection', ['order' => $order])
            ->set('damageLocation', 'Capô')
            ->set('upload', $file)
            ->call('savePhoto', 'avaria:Capô', hash_file('sha256', $file->getRealPath()), null);

        $damage = Damage::query()->sole();
        $page->assertSet('markingDamage', $damage->id)
            ->call('saveMarks', $damage->id, [['x' => 0.3, 'y' => 0.4, 'type' => 'Amassado', 'desc' => '']])
            ->assertSet('markingDamage', null);

        $this->assertSame('Amassado', $damage->fresh()->markList()[0]['type']);
    }

    public function test_mecanico_responde_a_parte_b_e_nao_mexe_na_a(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $this->actingAs(User::factory()->mecanico()->create());
        $page = Livewire::test('pages::auth.orders.inspection', ['order' => $order]);
        $battery = $order->checklistItems()->where('label', 'Bateria')->sole();

        $page->call('answer', $battery->id, 'troca')->call('markRemainingOk');
        $this->assertSame('troca', $battery->fresh()->state);

        $page->call('setFuel', '1/2')->assertDispatched('toast', type: 'error');
        $this->assertNull($order->fresh()->fuel_in);
    }

    public function test_foto_recusada_do_item_nao_tira_a_anterior_e_pdf_preparado_fica_travado(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $this->actingAs(User::factory()->mecanico()->create());
        $page = Livewire::test('pages::auth.orders.inspection', ['order' => $order]);
        $battery = $order->checklistItems()->where('label', 'Bateria')->sole();
        $page->call('answer', $battery->id, 'troca');
        $file = UploadedFile::fake()->image('bateria.jpg', 800, 600);
        $page->set('upload', $file)->call('savePhoto', 'item:'.$battery->id, hash_file('sha256', $file->getRealPath()), null);
        $first = $battery->fresh()->media_id;
        $this->assertNotNull($first);

        // Em outra aba a bateria voltou a "em funcionamento": a foto nova é recusada e a anterior continua valendo.
        $battery->update(['state' => 'ok']);
        $other = UploadedFile::fake()->image('outra.jpg', 800, 600);
        $page->set('upload', $other)->call('savePhoto', 'item:'.$battery->id, hash_file('sha256', $other->getRealPath()), null)
            ->assertDispatched('toast', type: 'error');
        $this->assertNull(Media::query()->findOrFail($first)->replaced_at);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('preparedPath', $order->storageDir().'/vistoria-entrada-20260101-000000.pdf');
    }

    public function test_campos_da_parte_a_gravam_na_hora(): void
    {
        $order = $this->order(Stage::EmVistoria);
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.orders.inspection', ['order' => $order])
            ->set('km', '45.300')
            ->call('setFuel', '3/4')
            ->call('quick', 'dashboard_lights', 'Nenhuma')
            ->call('setFlag', 'has_jack', false)
            ->call('setApprover', 'outro')
            ->set('approverName', 'João (pai)')
            ->set('approverWhatsapp', '55 99777-6655');

        $order = $order->fresh();
        $this->assertSame([45300, '3/4', 'Nenhuma', false], [$order->km_in, $order->fuel_in, $order->dashboard_lights, $order->has_jack]);
        $this->assertSame(['outro', 'João (pai)', '55997776655'], [$order->approver_kind, $order->approver_name, $order->approver_whatsapp]);
    }
}
