<?php

namespace App\Services\Finance;

use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\ProviderPayment;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\LicensePlate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pagamentos a prestadores externos por OS realizada (o "Douglas" do artefato e os outros). Entram no Painel
 * como saída de caixa (Pago) na data em que forem pagos.
 */
final class ProviderPayments
{
    public function __construct(private SupplierPicker $suppliers) {}

    /** @param array<string, mixed> $data supplier_id ou supplier_name, order_number, description, plate, amount_cents, due_date, paid_on, notes */
    public function create(array $data, User $by): ProviderPayment
    {
        return DB::transaction(function () use ($data, $by) {
            $attributes = $this->normalize($data);
            $payment = ProviderPayment::create($attributes + [
                'paid_by' => $attributes['paid_on'] ? $by->id : null,
                'created_by' => $by->id,
            ]);
            AuditLog::record($payment, 'provider_payment.created');

            return $payment;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ProviderPayment $payment, array $data, User $by): ProviderPayment
    {
        return DB::transaction(function () use ($payment, $data, $by) {
            $attributes = $this->normalize($data);

            if ($attributes['paid_on'] === null) {
                $attributes['paid_by'] = null;
            } elseif ($payment->paid_on?->toDateString() !== $attributes['paid_on']) {
                $attributes['paid_by'] = $by->id;
            }

            $payment->fill($attributes);
            $changes = array_keys($payment->getDirty());
            $payment->save();
            AuditLog::record($payment, 'provider_payment.updated', null, $changes);

            return $payment->fresh();
        });
    }

    public function markPaid(ProviderPayment $payment, string $paidOn, User $by): ProviderPayment
    {
        if ($payment->isPaid()) {
            throw new DomainException('Este pagamento já foi feito.');
        }

        $payment->update(['paid_on' => FinanceInput::date($paidOn, 'Informe a data do pagamento.'), 'paid_by' => $by->id]);
        AuditLog::record($payment, 'provider_payment.paid', null, ['paid_on' => $paidOn]);

        return $payment->fresh();
    }

    public function delete(ProviderPayment $payment, User $by): void
    {
        AuditLog::record($payment, 'provider_payment.deleted', null, ['supplier_id' => $payment->supplier_id, 'amount_cents' => $payment->amount_cents]);
        $payment->delete();
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
            SupplierKind::Servico,
            mustProvideService: true,
        );
        $order = filled($data['service_order_id'] ?? null) ? ServiceOrder::find((int) $data['service_order_id']) : null;
        $amount = FinanceInput::cents($data['amount_cents'] ?? null, 'valor');

        if ($amount <= 0) {
            throw new DomainException('Informe o valor.');
        }

        $plate = LicensePlate::clean((string) ($data['plate'] ?? '')) ?: ($order?->vehicle?->plate);

        return [
            'supplier_id' => $supplier->id,
            'service_order_id' => $order?->id,
            'order_number' => Str::limit(trim((string) ($data['order_number'] ?? '')) ?: (string) $order?->number, 20, '') ?: null,
            'description' => Str::limit(trim((string) ($data['description'] ?? '')), 200, '') ?: null,
            'plate' => $plate ? Str::limit($plate, 10, '') : null,
            'amount_cents' => $amount,
            'due_date' => FinanceInput::date((string) ($data['due_date'] ?? ''), 'Informe o vencimento.'),
            'paid_on' => filled($data['paid_on'] ?? null) ? FinanceInput::date((string) $data['paid_on'], 'Data do pagamento inválida.') : null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }
}
