<?php

namespace App\Services\Registry;

use App\Models\AuditLog;
use App\Models\Supplier;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Excluir fornecedor (o "Excluir" do artefato do Financeiro). Aqui o boleto, a OS e a cotação apontam para o cadastro,
 * então só sai o fornecedor que nunca foi usado (lançado por engano); o que já tem histórico fica e sai das listas
 * desmarcando "Ativo".
 */
final class SupplierDirectory
{
    /** Tabelas com `supplier_id` (boletos, prestadores, contas fixas, compras, itens e cotações da OS). */
    private const USED_IN = ['payables', 'provider_payments', 'recurring_bills', 'purchase_invoices', 'bank_slips', 'os_items', 'quote_sends', 'quote_responses'];

    /** Por que o fornecedor não pode ser excluído (null = pode). */
    public function deleteBlocker(Supplier $supplier): ?string
    {
        foreach (self::USED_IN as $table) {
            if (DB::table($table)->where('supplier_id', $supplier->id)->exists()) {
                return 'Este fornecedor já tem boletos, OS ou cotações: o cadastro fica para o histórico. Desmarque "Ativo" para ele sair das listas.';
            }
        }

        return null;
    }

    public function delete(Supplier $supplier): void
    {
        if ($reason = $this->deleteBlocker($supplier)) {
            throw new DomainException($reason);
        }

        DB::transaction(function () use ($supplier) {
            AuditLog::record($supplier, 'supplier.deleted', $supplier->name);
            $supplier->delete();
        });
    }
}
