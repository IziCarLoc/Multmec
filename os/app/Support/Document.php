<?php

namespace App\Support;

/**
 * CPF/CNPJ: no banco ficam só os dígitos (e as letras do CNPJ alfanumérico); a máscara é só para mostrar.
 * CNPJ alfanumérico (Receita, em vigor desde julho de 2026): 12 posições de letras ou números e 2 dígitos
 * verificadores; no cálculo cada caractere vale o código ASCII menos 48 (números mantêm o valor, A = 17).
 */
final class Document
{
    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }

    /** Só letras maiúsculas e números (o CNPJ alfanumérico mantém as letras). */
    public static function normalizeCnpj(?string $value): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', mb_strtoupper((string) $value));
    }

    /**
     * O que se grava: CNPJ alfanumérico válido mantém as letras; o resto vira só dígitos (CPF, CNPJ numérico,
     * telefone digitado por engano...). Vazio continua vazio.
     */
    public static function clean(?string $value): string
    {
        $cnpj = self::normalizeCnpj($value);

        if (preg_match('/[A-Z]/', $cnpj) === 1 && self::isValidCnpj($cnpj)) {
            return $cnpj;
        }

        return self::digits($value);
    }

    /** CPF com os dígitos verificadores certos (sequências repetidas, como 111.111.111-11, não valem). */
    public static function isValidCpf(?string $value): bool
    {
        $digits = self::digits($value);

        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        foreach ([9, 10] as $length) {
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * ($length + 1 - $i);
            }

            $check = ($sum * 10) % 11 % 10;

            if ($check !== (int) $digits[$length]) {
                return false;
            }
        }

        return true;
    }

    /**
     * CNPJ numérico ou alfanumérico com os dígitos verificadores certos. A máscara não importa
     * ("11.222.333/0001-81" = "11222333000181"); sequência repetida (00000000000000) não vale.
     */
    public static function isValidCnpj(?string $value): bool
    {
        $cnpj = self::normalizeCnpj($value);

        if (preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj) !== 1 || preg_match('/^(.)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $first = self::cnpjCheckDigit(substr($cnpj, 0, 12));
        $second = self::cnpjCheckDigit(substr($cnpj, 0, 12).$first);

        return substr($cnpj, 12) === $first.$second;
    }

    /** 11 dígitos = CPF válido, 14 posições = CNPJ válido (numérico ou alfanumérico); qualquer outro tamanho não vale. */
    public static function isValid(?string $value): bool
    {
        if (preg_match('/[A-Za-z]/', (string) $value) === 1 && strlen(self::normalizeCnpj($value)) === 14) {
            return self::isValidCnpj($value);
        }

        return match (strlen(self::digits($value))) {
            11 => self::isValidCpf($value),
            14 => self::isValidCnpj($value),
            default => false,
        };
    }

    /** "CPF", "CNPJ" ou "CPF/CNPJ" (quando o tamanho não diz). */
    public static function typeLabel(?string $value): string
    {
        if (strlen(self::clean($value)) === 14) {
            return 'CNPJ';
        }

        return match (strlen(self::digits($value))) {
            11 => 'CPF',
            default => 'CPF/CNPJ',
        };
    }

    /**
     * 11 dígitos viram CPF (000.000.000-00), 14 posições viram CNPJ (00.000.000/0000-00, também com letras);
     * o resto sai como está.
     */
    public static function format(?string $value): string
    {
        $clean = self::clean($value);

        return match (strlen($clean)) {
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $clean),
            14 => preg_replace('/([0-9A-Z]{2})([0-9A-Z]{3})([0-9A-Z]{3})([0-9A-Z]{4})(\d{2})/', '$1.$2.$3/$4-$5', $clean),
            default => (string) $value,
        };
    }

    /** Raiz do CNPJ (8 primeiras posições): matriz e filiais têm a mesma. */
    public static function cnpjRoot(?string $value): string
    {
        return substr(self::normalizeCnpj($value), 0, 8);
    }

    /** Dígito verificador do CNPJ: módulo 11, pesos 2 a 9 da direita para a esquerda, cada caractere = ASCII − 48. */
    private static function cnpjCheckDigit(string $base): string
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($base) - 1; $i >= 0; $i--) {
            $sum += (ord($base[$i]) - 48) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }
}
