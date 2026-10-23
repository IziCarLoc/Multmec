<?php

namespace App\Services\Finance;

use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Supplier;
use DomainException;

/**
 * Fornecedor (ou prestador) de um lançamento do Financeiro: o escolhido da lista ou, como no artefato, um novo
 * pelo nome. O nome que já existe (sem diferença de maiúsculas) é o mesmo cadastro, nunca um repetido.
 * O cadastro é o mesmo da cotação (/fornecedores), onde se completam WhatsApp, Pix etc.
 */
final class SupplierPicker
{
    public function resolve(?int $supplierId, ?string $newName, SupplierKind $kindForNew, bool $mustProvideService = false): Supplier
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $newName) ?? '');

        if ($supplierId) {
            $supplier = Supplier::find($supplierId) ?? throw new DomainException('Fornecedor não encontrado.');
        } elseif ($name !== '') {
            if (mb_strlen($name) < 2) {
                throw new DomainException('Informe o nome do fornecedor.');
            }

            $supplier = Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();

            if (! $supplier) {
                $supplier = Supplier::create(['name' => $name, 'kind' => $kindForNew, 'active' => true]);
                AuditLog::record($supplier, 'supplier.created', 'Cadastrado pelo Financeiro');
            }
        } else {
            throw new DomainException($mustProvideService ? 'Escolha o prestador.' : 'Escolha o fornecedor.');
        }

        if ($mustProvideService && ! $supplier->kind->providesService()) {
            throw new DomainException($supplier->name.' está cadastrado só como fornecedor de peças. Em Fornecedores, marque que ele presta serviço.');
        }

        return $supplier;
    }
}
