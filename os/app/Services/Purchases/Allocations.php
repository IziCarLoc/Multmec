<?php

namespace App\Services\Purchases;

use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\GroupCompany;
use App\Models\OrderItem;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\LicensePlate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Destino de cada peça da nota (doc 09 §1: "cada peça da nota precisa ter um destino"): uma OS (com a peça do
 * orçamento, quando escolhida), o estoque, o uso interno, a devolução ao fornecedor ou outra empresa do grupo. Nunca
 * passa da quantidade comprada; o custo vem do rateio da nota (frete e impostos incluídos).
 *
 * O custo da peça na OS vem da nota: quando a peça do orçamento não tinha custo, ou o custo já tinha vindo de nota,
 * ele é refeito pela soma das notas ligadas. Custo digitado nunca é sobrescrito, e OS entregue não muda (o "A receber"
 * já nasceu com o custo do dia): a diferença vira a ocorrência "Custo da OS diferente das notas".
 */
final class Allocations
{
    private const EPSILON = 0.000001;

    public function __construct(private GroupCompanies $companies) {}

    /** @return array{quantity: float, cost_cents: int} o que ainda falta dar destino */
    public function remaining(PurchaseInvoiceItem $item): array
    {
        $allocated = PurchaseAllocation::query()->where('purchase_invoice_item_id', $item->id)->valid()
            ->selectRaw('COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(cost_cents), 0) AS cost')->first();

        return [
            'quantity' => max(0.0, round($item->quantity - (float) $allocated->qty, 4)),
            'cost_cents' => max(0, $item->cost_cents - (int) $allocated->cost),
        ];
    }

    /**
     * Dá destino a (parte de) um item. Sem `quantity`, usa tudo o que ainda não tem destino.
     *
     * @param  array{destination?: ?string, service_order_id?: mixed, os_item_id?: mixed, group_company_id?: mixed, quantity?: mixed, notes?: ?string}  $data
     */
    public function allocate(PurchaseInvoiceItem $item, array $data, User $by): PurchaseAllocation
    {
        $destination = $data['destination'] ?? 'os';

        if (! array_key_exists($destination, PurchaseAllocation::DESTINATIONS)) {
            throw new DomainException('Destino inválido.');
        }

        if ($destination === 'outra_empresa' && ! $by->isGestor()) {
            throw new DomainException('Entregar peça a outra empresa do grupo é decisão do gestor (gera valor a receber).');
        }

        $invoice = $item->invoice;

        if (! $invoice->isActive()) {
            throw new DomainException('A nota '.$invoice->number.' está cancelada: a peça não pode ser aplicada.');
        }

        if (! $invoice->generatesCharge()) {
            throw new DomainException('A nota '.$invoice->number.' é de '.($invoice->purpose === 'devolucao' ? 'devolução' : 'ajuste').': não tem peça para aplicar.');
        }

        $remaining = $this->remaining($item);

        if ($remaining['quantity'] <= self::EPSILON) {
            throw new DomainException('Este item já tem destino para toda a quantidade.');
        }

        $quantity = filled($data['quantity'] ?? null) ? (float) str_replace(',', '.', (string) $data['quantity']) : $remaining['quantity'];

        if ($quantity <= 0) {
            throw new DomainException('A quantidade precisa ser maior que zero.');
        }

        if ($quantity > $remaining['quantity'] + self::EPSILON) {
            throw new DomainException('Só restam '.$item->quantityLabel($remaining['quantity']).' para dar destino.');
        }

        $order = null;
        $orderItem = null;

        if ($destination === 'os') {
            $order = ServiceOrder::find((int) ($data['service_order_id'] ?? 0)) ?? throw new DomainException('Escolha a OS onde a peça foi aplicada.');

            if ($order->stage === Stage::Cancelada) {
                throw new DomainException('A OS '.$order->number.' está cancelada: escolha outra.');
            }

            if (filled($data['os_item_id'] ?? null)) {
                $orderItem = OrderItem::query()->where('service_order_id', $order->id)->find((int) $data['os_item_id'])
                    ?? throw new DomainException('A peça escolhida não é desta OS.');

                if (! $orderItem->isPart()) {
                    throw new DomainException('Escolha uma peça da OS (não um serviço).');
                }
            }
        }

        $company = null;

        if ($destination === 'outra_empresa') {
            $company = GroupCompany::query()->active()->find((int) ($data['group_company_id'] ?? 0))
                ?? throw new DomainException('Escolha a empresa do grupo que ficou com a peça (cadastre em Compras > Grupo).');
        }

        $cost = $quantity >= $remaining['quantity'] - self::EPSILON
            ? $remaining['cost_cents']
            : (int) round($item->cost_cents * $quantity / max($item->quantity, self::EPSILON));

        $allocation = DB::transaction(function () use ($item, $invoice, $destination, $order, $orderItem, $company, $quantity, $cost, $data, $by) {
            $allocation = PurchaseAllocation::create([
                'purchase_invoice_item_id' => $item->id, 'destination' => $destination, 'service_order_id' => $order?->id, 'os_item_id' => $orderItem?->id,
                'group_company_id' => $company?->id, 'quantity' => $quantity, 'cost_cents' => $cost,
                'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 200) ?: null, 'created_by' => $by->id,
            ]);
            AuditLog::record($invoice, 'purchase.part_allocated', null, ['item' => $item->id, 'destino' => $destination, 'os' => $order?->number, 'quantidade' => $quantity, 'custo' => $cost]);

            // Peça comprada no CNPJ da oficina e entregue a outra empresa do grupo: a oficina passa a ter esse custo a receber
            // dela (só com custo de verdade, e se um boleto de empresa do grupo não pagou esta nota: senão contaria duas vezes).
            $paidByCompanySlip = BankSlip::query()->whereNotNull('group_company_id')->whereHas('links', fn ($q) => $q->where('purchase_invoice_id', $invoice->id))->exists();

            if ($company && ! $invoice->group_company_id && $cost > 0 && ! $paidByCompanySlip) {
                $this->companies->settlementFromPart($company, $allocation, 'Peças da nota '.$invoice->number.' ('.$item->description.') entregues à '.$company->name, PurchaseSettings::today(), $by);
            }

            return $allocation;
        });

        if ($orderItem) {
            $this->recalculateItemCost($orderItem, $by);
        }

        return $allocation;
    }

    public function remove(PurchaseAllocation $allocation, User $by): void
    {
        if ($allocation->destination === 'outra_empresa' && ! $by->isGestor()) {
            throw new DomainException('Desfazer a entrega de peça a outra empresa é do gestor (mexe no valor a receber).');
        }

        $orderItem = $allocation->orderItem;

        DB::transaction(function () use ($allocation) {
            $this->companies->removeFromOrigin(allocation: $allocation);          // recusa se a empresa já devolveu parte
            AuditLog::record($allocation->item->invoice, 'purchase.part_unallocated', null, ['item' => $allocation->purchase_invoice_item_id, 'destino' => $allocation->destination, 'custo' => $allocation->cost_cents]);
            $allocation->delete();
        });

        if ($orderItem) {
            $this->recalculateItemCost($orderItem, $by);
        }
    }

    /** Toda a nota (o que ainda não tem destino) para uma OS: o caso comum de compra feita para um carro só. */
    public function allocateInvoiceToOrder(PurchaseInvoice $invoice, ServiceOrder $order, User $by): int
    {
        return DB::transaction(function () use ($invoice, $order, $by) {
            $count = 0;

            foreach ($invoice->items as $item) {
                if ($this->remaining($item)['quantity'] > self::EPSILON) {
                    $this->allocate($item, ['destination' => 'os', 'service_order_id' => $order->id, 'os_item_id' => $this->matchingPart($order, $item)?->id], $by);
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Sugere a OS de cada item: pelo pedido (xPed, ex.: "OS1043") ou pela placa escrita nas informações da nota ou do
     * item. Só aceita o pedido se exatamente uma OS bater; placa citada em mais de uma OS fica "ambígua" (escolha à mão).
     *
     * @return list<array<string, mixed>>
     */
    public function suggestions(PurchaseInvoice $invoice): array
    {
        $issued = CarbonImmutable::parse($invoice->issue_date);
        $orders = ServiceOrder::query()->with('vehicle:id,plate')
            ->where('stage', '!=', Stage::Cancelada->value)
            ->whereBetween('entry_date', [$issued->subDays(45)->toDateString(), $issued->addDays(10)->toDateString()])
            ->orderByDesc('entry_date')->get(['id', 'number', 'vehicle_id', 'entry_date', 'stage']);
        $invoicePlates = $this->plates((string) $invoice->additional_info);
        $out = [];

        foreach ($invoice->items as $item) {
            $remaining = $this->remaining($item);

            if ($remaining['quantity'] <= self::EPSILON) {
                continue;
            }

            $found = null;
            $groups = array_values(array_filter(array_map(fn ($g) => ltrim($g, '0'), preg_match_all('/\d+/', (string) $item->customer_order, $m) ? $m[0] : []), fn ($g) => strlen($g) >= 3));

            if ($groups !== []) {
                $matching = $orders->filter(fn ($o) => in_array(ltrim((string) preg_replace('/\D/', '', (string) $o->number), '0'), $groups, true))->values();

                if ($matching->count() === 1) {
                    $found = ['order' => $matching[0], 'reason' => 'pedido '.$item->customer_order.' = OS '.$matching[0]->number, 'ambiguous' => false];
                } elseif ($matching->count() > 1) {
                    $found = ['order' => $matching[0], 'reason' => 'pedido '.$item->customer_order.' bate com mais de uma OS', 'ambiguous' => true];
                }
            }

            $plates = [...$invoicePlates, ...$this->plates((string) $item->additional_info)];

            if (! $found && $plates !== []) {
                $candidates = $orders->filter(fn ($o) => $o->vehicle && in_array(LicensePlate::normalize((string) $o->vehicle->plate), $plates, true))
                    ->sortBy(fn ($o) => abs($o->entry_date->diffInDays($issued)))->values();

                if ($candidates->isNotEmpty()) {
                    $found = ['order' => $candidates[0], 'reason' => 'placa '.$candidates[0]->vehicle->plate.' citada na nota', 'ambiguous' => $candidates->count() > 1];
                }
            }

            if ($found) {
                $out[] = [
                    'item_id' => $item->id, 'description' => $item->description, 'quantity' => $remaining['quantity'], 'cost_cents' => $remaining['cost_cents'],
                    'order_id' => $found['order']->id, 'order_number' => $found['order']->number, 'plate' => $found['order']->vehicle?->plate,
                    'os_item_id' => $this->matchingPart($found['order'], $item)?->id, 'reason' => $found['reason'], 'ambiguous' => $found['ambiguous'],
                ];
            }
        }

        return $out;
    }

    /** Aplica só o que o sistema realmente sugeriu, e sem dúvida (a sugestão ambígua é escolha à mão). */
    public function applySuggestions(PurchaseInvoice $invoice, User $by): int
    {
        return DB::transaction(function () use ($invoice, $by) {
            $count = 0;

            foreach ($this->suggestions($invoice) as $suggestion) {
                if ($suggestion['ambiguous']) {
                    continue;
                }

                $this->allocate(PurchaseInvoiceItem::findOrFail($suggestion['item_id']), [
                    'destination' => 'os', 'service_order_id' => $suggestion['order_id'], 'os_item_id' => $suggestion['os_item_id'],
                ], $by);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Refaz o custo da peça da OS pela soma das notas ligadas a ela, quando o custo não foi digitado (estava vazio ou já
     * tinha vindo de nota) e a OS ainda não foi entregue nem cancelada.
     */
    public function recalculateItemCost(OrderItem $orderItem, ?User $by = null): void
    {
        $orderItem->refresh();
        $order = $orderItem->serviceOrder;

        if (! $order || in_array($order->stage, [Stage::Entregue, Stage::Cancelada], true)) {
            return;
        }

        $sum = (int) PurchaseAllocation::query()->where('os_item_id', $orderItem->id)->where('destination', 'os')
            ->whereHas('item.invoice', fn ($q) => $q->chargeable())->sum('cost_cents');
        $before = $orderItem->cost_cents;

        if ($sum > 0 && ($orderItem->cost_cents === null || $orderItem->cost_from_invoice)) {
            $unit = (int) round($sum / max((float) $orderItem->quantity, 0.01));
            $orderItem->update(['cost_cents' => $unit, 'cost_from_invoice' => true]);
        } elseif ($sum === 0 && $orderItem->cost_from_invoice) {
            $orderItem->update(['cost_cents' => null, 'cost_from_invoice' => false]);
        }

        if ($orderItem->cost_cents !== $before) {
            AuditLog::record($order, 'purchase.item_cost_from_invoice', null, ['item' => $orderItem->id, 'antes' => $before, 'depois' => $orderItem->cost_cents]);
        }
    }

    /** Nota cancelada deixa de contar e nota reativada volta a contar no custo das peças das OS. */
    public function recalculateForInvoice(PurchaseInvoice $invoice): void
    {
        OrderItem::query()->whereIn('id', PurchaseAllocation::query()->whereNotNull('os_item_id')
            ->whereIn('purchase_invoice_item_id', $invoice->items()->select('id'))->select('os_item_id'))
            ->get()->each(fn (OrderItem $item) => $this->recalculateItemCost($item));
    }

    /** A peça do orçamento da OS que corresponde ao item da nota: mesmo código, ou a única peça do mesmo fornecedor. */
    public function matchingPart(ServiceOrder $order, PurchaseInvoiceItem $item): ?OrderItem
    {
        $parts = OrderItem::query()->where('service_order_id', $order->id)->where('kind', OrderItem::KIND_PART)->get();
        $code = $this->code($item->code);

        if ($code !== '') {
            $sameCode = $parts->filter(fn (OrderItem $p) => $this->code($p->part_code) === $code);

            if ($sameCode->count() === 1) {
                return $sameCode->first();
            }
        }

        $sameSupplier = $parts->where('supplier_id', $item->invoice->supplier_id);

        return $sameSupplier->count() === 1 ? $sameSupplier->first() : null;
    }

    /** @return list<string> placas (chave de comparação) citadas no texto */
    private function plates(string $text): array
    {
        preg_match_all('/\b([A-Z]{3}-?[0-9][A-Z0-9][0-9]{2})\b/', mb_strtoupper($text), $matches);

        return array_values(array_unique(array_map(fn ($p) => LicensePlate::normalize($p), $matches[1])));
    }

    private function code(?string $code): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', mb_strtoupper((string) $code));
    }
}
