<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Liberdade (padrão, numeração digitada do sistema atual durante o piloto) e Matriz (inativa até o
 * Matheus decidir usar). Rodar de novo não duplica nem apaga o que foi mudado em Ajustes.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        Unit::query()->firstOrCreate(['code' => 'LIB'], [
            'name' => 'Liberdade',
            'trade_name' => 'MULTMEC OFICINA MECANICA',
            'legal_name' => 'SCHOTT, ROMEIRO E CEZIMBRA OFICINA MECANICA LTDA',
            'cnpj' => '63796929000199',
            'address' => 'Av. Liberdade, 207 B - Passo d\'Areia',
            'city' => 'Santa Maria/RS',
            'phone' => '(55) 99197-4167',
            'numbering_mode' => Unit::NUMBERING_MANUAL,
            'is_default' => true,
            'active' => true,
        ]);

        Unit::query()->firstOrCreate(['code' => 'MAT'], [
            'name' => 'Matriz',
            'trade_name' => 'MULTMEC',
            'numbering_mode' => Unit::NUMBERING_MANUAL,
            'is_default' => false,
            'active' => false,
        ]);
    }
}
