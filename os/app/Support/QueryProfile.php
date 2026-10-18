<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Consultas do pedido atual (quantas, tempo somado e as 5 mais lentas), para o log de pedidos lentos.
 * Guarda só o SQL com "?" no lugar dos valores: nada de dado pessoal no log.
 */
final class QueryProfile
{
    private int $count = 0;

    private float $time = 0.0;

    /** @var list<array{ms: float, sql: string}> */
    private array $slowest = [];

    public function add(QueryExecuted $query): void
    {
        $this->count++;
        $this->time += (float) $query->time;

        if (count($this->slowest) < 5 || $query->time > $this->slowest[4]['ms']) {
            $this->slowest[] = ['ms' => round((float) $query->time, 1), 'sql' => mb_substr(preg_replace('/\s+/', ' ', $query->sql), 0, 300)];
            usort($this->slowest, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);
            $this->slowest = array_slice($this->slowest, 0, 5);
        }
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->time = 0.0;
        $this->slowest = [];
    }

    public function count(): int
    {
        return $this->count;
    }

    public function time(): float
    {
        return $this->time;
    }

    /** @return list<array{ms: float, sql: string}> */
    public function slowest(): array
    {
        return $this->slowest;
    }
}
