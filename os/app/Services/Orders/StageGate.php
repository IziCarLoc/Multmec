<?php

namespace App\Services\Orders;

use App\Enums\Stage;
use App\Models\ServiceOrder;

/**
 * "Falta para avançar": o que precisa estar preenchido para a OS ir para a etapa. A tela mostra esta lista
 * (cada item leva à aba e ao campo) e o StageMachine confere a mesma lista na hora de avançar.
 *
 * Cada entrega acrescenta as regras dela (vistoria, itens, cotação, aceite, execução, conferência, termo).
 */
final class StageGate
{
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
            case Stage::AguardandoCotacao:
            case Stage::AguardandoAprovacao:
                if ($order->stage === Stage::EmDiagnostico) {
                    $need(filled(trim((string) $order->diagnosis)), 'Diagnóstico (em linguagem de cliente)', 'problema', 'diagnosis');
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
