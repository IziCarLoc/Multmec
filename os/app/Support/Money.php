<?php

namespace App\Support;

/**
 * Dinheiro na tela: centavos (inteiro) para "R$ 1.234,56" — negativo como "-R$ 1.234,56". Uma regra
 * só para telas, PDFs, mensagens e serviços (nas views ele chega como `$money`, compartilhado pelo
 * AppServiceProvider).
 */
final class Money
{
    public static function brl(int|float|string|null $cents): string
    {
        $cents = (int) round((float) $cents);

        return ($cents < 0 ? '-' : '').'R$ '.number_format(abs($cents) / 100, 2, ',', '.');
    }

    /**
     * Valor digitado como se escreve → centavos: "100" = 100,00; "100,5" = 100,50; "1.234,56" = 1234,56;
     * "1.234" = 1234,00 (ponto de milhar); "12.5" = 12,50 (ponto decimal com 1–2 dígitos). Vazio = nulo.
     * Mesma regra do `parseMoneyBr` do layout (campos de dinheiro das telas).
     */
    public static function parseBr(?string $text): ?int
    {
        $text = preg_replace('/[^\d.,-]/u', '', str_replace(['R$', "\u{A0}", ' '], '', (string) $text)) ?? '';

        if ($text === '' || $text === '-' || preg_match('/\d/', $text) !== 1) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');

        if (str_contains($text, ',')) {
            [$int, $dec] = explode(',', str_replace('.', '', $text), 2) + [1 => ''];
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $text) === 1) {
            [$int, $dec] = [str_replace('.', '', $text), ''];
        } elseif (preg_match('/^(\d*)\.(\d{1,2})$/', $text, $m) === 1) {
            [$int, $dec] = [$m[1], $m[2]];
        } else {
            [$int, $dec] = [str_replace('.', '', $text), ''];
        }

        $int = preg_replace('/\D/', '', $int) ?: '0';
        $dec = substr(str_pad(preg_replace('/\D/', '', $dec) ?? '', 2, '0'), 0, 2);
        $cents = (int) $int * 100 + (int) $dec;

        return $negative ? -$cents : $cents;
    }
}
