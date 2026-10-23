<?php

namespace App\Services\Orders;

use App\Enums\Role;
use App\Enums\Stage;
use App\Models\ServiceOrder;
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

        $orders = ServiceOrder::query()->with(['vehicle', 'customer', 'responsible'])
            ->inStage(...array_map(fn (string $stage) => Stage::from($stage), array_keys($tasks)))
            ->orderBy('stage_changed_at')
            ->get()
            // Conferência é de quem não fez o serviço (a regra completa, por apontamento, vem com a execução).
            ->reject(fn (ServiceOrder $order) => $order->stage === Stage::EmConferencia && $order->responsible_id === $user->id)
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
        $orders = ServiceOrder::query()->with(['vehicle', 'customer', 'responsible'])
            ->inStage(Stage::AguardandoAprovacao, ...Budgets::ADDENDUM_STAGES)
            ->orderBy('stage_changed_at')
            ->get();

        foreach ($orders as $order) {
            $pending = $budgets->pending($order);

            if ($pending && $user->isGestor() && $budgets->aboveAuthority($pending)) {
                $tasks->push(['order' => $order, 'task' => 'Aprovar acima da alçada da Izi ('.Money::brl($pending->total_cents).')']);
            } elseif ($pending && $pending->isAddendum() && ! $user->isGestor()) {
                $tasks->push(['order' => $order, 'task' => 'Cobrar o aceite do adendo']);
            } elseif (! $pending && Budgets::isAddendumStage($order->stage) && ! $user->isGestor() && $budgets->draftItems($order)->isNotEmpty()) {
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
