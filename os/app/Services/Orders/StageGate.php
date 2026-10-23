<?php

namespace App\Services\Orders;

use App\Enums\Stage;
use App\Models\Budget;
use App\Models\ServiceOrder;
use App\Services\Budgets\Budgets;
use App\Services\Inspections\EntryInspection;
use App\Services\Inspections\TechnicalChecklist;
use App\Services\Quotes\Quotations;

/**
 * "Falta para avançar": o que precisa estar preenchido para a OS ir para a etapa. A tela mostra esta lista
 * (cada item leva à aba e ao campo) e o StageMachine confere a mesma lista na hora de avançar.
 *
 * Cada entrega acrescenta as regras dela (vistoria, itens, cotação, aceite, execução, conferência, termo).
 */
final class StageGate
{
    public function __construct(
        private readonly EntryInspection $inspection,
        private readonly TechnicalChecklist $technical,
        private readonly Quotations $quotations,
        private readonly Budgets $budgets,
    ) {}

    /**
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order, Stage $to): array
    {
        $missing = [];
        $need = function (bool $ok, string $label, string $tab, ?string $field = null) use (&$missing) {
            if (! $ok) {
                $missing[] = ['label' => $label, 'tab' => $tab, 'field' => $field];
            }
        };

        switch ($to) {
            case Stage::EmDiagnostico:
                if ($order->stage === Stage::EmVistoria) {
                    // Parte A (atendimento), entrega ao cliente (WhatsApp ou assinatura) e parte B (mecânico).
                    $missing = $this->inspection->missing($order);
                    $need(
                        $this->inspection->deliveredToCustomer($order),
                        $this->inspection->changedAfterDelivery($order)
                            ? 'Enviar a vistoria de novo ao cliente (ela mudou depois do envio ou da assinatura)'
                            : 'Vistoria enviada ao cliente pelo WhatsApp ou assinada na tela',
                        'vistoria',
                        'entrega',
                    );
                    $missing = array_merge($missing, $this->technical->missing($order));
                }
                break;

            case Stage::AguardandoCotacao:
                if ($order->stage === Stage::EmDiagnostico) {
                    $items = $order->items()->get();

                    $need(filled(trim((string) $order->diagnosis)), 'Diagnóstico (em linguagem de cliente)', 'problema', 'diagnosis');
                    $need($items->isNotEmpty(), 'Ao menos uma peça ou serviço', 'itens', 'novo-item');

                    foreach ($items->filter(fn ($item) => $item->isService() && (float) $item->hours <= 0) as $service) {
                        $need(false, 'Horas estimadas: '.$service->description, 'itens', 'item-'.$service->id);
                    }
                }
                break;

            case Stage::AguardandoAprovacao:
                // Enviar o orçamento: vencedor e preço em cada item, previsão de entrega e pagamento.
                if ($order->stage === Stage::AguardandoCotacao) {
                    $missing = $this->budgets->missing($order);
                }
                break;

            case Stage::Aprovada:
            case Stage::Recusada:
                if ($order->stage === Stage::AguardandoAprovacao) {
                    $status = $this->budgets->main($order)?->status;
                    $to === Stage::Aprovada
                        ? $need($status === Budget::STATUS_APPROVED, 'Aceite do cliente registrado (aba Orçamento)', 'orcamento', 'resposta')
                        : $need($status === Budget::STATUS_REFUSED, 'Recusa registrada com o motivo (aba Orçamento)', 'orcamento', 'resposta');
                }
                break;

            default:
                break;
        }

        return $missing;
    }

    /** Lista de "falta" em uma frase, para o aviso de erro. */
    public static function sentence(array $missing): string
    {
        return 'Falta para avançar: '.implode('; ', array_map(fn (array $item) => mb_strtolower(mb_substr($item['label'], 0, 1)).mb_substr($item['label'], 1), $missing)).'.';
    }
}
