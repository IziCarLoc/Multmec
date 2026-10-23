<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Janelas de vencimento dos cartões do Financeiro (as do artefato). Uma regra só para o cartão do Painel e
 * para o filtro da lista que ele abre:
 *  - vencidos: venceu antes de hoje;
 *  - hoje: vence hoje;
 *  - 7 dias: vence de amanhã até daqui a 7 dias;
 *  - este mês: vence no mês corrente (inclusive os já vencidos dele).
 */
enum DueWindow: string
{
    case Vencidos = 'vencidos';
    case Hoje = 'hoje';
    case Semana = '7dias';
    case Mes = 'mes';

    public function label(): string
    {
        return match ($this) {
            self::Vencidos => 'Vencidos',
            self::Hoje => 'Vencem hoje',
            self::Semana => 'Próximos 7 dias',
            self::Mes => 'Vencem este mês',
        };
    }

    /** Restringe a consulta (que já deve estar só com o que está em aberto) pela data de vencimento. */
    public function constrain(Builder $query, ?CarbonImmutable $today = null, string $column = 'due_date'): Builder
    {
        $today ??= CarbonImmutable::today();

        return match ($this) {
            self::Vencidos => $query->where($column, '<', $today->toDateString()),
            self::Hoje => $query->where($column, $today->toDateString()),
            self::Semana => $query->where($column, '>', $today->toDateString())->where($column, '<=', $today->addDays(7)->toDateString()),
            self::Mes => $query->whereBetween($column, [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()]),
        };
    }

    /** Cor do cartão: vermelho só para o que já venceu. */
    public function tone(): string
    {
        return match ($this) {
            self::Vencidos => 'bad',
            self::Hoje => 'warn',
            default => 'plain',
        };
    }
}
