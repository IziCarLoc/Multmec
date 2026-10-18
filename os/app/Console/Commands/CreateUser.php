<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Services\Users\UserManager;
use DomainException;
use Illuminate\Console\Command;

/**
 * Cria um usuário com senha provisória (mostrada uma vez). É o jeito de criar o primeiro gestor no
 * servidor; os outros o gestor cria em Ajustes > Usuários.
 *
 *     php artisan usuarios:criar gabriel@exemplo.com --nome="Gabriel Cezimbra" --curto="Gabriel C." --perfil=gestor
 */
class CreateUser extends Command
{
    protected $signature = 'usuarios:criar
        {email : E-mail de acesso}
        {--nome= : Nome completo}
        {--curto= : Nome curto que aparece nas listas (único)}
        {--perfil=gestor : gestor, atendimento ou mecanico}';

    protected $description = 'Cria um usuário com senha provisória (a pessoa troca no primeiro acesso)';

    public function handle(UserManager $users): int
    {
        $role = Role::tryFrom((string) $this->option('perfil'));

        if (! $role) {
            $this->error('Perfil inválido. Use gestor, atendimento ou mecanico.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('nome') ?: $this->ask('Nome completo'));
        $short = (string) ($this->option('curto') ?: $this->ask('Nome curto (aparece nas listas)', strtok($name, ' ') ?: $name));

        try {
            ['user' => $user, 'password' => $password] = $users->create($name, $short, (string) $this->argument('email'), $role);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Usuário criado: {$user->short_name} ({$role->label()}), {$user->email}.");
        $this->line("Senha provisória: <comment>{$password}</comment> (mostrada só agora; no primeiro acesso a pessoa cria a própria).");

        return self::SUCCESS;
    }
}
