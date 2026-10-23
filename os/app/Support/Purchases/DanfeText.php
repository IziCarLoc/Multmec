<?php

namespace App\Support\Purchases;

use App\Support\Document;

/**
 * Lê o TEXTO de uma DANFE (copiado do PDF, ou só a chave digitada) quando o fornecedor não manda o XML. O que sai
 * daqui é sugestão para a pessoa conferir com a nota na mão: a prova de que a nota existe vem da consulta da chave
 * no portal da NF-e.
 *
 * Porte de `app/src/danfe.js`. Dinheiro em centavos.
 */
final class DanfeText
{
    private const NUMBER = '(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})';

    /**
     * Procura a chave de 44 posições (a DANFE imprime em blocos de 4; o PDF às vezes solta tudo junto) e devolve a
     * primeira que passa no dígito verificador e é modelo 55. Sem nenhuma válida, devolve a primeira de 44 números
     * (inválida), para a pessoa saber o motivo.
     *
     * @return array<string, mixed>|null leitura de AccessKey::read()
     */
    public static function findKey(?string $text): ?array
    {
        $seen = [];
        $firstInvalid = null;

        preg_match_all('/(?:[0-9A-Za-z][ .\-]?){44,400}/', (string) $text, $matches);

        foreach ($matches[0] as $found) {
            $compact = mb_strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $found));

            for ($start = 0; $start + 44 <= strlen($compact); $start++) {
                $candidate = substr($compact, $start, 44);

                if (isset($seen[$candidate])) {
                    continue;
                }

                $seen[$candidate] = true;
                $key = AccessKey::read($candidate);

                if ($key['valid'] && $key['model'] === '55') {
                    return $key;
                }

                if (! $firstInvalid && preg_match('/^\d{44}$/', $candidate) === 1 && isset($key['key'])) {
                    $firstInvalid = $key;
                }
            }
        }

        return $firstInvalid;
    }

    /**
     * @return array{key: ?array<string, mixed>, total_cents: ?int, total_confirmed: bool, possible_totals: list<int>,
     *               issue_date: ?string, recipient_cnpj: ?string, cnpjs: list<string>, warnings: list<string>}
     */
    public static function read(?string $text): array
    {
        $original = mb_substr((string) $text, 0, 60000);
        $plain = BoletoText::plain($original);
        $collapsed = (string) preg_replace('/[ \t]+/', ' ', $plain);
        $warnings = [];
        $key = self::findKey($original);

        if (! $key) {
            $warnings[] = 'Não encontrei uma chave de acesso de 44 números no texto. Ela fica no rodapé do cabeçalho da DANFE, embaixo do código de barras.';
        } elseif (! $key['valid']) {
            $warnings[] = $key['reason'];
        } elseif ($key['model'] !== '55') {
            $warnings[] = "A chave é de uma nota modelo {$key['model']}; aqui só entra NF-e (modelo 55).";
        }

        // O CNPJ do emitente está dentro da chave; o destinatário é o primeiro outro CNPJ do bloco "DESTINATÁRIO / REMETENTE"
        // (que termina na fatura, no cálculo do imposto ou no transportador). Fora desse bloco há o CNPJ do transportador,
        // que nunca é o de quem comprou.
        $cnpjs = self::cnpjs($plain);
        $issuer = $key['issuer_cnpj'] ?? null;
        $recipient = null;
        $start = strpos($plain, 'destinatario');

        if ($start !== false) {
            $ends = array_filter(array_map(
                fn ($marker) => strpos($plain, $marker, $start + 12),
                ['fatura', 'calculo do imposto', 'transportador', 'dados dos produtos', 'dados do produto'],
            ), fn ($position) => $position !== false && $position > 0);
            $end = $ends !== [] ? min($ends) : $start + 600;

            foreach ($cnpjs as $found) {
                if ($found['cnpj'] !== $issuer && $found['position'] >= $start && $found['position'] < $end) {
                    $recipient = $found['cnpj'];
                    break;
                }
            }

            if (! $recipient && preg_match('/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/', substr($plain, $start, $end - $start)) === 1) {
                $warnings[] = 'O destinatário parece ser pessoa física (CPF): não é a oficina nem empresa do grupo.';
            }
        } else {
            $others = array_values(array_filter($cnpjs, fn ($c) => $c['cnpj'] !== $issuer));

            if (count($others) === 1) {
                $recipient = $others[0]['cnpj'];
            } elseif (count($others) > 1) {
                $warnings[] = 'Há mais de um CNPJ no texto além do emitente; digite o do destinatário (quem comprou) à mão.';
            }
        }

        $total = self::total($collapsed);

        if ($total['value'] === null) {
            $warnings[] = 'Não consegui ler o valor total da nota. Digite o que está em "VALOR TOTAL DA NOTA".';
        } elseif (! $total['confirmed']) {
            $warnings[] = 'Confira o "VALOR TOTAL DA NOTA" na DANFE: escolhi o maior número do bloco de impostos, que com desconto ou ICMS-ST pode não ser o total.';
        }

        $issueDate = self::issueDate($collapsed, ($key['valid'] ?? false) ? $key['yymm'] : null);

        if (($key['valid'] ?? false) && ! $issueDate) {
            $warnings[] = 'Não achei no texto uma data de emissão dentro do mês da chave. Digite a data que está na nota.';
        }

        return [
            'key' => $key,
            'total_cents' => $total['value'],
            'total_confirmed' => $total['confirmed'],
            'possible_totals' => $total['candidates'],
            'issue_date' => $issueDate,
            'recipient_cnpj' => $recipient,
            'cnpjs' => array_column($cnpjs, 'cnpj'),
            'warnings' => $warnings,
        ];
    }

    /** @return list<array{cnpj: string, position: int}> CNPJs válidos, sem repetir, na ordem do texto */
    private static function cnpjs(string $text): array
    {
        $found = [];

        preg_match_all(BoletoText::CNPJ_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$raw, $position]) {
            $cnpj = Document::normalizeCnpj($raw);

            if (Document::isValidCnpj($cnpj) && ! in_array($cnpj, array_column($found, 'cnpj'), true)) {
                $found[] = ['cnpj' => $cnpj, 'position' => $position];
            }
        }

        return $found;
    }

    /** @return array{value: ?int, candidates: list<int>, confirmed: bool} */
    private static function total(string $text): array
    {
        $block = self::blockValues($text);
        $largest = $block !== [] ? max($block) : null;

        // Outras fontes do mesmo número: o canhoto ("VALOR TOTAL: R$ x") e o rótulo "VALOR TOTAL DA NOTA" seguido de número.
        $stub = preg_match('/(?<![a-z])valor\s+total\s*:\s*(?:r\$\s*)?'.self::NUMBER.'/', $text, $m1) === 1 ? self::toCents($m1[1]) : null;
        $label = preg_match('/(?:valor|v\.?)\s*total\s*(?:da\s*)?(?:nota|nf-?e?)[^0-9]{0,40}'.self::NUMBER.'/', $text, $m2) === 1 ? self::toCents($m2[1]) : null;
        $sources = array_values(array_filter([$largest, $stub, $label], fn ($v) => $v !== null && $v > 0));

        $votes = [];
        foreach ($sources as $value) {
            $votes[$value] = ($votes[$value] ?? 0) + 1;
        }

        $best = null;
        foreach ($votes as $value => $count) {
            if ($best === null || $count > $best[1] || ($count === $best[1] && $value > $best[0])) {
                $best = [(int) $value, $count];
            }
        }

        $candidates = array_values(array_unique([...$block, ...$sources]));
        rsort($candidates);

        // Com desconto, ICMS-ST ou IPI o maior número do bloco pode não ser o total: só se confirma com duas fontes iguais.
        return ['value' => $best[0] ?? null, 'candidates' => array_slice($candidates, 0, 4), 'confirmed' => $best !== null && $best[1] >= 2];
    }

    /** @return list<int> valores do bloco "Cálculo do imposto" (o total da nota é o maior; os outros são partes) */
    private static function blockValues(string $text): array
    {
        $start = strpos($text, 'calculo do imposto');

        if ($start === false) {
            return [];
        }

        $end = strpos($text, 'transportador', $start);
        $block = substr($text, $start, $end !== false && $end > $start ? $end - $start : 1500);
        preg_match_all('/'.self::NUMBER.'(?!\d)/', $block, $matches);

        return array_map(fn ($v) => self::toCents($v), $matches[1]);
    }

    /** A data de emissão tem de cair no mês da chave de acesso; entre as datas do texto, vale a primeira que cai. */
    private static function issueDate(string $text, ?string $yymm): ?string
    {
        if ($yymm !== null) {
            // A data do protocolo de autorização vem com hora ("01/10/2026 09:12:30") e não é a de emissão.
            preg_match_all('/(\d{2})\/(\d{2})\/(\d{4})(?!\s*\d{2}:\d{2})/', $text, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $day, $month, $year]) {
                if (checkdate((int) $month, (int) $day, (int) $year) && substr($year, 2).$month === $yymm) {
                    return "{$year}-{$month}-{$day}";
                }
            }

            return null;
        }

        if (preg_match('/(?:data\s*(?:da|de)?\s*emissao|emissao)[^0-9]{0,30}(\d{2})\/(\d{2})\/(\d{4})/', $text, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    private static function toCents(string $text): int
    {
        return (int) round((float) str_replace(',', '.', str_replace('.', '', $text)) * 100);
    }
}
