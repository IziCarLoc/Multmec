<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * O que todo banco precisa (produção também): as unidades. Usuários de produção são criados com
 * `php artisan usuarios:criar` (senha provisória aleatória). Dados de exemplo para testar: DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UnitSeeder::class);
    }
}
