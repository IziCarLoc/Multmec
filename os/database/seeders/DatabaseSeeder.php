<?php

namespace Database\Seeders;

use App\Services\Execution\FinalCheck;
use App\Services\Inspections\TechnicalChecklist;
use Illuminate\Database\Seeder;

/**
 * O que todo banco precisa (produção também): as unidades, o checklist técnico e os modelos da conferência. Roda a cada
 * publicação (deploy/publicar.sh) e não duplica nem desfaz o que foi mudado em Ajustes. Usuários de produção:
 * `php artisan usuarios:iniciais` ou `usuarios:criar`. Dados de exemplo para testar: DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UnitSeeder::class);
        TechnicalChecklist::seedDefaults();
        FinalCheck::seedDefaults();
    }
}
