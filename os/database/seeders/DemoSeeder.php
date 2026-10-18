<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Dados de exemplo para testar sem cadastrar nada (nunca em produção):
 *     php artisan db:seed --class=DemoSeeder
 * Senha de todos: multmec123 (e-mails @exemplo.test).
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'multmec123';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder não roda em produção (cria usuários com senha conhecida).');
        }

        $this->call(UnitSeeder::class);

        $team = [
            ['Gabriel Cezimbra', 'Gabriel C.', 'gabriel@exemplo.test', Role::Gestor],
            ['Matheus Schott', 'Matheus', 'matheus@exemplo.test', Role::Gestor],
            ['Giovana', 'Giovana', 'giovana@exemplo.test', Role::Atendimento],
            ['Robson', 'Robson', 'robson@exemplo.test', Role::Mecanico],
            ['Gabriel (mecânico)', 'Gabriel M.', 'gabriel.mecanico@exemplo.test', Role::Mecanico],
        ];

        foreach ($team as [$name, $short, $email, $role]) {
            User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'short_name' => $short,
                'role' => $role,
                'active' => true,
                'must_change_password' => false,
                'password' => Hash::make(self::PASSWORD),
            ]);
        }
    }
}
