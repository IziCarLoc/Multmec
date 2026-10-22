<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Users\UserManager;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_usuario_com_senha_provisoria_aleatoria(): void
    {
        ['user' => $user, 'password' => $password] = app(UserManager::class)->create('Robson da Silva', 'Robson', 'Robson@Exemplo.test', Role::Mecanico);

        $this->assertSame('robson@exemplo.test', $user->email);
        $this->assertSame(10, strlen($password));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(AuditLog::query()->where('action', 'user.created')->exists());
    }

    public function test_nome_curto_e_email_sao_unicos(): void
    {
        $users = app(UserManager::class);
        $users->create('Gabriel Cezimbra', 'Gabriel', 'gabriel@exemplo.test', Role::Gestor);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('nome curto');
        $users->create('Gabriel Mecânico', 'Gabriel', 'outro@exemplo.test', Role::Mecanico);
    }

    public function test_gestor_nao_tira_o_proprio_acesso(): void
    {
        $gestor = User::factory()->gestor()->create();
        $this->actingAs($gestor);

        $this->expectException(DomainException::class);
        app(UserManager::class)->update($gestor, $gestor->name, $gestor->short_name, $gestor->email, Role::Mecanico, true);
    }

    public function test_sempre_fica_um_gestor_ativo(): void
    {
        $gestor = User::factory()->gestor()->create();
        $this->actingAs(User::factory()->gestor()->inactive()->create());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pelo menos um gestor');
        app(UserManager::class)->update($gestor, $gestor->name, $gestor->short_name, $gestor->email, Role::Gestor, false);
    }

    public function test_nova_senha_pelo_gestor_vale_uma_vez_e_derruba_o_manter_conectado(): void
    {
        $user = User::factory()->create(['remember_token' => 'antigo']);

        $password = app(UserManager::class)->resetPassword($user);

        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame('antigo', $user->remember_token);
    }

    public function test_tela_de_usuarios_cria_e_mostra_a_senha_uma_vez(): void
    {
        $gestor = User::factory()->gestor()->create();

        Livewire::actingAs($gestor)->test('pages::auth.settings.users')
            ->call('create')
            ->set('name', 'Giovana Souza')
            ->set('shortName', 'Giovana')
            ->set('email', 'giovana@exemplo.test')
            ->set('role', 'atendimento')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('shownFor', 'Giovana')
            ->assertSee('Senha provisória')
            ->call('closePassword')
            ->assertSet('shownPassword', null);

        $this->assertSame(Role::Atendimento, User::query()->where('email', 'giovana@exemplo.test')->firstOrFail()->role);
    }

    public function test_comando_cria_o_primeiro_gestor(): void
    {
        $this->artisan('usuarios:criar', ['email' => 'gabriel@exemplo.test', '--nome' => 'Gabriel Cezimbra', '--curto' => 'Gabriel C.', '--perfil' => 'gestor'])
            ->expectsOutputToContain('Senha provisória')
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'gabriel@exemplo.test')->first()->isGestor());
    }

    public function test_contas_iniciais_usam_a_senha_do_env_e_podem_rodar_de_novo(): void
    {
        config(['auth.initial_password' => '']);
        $this->artisan('usuarios:iniciais')->assertFailed();
        $this->assertSame(0, User::query()->count());

        config(['auth.initial_password' => 'senha-de-teste']);
        $this->artisan('usuarios:iniciais')->assertSuccessful();
        $this->artisan('usuarios:iniciais')->assertSuccessful();

        $this->assertSame(5, User::query()->count());
        $this->assertSame(2, User::query()->where('role', Role::Gestor->value)->count());
        $this->assertSame(2, User::query()->where('role', Role::Mecanico->value)->count());
        $mecanico = User::query()->where('email', 'mecanico1@multmec.local')->firstOrFail();
        $this->assertTrue(Hash::check('senha-de-teste', $mecanico->password));
        $this->assertFalse($mecanico->must_change_password);
    }

    public function test_na_publicacao_as_contas_iniciais_so_nascem_com_o_banco_vazio(): void
    {
        config(['auth.initial_password' => 'senha-de-teste']);
        User::factory()->gestor()->create(['email' => 'real@exemplo.test']);

        $this->artisan('usuarios:iniciais', ['--somente-sem-usuarios' => true])->assertSuccessful();

        $this->assertSame(1, User::query()->count(), 'com usuário real cadastrado, não recria as genéricas');
    }
}
