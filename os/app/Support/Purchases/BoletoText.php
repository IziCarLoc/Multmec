<?php

namespace App\Support\Purchases;

use App\Support\Document;

/**
 * Lê o TEXTO de um boleto (copiado do PDF ou do e-mail) e separa o que o código de barras não traz: CNPJ de quem
 * recebe (beneficiário), CNPJ de quem paga (pagador) e número do documento. Tudo o que sai daqui é sugestão para a
 * pessoa conferir: o sistema nunca decide sozinho que o beneficiário é o fornecedor.
 *
 * Porte de `app/src/boleto_texto.js`.
 */
final class BoletoText
{
    /** CNPJ numérico (com ou sem máscara) ou alfanumérico com máscara. */
    public const CNPJ_PATTERN = '/(?<![0-9A-Za-z])(?:\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}|[0-9A-Za-z]{2}\.[0-9A-Za-z]{3}\.[0-9A-Za-z]{3}\/[0-9A-Za-z]{4}-\d{2})(?![0-9A-Za-z])/';

    private const ROLES = [
        'beneficiario' => '/beneficiario|cedente|favorecido/',
        'pagador' => '/pagador|sacado/',
        'avalista' => '/sacador|avalista/',
    ];

    /**
     * Procura a linha digitável (47), o convênio (48) ou o código de barras (44) dentro do texto. Devolve a leitura
     * do boleto e o trecho do texto que ele ocupa (para não confundir esses números com CNPJ).
     *
     * @return array<string, mixed>|null leitura de BoletoCode::read() mais `span` [início, fim] em bytes
     */
    public static function findLine(?string $text, string $today): ?array
    {
        $text = (string) $text;
        $seen = [];
        $candidates = [];

        preg_match_all('/\d[\d .\-]{40,80}\d/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$found, $offset]) {
            $digits = (string) preg_replace('/\D/', '', $found);
            $span = [$offset, $offset + strlen($found)];

            // 44 e 48 dígitos só valem como sequência inteira (senão uma linha de 47 com erro "vira" outro boleto);
            // a linha de 47 aceita um número solto antes dela (agência, código), desde que a moeda (4º dígito) seja 9.
            if (strlen($digits) === 44 || strlen($digits) === 48) {
                $candidates[] = [$digits, $span];
            }

            for ($start = 0; $start <= min(strlen($digits) - 47, 20); $start++) {
                $candidate = substr($digits, $start, 47);

                if (($start > 0 && $candidate[3] !== '9') || isset($seen[$candidate])) {
                    continue;
                }

                $seen[$candidate] = true;
                $candidates[] = [$candidate, $span];
            }
        }

        foreach ($candidates as [$digits, $span]) {
            $read = BoletoCode::read($digits, $today);

            if ($read['ok']) {
                return $read + ['span' => $span];
            }
        }

        return null;
    }

    /**
     * CNPJs válidos do texto, com o papel indicado pelo rótulo mais próximo antes de cada um.
     *
     * @return list<array{cnpj: string, role: ?string}>
     */
    public static function cnpjs(?string $text): array
    {
        $text = self::plain((string) $text);
        $found = [];

        preg_match_all(self::CNPJ_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$raw, $offset]) {
            $cnpj = Document::normalizeCnpj($raw);

            if (! Document::isValidCnpj($cnpj)) {
                continue;
            }

            $before = substr($text, max(0, $offset - 160), min(160, $offset));
            $best = null;

            foreach (self::ROLES as $role => $pattern) {
                preg_match_all($pattern, $before, $labels, PREG_OFFSET_CAPTURE);

                foreach ($labels[0] as [, $position]) {
                    if ($best === null || $position > $best['position']) {
                        $best = ['role' => $role, 'position' => $position];
                    }
                }
            }

            $found[] = ['cnpj' => $cnpj, 'role' => $best['role'] ?? null];
        }

        return $found;
    }

    /**
     * @return array{slip: ?array<string, mixed>, payee_cnpj: ?string, payer_cnpj: ?string, document_number: ?string,
     *               cnpjs: list<array{cnpj: string, role: ?string}>, warnings: list<string>}
     */
    public static function read(?string $text, string $today): array
    {
        $original = (string) $text;
        $warnings = [];
        $slip = self::findLine($original, $today);

        if (! $slip) {
            $warnings[] = 'Não encontrei uma linha digitável válida no texto colado.';
        }

        // Os 14 últimos dígitos da linha (fator + valor) têm cara de CNPJ: tira o trecho da linha antes de procurar CNPJ.
        $withoutLine = $slip
            ? substr($original, 0, $slip['span'][0]).' '.str_repeat(' ', $slip['span'][1] - $slip['span'][0]).' '.substr($original, $slip['span'][1])
            : $original;
        $cnpjs = self::cnpjs($withoutLine);

        $single = function (string $role) use ($cnpjs, &$warnings): ?string {
            $list = array_values(array_unique(array_column(array_filter($cnpjs, fn ($c) => $c['role'] === $role), 'cnpj')));

            if (count($list) > 1) {
                $warnings[] = 'Achei mais de um CNPJ marcado como '.($role === 'pagador' ? 'pagador' : 'beneficiário').'; digite o correto.';

                return null;
            }

            return $list[0] ?? null;
        };

        $payee = $single('beneficiario');
        $payer = $single('pagador');

        if (! $payee && $cnpjs !== []) {
            $warnings[] = 'Há CNPJ no texto, mas não consegui dizer qual é o do beneficiário. Confira no boleto e digite.';
        }

        if ($payee && $payee === $payer) {
            $warnings[] = 'O CNPJ do beneficiário é igual ao do pagador: confira no boleto.';
        }

        if ($slip) {
            unset($slip['span']);
        }

        return [
            'slip' => $slip,
            'payee_cnpj' => $payee,
            'payer_cnpj' => $payer,
            'document_number' => self::documentNumber($original),
            'cnpjs' => $cnpjs,
            'warnings' => $warnings,
        ];
    }

    /** Texto sem acento e em minúsculas (para achar os rótulos "Beneficiário", "Cálculo do imposto"...). */
    public static function plain(string $text): string
    {
        $text = mb_strtolower($text);

        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    private static function documentNumber(string $text): ?string
    {
        $plain = self::plain($text);

        if (preg_match('/(?:numero|n[o.\x{00ba}\x{00b0}]?|num\.?)\s*(?:do\s*)?documento\s*[:\-]?[ \t]*([a-z0-9][a-z0-9\/.\-]{0,19})(?![a-z0-9])/u', $plain, $m) !== 1) {
            return null;
        }

        $value = $m[1];

        if (preg_match('/\d/', $value) !== 1 || preg_match('/^\d{2}\/\d{2}\/\d{2,4}$/', $value) === 1) {
            return null;
        }

        return mb_strtoupper($value);
    }
}
