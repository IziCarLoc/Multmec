<?php

namespace App\Services\Orders;

use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\StageTransition;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Registry\CustomerDirectory;
use App\Services\Registry\IziFleet;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Abre a OS (etapa Aberta). Obrigatório: número (o do sistema atual, enquanto a numeração é manual),
 * placa, cliente com nome e WhatsApp (carro da frota Izi entra no cliente Izi Car sozinho) e a queixa
 * nas palavras do cliente. O carro e o cliente são achados pela placa e pelo WhatsApp, ou criados.
 */
final class OrderOpener
{
    public function __construct(private readonly CustomerDirectory $customers) {}

    /**
     * @param  array{number?: int|string|null, plate: string, brand_model?: ?string, color?: ?string, year?: ?string, is_izi_fleet?: bool, customer_id?: ?int, customer_name?: ?string, customer_whatsapp?: ?string, complaint: string, entry_date?: ?string, expected_date?: ?string}  $input
     */
    public function open(Unit $unit, array $input, User $by): ServiceOrder
    {
        $complaint = trim((string) ($input['complaint'] ?? ''));

        if (! Vehicle::isValidPlate($input['plate'] ?? '')) {
            throw new DomainException('Placa inválida. Use o padrão antigo (ABC1234) ou o Mercosul (ABC1D23).');
        }

        if (mb_strlen($complaint) < 3) {
            throw new DomainException('Escreva a queixa do cliente, nas palavras dele.');
        }

        $entry = CarbonImmutable::parse($input['entry_date'] ?? now()->toDateString())->startOfDay();
        $expected = filled($input['expected_date'] ?? null) ? CarbonImmutable::parse($input['expected_date'])->startOfDay() : null;

        if ($expected && $expected->lt($entry)) {
            throw new DomainException('A previsão de entrega não pode ser antes da entrada.');
        }

        return DB::transaction(function () use ($unit, $input, $by, $complaint, $entry, $expected) {
            $number = $this->number($unit, $input['number'] ?? null);
            $vehicle = $this->vehicle($input);
            $customer = $this->customer($vehicle, $input);

            $vehicle->customer_id = $customer->id;
            $vehicle->save();

            $order = ServiceOrder::create([
                'unit_id' => $unit->id,
                'number' => $number,
                'stage' => Stage::Aberta,
                'stage_changed_at' => now(),
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'entry_date' => $entry,
                'expected_date' => $expected,
                'complaint' => $complaint,
                'approver_kind' => $vehicle->is_izi_fleet ? 'izi' : 'cliente',
                'approver_name' => $vehicle->is_izi_fleet ? null : $customer->name,
                'approver_whatsapp' => $vehicle->is_izi_fleet ? null : $customer->whatsapp,
                'received_by' => $by->id,
                'created_by' => $by->id,
            ]);

            StageTransition::create([
                'service_order_id' => $order->id,
                'from_stage' => null,
                'to_stage' => Stage::Aberta,
                'user_id' => $by->id,
            ]);

            AuditLog::record($order, 'order.opened', null, ['numero' => $number, 'placa' => $vehicle->plate]);

            return $order;
        });
    }

    /** Numeração manual: o número digitado (do sistema atual), único na unidade. Automática: o próximo. */
    private function number(Unit $unit, int|string|null $typed): int
    {
        if ($unit->numbersAutomatically()) {
            $locked = Unit::query()->lockForUpdate()->findOrFail($unit->id);
            $next = max((int) $locked->next_number, (int) ServiceOrder::query()->where('unit_id', $unit->id)->max('number') + 1);
            $locked->update(['next_number' => $next + 1]);

            return $next;
        }

        $number = (int) preg_replace('/\D/', '', (string) $typed);

        if ($number <= 0) {
            throw new DomainException('Informe o número da OS (o mesmo do sistema atual).');
        }

        if (ServiceOrder::query()->where('unit_id', $unit->id)->where('number', $number)->exists()) {
            throw new DomainException('Já existe a OS '.$number.' nesta unidade. Confira o número no sistema atual.');
        }

        return $number;
    }

    /** O carro pela placa (antiga ou Mercosul); dado digitado completa ou corrige o cadastro, vazio não apaga. */
    private function vehicle(array $input): Vehicle
    {
        $vehicle = Vehicle::byPlate($input['plate']) ?? new Vehicle(['plate' => $input['plate']]);

        foreach (['brand_model', 'color', 'year'] as $field) {
            if (filled($input[$field] ?? null)) {
                $vehicle->{$field} = trim((string) $input[$field]);
            }
        }

        if (! empty($input['is_izi_fleet'])) {
            $vehicle->is_izi_fleet = true;
        }

        return $vehicle;
    }

    private function customer(Vehicle $vehicle, array $input): Customer
    {
        if ($vehicle->is_izi_fleet) {
            return IziFleet::customer();
        }

        if (! empty($input['customer_id'])) {
            $customer = Customer::query()->findOrFail($input['customer_id']);
            $whatsapp = Phone::digits($input['customer_whatsapp'] ?? '');

            if (Phone::isValid($whatsapp) && $whatsapp !== $customer->whatsapp) {
                $customer->update(['whatsapp' => $whatsapp]);
            }

            if (! Phone::isValid($customer->whatsapp)) {
                throw new DomainException('Informe o WhatsApp do cliente com DDD: é por ele que vão a vistoria e o orçamento.');
            }

            return $customer;
        }

        return $this->customers->findOrCreate((string) ($input['customer_name'] ?? ''), (string) ($input['customer_whatsapp'] ?? ''));
    }
}
