<?php

namespace App\Services\Orders;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Edição dos dados da OS fora da troca de etapa, com o antes e o depois na auditoria. OS entregue ou
 * cancelada não se edita (defeito depois da entrega vira retorno em garantia).
 */
final class OrderEditor
{
    /**
     * Dados do atendimento: carro, cliente, datas.
     *
     * @param  array{brand_model?: ?string, color?: ?string, year?: ?string, customer_name?: ?string, customer_whatsapp?: ?string, entry_date?: ?string, expected_date?: ?string}  $data
     */
    public function details(ServiceOrder $order, array $data, User $by): ServiceOrder
    {
        $this->assertEditable($order);

        if (! Gate::forUser($by)->allows('atendimento')) {
            throw new DomainException('Só o atendimento ou o gestor muda os dados do carro e do cliente.');
        }

        $entry = CarbonImmutable::parse($data['entry_date'] ?? $order->entry_date)->startOfDay();
        $expected = filled($data['expected_date'] ?? null) ? CarbonImmutable::parse($data['expected_date'])->startOfDay() : null;

        if ($expected && $expected->lt($entry)) {
            throw new DomainException('A previsão de entrega não pode ser antes da entrada.');
        }

        $customer = $order->customer;

        if (! $customer->is_izi_fleet) {
            $whatsapp = Phone::digits($data['customer_whatsapp'] ?? $customer->whatsapp);

            if (mb_strlen(trim((string) ($data['customer_name'] ?? $customer->name))) < 2) {
                throw new DomainException('Informe o nome do cliente.');
            }

            if (! Phone::isValid($whatsapp)) {
                throw new DomainException('Informe o WhatsApp do cliente com DDD.');
            }
        }

        return DB::transaction(function () use ($order, $data, $entry, $expected, $customer) {
            $changes = [];
            $track = function ($model, array $values, string $prefix) use (&$changes) {
                $model->fill($values);

                foreach ($model->getDirty() as $field => $value) {
                    $changes[$prefix.$field] = ['antes' => $model->getOriginal($field), 'depois' => $value];
                }

                $model->save();
            };

            $track($order->vehicle, collect($data)->only(['brand_model', 'color', 'year'])->map(fn ($v) => filled($v) ? trim((string) $v) : null)->all(), 'carro.');

            if (! $customer->is_izi_fleet) {
                $track($customer, [
                    'name' => trim((string) ($data['customer_name'] ?? $customer->name)),
                    'whatsapp' => Phone::digits($data['customer_whatsapp'] ?? $customer->whatsapp),
                ], 'cliente.');
            }

            $track($order, ['entry_date' => $entry, 'expected_date' => $expected], '');

            if ($changes !== []) {
                AuditLog::record($order, 'order.updated', null, $this->plain($changes));
            }

            return $order->fresh();
        });
    }

    /** Queixa (atendimento) e diagnóstico (mecânico). */
    public function problem(ServiceOrder $order, ?string $complaint, ?string $diagnosis, User $by): ServiceOrder
    {
        $this->assertEditable($order);
        $values = [];

        if ($complaint !== null && trim($complaint) !== trim((string) $order->complaint)) {
            if (! Gate::forUser($by)->allows('atendimento')) {
                throw new DomainException('A queixa é registrada pelo atendimento.');
            }

            if (mb_strlen(trim($complaint)) < 3) {
                throw new DomainException('Escreva a queixa do cliente.');
            }

            $values['complaint'] = trim($complaint);
        }

        if ($diagnosis !== null && trim($diagnosis) !== trim((string) $order->diagnosis)) {
            if (! Gate::forUser($by)->allows('oficina')) {
                throw new DomainException('O diagnóstico é do mecânico.');
            }

            $values['diagnosis'] = trim($diagnosis) ?: null;
        }

        if ($values !== []) {
            $before = $order->only(array_keys($values));
            $order->update($values);
            AuditLog::record($order, 'order.updated', null, collect($values)->map(fn ($v, $k) => ['antes' => $before[$k], 'depois' => $v])->all());
        }

        return $order;
    }

    /** Responsável pelo conserto: o atendimento escolhe; o mecânico pode assumir. Sempre um usuário. */
    public function responsible(ServiceOrder $order, ?int $userId, User $by): ServiceOrder
    {
        $this->assertEditable($order);
        $target = $userId ? User::query()->active()->find($userId) : null;

        if ($userId && (! $target || ! $target->hasRole(Role::Mecanico))) {
            throw new DomainException('Escolha um mecânico ativo.');
        }

        $canAssign = Gate::forUser($by)->allows('atendimento') || ($by->isMecanico() && $userId === $by->id);

        if (! $canAssign) {
            throw new DomainException('O mecânico só assume a OS para ele mesmo.');
        }

        if ($order->responsible_id !== $userId) {
            AuditLog::record($order, 'order.responsible', null, ['responsavel' => ['antes' => $order->responsible?->short_name, 'depois' => $target?->short_name]]);
            $order->update(['responsible_id' => $userId]);
        }

        return $order->fresh('responsible');
    }

    private function assertEditable(ServiceOrder $order): void
    {
        if ($order->stage->isFinal()) {
            throw new DomainException('A OS está '.mb_strtolower($order->stage->label()).' e não muda mais.');
        }
    }

    private function plain(array $changes): array
    {
        return collect($changes)->map(fn (array $c) => collect($c)->map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : $v)->all())->all();
    }
}
