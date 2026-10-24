<?php

namespace App\Services\Finance;

use App\Models\Payable;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Contas a pagar por mês (o "Por mês" do artefato do Financeiro): pelo mês de vencimento, de emissão ou de pagamento
 * (este só com as pagas). Cada mês com quantas contas, quanto em aberto, quanto pago e o total; o detalhe do mês sai
 * por fornecedor. Conta sem data de emissão fica em "sem data".
 */
final class PayablesByMonth
{
    public const BASES = [
        'vencimento' => 'Por mês de vencimento',
        'emissao' => 'Por mês de emissão',
        'pagamento' => 'Por mês de pagamento',
    ];

    /**
     * @return array{rows: list<array{month: string, label: string, count: int, open: int, paid: int, total: int}>, total: array{count: int, open: int, paid: int, total: int}}
     */
    public function table(string $base): array
    {
        $rows = [];
        $total = ['count' => 0, 'open' => 0, 'paid' => 0, 'total' => 0];

        foreach ($this->byMonth($base) as $month => $items) {
            $paid = (int) $items->filter(fn (Payable $p) => $p->isPaid())->sum('amount_cents');
            $all = (int) $items->sum('amount_cents');
            $row = ['month' => (string) $month, 'label' => self::label((string) $month), 'count' => $items->count(), 'open' => $all - $paid, 'paid' => $paid, 'total' => $all];
            $rows[] = $row;

            foreach (['count', 'open', 'paid', 'total'] as $field) {
                $total[$field] += $row[$field];
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * As contas de um mês, por fornecedor (nome em ordem), cada grupo com o total e as contas pelo vencimento.
     *
     * @return Collection<string, array{cents: int, items: Collection<int, Payable>}>
     */
    public function detail(string $base, string $month): Collection
    {
        return $this->byMonth($base)->get($month, collect())
            ->groupBy(fn (Payable $p) => $p->supplier?->name ?? '(fornecedor removido)')
            ->sortKeys()
            ->map(fn (Collection $items) => [
                'cents' => (int) $items->sum('amount_cents'),
                'items' => $items->sortBy(fn (Payable $p) => $p->due_date->toDateString().'-'.$p->id)->values(),
            ]);
    }

    /** Texto para colar no WhatsApp ou numa planilha (o "Copiar resumo" do artefato). */
    public function summary(string $base): string
    {
        $table = $this->table($base);
        $line = fn (string $label, array $r) => $label.' | '.$r['count'].' | '.Money::brl($r['open']).' | '.Money::brl($r['paid']).' | '.Money::brl($r['total']);

        return implode("\n", [
            'CONTAS A PAGAR - MULTMEC',
            self::BASES[self::base($base)],
            'Mês | Boletos | Em aberto | Pagos | Total',
            ...array_map(fn (array $r) => $line($r['label'], $r), $table['rows']),
            $line('Total', $table['total']),
        ]);
    }

    public static function base(string $base): string
    {
        return array_key_exists($base, self::BASES) ? $base : 'vencimento';
    }

    public static function label(string $month): string
    {
        return $month === '' ? 'sem data' : Month::label($month);
    }

    /** @return Collection<string, Collection<int, Payable>> chave AAAA-MM ('' = sem data), do mês mais novo ao mais antigo */
    private function byMonth(string $base): Collection
    {
        $base = self::base($base);

        return Payable::query()->with('supplier:id,name')->orderBy('due_date')->orderBy('id')->get()
            ->filter(fn (Payable $p) => $base !== 'pagamento' || $p->isPaid())
            ->groupBy(fn (Payable $p) => match ($base) {
                'emissao' => $p->issue_date?->format('Y-m') ?? '',
                'pagamento' => $p->paid_on?->format('Y-m') ?? '',
                default => $p->due_date->format('Y-m'),
            })
            ->sortKeysDesc();
    }
}
