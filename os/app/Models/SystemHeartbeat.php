<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Última execução de uma rotina agendada. A "Saúde do sistema" e o `sistema:conferir-deploy` avisam
 * quando o batimento parou: cron `schedule:run` desligado no servidor.
 */
class SystemHeartbeat extends Model
{
    public const SCHEDULER = 'agendador';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected $fillable = ['name', 'ran_at'];

    protected function casts(): array
    {
        return ['ran_at' => 'datetime'];
    }

    public static function beat(string $name): void
    {
        try {
            self::query()->updateOrCreate(['name' => $name], ['ran_at' => now()]);
        } catch (Throwable) {
            // Sem a tabela (deploy sem migrate) a rotina segue; só o aviso não sabe dela.
        }
    }

    public static function lastRun(string $name): ?CarbonImmutable
    {
        try {
            $at = self::query()->where('name', $name)->value('ran_at');
        } catch (Throwable) {
            return null;
        }

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /** O agendador roda a cada minuto: mais de 10 minutos sem batimento = cron parado. */
    public static function schedulerIsAlive(int $minutes = 10): bool
    {
        $last = self::lastRun(self::SCHEDULER);

        return $last !== null && $last->gt(now()->subMinutes($minutes));
    }
}
