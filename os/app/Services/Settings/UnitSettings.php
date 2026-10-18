<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\Unit;
use App\Support\Document;
use DomainException;

/**
 * Dados e numeração de uma unidade (Ajustes). Mudar a numeração não renumera OS já abertas.
 */
class UnitSettings
{
    /**
     * @param  array{trade_name: string, legal_name: ?string, cnpj: ?string, address: ?string, city: ?string, phone: ?string, numbering_mode: string, next_number: ?int, active: bool}  $data
     */
    public function save(Unit $unit, array $data): Unit
    {
        $cnpj = Document::digits($data['cnpj'] ?? '');

        if ($cnpj !== '' && ! Document::isValidCnpj($cnpj)) {
            throw new DomainException('CNPJ inválido: confira os números.');
        }

        if (! in_array($data['numbering_mode'], [Unit::NUMBERING_MANUAL, Unit::NUMBERING_AUTO], true)) {
            throw new DomainException('Escolha como a OS é numerada.');
        }

        if ($data['numbering_mode'] === Unit::NUMBERING_AUTO && ! ($data['next_number'] ?? null)) {
            throw new DomainException('Na numeração automática, informe o próximo número de OS.');
        }

        if ($unit->is_default && ! $data['active']) {
            throw new DomainException('A unidade padrão não pode ser desativada.');
        }

        $unit->fill([
            'trade_name' => trim($data['trade_name']),
            'legal_name' => self::nullable($data['legal_name'] ?? null),
            'cnpj' => $cnpj === '' ? null : $cnpj,
            'address' => self::nullable($data['address'] ?? null),
            'city' => self::nullable($data['city'] ?? null),
            'phone' => self::nullable($data['phone'] ?? null),
            'numbering_mode' => $data['numbering_mode'],
            'next_number' => $data['next_number'] ?: null,
            'active' => $data['active'],
        ]);

        $changes = collect($unit->getDirty())->map(fn ($value, $key) => ['antes' => $unit->getOriginal($key), 'depois' => $value])->all();
        $unit->save();

        if ($changes !== []) {
            AuditLog::record($unit, 'unit.updated', null, $changes);
        }

        return $unit;
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
