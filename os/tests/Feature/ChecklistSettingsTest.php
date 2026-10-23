<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\Execution\FinalCheck;
use App\Services\Inspections\TechnicalChecklist;
use App\Services\Settings\Checklists;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ChecklistSettingsTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    public function test_gestor_ajusta_os_itens_e_vale_para_as_proximas_os(): void
    {
        $gestor = User::factory()->gestor()->create();
        $checklists = app(Checklists::class);
        $old = $this->order(Stage::EmVistoria);
        app(TechnicalChecklist::class)->itemsFor($old);

        $added = $checklists->add('tecnico', ['section' => 'Freios', 'label' => 'Pastilhas dianteiras', 'is_fluid' => false], $gestor);
        $first = $checklists->items('tecnico')->first();
        $checklists->toggle($first, $gestor);
        $checklists->move($added, -1, $gestor);

        $new = $this->order(Stage::EmVistoria);
        $labels = app(TechnicalChecklist::class)->itemsFor($new)->pluck('label');
        $this->assertContains('Pastilhas dianteiras', $labels);
        $this->assertNotContains($first->label, $labels);
        $this->assertContains($first->label, $old->checklistItems()->pluck('label'), 'OS antiga mantém a cópia');

        $item = $checklists->items('expresso')->first();
        $this->assertTrue($item->needs_review);
        $this->assertFalse($checklists->update($item, ['label' => 'Tudo o que foi aprovado foi feito'], $gestor)->needs_review);

        $this->expectException(DomainException::class);
        $checklists->add('completo', ['section' => 'X', 'label' => 'Y item'], User::factory()->atendimento()->create());
    }

    public function test_tela_de_checklists(): void
    {
        $this->actingAs(User::factory()->gestor()->create());
        FinalCheck::seedDefaults();

        Livewire::test('pages::auth.settings.checklists')
            ->set('list', 'completo')
            ->assertSee('Calotas e tampas no lugar')
            ->set('section', 'Comunicação')
            ->set('label', 'Avisar o cliente pelo WhatsApp que o carro está pronto')
            ->call('save')
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Avisar o cliente pelo WhatsApp');

        $this->assertTrue(ChecklistItem::query()->where('template', 'completo')->where('label', 'like', 'Avisar o cliente%')->exists());
        $this->actingAs(User::factory()->atendimento()->create())->get(route('settings.checklists'))->assertForbidden();
    }
}
