<?php

namespace App\Services\Purchases;

use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\Payable;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Trava de pagamento no "A pagar" para o boleto que passou pela conferência de compras (doc 09, "Pagamento: o que o
 * sistema exige"). Quem chama é App\Services\Finance\Payables: marcar como pago (ou editar para pago), voltar para
 * "a pagar", mudar valor, vencimento ou fornecedor e excluir. Conta sem boleto da conferência não muda nada.
 *
 * O pagamento "com ressalva" (pagar mesmo assim com o motivo, valor diferente explicado, conferência no app do banco)
 * é feito em Compras > Boletos (BankSlips::pay), que marca a conta paga junto.
 */
final class PaymentLock
{
    public function __construct(private Occurrences $occurrences, private Dda $dda) {}

    public function slipOf(Payable $payable): ?BankSlip
    {
        return $payable->exists ? BankSlip::query()->where('payable_id', $payable->id)->first() : null;
    }

    /** Antes de marcar como pago: só o gestor, e só sem ocorrência grave aberta e com o recebedor conferido. */
    public function beforeSettle(Payable $payable, User $by): void
    {
        $slip = $this->slipOf($payable);

        if (! $slip) {
            return;
        }

        if (! $by->isGestor()) {
            throw new DomainException('Este boleto passou pela conferência de compras: só o gestor marca como pago.');
        }

        if ($slip->status === BankSlip::CONTESTED) {
            throw new DomainException('Este boleto está contestado em Compras: reabra-o antes de pagar.');
        }

        $cross = $this->dda->cross();
        $serious = array_values(array_filter($this->occurrences->all(null, $cross), fn ($o) => in_array($slip->id, $o['slips'], true) && $o['severity'] === 'alta' && ! $o['accepted']));

        if ($serious !== []) {
            throw new DomainException('Pagamento travado pela conferência de compras: '.$serious[0]['title'].(count($serious) > 1 ? ' (+'.(count($serious) - 1).')' : '')
                .'. Resolva ou aceite a ocorrência com o motivo em Financeiro > Compras, ou pague por Compras > Boletos informando o motivo.');
        }

        $ddaConfirmed = $slip->barcode && ($this->dda->statusBySlip($cross)[$slip->id] ?? null) === 'confirmado';

        if (! $slip->bank_checked_on && ! $ddaConfirmed) {
            throw new DomainException('Pagamento travado: antes de pagar, confira no app do banco o nome e o CNPJ de quem recebe. Pague por Financeiro > Compras > Boletos (lá fica registrada a conferência).');
        }
    }

    /** Depois de marcar como pago no "A pagar": o boleto da conferência fica pago junto. */
    public function afterSettle(Payable $payable, User $by): void
    {
        $slip = $this->slipOf($payable);

        if (! $slip || $slip->isPaid() || ! $payable->isPaid()) {
            return;
        }

        $slip->update(['status' => BankSlip::PAID, 'paid_on' => $payable->paid_on, 'paid_cents' => $payable->amount_cents, 'paid_by' => $by->id]);
        AuditLog::record($slip, 'purchase.slip_paid', 'Pago pelo A pagar', ['valor' => $payable->amount_cents, 'data' => $payable->paid_on?->toDateString()]);
    }

    /**
     * Antes de editar no "A pagar": valor, vencimento e fornecedor vêm do boleto (a conferência compara com a nota e o
     * DDA); voltar um boleto pago para "a pagar" é do gestor.
     *
     * @param  array<string, mixed>  $attributes  como vão ser gravados
     */
    public function beforeUpdate(Payable $payable, array $attributes, User $by): void
    {
        $slip = $this->slipOf($payable);

        if (! $slip) {
            return;
        }

        $changes = (int) $attributes['amount_cents'] !== $payable->amount_cents
            || $attributes['due_date'] !== $payable->due_date->toDateString()
            || (int) $attributes['supplier_id'] !== $payable->supplier_id;

        if ($changes) {
            throw new DomainException('Valor, vencimento e fornecedor deste boleto vêm da conferência de compras: corrija em Financeiro > Compras > Boletos.');
        }

        $becomesPaid = $attributes['status'] === Payable::STATUS_PAID && ! $payable->isPaid();
        $becomesOpen = $attributes['status'] !== Payable::STATUS_PAID && $payable->isPaid();

        if ($becomesPaid) {
            $this->beforeSettle($payable, $by);
        }

        if ($becomesOpen && ! $by->isGestor()) {
            throw new DomainException('Este boleto passou pela conferência de compras: só o gestor desfaz o pagamento.');
        }
    }

    /** Depois de editar no "A pagar": o boleto acompanha a situação da conta (pago ou de volta a pagar). */
    public function afterUpdate(Payable $payable, User $by): void
    {
        $slip = $this->slipOf($payable);

        if (! $slip) {
            return;
        }

        if ($payable->isPaid()) {
            $this->afterSettle($payable, $by);
        } elseif ($slip->isPaid()) {
            $slip->update(['status' => BankSlip::OPEN, 'paid_on' => null, 'paid_cents' => null, 'paid_by' => null, 'released_reason' => null, 'released_on' => null]);
            AuditLog::record($slip, 'purchase.slip_payment_undone', 'Desfeito pelo A pagar');
        }
    }

    public function beforeDelete(Payable $payable): void
    {
        if ($this->slipOf($payable)) {
            throw new DomainException('Este boleto é da conferência de compras: cancele-o em Financeiro > Compras > Boletos (a conta sai junto).');
        }
    }

    /**
     * O selo da conferência para cada conta do "A pagar" que tem boleto em Compras (o mesmo veredito da lista de Boletos).
     *
     * @param  Collection<int, Payable>  $payables
     * @return array<int, array{slip_id: int, level: string, short: string, text: string}> conta => selo
     */
    public function verdicts(Collection $payables): array
    {
        $slips = BankSlip::query()->whereIn('payable_id', $payables->pluck('id'))->with(['supplier:id,name', 'groupCompany:id,name'])->get();

        if ($slips->isEmpty()) {
            return [];
        }

        $cross = $this->dda->cross();
        $all = $this->occurrences->all(null, $cross);
        $dda = $this->dda->statusBySlip($cross);
        $out = [];

        foreach ($slips as $slip) {
            $verdict = $this->occurrences->verdict($slip, array_values(array_filter($all, fn ($o) => in_array($slip->id, $o['slips'], true))), $dda[$slip->id] ?? null);
            $out[$slip->payable_id] = ['slip_id' => $slip->id, ...$verdict];
        }

        return $out;
    }
}
