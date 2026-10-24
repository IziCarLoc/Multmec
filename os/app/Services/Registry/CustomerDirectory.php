<?php

namespace App\Services\Registry;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Document;
use App\Support\Phone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Acha ou cria o cliente pelo WhatsApp: o mesmo número é o mesmo cliente (o nome pode vir digitado de
 * jeitos diferentes). Sem WhatsApp não dá para mandar a vistoria nem o orçamento, por isso é obrigatório.
 */
final class CustomerDirectory
{
    public function findOrCreate(string $name, string $whatsapp): Customer
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $digits = Phone::digits($whatsapp);

        if (mb_strlen($name) < 2) {
            throw new DomainException('Informe o nome do cliente.');
        }

        if (! Phone::isValid($digits)) {
            throw new DomainException('Informe o WhatsApp do cliente com DDD (ex.: 55 99197-4167).');
        }

        $existing = Customer::query()->where('whatsapp', $digits)->where('is_izi_fleet', false)->orderBy('id')->first();

        return $existing ?? Customer::create(['name' => $name, 'whatsapp' => $digits]);
    }

    /**
     * "Novo cliente" de Clientes e veículos (como no artefato do Financeiro): o cadastro sem OS nem lançamento, com o
     * carro se vier a placa. WhatsApp já cadastrado não cria outro cliente. Placa já conhecida passa para este cliente
     * e o que foi digitado completa o carro (como ao abrir a OS); carro da frota Izi não muda de dono.
     *
     * @param  array{name?: ?string, whatsapp?: ?string, document?: ?string, email?: ?string, address?: ?string, notes?: ?string, plate?: ?string, brand_model?: ?string, year?: ?string, color?: ?string}  $data
     */
    public function register(array $data): Customer
    {
        $text = fn (string $field, int $max) => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($data[$field] ?? '')) ?? ''), 0, $max);
        $name = $text('name', 120);
        $whatsapp = Phone::digits((string) ($data['whatsapp'] ?? ''));
        $document = Document::clean((string) ($data['document'] ?? ''));
        $plate = trim((string) ($data['plate'] ?? ''));

        if (mb_strlen($name) < 2) {
            throw new DomainException('Informe o nome do cliente.');
        }

        if ($whatsapp !== '' && ! Phone::isValid($whatsapp)) {
            throw new DomainException('WhatsApp com DDD, por exemplo (55) 99999-9999.');
        }

        if ($whatsapp !== '' && $same = Customer::query()->where('whatsapp', $whatsapp)->where('is_izi_fleet', false)->first()) {
            throw new DomainException('Esse WhatsApp já é do cliente '.$same->name.'.');
        }

        if ($document !== '' && ! Document::isValid($document)) {
            throw new DomainException('CPF ou CNPJ inválido.');
        }

        if ($plate !== '' && ! Vehicle::isValidPlate($plate)) {
            throw new DomainException('Placa inválida. Use o padrão antigo (ABC1234) ou o Mercosul (ABC1D23).');
        }

        $vehicle = $plate !== '' ? Vehicle::byPlate($plate) : null;

        if ($vehicle?->is_izi_fleet) {
            throw new DomainException('A placa '.$vehicle->plateFormatted().' é da frota Izi: o cliente dela é a Izi Car.');
        }

        return DB::transaction(function () use ($data, $text, $name, $whatsapp, $document, $plate, $vehicle) {
            $customer = Customer::create([
                'name' => $name,
                'whatsapp' => $whatsapp ?: null,
                'document' => $document ?: null,
                'email' => $text('email', 120) ?: null,
                'address' => $text('address', 255) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            AuditLog::record($customer, 'customer.created', 'Novo cliente em Clientes e veículos');

            if ($plate !== '') {
                $vehicle ??= new Vehicle(['plate' => $plate]);
                $previous = $vehicle->customer_id;

                foreach (['brand_model' => 80, 'year' => 9, 'color' => 30] as $field => $max) {
                    if ($text($field, $max) !== '') {
                        $vehicle->{$field} = $text($field, $max);
                    }
                }

                $vehicle->customer_id = $customer->id;
                $vehicle->save();
                AuditLog::record($vehicle, $previous ? 'vehicle.customer_changed' : 'vehicle.created', 'Novo cliente '.$customer->name, $previous ? ['customer_id' => [$previous, $customer->id]] : []);
            }

            return $customer;
        });
    }

    /** Por que o cliente não pode ser excluído (null = pode): quem tem OS ou lançamento fica para o histórico. */
    public function deleteBlocker(Customer $customer): ?string
    {
        if ($customer->is_izi_fleet) {
            return 'O cliente Izi Car não se exclui: é o dono dos carros da frota.';
        }

        if ($customer->serviceOrders()->exists() || $customer->receivables()->exists()) {
            return 'Este cliente tem OS ou lançamentos no "A receber": o cadastro fica para o histórico.';
        }

        return null;
    }

    /** Exclui o cadastro sem histórico; os carros dele ficam, sem cliente. */
    public function delete(Customer $customer): void
    {
        if ($reason = $this->deleteBlocker($customer)) {
            throw new DomainException($reason);
        }

        DB::transaction(function () use ($customer) {
            Vehicle::query()->where('customer_id', $customer->id)->update(['customer_id' => null]);
            AuditLog::record($customer, 'customer.deleted', $customer->name);
            $customer->delete();
        });
    }
}
