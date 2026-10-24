<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de quem mudou o quê (ajustes, usuários e, depois, as OS). Não se edita: só `created_at`.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'reason',
        'changes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public static function record(Model $subject, string $action, ?string $reason = null, array $changes = []): self
    {
        return self::create([
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'reason' => $reason,
            'changes' => $changes === [] ? null : $changes,
            'user_id' => auth()->id(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'user.created' => 'Usuário criado',
            'user.updated' => 'Usuário alterado',
            'user.password_reset' => 'Senha trocada pelo gestor',
            'unit.updated' => 'Dados da oficina alterados',
            'settings.updated' => 'Ajustes alterados',
            'purchase.slip_created' => 'Boleto cadastrado',
            'purchase.slip_corrected' => 'Boleto corrigido',
            'purchase.slip_linked' => 'Boleto ligado à nota',
            'purchase.slip_unlinked' => 'Ligação com a nota desfeita',
            'purchase.slip_paid' => 'Boleto pago',
            'purchase.slip_paid_released' => 'Boleto pago com ressalva',
            'purchase.slip_payment_undone' => 'Pagamento desfeito',
            'purchase.slip_canceled' => 'Boleto cancelado',
            'purchase.slip_contested' => 'Boleto contestado',
            'purchase.slip_reopened' => 'Boleto reaberto',
            'purchase.slip_company' => 'Empresa do boleto definida',
            'purchase.slip_filled_by_dda' => 'Completado pelo DDA',
            'purchase.occurrence_accepted' => 'Ocorrência aceita',
            'purchase.occurrence_unaccepted' => 'Aceite desfeito',
            'purchase.invoice_created' => 'Nota lançada',
            'purchase.invoice_completed_xml' => 'Nota completada pelo XML',
            'purchase.invoice_key' => 'Chave da nota informada',
            'purchase.invoice_portal_check' => 'Consulta no portal da NF-e',
            'purchase.invoice_canceled', 'purchase.invoice_canceled_xml' => 'Nota cancelada',
            'purchase.invoice_reactivated' => 'Nota reativada',
            'purchase.invoice_deleted' => 'Nota apagada',
            'purchase.part_allocated' => 'Destino da peça',
            'purchase.part_unallocated' => 'Destino da peça desfeito',
            'purchase.item_cost_from_invoice' => 'Custo da peça vindo da nota',
            'supplier.confirmed' => 'Fornecedor conferido',
            'supplier.deleted' => 'Fornecedor excluído',
            'customer.created' => 'Cliente cadastrado',
            'customer.deleted' => 'Cliente excluído',
            'vehicle.created' => 'Veículo cadastrado',
            'vehicle.customer_changed' => 'Veículo passou para outro cliente',
            'supplier.unconfirmed' => 'Conferência do fornecedor desfeita',
            'supplier.authorized_payees' => 'Recebedores autorizados',
            default => $this->action,
        };
    }
}
