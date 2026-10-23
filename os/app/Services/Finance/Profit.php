<?php

namespace App\Services\Finance;

use App\Models\ProviderPayment;
use App\Models\Receivable;

/**
 * Lucro de um serviço cobrado (aprovado no plano). As fórmulas ficam só aqui:
 *  - lucro bruto  = valor total − custo das peças − frete − prestador externo;
 *  - lucro da peça = (valor total − mão de obra) − custo das peças − frete.
 * "Cliente trouxe a peça" zera o custo das peças. O atendimento nunca vê lucro nem margem (só o gestor).
 */
final class Profit
{
    public function gross(int $totalCents, int $partsCents, int $freightCents, int $providerCents = 0): int
    {
        return $totalCents - $partsCents - $freightCents - $providerCents;
    }

    public function part(int $totalCents, int $laborCents, int $partsCents, int $freightCents): int
    {
        return ($totalCents - $laborCents) - $partsCents - $freightCents;
    }

    /**
     * Custo do prestador externo do lançamento: os pagamentos de prestador da mesma OS (lançados na entrega),
     * divididos entre as parcelas na proporção do valor de cada uma. Lançamento sem OS não tem prestador.
     */
    public function providerCost(Receivable $receivable): int
    {
        if ($receivable->service_order_id === null) {
            return 0;
        }

        $provider = (int) ProviderPayment::query()->where('service_order_id', $receivable->service_order_id)->sum('amount_cents');

        if ($provider === 0) {
            return 0;
        }

        $orderTotal = (int) Receivable::query()->where('service_order_id', $receivable->service_order_id)->sum('total_cents');

        return $orderTotal > 0 ? (int) round($provider * $receivable->total_cents / $orderTotal) : 0;
    }

    public function grossFor(Receivable $receivable): int
    {
        return $this->gross($receivable->total_cents, $this->partsCost($receivable), $receivable->freight_cents, $this->providerCost($receivable));
    }

    public function partFor(Receivable $receivable): int
    {
        return $this->part($receivable->total_cents, $receivable->labor_cents, $this->partsCost($receivable), $receivable->freight_cents);
    }

    /**
     * Parte do lucro bruto que já entrou com um pagamento: proporcional ao que foi pago (o Painel soma o lucro
     * no mês em que o dinheiro entrou, como o artefato).
     */
    public function realizedBy(Receivable $receivable, int $paidCents): int
    {
        if ($receivable->total_cents <= 0) {
            return 0;
        }

        return (int) round($this->grossFor($receivable) * $paidCents / $receivable->total_cents);
    }

    private function partsCost(Receivable $receivable): int
    {
        return $receivable->customer_brought_part ? 0 : $receivable->parts_cost_cents;
    }
}
