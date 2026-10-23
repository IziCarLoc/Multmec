<?php

namespace App\Support\Purchases;

use Carbon\CarbonImmutable;

/**
 * Leitura e validação do boleto bancário (linha digitável de 47 dígitos, código de barras de 44) e do boleto
 * de arrecadação/convênio (48 dígitos). Só usa o que a linha traz: banco, valor e vencimento. O que NÃO vem na
 * linha (CNPJ e nome do beneficiário, número da nota) se confere no app do banco, no DDA ou no papel.
 *
 * Porte de `app/src/boleto.js` (sistema em Node). Os dígitos verificadores pegam erro de digitação, não fraude:
 * um golpista gera um boleto com os dígitos certos.
 */
final class BoletoCode
{
    /** 07/10/1997 = fator 0. */
    private const FACTOR_BASE = '1997-10-07';

    private const BANKS = [
        '001' => 'Banco do Brasil', '033' => 'Santander', '041' => 'Banrisul', '070' => 'BRB', '077' => 'Inter', '104' => 'Caixa',
        '136' => 'Unicred', '237' => 'Bradesco', '260' => 'Nubank', '290' => 'PagBank', '336' => 'C6', '341' => 'Itaú', '348' => 'XP',
        '364' => 'Gerencianet', '380' => 'PicPay', '403' => 'Cora', '748' => 'Sicredi', '756' => 'Sicoob', '085' => 'Ailos',
        '655' => 'Votorantim', '422' => 'Safra',
    ];

    /** Módulo 10: pesos 2 e 1 alternados da direita para a esquerda; produto ≥ 10 soma os algarismos. */
    public static function mod10(string $digits): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $product = (int) $digits[$i] * $weight;
            $sum += $product > 9 ? intdiv($product, 10) + $product % 10 : $product;
            $weight = $weight === 2 ? 1 : 2;
        }

        return (10 - $sum % 10) % 10;
    }

    /** DV geral do código de barras bancário: módulo 11, pesos 2 a 9; resultado 0, 10 ou 11 vira 1. */
    public static function bankBarcodeDigit(string $base43): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($base43) - 1; $i >= 0; $i--) {
            $sum += (int) $base43[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $digit = 11 - $sum % 11;

        return in_array($digit, [0, 10, 11], true) ? 1 : $digit;
    }

    /** Módulo 11 dos boletos de convênio (identificador de valor 8 ou 9): resto 0 ou 1 → 0; resto 10 → 1. */
    public static function mod11Convenio(string $digits): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $rest = $sum % 11;

        return match (true) {
            $rest === 0, $rest === 1 => 0,
            $rest === 10 => 1,
            default => 11 - $rest,
        };
    }

    /**
     * Fator de vencimento → data. O fator tem 4 dígitos e se repete a cada 9.000 dias (em 22/02/2025 voltou a
     * 1000), então a mesma sequência serve para duas datas. O modelo dos bancos resolve com uma janela em torno de
     * "hoje": até 3.000 dias para trás (3.001 de tolerância) e 5.500 para frente. Fator fora da janela ou abaixo
     * de 1000 não tem data confiável: devolve null.
     */
    public static function factorToDate(string $factor, string $today): ?string
    {
        if (preg_match('/^\d{4}$/', $factor) !== 1 || (int) $factor < 1000) {
            return null;
        }

        $base = CarbonImmutable::parse(self::FACTOR_BASE, 'UTC');
        $now = CarbonImmutable::parse($today, 'UTC');

        for ($cycle = 0; $cycle <= 3; $cycle++) {
            $date = $base->addDays((int) $factor + $cycle * 9000);
            $delta = (int) $now->diffInDays($date, false);

            if ($delta >= -3001 && $delta <= 5500) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /** Data → fator (para montar casos de teste). Datas a partir de 22/02/2025 usam o ciclo novo. */
    public static function dateToFactor(string $date): string
    {
        $days = (int) CarbonImmutable::parse(self::FACTOR_BASE, 'UTC')->diffInDays(CarbonImmutable::parse($date, 'UTC'), false);

        return str_pad((string) ((($days - 1000) % 9000 + 9000) % 9000 + 1000), 4, '0', STR_PAD_LEFT);
    }

    public static function bankName(?string $code): ?string
    {
        return $code === null ? null : (self::BANKS[$code] ?? null);
    }

    /**
     * Interpreta o que a pessoa colou ou digitou. `ok` só é true quando todos os dígitos verificadores conferem;
     * `errors` impedem o cadastro pela linha; `warnings` são para conferir.
     *
     * @return array{ok: bool, type: ?string, input: string, barcode: ?string, line: ?string, bank: ?string, bank_name: ?string,
     *               amount_cents: ?int, due_date: ?string, factor: ?string, errors: list<string>, warnings: list<string>}
     */
    public static function read(?string $text, string $today): array
    {
        $digits = (string) preg_replace('/\D/', '', (string) $text);
        $r = [
            'ok' => false, 'type' => null, 'input' => $digits, 'barcode' => null, 'line' => null, 'bank' => null, 'bank_name' => null,
            'amount_cents' => null, 'due_date' => null, 'factor' => null, 'errors' => [], 'warnings' => [],
        ];
        $length = strlen($digits);

        if (! in_array($length, [44, 47, 48], true)) {
            $r['errors'][] = "Número de dígitos inválido ({$length}). A linha digitável tem 47 dígitos (48 em boleto de convênio) e o código de barras tem 44.";

            return $r;
        }

        if ($length === 48 || ($length === 44 && $digits[0] === '8')) {
            return self::readConvenio($digits, $r);
        }

        if ($length === 47) {
            $r['line'] = $digits;
            foreach ([[0, 9, 9, '1º'], [10, 10, 20, '2º'], [21, 10, 31, '3º']] as [$start, $size, $dvAt, $label]) {
                if (self::mod10(substr($digits, $start, $size)) !== (int) $digits[$dvAt]) {
                    $r['errors'][] = "Dígito verificador do {$label} campo não confere: algum número da linha está errado.";
                }
            }
            $barcode = self::lineToBarcode($digits);
        } else {
            $barcode = $digits;
            $r['line'] = self::barcodeToLine($digits);
        }

        $r['type'] = 'banco';
        $r['barcode'] = $barcode;

        if (self::bankBarcodeDigit(substr($barcode, 0, 4).substr($barcode, 5)) !== (int) $barcode[4]) {
            $r['errors'][] = 'Dígito verificador geral não confere: a linha foi digitada errada ou foi adulterada.';
        }

        $r['bank'] = substr($barcode, 0, 3);
        $r['bank_name'] = self::bankName($r['bank']);

        if ($barcode[3] !== '9') {
            $r['warnings'][] = 'Moeda diferente de real (9).';
        }

        $r['factor'] = substr($barcode, 5, 4);
        $r['amount_cents'] = (int) substr($barcode, 9, 10);

        if ($r['factor'] === '0000') {
            $r['warnings'][] = 'O código deste boleto não traz o vencimento (fator 0000). Boleto de fornecedor deve ter data: confira o papel e confirme com o fornecedor.';
        } elseif ((int) $r['factor'] < 1000) {
            $r['errors'][] = "Fator de vencimento inválido ({$r['factor']}): nenhum banco emite fator abaixo de 1000.";
        } else {
            $r['due_date'] = self::factorToDate($r['factor'], $today);

            if ($r['due_date'] === null) {
                $r['errors'][] = "O vencimento gravado no código (fator {$r['factor']}) cai fora da faixa que os bancos aceitam. Boleto estranho: não pague antes de confirmar com o fornecedor e com o banco.";
            } else {
                $days = (int) CarbonImmutable::parse($today, 'UTC')->diffInDays(CarbonImmutable::parse($r['due_date'], 'UTC'), false);

                if ($days > 400 || $days < -400) {
                    $r['warnings'][] = 'Vencimento muito distante de hoje ('.CarbonImmutable::parse($r['due_date'])->format('d/m/Y').'): confira.';
                }
            }
        }

        if ($r['amount_cents'] === 0) {
            $r['warnings'][] = 'Boleto sem valor definido (qualquer valor): informe o valor combinado.';
        }

        $r['ok'] = $r['errors'] === [];

        return $r;
    }

    /**
     * Banco + campo livre (posições 1-3 e 20-44 do código de barras): identifica o título mesmo que o valor ou o
     * vencimento tenham sido mexidos (o caso típico do boleto adulterado).
     */
    public static function titleKey(?string $barcode): ?string
    {
        return $barcode !== null && strlen($barcode) === 44 ? substr($barcode, 0, 3).'|'.substr($barcode, 19) : null;
    }

    /** Monta uma linha digitável bancária válida (47 dígitos). Serve para os testes. */
    public static function buildLine(string $bank, int $amountCents, string $dueDate, int|string $freeField = '1'): string
    {
        $factor = self::dateToFactor($dueDate);
        $amount = str_pad((string) $amountCents, 10, '0', STR_PAD_LEFT);
        $free = substr(str_pad((string) $freeField, 25, '0', STR_PAD_LEFT), -25);
        $barcode = $bank.'9'.'0'.$factor.$amount.$free;
        $dv = self::bankBarcodeDigit(substr($barcode, 0, 4).substr($barcode, 5));

        return self::barcodeToLine(substr($barcode, 0, 4).$dv.substr($barcode, 5));
    }

    public static function lineToBarcode(string $line): string
    {
        return substr($line, 0, 4).$line[32].substr($line, 33, 14).substr($line, 4, 5).substr($line, 10, 10).substr($line, 21, 10);
    }

    public static function barcodeToLine(string $barcode): string
    {
        $field1 = substr($barcode, 0, 4).substr($barcode, 19, 5);
        $field2 = substr($barcode, 24, 10);
        $field3 = substr($barcode, 34, 10);

        return $field1.self::mod10($field1).$field2.self::mod10($field2).$field3.self::mod10($field3).$barcode[4].substr($barcode, 5, 14);
    }

    /** Linha com a máscara impressa no boleto ("00190.50095 40144.816069 06809.350314 3 37370000000100"). */
    public static function formatLine(?string $line): string
    {
        $line = (string) $line;

        if (strlen($line) !== 47) {
            return $line;
        }

        return substr($line, 0, 5).'.'.substr($line, 5, 5).' '.substr($line, 10, 5).'.'.substr($line, 15, 6).' '
            .substr($line, 21, 5).'.'.substr($line, 26, 6).' '.$line[32].' '.substr($line, 33);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private static function readConvenio(string $digits, array $r): array
    {
        $r['type'] = 'convenio';

        if (strlen($digits) === 48) {
            $r['line'] = $digits;
            $blocks = array_map(fn ($i) => substr($digits, $i, 12), [0, 12, 24, 36]);
            $id = $digits[2];

            foreach ($blocks as $i => $block) {
                $dv = $id === '6' || $id === '7' ? self::mod10(substr($block, 0, 11)) : self::mod11Convenio(substr($block, 0, 11));

                if ($dv !== (int) $block[11]) {
                    $r['errors'][] = 'Dígito verificador do bloco '.($i + 1).' não confere: algum número da linha está errado.';
                }
            }

            $barcode = implode('', array_map(fn ($b) => substr($b, 0, 11), $blocks));
        } else {
            $barcode = $digits;
        }

        $r['barcode'] = $barcode;
        $base = substr($barcode, 0, 3).substr($barcode, 4);
        $general = $barcode[2] === '6' || $barcode[2] === '7' ? self::mod10($base) : self::mod11Convenio($base);

        if ($general !== (int) $barcode[3]) {
            $r['errors'][] = 'Dígito verificador geral do código de barras não confere.';
        }

        if ($barcode[2] === '6' || $barcode[2] === '8') {
            $r['amount_cents'] = (int) substr($barcode, 4, 11);
        } else {
            $r['warnings'][] = 'O valor não vem em reais na linha deste boleto de convênio: digite o valor.';
        }

        $r['warnings'][] = 'Boleto de convênio/arrecadação (tributo, água, luz, taxa): o vencimento não vem na linha. Confira o papel.';
        $r['ok'] = $r['errors'] === [];

        return $r;
    }
}
