<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function telasDoGestor(): array
    {
        return [
            'ajustes' => ['settings'],
            'usuários' => ['settings.users'],
            'saúde do sistema' => ['settings.health'],
            'pdf de teste' => ['settings.health.pdf'],
        ];
    }

    #[DataProvider('telasDoGestor')]
    public function test_so_o_gestor_abre_ajustes(string $route): void
    {
        $this->actingAs(User::factory()->mecanico()->create())->get(route($route))->assertForbidden();
        $this->actingAs(User::factory()->atendimento()->create())->get(route($route))->assertForbidden();
    }

    public function test_gestor_abre_ajustes_e_ve_o_menu(): void
    {
        $this->seed(UnitSeeder::class);
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor)->get(route('settings'))->assertOk()->assertSee('Oficina e numeração')->assertSee('MULTMEC OFICINA MECANICA');
        $this->actingAs($gestor)->get(route('settings.users'))->assertOk();
        $this->actingAs($gestor)->get(route('settings.health'))->assertOk()->assertSee('Tamanho máximo de envio');
        $this->actingAs($gestor)->get('/')->assertOk()->assertSee('Ajustes');
    }

    public function test_mecanico_nao_ve_ajustes_no_menu(): void
    {
        $this->actingAs(User::factory()->mecanico()->create())->get('/')->assertOk()->assertDontSee(route('settings'));
    }

    public function test_gestor_passa_em_todos_os_perfis(): void
    {
        $gestor = User::factory()->gestor()->create();
        $mecanico = User::factory()->mecanico()->create();
        $atendimento = User::factory()->atendimento()->create();

        $this->assertTrue($gestor->can('gestor') && $gestor->can('atendimento') && $gestor->can('oficina'));
        $this->assertTrue($mecanico->can('oficina'));
        $this->assertFalse($mecanico->can('atendimento') || $mecanico->can('gestor'));
        $this->assertTrue($atendimento->can('atendimento'));
        $this->assertFalse($atendimento->can('oficina') || $atendimento->can('gestor'));
    }
}
