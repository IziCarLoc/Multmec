<?php

namespace App\Services\Registry;

use App\Models\Customer;
use App\Support\Phone;
use DomainException;

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
}
