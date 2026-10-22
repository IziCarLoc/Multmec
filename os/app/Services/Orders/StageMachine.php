<?php

namespace App\Services\Orders;

use App\Enums\Stage;
use App\Models\ServiceOrder;
use App\Models\StageTransition;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Único jeito de mudar a etapa da OS. Confere, nesta ordem:
 *  1. se a OS ainda está na etapa que a tela mostrava (duas pessoas ao mesmo tempo: a segunda é avisada);
 *  2. se o caminho existe e se o perfil pode fazê-lo;
 *  3. o "Falta para avançar" (StageGate).
 * E grava a trilha (de onde, para onde, quem, quando, por quê).
 */
final class StageMachine
{
    /**
     * Caminhos para a frente e quem pode fazê-los (Gate; o gestor passa em todos). "a|b": qualquer um dos dois.
     *
     * @var array<string, array<string, string>>
     */
    public const FORWARD = [
        'aberta' => ['em_vistoria' => 'atendimento'],
        'em_vistoria' => ['em_diagnostico' => 'atendimento|oficina'],
        'em_diagnostico' => ['aguardando_cotacao' => 'oficina', 'aguardando_aprovacao' => 'oficina'],
        'aguardando_cotacao' => ['aguardando_aprovacao' => 'atendimento'],
        'aguardando_aprovacao' => ['aprovada' => 'atendimento', 'recusada' => 'atendimento'],
        'aprovada' => ['aguardando_pecas' => 'atendimento|oficina', 'em_execucao' => 'oficina'],
        'aguardando_pecas' => ['em_execucao' => 'oficina'],
        'em_execucao' => ['em_conferencia' => 'oficina'],
        'em_conferencia' => ['pronta_retirada' => 'oficina', 'em_execucao' => 'oficina'],
        'pronta_retirada' => ['entregue' => 'atendimento'],
        'recusada' => ['pronta_retirada' => 'atendimento'],
    ];

    /** Rótulo do botão de cada caminho. */
    public const ACTIONS = [
        'aberta>em_vistoria' => 'Iniciar vistoria',
        'em_vistoria>em_diagnostico' => 'Concluir vistoria',
        'em_diagnostico>aguardando_cotacao' => 'Enviar para cotação',
        'em_diagnostico>aguardando_aprovacao' => 'Enviar para orçamento (sem peças a cotar)',
        'aguardando_cotacao>aguardando_aprovacao' => 'Cotação pronta: enviar orçamento',
        'aguardando_aprovacao>aprovada' => 'Registrar aceite',
        'aguardando_aprovacao>recusada' => 'Registrar recusa',
        'aprovada>aguardando_pecas' => 'Aguardar peças',
        'aprovada>em_execucao' => 'Iniciar serviço',
        'aguardando_pecas>em_execucao' => 'Iniciar serviço',
        'em_execucao>em_conferencia' => 'Concluir serviço',
        'em_conferencia>pronta_retirada' => 'Liberar para retirada',
        'em_conferencia>em_execucao' => 'Corrigir (volta para execução)',
        'pronta_retirada>entregue' => 'Registrar entrega',
        'recusada>pronta_retirada' => 'Carro pronto para devolver',
    ];

    public function __construct(private readonly StageGate $gate) {}

    /**
     * Caminhos para a frente que esta pessoa pode fazer agora (os botões da tela).
     *
     * @return list<Stage>
     */
    public function targetsFor(ServiceOrder $order, User $user): array
    {
        return collect(self::FORWARD[$order->stage->value] ?? [])
            ->filter(fn (string $ability) => $this->can($user, $ability))
            ->keys()
            ->map(fn (string $to) => Stage::from($to))
            ->values()
            ->all();
    }

    public static function actionLabel(Stage $from, Stage $to): string
    {
        return self::ACTIONS[$from->value.'>'.$to->value] ?? $to->label();
    }

    /** Avança (ou devolve da conferência para a execução, que é o CORRIGIR). */
    public function move(ServiceOrder $order, Stage $expectedFrom, Stage $to, User $by, ?string $reason = null): ServiceOrder
    {
        $reason = $this->clean($reason);

        if ($expectedFrom === Stage::EmConferencia && $to === Stage::EmExecucao && $reason === null) {
            throw new DomainException('Diga o que precisa ser corrigido.');
        }

        return $this->transition($order, $expectedFrom, $to, $by, $reason, function (ServiceOrder $fresh) use ($to, $by) {
            $ability = self::FORWARD[$fresh->stage->value][$to->value] ?? null;

            if ($ability === null) {
                throw new DomainException('A OS está em "'.$fresh->stage->label().'" e não pode ir direto para "'.$to->label().'".');
            }

            if (! $this->can($by, $ability)) {
                throw new DomainException('O seu perfil não faz este passo ("'.self::actionLabel($fresh->stage, $to).'").');
            }

            $missing = $this->gate->missing($fresh, $to);

            if ($missing !== []) {
                throw new DomainException(StageGate::sentence($missing));
            }
        });
    }

    /** Volta uma ou mais etapas: só o gestor, sempre com motivo. Entregue e Cancelada não voltam. */
    public function back(ServiceOrder $order, Stage $expectedFrom, Stage $to, User $by, ?string $reason): ServiceOrder
    {
        $reason = $this->clean($reason);

        if (! $by->isGestor()) {
            throw new DomainException('Só o gestor volta a OS de etapa.');
        }

        if ($reason === null || mb_strlen($reason) < 5) {
            throw new DomainException('Escreva o motivo para voltar a etapa.');
        }

        return $this->transition($order, $expectedFrom, $to, $by, $reason, function (ServiceOrder $fresh) use ($to) {
            if (! in_array($to, self::backTargets($fresh->stage), true)) {
                throw new DomainException('A OS em "'.$fresh->stage->label().'" não volta para "'.$to->label().'".');
            }
        });
    }

    public function cancel(ServiceOrder $order, Stage $expectedFrom, User $by, ?string $reason): ServiceOrder
    {
        $reason = $this->clean($reason);

        if (! $this->can($by, 'atendimento')) {
            throw new DomainException('Só o atendimento ou o gestor cancela a OS.');
        }

        if ($reason === null || mb_strlen($reason) < 5) {
            throw new DomainException('Escreva o motivo do cancelamento.');
        }

        return $this->transition($order, $expectedFrom, Stage::Cancelada, $by, $reason, function (ServiceOrder $fresh) {
            if ($fresh->stage->isFinal()) {
                throw new DomainException('A OS já está '.mb_strtolower($fresh->stage->label()).': não dá para cancelar.');
            }
        });
    }

    /**
     * Etapas anteriores para onde o gestor pode voltar a OS.
     *
     * @return list<Stage>
     */
    public static function backTargets(Stage $from): array
    {
        if ($from->isFinal() || $from === Stage::Aberta) {
            return [];
        }

        // Recusada fica fora do caminho: volta para antes da aprovação (o cliente mudou de ideia).
        $limit = $from === Stage::Recusada ? Stage::AguardandoAprovacao->position() + 1 : $from->position();

        return array_values(array_slice(Stage::path(), 0, $limit));
    }

    /**
     * @param  callable(ServiceOrder): void  $check
     */
    private function transition(ServiceOrder $order, Stage $expectedFrom, Stage $to, User $by, ?string $reason, callable $check): ServiceOrder
    {
        return DB::transaction(function () use ($order, $expectedFrom, $to, $by, $reason, $check) {
            $fresh = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($fresh->stage !== $expectedFrom) {
                throw new DomainException($this->movedByOther($fresh));
            }

            $check($fresh);

            $attributes = ['stage' => $to, 'stage_changed_at' => now()];

            if ($to === Stage::Entregue) {
                $attributes['delivered_at'] = now();
            }

            if ($to === Stage::Cancelada) {
                $attributes['cancelled_at'] = now();
                $attributes['cancel_reason'] = $reason;
            }

            $fresh->update($attributes);

            StageTransition::create([
                'service_order_id' => $fresh->id,
                'from_stage' => $expectedFrom,
                'to_stage' => $to,
                'user_id' => $by->id,
                'reason' => $reason,
            ]);

            return $fresh;
        });
    }

    /** "A Giovana já moveu esta OS para Em diagnóstico (14:32)." */
    private function movedByOther(ServiceOrder $fresh): string
    {
        $last = StageTransition::query()->with('user')->where('service_order_id', $fresh->id)->orderByDesc('id')->first();
        $who = $last?->user?->short_name ?? 'Outra pessoa';

        return $who.' já moveu esta OS para "'.$fresh->stage->label().'"'.($last?->created_at ? ' às '.$last->created_at->format('H:i') : '').'. A tela foi atualizada.';
    }

    private function can(User $user, string $ability): bool
    {
        return collect(explode('|', $ability))->contains(fn (string $one) => Gate::forUser($user)->allows($one));
    }

    private function clean(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
