<?php

namespace App\Support;

/**
 * CPF/CNPJ: no banco ficam só os dígitos; a máscara é só para mostrar.
 */
final class Document
{
    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
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

    /** CNPJ numérico com os dígitos verificadores certos (o formato com letras ainda não é aceito). */
    public static function isValidCnpj(?string $value): bool
    {
        $digits = self::digits($value);

        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        foreach ([12, 13] as $length) {
            $weight = $length - 7;
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $check = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);

            if ($check !== (int) $digits[$length]) {
                return false;
            }
        }

        return true;
    }

    /** 11 dígitos = CPF válido, 14 = CNPJ válido; qualquer outro tamanho não vale. */
    public static function isValid(?string $value): bool
    {
        return match (strlen(self::digits($value))) {
            11 => self::isValidCpf($value),
            14 => self::isValidCnpj($value),
            default => false,
        };
    }

    /** "CPF", "CNPJ" ou "CPF/CNPJ" (quando o tamanho não diz). */
    public static function typeLabel(?string $value): string
    {
        return match (strlen(self::digits($value))) {
            11 => 'CPF',
            14 => 'CNPJ',
            default => 'CPF/CNPJ',
        };
    }

    /**
     * 11 dígitos viram CPF (000.000.000-00), 14 viram CNPJ (00.000.000/0000-00); o resto sai como está.
     */
    public static function format(?string $value): string
    {
        $digits = self::digits($value);

        return match (strlen($digits)) {
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits),
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits),
            default => (string) $value,
        };
    }
}
