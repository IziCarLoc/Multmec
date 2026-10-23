<?php

namespace App\Services\Finance;

use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Payable;
use App\Models\User;
use App\Services\Purchases\PaymentLock;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contas a pagar (boletos de fornecedor). Parcelas como no artefato: o vencimento de cada uma é digitado e o
 * valor informado é o de cada parcela; quem preferir informa o total e ele é dividido em centavos exatos.
 * Cada parcela é um boleto separado: editar uma nunca apaga nem recria as outras.
 */
final class Payables
{
    public function __construct(private SupplierPicker $suppliers) {}

    /**
     * @param  array<string, mixed>  $data  supplier_id ou supplier_name (novo), document_number, description,
     *                                      issue_date, amount_cents, notes; sem parcelas: status e paid_on
     * @param  list<string>  $dueDates  uma data = um boleto; duas ou mais = parcelas
     * @param  bool  $amountIsTotal  o valor é o total, a dividir (senão é o de cada parcela, como no artefato)
     * @return Collection<int, Payable>
     */
    public function create(array $data, array $dueDates, User $by, bool $amountIsTotal = false): Collection
    {
        $dueDates = array_values(array_filter(array_map(fn ($d) => trim((string) $d), $dueDates), fn ($d) => $d !== ''));
        $count = count($dueDates);

        if ($count === 0) {
            throw new DomainException('Informe o vencimento.');
        }

        if ($count > Installments::MAX) {
            throw new DomainException('O número de parcelas vai de 2 a '.Installments::MAX.'.');
        }

        $dueDates = array_map(fn ($d) => FinanceInput::date($d, 'Data de vencimento inválida.'), $dueDates);
        $amount = FinanceInput::cents($data['amount_cents'] ?? null, 'valor');

        if ($amount <= 0) {
            throw new DomainException('Informe o valor.');
        }

        $amounts = $count > 1 && $amountIsTotal ? Installments::split($amount, $count) : array_fill(0, $count, $amount);

        return DB::transaction(function () use ($data, $dueDates, $amounts, $count, $by) {
            $attributes = $this->normalize($data);
            [$status, $paidOn] = $count === 1 ? $this->payment($data) : [Payable::STATUS_OPEN, null];
            $group = $count > 1 ? (string) Str::uuid() : null;
            $created = collect();

            foreach ($dueDates as $i => $dueDate) {
                $payable = Payable::create([
                    ...$attributes,
                    'due_date' => $dueDate,
                    'amount_cents' => $amounts[$i],
                    'installment_group' => $group,
                    'installment_number' => $group ? $i + 1 : null,
                    'installment_total' => $group ? $count : null,
                    'status' => $status,
                    'paid_on' => $paidOn,
                    'paid_by' => $paidOn ? $by->id : null,
                    'created_by' => $by->id,
                ]);
                AuditLog::record($payable, 'payable.created');
                $created->push($payable);
            }

            return $created;
        });
    }

    /**
     * Edita só este boleto (a parcela nunca mexe nas outras).
     *
     * @param  array<string, mixed>  $data  os mesmos campos de create, mais due_date, status e paid_on
     */
    public function update(Payable $payable, array $data, User $by): Payable
    {
        return DB::transaction(function () use ($payable, $data, $by) {
            $attributes = $this->normalize($data);
            $attributes['due_date'] = FinanceInput::date((string) ($data['due_date'] ?? ''), 'Informe o vencimento.');
            $attributes['amount_cents'] = FinanceInput::cents($data['amount_cents'] ?? null, 'valor');

            if ($attributes['amount_cents'] <= 0) {
                throw new DomainException('Informe o valor.');
            }

            [$status, $paidOn] = $this->payment($data);
            $attributes += ['status' => $status, 'paid_on' => $paidOn];

            // Boleto que passou pela conferência de compras: a trava de pagamento (App\Services\Purchases\PaymentLock).
            app(PaymentLock::class)->beforeUpdate($payable, $attributes, $by);

            if ($paidOn !== null && (! $payable->isPaid() || $payable->paid_on?->toDateString() !== $paidOn)) {
                $attributes['paid_by'] = $by->id;
            } elseif ($paidOn === null) {
                $attributes['paid_by'] = null;
            }

            $payable->fill($attributes);
            $changes = array_keys($payable->getDirty());
            $payable->save();
            AuditLog::record($payable, 'payable.updated', null, $changes);
            app(PaymentLock::class)->afterUpdate($payable, $by);

            return $payable->fresh();
        });
    }

    /** "Marcar como pago" (da lista ou da janela). */
    public function markPaid(Payable $payable, string $paidOn, User $by): Payable
    {
        if ($payable->isPaid()) {
            throw new DomainException('Este boleto já está pago.');
        }

        app(PaymentLock::class)->beforeSettle($payable, $by);
        $payable->update(['status' => Payable::STATUS_PAID, 'paid_on' => FinanceInput::date($paidOn, 'Informe a data do pagamento.'), 'paid_by' => $by->id]);
        AuditLog::record($payable, 'payable.paid', null, ['paid_on' => $paidOn]);
        app(PaymentLock::class)->afterSettle($payable, $by);

        return $payable->fresh();
    }

    public function delete(Payable $payable, User $by): void
    {
        app(PaymentLock::class)->beforeDelete($payable);
        AuditLog::record($payable, 'payable.deleted', null, ['supplier_id' => $payable->supplier_id, 'amount_cents' => $payable->amount_cents, 'due_date' => $payable->due_date->toDateString()]);
        $payable->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $supplier = $this->suppliers->resolve(
            filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null,
            (string) ($data['supplier_name'] ?? ''),
            SupplierKind::Pecas,
        );

        return [
            'supplier_id' => $supplier->id,
            'document_number' => Str::limit(trim((string) ($data['document_number'] ?? '')), 60, '') ?: null,
            'description' => Str::limit(trim((string) ($data['description'] ?? '')), 200, '') ?: null,
            'issue_date' => filled($data['issue_date'] ?? null) ? FinanceInput::date((string) $data['issue_date'], 'Data de emissão inválida.') : null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: ?string} situação e data do pagamento
     */
    private function payment(array $data): array
    {
        if (($data['status'] ?? Payable::STATUS_OPEN) !== Payable::STATUS_PAID) {
            return [Payable::STATUS_OPEN, null];
        }

        return [Payable::STATUS_PAID, FinanceInput::date((string) ($data['paid_on'] ?? ''), 'Informe a data do pagamento.')];
    }
}
