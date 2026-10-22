<?php

namespace App\Services\Registry;

use App\Models\AuditLog;
use App\Models\Vehicle;
use App\Support\LicensePlate;
use App\Support\Phone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Traz a frota da Izi Car do CSV exportado no Gestão de Frota (Veículos → Exportar), para a Giovana digitar
 * só a placa: o carro já vem com modelo, ano, chassi e investidor, e entra no cliente Izi Car.
 *
 * Colunas reconhecidas pelo nome (a ordem não importa): Placa (obrigatória), Modelo, Fabricação/Modelo (ano),
 * Chassi, Investidor, Cor e WhatsApp/Telefone do investidor (estas duas não vêm do Gestão; dá para
 * acrescentar na planilha). Carro com "Saída da Izi" preenchida fica de fora. Rodar de novo atualiza.
 */
final class IziFleetImport
{
    /** Investidor "Izi Car (frota própria)" ou vazio: carro da própria Izi, sem investidor. */
    private const OWN_FLEET = ['izi car (frota propria)', 'sem investidor', '-', ''];

    /**
     * Lê o CSV e devolve o que vai acontecer, sem gravar nada.
     *
     * @return array{rows: list<array{plate: string, brand_model: ?string, year: ?string, chassis: ?string, color: ?string, investor_name: ?string, investor_whatsapp: ?string, action: string}>, skipped: list<string>}
     */
    public function preview(string $csv): array
    {
        $lines = $this->parse($csv);
        $header = array_map(fn ($h) => $this->key($h), array_shift($lines) ?? []);
        $col = fn (string ...$names) => collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false);

        $plateCol = $col('placa');

        if ($plateCol === null) {
            throw new DomainException('Não achei a coluna "Placa" no arquivo. Use o CSV de Veículos do Gestão (Exportar).');
        }

        $cols = [
            'brand_model' => $col('modelo', 'veiculo', 'marca/modelo'),
            'year' => $col('fabricacao/modelo', 'ano'),
            'chassis' => $col('chassi'),
            'color' => $col('cor'),
            'investor_name' => $col('investidor', 'proprietario'),
            'investor_whatsapp' => $col('whatsapp do investidor', 'whatsapp', 'telefone do investidor', 'telefone'),
            'left_at' => $col('saida da izi'),
        ];

        $rows = [];
        $skipped = [];

        foreach ($lines as $number => $line) {
            $plate = LicensePlate::clean($line[$plateCol] ?? '');

            if ($plate === '') {
                continue;
            }

            if (! Vehicle::isValidPlate($plate)) {
                $skipped[] = 'Linha '.($number + 2).': placa "'.$plate.'" não parece válida.';

                continue;
            }

            $value = fn (string $field) => $cols[$field] === null ? null : (trim((string) ($line[$cols[$field]] ?? '')) ?: null);

            if (($left = $value('left_at')) !== null && $left !== '-') {
                $skipped[] = $plate.': saiu da Izi em '.$left.'.';

                continue;
            }

            $investor = $value('investor_name');

            if (in_array(Str::lower(Str::ascii((string) $investor)), self::OWN_FLEET, true)) {
                $investor = null;
            }

            $phone = Phone::digits($value('investor_whatsapp'));

            $rows[] = [
                'plate' => $plate,
                'brand_model' => $value('brand_model'),
                'year' => $value('year') !== '-' ? $value('year') : null,
                'chassis' => ($chassis = $value('chassis')) !== '-' ? $chassis : null,
                'color' => $value('color'),
                'investor_name' => $investor,
                'investor_whatsapp' => Phone::isValid($phone) ? $phone : null,
                'action' => Vehicle::byPlate($plate) ? 'atualizar' : 'novo',
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** Grava: cria os carros que faltam e atualiza os que já existem (só os campos que vieram preenchidos). */
    public function apply(string $csv): int
    {
        $preview = $this->preview($csv);
        $customer = IziFleet::customer();

        DB::transaction(function () use ($preview, $customer) {
            foreach ($preview['rows'] as $row) {
                $vehicle = Vehicle::byPlate($row['plate']) ?? new Vehicle(['plate' => $row['plate']]);

                foreach (['brand_model', 'year', 'chassis', 'color', 'investor_name', 'investor_whatsapp'] as $field) {
                    if ($row[$field] !== null) {
                        $vehicle->{$field} = $row[$field];
                    }
                }

                $vehicle->is_izi_fleet = true;
                $vehicle->customer_id = $customer->id;
                $vehicle->save();
            }

            AuditLog::record($customer, 'izi_fleet.imported', null, ['carros' => count($preview['rows'])]);
        });

        return count($preview['rows']);
    }

    /** @return list<list<string>> */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';

        return array_values(array_map(fn (string $line) => str_getcsv($line, $delimiter, '"', ''), array_filter($lines, fn ($l) => trim($l) !== '')));
    }

    private function key(string $header): string
    {
        return trim(Str::lower(Str::ascii($header)));
    }
}
