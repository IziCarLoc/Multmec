<?php

namespace App\Services\Finance;

use App\Enums\LossCause;
use App\Models\AuditLog;
use App\Models\Loss;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

/**
 * Prejuízos lançados à mão (peça avariada, erro de serviço, perda...). O que nasce da baixa de um "A receber"
 * (Receivables::writeOff) não se exclui nem muda de valor ou data: o lançamento baixado nunca reabre.
 */
final class Losses
{
    /** @param array<string, mixed> $data occurred_on, amount_cents, item, cause, notes */
    public function create(array $data, User $by): Loss
    {
        $loss = Loss::create($this->normalize($data) + ['created_by' => $by->id]);
        AuditLog::record($loss, 'loss.created');

        return $loss;
    }

    /** @param array<string, mixed> $data */
    public function update(Loss $loss, array $data, User $by): Loss
    {
        $attributes = $this->normalize($data);

        if ($loss->isWriteOff()) {
            if ($attributes['amount_cents'] !== $loss->amount_cents || $attributes['occurred_on'] !== $loss->occurred_on->toDateString()) {
                throw new DomainException('Prejuízo da baixa de um "A receber": o valor e a data não mudam.');
            }

            $attributes['cause'] = LossCause::Inadimplencia;
        }

        $loss->fill($attributes);
        $changes = array_keys($loss->getDirty());
        $loss->save();
        AuditLog::record($loss, 'loss.updated', null, $changes);

        return $loss->fresh();
    }

    public function delete(Loss $loss, User $by): void
    {
        if ($loss->isWriteOff()) {
            throw new DomainException('Prejuízo da baixa de um "A receber" não se exclui: o lançamento baixado não reabre.');
        }

        AuditLog::record($loss, 'loss.deleted', null, ['amount_cents' => $loss->amount_cents, 'item' => $loss->item]);
        $loss->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $item = trim((string) ($data['item'] ?? ''));

        if ($item === '') {
            throw new DomainException('Descreva a peça ou o item.');
        }

        $amount = FinanceInput::cents($data['amount_cents'] ?? null, 'valor');

        if ($amount <= 0) {
            throw new DomainException('Informe o valor.');
        }

        $cause = LossCause::tryFrom((string) ($data['cause'] ?? '')) ?? throw new DomainException('Escolha a causa.');

        return [
            'occurred_on' => FinanceInput::date((string) ($data['occurred_on'] ?? ''), 'Informe a data.'),
            'amount_cents' => $amount,
            'item' => Str::limit($item, 200, ''),
            'cause' => $cause,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }
}
