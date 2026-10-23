<?php

namespace App\Services\Import;

use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Finance\FinanceInput;
use App\Services\Finance\Month;
use App\Services\Finance\Receivables;
use App\Services\Registry\IziFleet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa a aba SERVIÇOS da planilha CONTROLE SERVIÇOS (CSV) para o "A receber": o `importar.js` do financeiro em
 * Node, com as mesmas regras de limpeza.
 *  - Lê as colunas da planilha; ignora linhas de mês, totais, OS sem valor e compra de peça lançada como OS.
 *    Orçamento não é serviço feito: fica fora (só é contado na prévia).
 *  - Datas: dd/mm/aaaa, as digitações conhecidas (27/08/2028, 21/001/2026, 19/022026), dd/mm sem ano pelo vizinho
 *    mais perto, e a mediana das vizinhas recoloca data absurda (a planilha é cronológica por blocos). Data
 *    corrigida fica anotada no lançamento.
 *  - IZI, IZICAR, IZICR e IZI CAR são o cliente da frota Izi Car; os outros pelo nome (o que não existe é cadastrado).
 *  - Data de corte: tudo até ela entra como já recebido (quitação histórica, que não entra no caixa), para o
 *    controle começar zerado. O que o cliente ainda devia entra depois como "saldo anterior" (tela Crédito).
 *  - `legacy_id` "planilha:…" (número da OS, placa e a data como foi digitada): importar de novo não duplica.
 *  - A prévia roda a importação inteira e desfaz no fim (o que ela mostra é o que o Importar grava).
 * Valores em centavos; insumos somam no custo das peças (o lucro do sistema é total − peças − frete − prestador).
 */
final class SpreadsheetImport
{
    public const PREFIX = 'planilha:';

    /** Até 5 MB, como no Node. */
    public const MAX_BYTES = 5_000_000;

    private const IZI = ['IZI', 'IZICAR', 'IZICR', 'IZI CAR'];

    private const COLUMNS = ['os' => 0, 'data' => 1, 'veiculo' => 2, 'placa' => 3, 'operador' => 4, 'custo_pecas' => 5, 'mao_obra' => 6, 'insumos' => 7, 'frete' => 8, 'total' => 9, 'cliente' => 11, 'obs' => 14, 'status' => 15];

    /** @var array<string, ?Customer> */
    private array $customers = [];

    public function __construct(private Receivables $receivables) {}

    /**
     * Lê e mostra o que vai acontecer, sem gravar nada.
     *
     * @return array<string, mixed>
     */
    public function preview(string $csv, ?string $cutoff, User $by): array
    {
        $read = $this->read($csv);
        $cutoff = $this->cutoff($cutoff);
        DB::beginTransaction();

        try {
            return $this->import($read, $cutoff, $by) + ['sha256' => hash('sha256', $csv)];
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, mixed> o mesmo resumo da prévia */
    public function apply(string $csv, ?string $cutoff, User $by): array
    {
        $read = $this->read($csv);
        $cutoff = $this->cutoff($cutoff);
        $sha256 = hash('sha256', $csv);

        return DB::transaction(function () use ($read, $cutoff, $by, $sha256) {
            $report = $this->import($read, $cutoff, $by);
            AuditLog::record($by, 'spreadsheet.imported', null, ['sha256' => $sha256, 'corte' => $cutoff, 'novos' => $report['new'], 'ja_existiam' => $report['existing']]);

            return $report + ['sha256' => $sha256];
        });
    }

    /**
     * Converte o CSV em linhas normalizadas, sem tocar no banco (o `lerPlanilhaServicos` do Node).
     *
     * @return array{lines: list<array<string, mixed>>, warnings: list<string>}
     */
    public function read(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new DomainException('Arquivo grande demais (o limite é 5 MB).');
        }

        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($csv, "\n") ?: '';
        $rows = self::csv($csv, substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',');
        $raw = [];
        $warnings = [];
        $lastFull = self::ord('2025-08-25');

        foreach (array_slice($rows, 1) as $c) {
            $number = trim($c[self::COLUMNS['os']] ?? '');

            if (preg_match('/^\d{1,4}$/', $number) !== 1) {
                continue; // linhas de mês, totais e em branco
            }

            $dateText = trim($c[self::COLUMNS['data']] ?? '');
            $marker = mb_strtoupper($dateText);
            $total = self::value($c[self::COLUMNS['total']] ?? null);
            $budget = preg_match('/OR[CÇ]AM/u', $marker) === 1;

            if (preg_match('/PE[CÇ]AS/u', $marker) === 1) {
                $warnings[] = 'OS '.$number.': compra de peças lançada como OS, ignorada.';

                continue;
            }

            if (! $budget && ! ($total > 0)) {
                continue; // sem valor: nada a lançar
            }

            $date = self::date($dateText);
            $candidate = null;

            if (isset($date['iso'])) {
                $candidate = $date['iso'];
                $lastFull = self::ord($date['iso']);
            } elseif (isset($date['day'])) {
                // dd/mm sem ano: o ano mais perto da última data completa.
                $options = array_values(array_filter(array_map(fn (int $y) => sprintf('%04d-%02d-%02d', $y, $date['month'], $date['day']), [2025, 2026]), fn (string $iso) => self::validIso($iso)));
                usort($options, fn ($a, $b) => abs(self::ord($a) - $lastFull) <=> abs(self::ord($b) - $lastFull));
                $candidate = $options[0] ?? null;
            }

            $obs = trim($c[self::COLUMNS['obs']] ?? '');
            $status = trim($c[self::COLUMNS['status']] ?? '');
            $received = preg_match('/^RECEBIDO$/iu', $status) === 1;
            $mechanic = mb_strtoupper(trim($c[self::COLUMNS['operador']] ?? ''));

            $raw[] = [
                'candidate' => $candidate,
                'line' => [
                    'number' => $number,
                    'raw_date' => $dateText,
                    'budget' => $budget,
                    'vehicle' => mb_strtoupper(trim($c[self::COLUMNS['veiculo']] ?? '')) ?: null,
                    'plate' => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($c[self::COLUMNS['placa']] ?? '')) ?: null,
                    'mechanic' => preg_match('/^\(?SEM\)?$/', $mechanic) === 1 ? null : ($mechanic ?: null),
                    'customer' => self::customerName($c[self::COLUMNS['cliente']] ?? ''),
                    'total' => $total ?? 0,
                    'labor' => self::value($c[self::COLUMNS['mao_obra']] ?? null) ?? 0,
                    'parts' => self::value($c[self::COLUMNS['custo_pecas']] ?? null),
                    'freight' => self::value($c[self::COLUMNS['frete']] ?? null) ?? 0,
                    'supplies' => self::value($c[self::COLUMNS['insumos']] ?? null) ?? 0,
                    'method' => self::method($status.' '.$obs),
                    'received' => $received,
                    'notes' => implode(' | ', array_filter([$obs, $status !== '' && ! $received ? $status : ''])) ?: null,
                ],
            ];
        }

        $dates = self::resolveDates(array_column($raw, 'candidate'));
        $lines = [];

        foreach ($raw as $i => $item) {
            if ($dates[$i]['iso'] === null) {
                $warnings[] = 'OS '.$item['line']['number'].': sem data que dê para aproveitar, ignorada.';

                continue;
            }

            $lines[] = $item['line'] + ['date' => $dates[$i]['iso'], 'estimated' => $dates[$i]['estimated']];
        }

        return ['lines' => $lines, 'warnings' => $warnings];
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, warnings: list<string>}  $read
     * @return array<string, mixed>
     */
    private function import(array $read, ?string $cutoff, User $by): array
    {
        $this->customers = [];
        $report = [
            'lines' => count($read['lines']), 'budgets' => 0, 'estimated' => 0, 'warnings' => $read['warnings'],
            'months' => [], 'new' => 0, 'existing' => 0, 'settled_by_cutoff' => 0, 'received_in_sheet' => 0,
            'customers_new' => 0, 'new_cents' => 0, 'open_cents' => 0, 'cutoff' => $cutoff,
        ];
        $seen = [];

        foreach ($read['lines'] as $line) {
            if ($line['budget']) {
                $report['budgets']++;

                continue;
            }

            $month = substr($line['date'], 0, 7);
            $report['months'][$month] = ($report['months'][$month] ?? 0) + $line['total'];
            $base = self::PREFIX.$line['number'].':'.substr(sha1($line['number'].'|'.$line['plate'].'|'.$line['raw_date']), 0, 16);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $legacy = $seen[$base] > 1 ? $base.'#'.$seen[$base] : $base;

            if (Receivable::query()->where('legacy_id', $legacy)->exists()) {
                $report['existing']++;

                continue;
            }

            $customer = $this->customer($line['customer'], $report);
            $plate = $line['plate'] ? Str::limit($line['plate'], 10, '') : null;
            $vehicle = $plate && Vehicle::isValidPlate($plate) ? Vehicle::byPlate($plate) : null;
            $parts = max(0, $line['parts'] ?? 0) + max(0, $line['supplies']);
            $due = CarbonImmutable::parse($line['date'])->addDays($customer?->credit_days ?? 0)->toDateString();
            $notes = array_filter([
                $line['notes'],
                $line['mechanic'] ? 'Mecânico na planilha: '.$line['mechanic'] : null,
                $line['supplies'] > 0 ? 'Custo das peças inclui insumos de '.Money::brl($line['supplies']) : null,
                $line['estimated'] ? 'Data estimada pelas vizinhas (na planilha: "'.Str::limit($line['raw_date'], 30, '').'")' : null,
                'Importado da planilha CONTROLE SERVIÇOS.',
            ]);

            $receivable = Receivable::create([
                'legacy_id' => $legacy,
                'customer_id' => $customer?->id,
                'vehicle_id' => $vehicle?->id,
                'order_number' => Str::limit($line['number'], 20, ''),
                'service_date' => $line['date'],
                'plate' => $plate,
                'vehicle_model' => Str::limit((string) $line['vehicle'], 80, '') ?: null,
                'parts_cost_cents' => min($parts, FinanceInput::MAX_CENTS),
                'labor_cents' => min(max(0, $line['labor']), FinanceInput::MAX_CENTS),
                'freight_cents' => min(max(0, $line['freight']), FinanceInput::MAX_CENTS),
                'total_cents' => min($line['total'], FinanceInput::MAX_CENTS),
                'payment_method' => $line['method'],
                'due_date' => $due,
                'notes' => implode(' · ', $notes),
                'created_by' => $by->id,
            ]);
            AuditLog::record($receivable, 'receivable.created', 'Importado da planilha');
            $report['new']++;
            $report['new_cents'] += $receivable->total_cents;
            $report['estimated'] += $line['estimated'] ? 1 : 0;

            $settledByCutoff = $cutoff !== null && $line['date'] <= $cutoff;

            if ($line['received'] || $settledByCutoff) {
                $payment = $this->receivables->addPayment($receivable, $line['date'], $receivable->total_cents, $by);
                $payment->update(['legacy_id' => $legacy.':pago']);
                $line['received'] ? $report['received_in_sheet']++ : $report['settled_by_cutoff']++;
            } else {
                $report['open_cents'] += $receivable->total_cents;
            }
        }

        ksort($report['months']);

        return $report;
    }

    private function customer(?string $name, array &$report): ?Customer
    {
        if ($name === null) {
            return null;
        }

        if (array_key_exists($name, $this->customers)) {
            return $this->customers[$name];
        }

        if ($name === 'IZICAR') {
            return $this->customers[$name] = IziFleet::customer();
        }

        $customer = Customer::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();

        if ($customer === null) {
            $customer = Customer::create(['name' => Str::limit($name, 120, '')]);
            AuditLog::record($customer, 'customer.created', 'Importado da planilha');
            $report['customers_new']++;
        }

        return $this->customers[$name] = $customer;
    }

    private function cutoff(?string $cutoff): ?string
    {
        $cutoff = trim((string) $cutoff);

        return $cutoff === '' ? null : FinanceInput::date($cutoff, 'Data de corte inválida.');
    }

    /** Nome do cliente como na planilha (maiúsculas); as grafias da Izi viram uma só; vazio = sem cliente. */
    private static function customerName(string $text): ?string
    {
        $name = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));

        if ($name === '') {
            return null;
        }

        return in_array($name, self::IZI, true) ? 'IZICAR' : $name;
    }

    private static function method(string $text): ?string
    {
        $t = mb_strtoupper($text);

        return match (true) {
            preg_match('/C\.?C\b|CART|CRED/u', $t) === 1 => PaymentMethod::Cartao->value,
            str_contains($t, 'PIX') => PaymentMethod::Pix->value,
            str_contains($t, 'BOLETO') => PaymentMethod::Boleto->value,
            str_contains($t, 'DINHEIRO') => PaymentMethod::Dinheiro->value,
            default => null,
        };
    }

    /**
     * CSV (RFC 4180): aspas duplas, separador e quebra de linha dentro do campo (o `lerCsv` do Node).
     *
     * @return list<list<string>>
     */
    public static function csv(string $text, string $delimiter = ','): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $rows = [];
        $row = [];
        $field = '';
        $quoted = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quoted) {
                if ($ch === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $ch;
                }
            } elseif ($ch === '"') {
                $quoted = true;
            } elseif ($ch === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }

                $row[] = $field;
                $field = '';
                $rows[] = $row;
                $row = [];
            } else {
                $field .= $ch;
            }
        }

        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Valor da planilha em centavos (o `lerValor` do Node): "R$ 1.234,56", "-R$ 28,00", "1234.5" (sem vírgula, o
     * ponto é decimal). Vazio, "-" ou inválido = nulo.
     */
    public static function value(?string $text): ?int
    {
        $s = trim((string) $text);

        if ($s === '' || $s === '-' || $s === '\\-') {
            return null;
        }

        $negative = str_contains($s, '-');
        $s = preg_replace('/[^0-9,.]/', '', $s) ?? '';

        if ($s === '') {
            return null;
        }

        if (str_contains($s, ',')) {
            $s = preg_replace('/,/', '.', str_replace('.', '', $s), 1) ?? '';
        }

        if (preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m) !== 1 || ($m[1] === '' && ($m[2] ?? '') === '')) {
            return null;
        }

        $decimals = str_pad($m[2] ?? '', 3, '0');
        $cents = (int) ($m[1] ?: '0') * 100 + (int) substr($decimals, 0, 2) + ((int) $decimals[2] >= 5 ? 1 : 0);

        return $negative ? -$cents : $cents;
    }

    /** @return array{iso?: string, day?: int, month?: int} */
    private static function date(string $text): array
    {
        $s = trim($text);
        $s = preg_replace('/^0(\d{2}\/)/', '$1', $s) ?? $s;
        $s = preg_replace('#/001/#', '/01/', $s, 1) ?? $s;
        $s = preg_replace('#^(\d{1,2})/(\d{2})(\d{4})$#', '$1/$2/$3', $s) ?? $s;

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year = $year === 2028 ? 2025 : $year; // 27/08/2028 digitado errado

            if ($year < 2025 || $year > 2027) {
                return [];
            }

            $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);

            return self::validIso($iso) ? ['iso' => $iso] : [];
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $s, $m) === 1) {
            return ['day' => (int) $m[1], 'month' => (int) $m[2]];
        }

        return [];
    }

    /**
     * Datas digitadas → datas de cada linha (o `resolverDatas` do Node): a mediana móvel das vizinhas descarta a
     * digitação absurda (mais de 25 dias longe) e a linha sem data herda a posição por interpolação.
     *
     * @param  list<?string>  $candidates
     * @return list<array{iso: ?string, estimated: bool}>
     */
    private static function resolveDates(array $candidates): array
    {
        $indexes = array_keys(array_filter($candidates, fn ($c) => $c !== null));
        $asGiven = array_map(fn ($c) => ['iso' => $c, 'estimated' => $c === null], $candidates);

        if (count($indexes) < 5) {
            return $asGiven;
        }

        $y = array_map(fn (int $i) => self::ord($candidates[$i]), $indexes);
        $median = self::rollingMedian($y, 15, 5);
        $goodX = [];
        $goodY = [];

        foreach ($indexes as $k => $i) {
            if ($median[$k] !== null && abs($y[$k] - $median[$k]) <= 25) {
                $goodX[] = $i;
                $goodY[] = $y[$k];
            }
        }

        if ($goodX === []) {
            return $asGiven;
        }

        $smooth = self::rollingMedian($goodY, 5, 1);

        for ($k = 1; $k < count($smooth); $k++) {
            $smooth[$k] = max($smooth[$k], $smooth[$k - 1]);
        }

        $good = array_flip($goodX);
        $out = [];

        foreach ($candidates as $i => $candidate) {
            $estimate = self::interpolate($goodX, $smooth, $i);
            $own = $candidate !== null ? self::ord($candidate) : null;
            $out[] = $own !== null && isset($good[$i]) && abs($own - $estimate) <= 25
                ? ['iso' => $candidate, 'estimated' => false]
                : ['iso' => self::fromOrd((int) floor($estimate + 0.5)), 'estimated' => true];
        }

        return $out;
    }

    /**
     * Mediana móvel centrada (como o pandas `rolling(janela, center=True, min_periods)`).
     *
     * @param  list<int|float>  $values
     * @return list<?float>
     */
    private static function rollingMedian(array $values, int $window, int $minimum): array
    {
        $half = intdiv($window, 2);
        $out = [];

        foreach (array_keys($values) as $i) {
            $slice = array_slice($values, max(0, $i - $half), $i + $half + 1 - max(0, $i - $half));
            $out[] = count($slice) >= $minimum ? self::median($slice) : null;
        }

        return $out;
    }

    /** @param  list<int|float>  $values */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  list<int>  $xs
     * @param  list<float>  $ys
     */
    private static function interpolate(array $xs, array $ys, int $x): float
    {
        $last = count($xs) - 1;

        if ($x <= $xs[0]) {
            return (float) $ys[0];
        }

        if ($x >= $xs[$last]) {
            return (float) $ys[$last];
        }

        $j = 1;

        while ($xs[$j] < $x) {
            $j++;
        }

        $t = ($x - $xs[$j - 1]) / ($xs[$j] - $xs[$j - 1]);

        return $ys[$j - 1] + $t * ($ys[$j] - $ys[$j - 1]);
    }

    /** Dias desde 1970-01-01. */
    private static function ord(string $iso): int
    {
        return intdiv(CarbonImmutable::createFromFormat('!Y-m-d', $iso, 'UTC')->getTimestamp(), 86400);
    }

    private static function fromOrd(int $days): string
    {
        return CarbonImmutable::createFromTimestampUTC($days * 86400)->toDateString();
    }

    private static function validIso(string $iso): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Mês "out/2026" (para a prévia). */
    public static function monthLabel(string $month): string
    {
        return Month::label($month);
    }
}
