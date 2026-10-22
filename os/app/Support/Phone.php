<?php

namespace App\Support;

/**
 * Telefone/WhatsApp: no banco ficam só os dígitos, com DDD e sem o 55 do Brasil. O link do WhatsApp
 * (wa.me) põe o 55 de volta.
 */
final class Phone
{
    public static function digits(?string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        // Quem digita com o código do país (55 + DDD + número) fica igual a quem digita sem.
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /** DDD + 8 ou 9 dígitos. */
    public static function isValid(?string $value): bool
    {
        return preg_match('/^[1-9]{2}\d{8,9}$/', self::digits($value)) === 1;
    }

    public static function format(?string $value): string
    {
        $digits = self::digits($value);

        return match (strlen($digits)) {
            11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
            10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
            default => $digits,
        };
    }

    /** Link que abre a conversa no WhatsApp, com o texto pronto (o envio é sempre da pessoa). */
    public static function whatsappLink(?string $value, string $text = ''): ?string
    {
        if (! self::isValid($value)) {
            return null;
        }

        return 'https://wa.me/55'.self::digits($value).($text !== '' ? '?text='.rawurlencode($text) : '');
    }
}
