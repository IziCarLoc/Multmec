<?php

namespace App\Services\Finance;

use App\Enums\LossCause;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Loss;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Registry\CustomerDirectory;
use App\Support\LicensePlate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "A receber": lançar (com ou sem parcelas), editar uma parcela, registrar e remover pagamentos e dar baixa
 * como prejuízo. A situação de cada lançamento sai dos pagamentos (`paid_cents`/`received_on` são o resumo
 * deles, refeito aqui a cada mudança).
 */
final class Receivables
{
    public function __construct(private CustomerDirectory $customers) {}

    /**
     * Lança o serviço. Uma data de vencimento = um lançamento; duas ou mais = parcelas (o total, as peças, a mão
     * de obra e o frete são divididos em centavos exatos, cada parcela com o seu vencimento).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $dueDates
     * @return Collection<int, Receivable>
     */
    public function create(array $data, array $dueDates, User $by): Collection
    {
        $attributes = $this->normalize($data);
        $dueDates = array_values(array_filter(array_map(fn ($d) => trim((string) $d), $dueDates), fn ($d) => $d !== ''));
        $count = count($dueDates);

        if ($count === 0) {
            throw new DomainException('Informe o vencimento.');
        }

        if ($count > Installments::MAX) {
            throw new DomainException('O número de parcelas vai de 2 a '.Installments::MAX.'.');
        }

        $dueDates = array_map(fn ($d) => FinanceInput::date($d, 'Data de vencimento inválida.'), $dueDates);
        $receivedOn = $count === 1 && filled($data['received_on'] ?? null) ? FinanceInput::date((string) $data['received_on'], 'Data do recebimento inválida.') : null;

        return DB::transaction(function () use ($attributes, $dueDates, $count, $receivedOn, $by) {
            $group = $count > 1 ? (string) Str::uuid() : null;
            $split = fn (string $field) => Installments::split($attributes[$field], $count);
            [$totals, $parts, $labor, $freight] = [$split('total_cents'), $split('parts_cost_cents'), $split('labor_cents'), $split('freight_cents')];
            $created = collect();

            foreach ($dueDates as $i => $dueDate) {
                $receivable = Receivable::create([
                    ...$attributes,
                    'total_cents' => $totals[$i],
                    'parts_cost_cents' => $parts[$i],
                    'labor_cents' => $labor[$i],
                    'freight_cents' => $freight[$i],
                    'due_date' => $dueDate,
                    'installment_group' => $group,
                    'installment_number' => $group ? $i + 1 : null,
                    'installment_total' => $group ? $count : null,
                    'created_by' => $by->id,
                ]);
                AuditLog::record($receivable, 'receivable.created');
                $created->push($receivable);
            }

            // Lançado já recebido (à vista): o pagamento integral na data informada.
            if ($receivedOn !== null) {
                $this->storePayment($created->first(), $receivedOn, $created->first()->total_cents, $by);
            }

            return $created->map->fresh();
        });
    }

    /**
     * Edita só este lançamento (uma parcela nunca mexe nas outras). O total não pode ficar abaixo do que já
     * foi recebido; lançamento baixado como prejuízo não muda.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Receivable $receivable, array $data, User $by): Receivable
    {
        return DB::transaction(function () use ($receivable, $data) {
            $receivable = $this->lock($receivable);

            if ($receivable->isWrittenOff()) {
                throw new DomainException('Lançamento baixado como prejuízo não muda.');
            }

            $attributes = $this->normalize($data, $receivable);
            $attributes['due_date'] = FinanceInput::date((string) ($data['due_date'] ?? ''), 'Informe o vencimento.');

            if ($attributes['total_cents'] < $receivable->paid_cents) {
                throw new DomainException('O valor total não pode ficar abaixo do que já foi recebido ('.Money::brl($receivable->paid_cents).').');
            }

            $receivable->fill($attributes);
            $changes = $receivable->getDirty();
            $receivable->save();
            $this->refreshSummary($receivable);
            AuditLog::record($receivable, 'receivable.updated', null, array_keys($changes));

            return $receivable->fresh();
        });
    }

    /** Pagamento parcial ou integral, com a data em que o dinheiro entrou. */
    public function addPayment(Receivable $receivable, string $paidOn, ?int $amountCents, User $by): ReceivablePayment
    {
        $paidOn = FinanceInput::date($paidOn, 'Informe a data do pagamento.');

        return DB::transaction(function () use ($receivable, $paidOn, $amountCents, $by) {
            $receivable = $this->lock($receivable);

            if ($receivable->isWrittenOff()) {
                throw new DomainException('Lançamento baixado como prejuízo não recebe pagamento.');
            }

            $balance = $receivable->balanceCents();

            if ($balance <= 0) {
                throw new DomainException('Este lançamento já foi recebido inteiro.');
            }

            if (($amountCents ?? 0) <= 0) {
                throw new DomainException('Informe o valor pago.');
            }

            if ($amountCents > $balance) {
                throw new DomainException('O pagamento passa do saldo em aberto ('.Money::brl($balance).').');
            }

            return $this->storePayment($receivable, $paidOn, $amountCents, $by);
        });
    }

    /** "Marcar como recebido": paga o saldo que falta, na data informada. */
    public function receiveRemaining(Receivable $receivable, string $paidOn, User $by): ReceivablePayment
    {
        return $this->addPayment($receivable, $paidOn, $receivable->fresh()->balanceCents(), $by);
    }

    public function removePayment(ReceivablePayment $payment, User $by): void
    {
        DB::transaction(function () use ($payment) {
            $receivable = $this->lock($payment->receivable);

            if ($receivable->isWrittenOff()) {
                throw new DomainException('Lançamento baixado como prejuízo não muda: o pagamento fica como está.');
            }

            AuditLog::record($receivable, 'receivable.payment_removed', null, ['paid_on' => $payment->paid_on->toDateString(), 'amount_cents' => $payment->amount_cents]);
            $payment->delete();
            $this->refreshSummary($receivable);
        });
    }

    /**
     * "Lançar como prejuízo": o saldo em aberto de um lançamento vencido vira prejuízo (inadimplência), numa
     * transação só. O lançamento fica no histórico, baixado, e nunca reabre.
     */
    public function writeOff(Receivable $receivable, User $by): Loss
    {
        return DB::transaction(function () use ($receivable, $by) {
            $receivable = $this->lock($receivable);
            $today = CarbonImmutable::today();

            if ($receivable->isWrittenOff()) {
                throw new DomainException('Este lançamento já foi baixado como prejuízo.');
            }

            if ($receivable->received_by_matrix) {
                throw new DomainException('Recebimento pela matriz não vira prejuízo aqui: a cobrança é da matriz.');
            }

            $balance = $receivable->balanceCents();

            if ($balance <= 0) {
                throw new DomainException('Não há saldo em aberto para dar baixa.');
            }

            if (! $receivable->isOverdue($today)) {
                throw new DomainException('Só lançamento vencido pode ser baixado como prejuízo.');
            }

            $customer = $receivable->customer?->name ?? 'sem cliente';
            $loss = Loss::create([
                'occurred_on' => $today->toDateString(),
                'amount_cents' => $balance,
                'item' => Str::limit($receivable->reference().' — '.$customer.' (inadimplência)', 200, ''),
                'cause' => LossCause::Inadimplencia,
                'notes' => 'Baixa do saldo em aberto do "A receber"'.($receivable->plate ? ' · '.$receivable->plate : '').'. Vencido em '.$receivable->due_date->format('d/m/Y').'.',
                'receivable_id' => $receivable->id,
                'created_by' => $by->id,
            ]);

            $receivable->update(['written_off_at' => now(), 'loss_id' => $loss->id, 'written_off_by' => $by->id]);
            AuditLog::record($receivable, 'receivable.written_off', null, ['loss_id' => $loss->id, 'amount_cents' => $balance]);

            return $loss;
        });
    }

    public function delete(Receivable $receivable, User $by): void
    {
        DB::transaction(function () use ($receivable) {
            $receivable = $this->lock($receivable);

            if ($receivable->isWrittenOff()) {
                throw new DomainException('Lançamento baixado como prejuízo fica no histórico: não se exclui.');
            }

            if ($receivable->payments()->exists()) {
                throw new DomainException('Este lançamento tem pagamentos: remova os pagamentos antes de excluir.');
            }

            AuditLog::record($receivable, 'receivable.deleted', null, ['order_number' => $receivable->order_number, 'total_cents' => $receivable->total_cents]);
            $receivable->delete();
        });
    }

    /**
     * Campos do formulário → atributos. Cliente novo entra pelo cadastro de clientes (CustomerDirectory, pelo
     * WhatsApp); a placa liga o carro do cadastro quando existe.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?Receivable $current = null): array
    {
        $order = filled($data['service_order_id'] ?? null) ? ServiceOrder::find((int) $data['service_order_id']) : null;

        $customerId = filled($data['customer_id'] ?? null) ? (int) $data['customer_id'] : null;

        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
            throw new DomainException('Cliente não encontrado.');
        }

        if ($customerId === null && filled($data['customer_name'] ?? null)) {
            $customerId = $this->customers->findOrCreate((string) $data['customer_name'], (string) ($data['customer_whatsapp'] ?? ''))->id;
        }

        $customerId ??= $order?->customer_id ?? $current?->customer_id;

        if ($customerId === null) {
            throw new DomainException('Escolha o cliente (ou cadastre um novo com nome e WhatsApp).');
        }

        $orderNumber = trim((string) ($data['order_number'] ?? '')) ?: ($order ? (string) $order->number : '');

        if ($orderNumber === '') {
            throw new DomainException('Informe o número da OS.');
        }

        $total = FinanceInput::cents($data['total_cents'] ?? null, 'valor total');

        if ($total <= 0) {
            throw new DomainException('Informe o valor total a cobrar.');
        }

        $broughtPart = (bool) ($data['customer_brought_part'] ?? false);
        $plate = LicensePlate::clean((string) ($data['plate'] ?? '')) ?: null;
        $vehicle = $plate ? Vehicle::byPlate($plate) : ($order?->vehicle);
        $plate ??= $vehicle?->plate;
        $method = filled($data['payment_method'] ?? null) ? PaymentMethod::tryFrom((string) $data['payment_method']) : null;

        if (filled($data['payment_method'] ?? null) && $method === null) {
            throw new DomainException('Forma de pagamento inválida.');
        }

        return [
            'service_order_id' => $order?->id ?? $current?->service_order_id,
            'customer_id' => $customerId,
            'vehicle_id' => $vehicle?->id,
            'order_number' => Str::limit($orderNumber, 20, ''),
            'service_date' => filled($data['service_date'] ?? null) ? FinanceInput::date((string) $data['service_date'], 'Data da manutenção inválida.') : null,
            'plate' => $plate ? Str::limit($plate, 10, '') : null,
            'vehicle_model' => Str::limit(trim((string) ($data['vehicle_model'] ?? '')) ?: (string) $vehicle?->brand_model, 80, '') ?: null,
            // Peça trazida pelo cliente: sem custo de peça para a oficina.
            'parts_cost_cents' => $broughtPart ? 0 : FinanceInput::cents($data['parts_cost_cents'] ?? null, 'custo da peça'),
            'labor_cents' => FinanceInput::cents($data['labor_cents'] ?? null, 'mão de obra'),
            'freight_cents' => FinanceInput::cents($data['freight_cents'] ?? null, 'frete'),
            'total_cents' => $total,
            'customer_brought_part' => $broughtPart,
            'received_by_matrix' => (bool) ($data['received_by_matrix'] ?? false),
            'payment_method' => $method?->value,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    private function storePayment(Receivable $receivable, string $paidOn, int $amountCents, User $by): ReceivablePayment
    {
        $payment = $receivable->payments()->create(['paid_on' => $paidOn, 'amount_cents' => $amountCents, 'created_by' => $by->id]);
        $this->refreshSummary($receivable);
        AuditLog::record($receivable, 'receivable.payment_added', null, ['paid_on' => $paidOn, 'amount_cents' => $amountCents]);

        return $payment;
    }

    /** Refaz o resumo dos pagamentos: quanto já entrou e, quando completou, a data do último pagamento. */
    private function refreshSummary(Receivable $receivable): void
    {
        $paid = (int) ReceivablePayment::query()->where('receivable_id', $receivable->id)->sum('amount_cents');
        $last = ReceivablePayment::query()->where('receivable_id', $receivable->id)->max('paid_on');

        $receivable->forceFill([
            'paid_cents' => $paid,
            'received_on' => $paid >= $receivable->total_cents && $last ? substr((string) $last, 0, 10) : null,
        ])->save();
    }

    private function lock(Receivable $receivable): Receivable
    {
        return Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();
    }
}
