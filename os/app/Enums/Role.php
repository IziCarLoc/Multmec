<?php

namespace App\Enums;

/**
 * Perfis fixos do sistema. Gestor faz tudo; Atendimento recebe o carro, cota, orça e entrega;
 * Mecânico faz a vistoria técnica, o diagnóstico, a execução e confere o serviço dos outros.
 */
enum Role: string
{
    case Gestor = 'gestor';
    case Atendimento = 'atendimento';
    case Mecanico = 'mecanico';

    public function label(): string
    {
        return match ($this) {
            self::Gestor => 'Gestor',
            self::Atendimento => 'Atendimento',
            self::Mecanico => 'Mecânico',
        };
    }

    /** @return array<string, string> valor => rótulo, na ordem das telas */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $role) => [$role->value => $role->label()])->all();
    }
}
