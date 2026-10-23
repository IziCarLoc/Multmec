<?php

namespace App\Support\Purchases;

/**
 * Chave de acesso da NF-e (44 posições): UF, ano/mês, CNPJ do emitente, modelo, série, número, forma de emissão,
 * código e dígito verificador. Com CNPJ alfanumérico (NT Conjunta 2025.001) a máscara é [0-9]{6}[A-Z0-9]{12}[0-9]{26}
 * e o DV usa a mesma regra do CNPJ: cada caractere vale o código ASCII menos 48.
 *
 * Porte de `lerChaveNfe`/`dvChaveNfe` (`app/src/documentos.js`).
 */
final class AccessKey
{
    /** Dígito verificador (43 posições → 1): módulo 11, pesos 2 a 9 da direita para a esquerda; resto 0 ou 1 → 0. */
    public static function checkDigit(string $base43): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($base43) - 1; $i >= 0; $i--) {
            $sum += (ord($base43[$i]) - 48) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }

    /**
     * @return array{valid: bool, reason: ?string, key?: string, uf?: string, yymm?: string, issuer_cnpj?: string,
     *               model?: string, series?: string, number?: string}
     */
    public static function read(?string $text): array
    {
        $key = mb_strtoupper((string) preg_replace('/[\s.\-]/', '', (string) $text));

        if (preg_match('/^[0-9]{6}[0-9A-Z]{12}[0-9]{26}$/', $key) !== 1) {
            return ['valid' => false, 'reason' => 'A chave de acesso precisa ter 44 caracteres (só números; letras apenas no CNPJ).'];
        }

        $valid = (string) self::checkDigit(substr($key, 0, 43)) === $key[43];

        return [
            'valid' => $valid,
            'reason' => $valid ? null : 'O dígito verificador da chave não confere: a chave está errada ou foi adulterada.',
            'key' => $key,
            'uf' => substr($key, 0, 2),
            'yymm' => substr($key, 2, 4),
            'issuer_cnpj' => substr($key, 6, 14),
            'model' => substr($key, 20, 2),
            'series' => (string) (int) substr($key, 22, 3),
            'number' => (string) (int) substr($key, 25, 9),
        ];
    }

    /** Monta uma chave válida (para os testes e para conferir o que foi digitado). */
    public static function build(string $uf, string $yymm, string $cnpj, string $model, int $series, int $number, string $emission = '1', string $code = '12345678'): string
    {
        $base = $uf.$yymm.$cnpj.$model.str_pad((string) $series, 3, '0', STR_PAD_LEFT).str_pad((string) $number, 9, '0', STR_PAD_LEFT).$emission.$code;

        return $base.self::checkDigit($base);
    }

    /** "4326 1012 3456 ..." (blocos de 4, como a DANFE imprime). */
    public static function format(?string $key): string
    {
        return trim(chunk_split((string) $key, 4, ' '));
    }
}
