<?php

namespace App\Services\Execution;

use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\ServiceOrder;
use App\Models\TimeEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Apontamento de tempo: Iniciar e Pausar na OS em execução. Um mecânico tem um apontamento aberto por vez
 * (iniciar outra OS pausa a anterior). Quem apontou tempo na OS não confere o serviço dela.
 */
final class WorkLog
{
    public function start(ServiceOrder $order, User $by): TimeEntry
    {
        if (! Gate::forUser($by)->allows('oficina')) {
            throw new DomainException('O apontamento é do mecânico.');
        }

        if ($order->stage !== Stage::EmExecucao) {
            throw new DomainException('Iniciar o serviço: a OS precisa estar "Em execução" (o Próximo passo "Iniciar serviço" já começa o apontamento).');
        }

        return DB::transaction(function () use ($order, $by) {
            $open = TimeEntry::query()->open()->where('user_id', $by->id)->lockForUpdate()->get();
            $current = $open->firstWhere('service_order_id', $order->id);

            if ($current) {
                return $current;
            }

            foreach ($open as $other) {
                $other->update(['ended_at' => now()]);
            }

            $entry = TimeEntry::create(['service_order_id' => $order->id, 'user_id' => $by->id, 'started_at' => now()]);
            AuditLog::record($order, 'work.started', null, ['apontamento' => $entry->id]);

            return $entry;
        });
    }

    public function pause(ServiceOrder $order, User $by): int
    {
        $closed = TimeEntry::query()->open()->where('service_order_id', $order->id)->where('user_id', $by->id)->update(['ended_at' => now()]);

        if ($closed === 0) {
            throw new DomainException('Você não tem apontamento aberto nesta OS.');
        }

        AuditLog::record($order, 'work.paused', null, []);

        return $closed;
    }

    /** @return Collection<int, TimeEntry> */
    public function open(ServiceOrder $order): Collection
    {
        return TimeEntry::query()->open()->with('user')->where('service_order_id', $order->id)->get();
    }

    public function isRunning(ServiceOrder $order, User $user): bool
    {
        return TimeEntry::query()->open()->where('service_order_id', $order->id)->where('user_id', $user->id)->exists();
    }

    /** Apontou tempo nesta OS (não pode conferir). */
    public function workedOn(ServiceOrder $order, User $user): bool
    {
        return TimeEntry::query()->where('service_order_id', $order->id)->where('user_id', $user->id)->exists();
    }

    /**
     * Minutos por pessoa nesta OS.
     *
     * @return Collection<int, array{user: User, minutes: int, auto_closed: bool}>
     */
    public function totals(ServiceOrder $order): Collection
    {
        return TimeEntry::query()->with('user')->where('service_order_id', $order->id)->get()
            ->groupBy('user_id')
            ->map(fn (Collection $entries) => [
                'user' => $entries->first()->user,
                'minutes' => (int) $entries->sum(fn (TimeEntry $e) => $e->minutes()),
                'auto_closed' => $entries->contains('auto_closed', true),
            ])->values();
    }

    /** Fim do dia: fecha o que ficou aberto e marca para conferir (o horário real fica para o gestor ajustar). */
    public function closeForgotten(): int
    {
        return TimeEntry::query()->open()->update(['ended_at' => now(), 'auto_closed' => true]);
    }
}
