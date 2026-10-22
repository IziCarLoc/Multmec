<?php

namespace Tests\Concerns;

use App\Enums\Stage;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\StageTransition;
use App\Models\Unit;
use App\Models\Vehicle;
use Database\Seeders\UnitSeeder;

/**
 * Monta OS para os testes sem passar pela tela: unidade Liberdade, cliente com WhatsApp e carro.
 */
trait BuildsOrders
{
    protected function unit(): Unit
    {
        if (! Unit::query()->where('code', 'LIB')->exists()) {
            $this->seed(UnitSeeder::class);
        }

        return Unit::query()->where('code', 'LIB')->firstOrFail();
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + ['name' => 'Cliente de Teste', 'whatsapp' => '55991974167']);
    }

    protected function vehicle(array $attributes = []): Vehicle
    {
        static $sequence = 0;
        $sequence++;

        return Vehicle::create($attributes + ['plate' => sprintf('TST%d%s%02d', $sequence % 10, chr(65 + $sequence % 26), $sequence % 100), 'brand_model' => 'Onix 1.0', 'color' => 'Branco']);
    }

    /** OS já numa etapa (sem passar pelas travas), com a trilha de abertura. */
    protected function order(Stage $stage = Stage::Aberta, array $attributes = []): ServiceOrder
    {
        static $number = 1200;
        $customer = $this->customer();
        $vehicle = $this->vehicle(['customer_id' => $customer->id]);

        $order = ServiceOrder::create($attributes + [
            'unit_id' => $this->unit()->id,
            'number' => ++$number,
            'stage' => $stage,
            'stage_changed_at' => now(),
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'entry_date' => now()->toDateString(),
            'complaint' => 'Barulho na suspensão dianteira',
        ]);

        StageTransition::create(['service_order_id' => $order->id, 'to_stage' => Stage::Aberta]);

        return $order;
    }

    /** Item lançado direto (sem passar pelo OrderItems): peça a cotar ou serviço com horas. */
    protected function item(ServiceOrder $order, string $kind = OrderItem::KIND_PART, array $attributes = []): OrderItem
    {
        return OrderItem::create($attributes + [
            'service_order_id' => $order->id,
            'kind' => $kind,
            'description' => $kind === OrderItem::KIND_PART ? 'Bieleta dianteira esquerda' : 'Troca da bieleta',
            'quantity' => 1,
            'hours' => $kind === OrderItem::KIND_SERVICE ? 0.5 : null,
            'needs_quote' => $kind === OrderItem::KIND_PART,
            'requires_photos' => $kind === OrderItem::KIND_PART,
            'position' => (int) $order->items()->max('position') + 1,
        ]);
    }
}
