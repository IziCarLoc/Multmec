<?php

namespace App\Services\Registry;

use App\Models\Customer;
use App\Support\Settings;

/**
 * A Izi Car é um cliente como os outros, marcado como frota: os carros dela entram nesse cliente
 * sozinhos e o orçamento passa pela alçada (até o valor de Ajustes o Atendimento aprova; acima, o gestor).
 */
final class IziFleet
{
    public static function customer(): Customer
    {
        return Customer::query()->where('is_izi_fleet', true)->orderBy('id')->first()
            ?? Customer::create([
                'name' => (string) Settings::get('izi_customer_name'),
                'whatsapp' => Settings::get('izi_whatsapp') ?: null,
                'is_izi_fleet' => true,
            ]);
    }
}
