<?php

namespace App\Services\Orders;

use App\Enums\Role;
use App\Enums\Stage;
use App\Models\Budget;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * "Avisos": o que está esperando por esta pessoa, com o contador no menu. Cada perfil vê o seu passo:
 * o atendimento abre a vistoria, cota, cobra o aceite e entrega; o mecânico faz a parte técnica, o
 * diagnóstico, o serviço e confere o serviço dos outros; o gestor vê as OS paradas há dias.
 * As próximas entregas acrescentam os avisos delas (acima da alçada, serviço sem preço...).
 */
final class Inbox
{
    /** Etapa → o que o atendimento faz nela. */
    private const ATENDIMENTO = [
        'aberta' => 'Iniciar a vistoria',
        'aguardando_cotacao' => 'Cotar e montar o orçamento',
        'aguardando_aprovacao' => 'Cobrar o aceite do cliente',
        'pronta_retirada' => 'Avisar o cliente e entregar',
        'recusada' => 'Devolver o carro',
    ];

    /** Etapa → o que o mecânico faz nela. */
    private const MECANICO = [
        'em_vistoria' => 'Vistoria técnica (parte B)',
        'em_diagnostico' => 'Diagnosticar',
        'aprovada' => 'Iniciar o serviço',
        'aguardando_pecas' => 'Iniciar o serviço (falta peça)',
        'em_execucao' => 'Terminar o serviço',
        'em_conferencia' => 'Conferir o serviço',
    ];

    /** OS parada há mais que isso na mesma etapa aparece para o gestor. */
    public const STALE_DAYS = 3;

    /**
     * @return Collection<int, array{order: ServiceOrder, task: string}>
     */
    public function for(User $user): Collection
    {
        $tasks = match ($user->role) {
            Role::Atendimento => self::ATENDIMENTO,
            Role::Mecanico => self::MECANICO,
            Role::Gestor => [],
        };

        // Conferência é de quem não apontou tempo na OS (as OS em que a pessoa apontou, numa consulta só).
        $worked = TimeEntry::query()->where('user_id', $user->id)->distinct()->pluck('service_order_id')->flip();
        $orders = ServiceOrder::query()->with(['vehicle', 'customer', 'responsible'])
            ->inStage(...array_map(fn (string $stage) => Stage::from($stage), array_keys($tasks)))
            ->orderBy('stage_changed_at')
            ->get()
            ->reject(fn (ServiceOrder $order) => $order->stage === Stage::EmConferencia && $worked->has($order->id))
            ->map(fn (ServiceOrder $order) => ['order' => $order, 'task' => $tasks[$order->stage->value]]);

        if ($user->isGestor()) {
            $orders = ServiceOrder::query()->with(['vehicle', 'customer', 'responsible'])->open()
                ->where('stage_changed_at', '<=', now()->subDays(self::STALE_DAYS))
                ->orderBy('stage_changed_at')
                ->get()
                ->map(fn (ServiceOrder $order) => ['order' => $order, 'task' => 'Parada há '.$order->daysInStage().' dias em "'.$order->stage->label().'"']);
        }

        return $this->budgetTasks($user)->concat($orders)->unique(fn (array $task) => $task['order']->id.$task['task'])->values();
    }

    /**
     * Orçamento: adendo para montar ou esperando resposta (atendimento) e carro da Izi acima da alçada (gestor).
     *
     * @return Collection<int, array{order: ServiceOrder, task: string}>
     */
    private function budgetTasks(User $user): Collection
    {
        if (! $user->can('atendimento')) {
            return collect();
        }

        $budgets = app(Budgets::class);
        $tasks = collect();
        // Lido em lote (o contador do menu roda em toda tela): itens das OS e o orçamento esperando resposta de cada uma
        // (o mesmo que Budgets::pending e draftItems, sem uma consulta por OS).
        $orders = ServiceOrder::query()->with(['vehicle', 'customer', 'responsible', 'items:id,service_order_id,budget_id'])
            ->inStage(Stage::AguardandoAprovacao, ...Budgets::ADDENDUM_STAGES)
            ->orderBy('stage_changed_at')
            ->get();
        $pendingByOrder = Budget::query()->whereIn('service_order_id', $orders->modelKeys())->where('status', Budget::STATUS_SENT)
            ->orderByDesc('number')->get()->unique('service_order_id')->keyBy('service_order_id');

        foreach ($orders as $order) {
            $pending = $pendingByOrder->get($order->id)?->setRelation('serviceOrder', $order);

            if ($pending && $user->isGestor() && $budgets->aboveAuthority($pending)) {
                $tasks->push(['order' => $order, 'task' => 'Aprovar acima da alçada da Izi ('.Money::brl($pending->total_cents).')']);
            } elseif ($pending && $pending->isAddendum() && ! $user->isGestor()) {
                $tasks->push(['order' => $order, 'task' => 'Cobrar o aceite do adendo']);
            } elseif (! $pending && Budgets::isAddendumStage($order->stage) && ! $user->isGestor() && $order->items->contains(fn (OrderItem $item) => ! $item->isFrozen())) {
                $tasks->push(['order' => $order, 'task' => 'Montar o adendo (item novo)']);
            }
        }

        return $tasks;
    }

    public function count(User $user): int
    {
        return $this->for($user)->count();
    }
}
