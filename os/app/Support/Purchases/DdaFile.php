<?php

namespace App\Support\Purchases;

use App\Support\Document;
use App\Support\Money;
use DomainException;

/**
 * Leitura do arquivo do DDA (Débito Direto Autorizado): a lista que o BANCO tem de todo boleto registrado contra o
 * CNPJ. Formatos: CNAB 240 do DDA (leiaute FEBRABAN, segmentos G, H e Y-03; as posições coincidem nos manuais do
 * Itaú, do Santander e do Banrisul), CSV/planilha de qualquer banco (acha as colunas pelo nome) e texto colado (acha a
 * linha digitável e os CNPJ ao redor). BETA: o CNAB ainda não foi testado com um arquivo real de banco.
 *
 * Porte da parte de leitura de `app/src/dda.js`. Dinheiro em centavos.
 */
final class DdaFile
{
    private const COLUMNS = [
        'code' => '/c[oó]d.*barra|linha.*digit|barra/i',
        'payee' => '/benefici|cedente|favorecido|credor|raz[aã]o|emitente|nome/i',
        'cnpj' => '/cnpj|cpf|inscri/i',
        'amount' => '/valor|total/i',
        'due' => '/venc/i',
        'document' => '/documento|n[uú]m.*doc|n[ºo°]\s*doc|\bnf\b/iu',
    ];

    /**
     * @return array{format: string, titles: list<array<string, mixed>>, warnings: list<string>, file_cnpj: ?string, generated_on: ?string}
     */
    public static function read(?string $content, string $today): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content) ?? '';
        $lines = array_values(array_filter(preg_split('/\r?\n/', $text) ?: [], fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            throw new DomainException('O arquivo está vazio.');
        }

        if (self::isCnab240($lines)) {
            $result = self::readCnab240($lines, $today);
        } else {
            $csv = self::splitCsv($text);
            $result = $csv !== null && count($csv) >= 2 ? self::readCsv($csv, $today) : self::readText($text, $today);

            if ($result['titles'] === [] && $csv !== null) {
                $result = self::readText($text, $today);
            }
        }

        // Sem repetidos dentro do próprio arquivo.
        $seen = [];
        $result['titles'] = array_values(array_filter($result['titles'], function ($title) use (&$seen) {
            $key = self::titleKey($title);

            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));

        return $result;
    }

    /** Código de barras (44) ou, sem ele, valor|vencimento|CNPJ de quem recebe. */
    public static function titleKey(array $title): string
    {
        return $title['barcode'] ?? $title['amount_cents'].'|'.$title['due_date'].'|'.($title['payee_cnpj'] ?? '');
    }

    /** @param  list<string>  $lines */
    private static function isCnab240(array $lines): bool
    {
        foreach ($lines as $line) {
            $length = strlen(rtrim($line, "\r"));

            if ($length >= 230 && $length <= 242 && preg_match('/^\d{3}\d{4}3.{5}G/', substr(str_pad($line, 240), 0, 14)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function readCnab240(array $lines, string $today): array
    {
        $titles = [];
        $warnings = [];
        $fileCnpj = null;
        $generatedOn = null;
        $current = null;
        $lotCnpj = null;    // um lote por CNPJ (matriz e filiais): cada título pertence ao CNPJ do lote em que está

        foreach ($lines as $raw) {
            $l = str_pad(str_replace("\x1a", '', rtrim($raw, "\r")), 240);
            $type = $l[7];

            if ($type === '0') {
                if ($l[17] === '2') {
                    $fileCnpj = substr($l, 18, 14);
                }
                $generatedOn = self::ddmmyyyy(substr($l, 143, 8));
            } elseif ($type === '1') {
                $lotCnpj = $l[17] === '2' ? substr($l, 19, 14) : null;
            } elseif ($type === '3') {
                $segment = $l[13];

                if ($segment === 'G') {
                    if ($current) {
                        $titles[] = $current;
                    }

                    $code = (string) preg_replace('/\D/', '', substr($l, 17, 44));
                    $current = [
                        'format' => 'cnab240', 'movement' => substr($l, 15, 2), 'lot_cnpj' => $lotCnpj,
                        'barcode' => strlen($code) === 44 ? $code : null, 'bank' => null,
                        'payee_cnpj' => $l[61] === '2' ? substr(substr($l, 62, 15), 1) : null,
                        'payee_name' => trim(substr($l, 77, 30)) ?: null,
                        'due_date' => self::ddmmyyyy(substr($l, 107, 8)),
                        'amount_cents' => (int) substr($l, 115, 15),
                        'currency' => substr($l, 145, 2),
                        'document_number' => trim(substr($l, 147, 15)) ?: null,
                        'issue_date' => self::ddmmyyyy(substr($l, 181, 8)),
                        'drawer_cnpj' => null, 'drawer_name' => null, 'payer_cnpj' => null,
                    ];
                } elseif ($segment === 'H' && $current) {
                    if ($l[17] === '2') {
                        $current['drawer_cnpj'] = substr($l, 19, 14);
                    }
                    $current['drawer_name'] = trim(substr($l, 33, 40)) ?: null;
                } elseif ($segment === 'Y' && $current && substr($l, 17, 2) === '03' && $l[19] === '2') {
                    $current['payer_cnpj'] = substr($l, 21, 14);
                }
            }
        }

        if ($current) {
            $titles[] = $current;
        }

        // O arquivo pode trazer a história do título (entrada, alteração, baixa): vale a última movimentação de cada código.
        $last = [];
        foreach ($titles as $i => $title) {
            if ($title['barcode']) {
                $last[$title['barcode']] = $i;
            }
        }

        $out = [];

        foreach ($titles as $i => $t) {
            if ($t['barcode'] && $last[$t['barcode']] !== $i) {
                continue;
            }

            if ($t['movement'] === '02') {
                continue;     // baixa do título pelo beneficiário: não vale mais
            }

            if ($t['currency'] && trim($t['currency']) !== '' && ! in_array($t['currency'], ['09', '00'], true)) {
                $warnings[] = 'Título de '.($t['payee_name'] ?? 'beneficiário').' em moeda diferente de real foi ignorado.';

                continue;
            }

            if ($t['barcode']) {
                $slip = BoletoCode::read($t['barcode'], $today);
                $label = $t['document_number'] ?? substr($t['barcode'], -8);

                if ($slip['ok']) {
                    $t['bank'] = $slip['bank'];

                    if ($slip['amount_cents'] > 0 && $slip['amount_cents'] !== $t['amount_cents']) {
                        $warnings[] = "Título {$label}: o valor do arquivo (".Money::brl($t['amount_cents']).') difere do valor do código de barras ('.Money::brl($slip['amount_cents']).'). Valeu o do código de barras.';
                        $t['amount_cents'] = $slip['amount_cents'];
                    }

                    if ($slip['due_date'] && $t['due_date'] && $slip['due_date'] !== $t['due_date']) {
                        $warnings[] = "Título {$label}: o vencimento do arquivo difere do que o código de barras indica.";
                    }

                    $t['due_date'] ??= $slip['due_date'];
                } else {
                    $warnings[] = 'Um código de barras do arquivo não passou na validação ('.($slip['errors'][0] ?? 'inválido').').';
                    $t['barcode'] = null;
                }
            }

            if (! $t['due_date'] || $t['amount_cents'] <= 0) {
                $warnings[] = 'Um título do arquivo veio sem vencimento ou sem valor e foi ignorado.';

                continue;
            }

            foreach (['payee_cnpj', 'drawer_cnpj', 'payer_cnpj', 'lot_cnpj'] as $field) {
                if ($t[$field] && ! Document::isValidCnpj($t[$field])) {
                    $t[$field] = null;
                }
            }

            unset($t['movement'], $t['currency']);
            $out[] = $t;
        }

        return [
            'format' => 'cnab240', 'titles' => $out, 'warnings' => $warnings,
            'file_cnpj' => $fileCnpj && Document::isValidCnpj($fileCnpj) ? $fileCnpj : null, 'generated_on' => $generatedOn,
        ];
    }

    /** @return list<list<string>>|null */
    private static function splitCsv(string $text): ?array
    {
        $first = '';
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }

        $best = null;
        foreach ([';', "\t", '|', ','] as $delimiter) {
            $count = count(explode($delimiter, $first));

            if ($best === null || $count > $best[1]) {
                $best = [$delimiter, $count];
            }
        }

        if ($best === null || $best[1] < 3) {
            return null;
        }

        [$delimiter] = $best;
        $rows = [];
        $field = '';
        $row = [];
        $quoted = false;
        $length = strlen($text);
        $close = function () use (&$row, &$field, &$rows) {
            $row[] = $field;
            $field = '';

            if (array_filter($row, fn ($c) => trim($c) !== '') !== []) {
                $rows[] = $row;
            }

            $row = [];
        };

        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];

            if ($quoted) {
                if ($c === '"' && ($text[$i + 1] ?? '') === '"') {
                    $field .= '"';
                    $i++;
                } elseif ($c === '"') {
                    $quoted = false;
                } else {
                    $field .= $c;
                }
            } elseif ($c === '"') {
                $quoted = true;
            } elseif ($c === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($c === "\n") {
                $close();
            } elseif ($c !== "\r") {
                $field .= $c;
            }
        }

        if ($field !== '' || $row !== []) {
            $close();
        }

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    private static function readCsv(array $rows, string $today): array
    {
        $warnings = [];
        $header = array_map(fn ($c) => BoletoText::plain((string) $c), $rows[0]);
        $col = [];

        foreach (self::COLUMNS as $name => $pattern) {
            foreach ($header as $index => $cell) {
                if (preg_match($pattern, $cell) === 1 && ! in_array($index, $col, true)) {
                    $col[$name] = $index;
                    break;
                }
            }
        }

        $hasHeader = array_reduce($rows[0], fn ($all, $c) => $all && preg_match('/^\d[\d.,\/ -]*$/', trim($c)) !== 1, true)
            && (isset($col['amount']) || isset($col['due']) || isset($col['code']));
        $data = $hasHeader ? array_slice($rows, 1) : $rows;

        if (! $hasHeader) {
            $warnings[] = 'Não achei a linha de títulos das colunas; li cada linha procurando o código de barras ou a linha digitável.';
        }

        $titles = [];

        foreach ($data as $cells) {
            $lineText = implode(' ; ', $cells);
            $fromCode = ($hasHeader && isset($col['code']) ? self::fromCode($cells[$col['code']] ?? '', $today) : null) ?? self::fromCode($lineText, $today);
            $cell = fn (string $name) => $hasHeader && isset($col[$name]) ? (string) ($cells[$col[$name]] ?? '') : null;
            $cnpjColumn = $cell('cnpj');
            $cnpjColumn = $cnpjColumn !== null ? Document::normalizeCnpj($cnpjColumn) : null;
            $lineCnpjs = self::cnpjsIn($lineText);

            $title = [
                'format' => 'csv', 'lot_cnpj' => null,
                'barcode' => $fromCode['barcode'] ?? null, 'bank' => $fromCode['bank'] ?? null,
                'amount_cents' => ($fromCode['amount_cents'] ?? 0) > 0 ? $fromCode['amount_cents'] : ($cell('amount') !== null ? self::money($cell('amount')) : null),
                'due_date' => $fromCode['due_date'] ?? ($cell('due') !== null ? self::anyDate($cell('due')) : null),
                // Havendo coluna de CNPJ, vale só ela (pessoa física ou CNPJ inválido ficam sem CNPJ: nunca pegar o do sacador).
                'payee_cnpj' => $cnpjColumn !== null ? ($cnpjColumn !== '' && Document::isValidCnpj($cnpjColumn) ? $cnpjColumn : null) : ($lineCnpjs[0] ?? null),
                'payee_name' => $cell('payee') !== null ? (mb_substr(trim($cell('payee')), 0, 60) ?: null) : null,
                'document_number' => $cell('document') !== null ? (mb_substr(trim($cell('document')), 0, 30) ?: null) : null,
                'issue_date' => null, 'drawer_cnpj' => null, 'drawer_name' => null, 'payer_cnpj' => null,
            ];

            if (($title['amount_cents'] ?? 0) <= 0 || ! $title['due_date']) {
                continue;      // linha de totais, de rodapé ou sem dados
            }

            $titles[] = $title;
        }

        return ['format' => 'csv', 'titles' => $titles, 'warnings' => $warnings, 'file_cnpj' => null, 'generated_on' => null];
    }

    /** @return array<string, mixed> */
    private static function readText(string $text, string $today): array
    {
        $titles = [];
        $warnings = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $marks = [];

        foreach ($lines as $i => $line) {
            if ($found = self::fromCode($line, $today)) {
                $marks[] = ['i' => $i, 'title' => $found];
            }
        }

        $withoutData = 0;

        foreach ($marks as $k => $mark) {
            // O CNPJ de quem recebe costuma vir antes do código, na mesma linha ou perto; nunca depois (seria do título
            // seguinte) e nunca passando por outro código.
            $start = $k === 0 ? max(0, $mark['i'] - 2) : max($marks[$k - 1]['i'] + 1, $mark['i'] - 2);
            $cnpjs = self::cnpjsIn(implode(' ; ', array_slice($lines, $start, $mark['i'] - $start + 1)));

            if (($mark['title']['amount_cents'] ?? 0) <= 0 || ! $mark['title']['due_date']) {
                $withoutData++;

                continue;
            }

            $titles[] = [
                'format' => 'texto', 'lot_cnpj' => null, ...$mark['title'],
                'payee_cnpj' => count($cnpjs) === 1 ? $cnpjs[0] : null, 'payee_name' => null, 'document_number' => null,
                'issue_date' => null, 'drawer_cnpj' => null, 'drawer_name' => null, 'payer_cnpj' => null,
            ];
        }

        if ($withoutData) {
            $warnings[] = "{$withoutData} código(s) de barras sem valor ou sem vencimento foram ignorados.";
        }

        $warnings[] = $titles === []
            ? 'Não encontrei nenhum código de barras nem linha digitável no texto.'
            : 'Li o texto procurando códigos de barras: confira o CNPJ de quem recebe, que pode não ter sido identificado em todos.';

        return ['format' => 'texto', 'titles' => $titles, 'warnings' => $warnings, 'file_cnpj' => null, 'generated_on' => null];
    }

    /** @return array{barcode: ?string, bank: ?string, amount_cents: ?int, due_date: ?string}|null */
    private static function fromCode(string $text, string $today): ?array
    {
        $found = BoletoText::findLine($text, $today);

        return $found ? ['barcode' => $found['barcode'], 'bank' => $found['bank'], 'amount_cents' => $found['amount_cents'], 'due_date' => $found['due_date']] : null;
    }

    /** @return list<string> */
    private static function cnpjsIn(string $text): array
    {
        preg_match_all(BoletoText::CNPJ_PATTERN, $text, $matches);

        return array_values(array_filter(array_map(fn ($c) => Document::normalizeCnpj($c), $matches[0]), fn ($c) => Document::isValidCnpj($c)));
    }

    /** "1.250" é mil duzentos e cinquenta (ponto de milhar); "1250.50" é decimal com ponto; "1.250,50" é o formato brasileiro. */
    private static function money(string $value): ?int
    {
        $text = (string) preg_replace('/[R$\s]/u', '', $value);

        if ($text === '') {
            return null;
        }

        $normalized = match (true) {
            str_contains($text, ',') => str_replace(',', '.', str_replace('.', '', $text)),
            preg_match('/^\d{1,3}(\.\d{3})+$/', $text) === 1 => str_replace('.', '', $text),
            default => $text,
        };

        return is_numeric($normalized) && (float) $normalized >= 0 ? (int) round((float) $normalized * 100) : null;
    }

    /** dd/mm/aaaa, dd/mm/aa ou aaaa-mm-dd; data que não existe (31/02) não vale. */
    private static function anyDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4}|\d{2})(?!\d)/', $value, $m) === 1) {
            [$day, $month, $year] = [$m[1], $m[2], strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            [$year, $month, $day] = [$m[1], $m[2], $m[3]];
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year) ? "{$year}-{$month}-{$day}" : null;
    }

    /** 11111111 = "à vista" e 99999999 = "contra apresentação" no leiaute; ano fora de 2000-2100 não é vencimento. */
    private static function ddmmyyyy(string $value): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $m) !== 1 || (int) $m[3] < 2000 || (int) $m[3] > 2100) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
    }
}
