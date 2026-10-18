<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_vai_para_a_tela_de_acesso(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Manter conectado neste aparelho');
    }

    public function test_entra_com_email_e_senha_e_fica_conectado(): void
    {
        $user = User::factory()->mecanico()->create(['email' => 'robson@exemplo.test']);

        Livewire::test('pages::guest.login')
            ->set('email', 'Robson@Exemplo.test ')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token, 'manter conectado vem marcado: o celular não pede senha de novo');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_senha_errada_nao_entra(): void
    {
        User::factory()->create(['email' => 'robson@exemplo.test']);

        Livewire::test('pages::guest.login')
            ->set('email', 'robson@exemplo.test')
            ->set('password', 'errada')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_desativado_nao_entra(): void
    {
        User::factory()->inactive()->create(['email' => 'saiu@exemplo.test']);

        Livewire::test('pages::guest.login')
            ->set('email', 'saiu@exemplo.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_desativado_com_sessao_aberta_sai_na_hora(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->update(['active' => false]);

        $this->actingAs($user->fresh())->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_cinco_tentativas_erradas_bloqueiam_por_alguns_minutos(): void
    {
        User::factory()->create(['email' => 'robson@exemplo.test']);
        $component = Livewire::test('pages::guest.login')->set('email', 'robson@exemplo.test')->set('password', 'errada');

        for ($i = 0; $i < 5; $i++) {
            $component->call('login');
        }

        $component->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_senha_provisoria_obriga_a_criar_a_propria_senha(): void
    {
        $user = User::factory()->withTemporaryPassword()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('profile'));
        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('crie a sua senha');

        Livewire::actingAs($user)->test('pages::auth.profile')
            ->set('current', 'password')
            ->set('password', 'nova-senha-123')
            ->set('password_confirmation', 'nova-senha-123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('nova-senha-123', $user->password));
        // Sessão nova (no navegador o próprio pedido do Livewire grava a senha nova na sessão de quem trocou).
        $this->flushSession();
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_troca_de_senha_confere_a_senha_atual(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::auth.profile')
            ->set('current', 'errada')
            ->set('password', 'nova-senha-123')
            ->set('password_confirmation', 'nova-senha-123')
            ->call('save')
            ->assertHasErrors('current');
    }

    public function test_sair_encerra_a_sessao(): void
    {
        $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
