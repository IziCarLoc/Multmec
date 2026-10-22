<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Cria as cinco contas genéricas do começo (2 gestores, atendimento, 2 mecânicos) com a senha do .env
 * (SENHA_INICIAL). Cada pessoa usa sempre a mesma conta, que é quem guarda o que ela fez; depois o
 * gestor troca nome e e-mail em Ajustes > Usuários e o histórico acompanha. Pode rodar de novo: conta
 * que já existe (pelo e-mail) fica como está.
 */
class CreateInitialUsers extends Command
{
    protected $signature = 'usuarios:iniciais {--somente-sem-usuarios : só cria se ainda não houver nenhum usuário (publicação automática)}';

    protected $description = 'Cria as contas genéricas (gestor1, gestor2, atendimento, mecanico1, mecanico2) com a SENHA_INICIAL do .env';

    /** @var list<array{0: string, 1: string, 2: string, 3: Role}> e-mail, nome, nome curto, perfil */
    public const ACCOUNTS = [
        ['gestor1@multmec.local', 'Gestor 1', 'Gestor 1', Role::Gestor],
        ['gestor2@multmec.local', 'Gestor 2', 'Gestor 2', Role::Gestor],
        ['atendimento@multmec.local', 'Atendimento', 'Atendimento', Role::Atendimento],
        ['mecanico1@multmec.local', 'Mecânico 1', 'Mecânico 1', Role::Mecanico],
        ['mecanico2@multmec.local', 'Mecânico 2', 'Mecânico 2', Role::Mecanico],
    ];

    public function handle(): int
    {
        // Na publicação: só na primeira vez. Depois que o gestor trocar nomes e e-mails, não recria as genéricas.
        if ($this->option('somente-sem-usuarios') && User::query()->exists()) {
            $this->line('Usuários já existem: nada a criar.');

            return self::SUCCESS;
        }

        $password = (string) config('auth.initial_password');

        if (mb_strlen($password) < 8) {
            $this->error('Defina SENHA_INICIAL no .env (pelo menos 8 caracteres) e rode de novo. Depois de mudar o .env no servidor, rode php artisan optimize.');

            return self::FAILURE;
        }

        foreach (self::ACCOUNTS as [$email, $name, $short, $role]) {
            if (User::query()->where('email', $email)->exists()) {
                $this->line("Já existe: {$email}");

                continue;
            }

            $user = User::create([
                'name' => $name,
                'short_name' => $short,
                'email' => $email,
                'role' => $role,
                'active' => true,
                'must_change_password' => false,
                'password' => Hash::make($password),
            ]);

            AuditLog::record($user, 'user.created', 'conta genérica inicial', ['perfil' => $role->value, 'email' => $email]);
            $this->info("Criada: {$email} ({$role->label()})");
        }

        $this->line('Senha: a SENHA_INICIAL do .env. Troque-a em "Trocar minha senha" antes da primeira OS real.');

        return self::SUCCESS;
    }
}
