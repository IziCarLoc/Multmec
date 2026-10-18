<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Ajustes da oficina: o valor gravado em Ajustes (tabela `settings`) ou o padrão de config/oficina.php.
 * Chaves com ponto leem dentro do padrão ("texts.warranty").
 *
 *     Settings::get('izi_approval_limit_cents')   // 150000
 *     Settings::set('warranty_days', 120);
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $stored = null;

    public static function get(string $key): mixed
    {
        $stored = self::stored();

        return array_key_exists($key, $stored) ? $stored[$key] : config('oficina.'.$key);
    }

    public static function int(string $key): ?int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    public static function text(string $key): string
    {
        return (string) self::get('texts.'.$key);
    }

    /**
     * Grava (e registra na auditoria o antes e o depois). Valor igual ao padrão continua gravado:
     * o padrão do código pode mudar depois, e o que a oficina escolheu não deve mudar junto.
     */
    public static function set(string $key, mixed $value): void
    {
        $before = self::get($key);

        $setting = Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => auth()->id()]);

        if ($before !== $value) {
            AuditLog::record($setting, 'settings.updated', null, [$key => ['antes' => $before, 'depois' => $value]]);
        }

        self::$stored = null;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return Arr::dot(config('oficina', []));
    }

    public static function flush(): void
    {
        self::$stored = null;
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        try {
            return self::$stored = Setting::query()->pluck('value', 'key')->all();
        } catch (Throwable) {
            // Sem a tabela (antes do migrate) valem os padrões.
            return [];
        }
    }
}
