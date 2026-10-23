<?php

namespace App\Services\Purchases;

use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Document;
use DomainException;

/**
 * Fornecedor na conferência de compras: o mesmo cadastro da cotação e do "A pagar". Acha pelo CNPJ (o que vem do
 * XML e da chave) ou pelo nome; um CNPJ novo num fornecedor que não tinha CNPJ desfaz a conferência dele; mesmo
 * nome com outro CNPJ é outra empresa (não funde). Conferir o fornecedor e autorizar outros recebedores é do gestor.
 */
final class PurchaseSuppliers
{
    public function ensure(?string $name, ?string $cnpj): Supplier
    {
        $cnpj = Document::normalizeCnpj($cnpj) ?: null;

        if ($cnpj && ($byCnpj = Supplier::query()->where('document', $cnpj)->orderBy('id')->first())) {
            return $byCnpj;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        if (mb_strlen($name) < 2) {
            throw new DomainException('Informe o nome do fornecedor.');
        }

        $byName = Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();

        if ($byName) {
            if (! $byName->document) {
                if ($cnpj) {
                    $wasConfirmed = $byName->isConfirmed();
                    $byName->update(['document' => $cnpj]);      // o modelo desfaz a conferência
                    AuditLog::record($byName, 'supplier.document_from_purchase', $wasConfirmed ? 'CNPJ novo: a conferência foi desfeita' : null, ['document' => $cnpj]);
                }

                return $byName;
            }

            if (! $cnpj || $byName->document === $cnpj) {
                return $byName;
            }

            // Mesmo nome, CNPJ diferente: outra empresa (filial usa o CNPJ completo).
            $root = $name.' ('.substr($cnpj, 0, 8).')';
            $name = Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($root)])->exists() ? $name.' ('.$cnpj.')' : $root;
        }

        $supplier = Supplier::create(['name' => mb_substr($name, 0, 120), 'kind' => SupplierKind::Pecas, 'document' => $cnpj, 'active' => true]);
        AuditLog::record($supplier, 'supplier.created', 'Cadastrado pela conferência de compras');

        return $supplier;
    }

    /** "Conferi": o gestor viu o CNPJ (cartão CNPJ) e confirmou o telefone por um número que a oficina já tinha. */
    public function confirm(Supplier $supplier, bool $confirmed, User $by): Supplier
    {
        $this->onlyGestor($by, 'Conferir o fornecedor é do gestor.');

        if ($confirmed && ! $supplier->document) {
            throw new DomainException('Cadastre o CNPJ do fornecedor antes de marcar como conferido.');
        }

        $supplier->update(['confirmed_at' => $confirmed ? now() : null, 'confirmed_by' => $confirmed ? $by->id : null]);
        AuditLog::record($supplier, $confirmed ? 'supplier.confirmed' : 'supplier.unconfirmed', null, ['document' => $supplier->document]);

        return $supplier;
    }

    /** Outros CNPJs que podem receber os boletos deste fornecedor (filial, banco, factoring): só depois de confirmar por telefone. */
    public function setAuthorizedPayees(Supplier $supplier, string $text, User $by): Supplier
    {
        $this->onlyGestor($by, 'Autorizar outro CNPJ a receber os boletos é do gestor.');
        $list = [];

        foreach (preg_split('/[,;\s]+/', $text) ?: [] as $raw) {
            $cnpj = Document::normalizeCnpj($raw);

            if ($cnpj === '') {
                continue;
            }

            if (! Document::isValidCnpj($cnpj)) {
                throw new DomainException('O CNPJ '.$raw.' não passa na validação (confira os números).');
            }

            $list[$cnpj] = true;
        }

        $before = $supplier->authorized_payees;
        $supplier->update(['authorized_payees' => $list === [] ? null : implode(',', array_keys($list))]);
        AuditLog::record($supplier, 'supplier.authorized_payees', null, ['antes' => $before, 'depois' => $supplier->authorized_payees]);

        return $supplier;
    }

    private function onlyGestor(User $by, string $message): void
    {
        if (! $by->isGestor()) {
            throw new DomainException($message);
        }
    }
}
