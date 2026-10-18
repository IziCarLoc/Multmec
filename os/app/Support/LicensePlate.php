<?php

namespace App\Support;

/**
 * Normalização de placas para comparação entre fontes (Gestão, contratos,
 * Detran, planilhas). Remove espaços/traços, coloca em maiúsculas e converte o
 * padrão antigo (ABC1234) para o Mercosul (ABC1C34), que é como o Detran exibe.
 */
final class LicensePlate
{
    /**
     * A placa como está no cadastro, só sem espaços e traços e em maiúsculas,
     * sem converter o padrão antigo para o Mercosul. É a que se consulta no
     * Detran: carro que ainda tem placa antiga não existe com a placa Mercosul equivalente.
     */
    public static function clean(?string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $plate));
    }

    public static function normalize(?string $plate): string
    {
        $plate = self::clean($plate);

        if (preg_match('/^[A-Z]{3}\d{4}$/', $plate) === 1) {
            $plate = substr($plate, 0, 4).chr(ord('A') + (int) $plate[4]).substr($plate, 5);
        }

        return $plate;
    }

    public static function same(?string $first, ?string $second): bool
    {
        $normalized = self::normalize($first);

        return $normalized !== '' && $normalized === self::normalize($second);
    }
}
