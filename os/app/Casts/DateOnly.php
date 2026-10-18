<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Data sem horário, gravada como `Y-m-d`.
 *
 * O cast `date` do Eloquent grava `Y-m-d 00:00:00`, o que faz comparações
 * por string (`whereBetween('date', ['2026-09-21', '2026-09-27'])`) perderem
 * o último dia no SQLite. Aqui o valor persistido é sempre `Y-m-d`.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }
}
