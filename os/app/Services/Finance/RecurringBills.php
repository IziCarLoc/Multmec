<?php

namespace App\Services\Finance;

use App\Enums\ExpenseCategory;
use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Payable;
use App\Models\RecurringBill;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contas fixas (o `gerarRecorrentes` do financeiro em Node): cada modelo ativo gera a conta do mês no "A pagar",
 * com o fornecedor, a descrição, o valor e a categoria dele, vencendo no dia do modelo (no fim do mês quando o
 * dia não existe).
 *  - Nunca duplica: a conta guarda o modelo e o mês (par único) e o modelo guarda o último mês gerado, então a
 *    conta excluída no A pagar não volta sozinha.
 *  - Gera o mês atual e o próximo, como o Node: no botão "Gerar contas do mês", ao salvar um modelo e todo dia no
 *    agendador (`routes/console.php`). Mês passado não ganha conta retroativa.
 *  - Mudar o modelo vale dali para a frente: as contas já geradas não mudam.
 */
final class RecurringBills
{
    public function __construct(private SupplierPicker $suppliers) {}

    /**
     * Cria ou edita o modelo.
     *
     * @param  array<string, mixed>  $data  supplier_id ou supplier_name (novo), description, category, due_day,
     *                                      amount (texto digitado ou centavos), first_month (AAAA-MM), active, notes
     */
    public function save(?RecurringBill $bill, array $data, User $by): RecurringBill
    {
        $description = Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($data['description'] ?? '')) ?? ''), 200, '');
        $category = ExpenseCategory::tryFrom((string) ($data['category'] ?? ''));
        $day = filter_var(trim((string) ($data['due_day'] ?? '')), FILTER_VALIDATE_INT);
        $amount = FinanceInput::cents($data['amount'] ?? null, 'valor');
        $firstMonth = trim((string) ($data['first_month'] ?? '')) ?: ($bill?->first_month ?? self::firstMonthFor((int) $day));

        if (mb_strlen($description) < 2) {
            throw new DomainException('Escreva a descrição da conta (ex.: Aluguel).');
        }

        if ($category === null) {
            throw new DomainException('Escolha a categoria da conta.');
        }

        if ($day === false || $day < 1 || $day > 31) {
            throw new DomainException('O dia do vencimento vai de 1 a 31.');
        }

        if ($amount <= 0) {
            throw new DomainException('Informe o valor mensal.');
        }

        if (! Month::isValid($firstMonth)) {
            throw new DomainException('Informe o mês da primeira conta.');
        }

        return DB::transaction(function () use ($bill, $data, $description, $category, $day, $amount, $firstMonth, $by) {
            $supplier = $this->suppliers->resolve(
                filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null,
                (string) ($data['supplier_name'] ?? ''),
                SupplierKind::Pecas,
            );
            $attributes = [
                'supplier_id' => $supplier->id,
                'description' => $description,
                'category' => $category,
                'due_day' => $day,
                'amount_cents' => $amount,
                'first_month' => $firstMonth,
                'active' => (bool) ($data['active'] ?? true),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ];

            if ($bill === null) {
                $bill = RecurringBill::create($attributes + ['created_by' => $by->id]);
                AuditLog::record($bill, 'recurring_bill.created');
            } else {
                // Primeiro mês trazido para antes do que já foi gerado: os meses dele voltam a gerar (sem duplicar).
                if ($bill->generated_through !== null && $firstMonth <= $bill->generated_through && $firstMonth < $bill->first_month) {
                    $attributes['generated_through'] = Month::shift($firstMonth, -1);
                }

                $bill->fill($attributes);
                $changes = $bill->getDirty();
                $bill->save();
                AuditLog::record($bill, 'recurring_bill.updated', null, array_keys($changes));
            }

            return $bill;
        });
    }

    /** Pausa ou reativa (o modelo nunca se apaga: as contas geradas continuam ligadas a ele). */
    public function toggle(RecurringBill $bill, User $by): RecurringBill
    {
        $bill->update(['active' => ! $bill->active]);
        AuditLog::record($bill, 'recurring_bill.toggled', null, ['ativo' => $bill->active]);

        return $bill;
    }

    /** Gera as contas do mês (AAAA-MM) que faltam. Devolve quantas criou. */
    public function generate(string $month, ?User $by = null): int
    {
        if (! Month::isValid($month)) {
            throw new DomainException('Mês inválido.');
        }

        $created = 0;

        foreach (RecurringBill::query()->active()->where('first_month', '<=', $month)->orderBy('id')->get() as $bill) {
            $created += DB::transaction(function () use ($bill, $month, $by) {
                $bill = RecurringBill::query()->whereKey($bill->id)->lockForUpdate()->first();

                if (! $bill || ! $bill->active || ($bill->generated_through !== null && $bill->generated_through >= $month)) {
                    return 0;
                }

                $exists = Payable::query()->where('recurring_bill_id', $bill->id)->where('competence', $month)->exists();

                if (! $exists) {
                    $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
                    $payable = Payable::create([
                        'supplier_id' => $bill->supplier_id,
                        'description' => $bill->description,
                        'due_date' => $start->setDay(min($bill->due_day, $start->daysInMonth))->toDateString(),
                        'amount_cents' => $bill->amount_cents,
                        'notes' => 'Conta fixa de '.Month::label($month).'.',
                        'status' => Payable::STATUS_OPEN,
                        'recurring_bill_id' => $bill->id,
                        'competence' => $month,
                        'category' => $bill->category,
                        'created_by' => $by?->id,
                    ]);
                    AuditLog::record($payable, 'payable.created', 'Conta fixa');
                }

                $bill->update(['generated_through' => $month]);

                return $exists ? 0 : 1;
            });
        }

        return $created;
    }

    /** O mês atual e o próximo (o botão, o salvar do modelo e o agendador). */
    public function generateDue(?User $by = null, ?CarbonImmutable $today = null): int
    {
        $current = Month::current($today);

        return $this->generate($current, $by) + $this->generate(Month::shift($current, 1), $by);
    }

    /** Primeiro mês de um modelo novo: o atual, se o dia ainda não passou; senão o próximo (sem conta já vencida). */
    public static function firstMonthFor(int $dueDay, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        return $dueDay >= $today->day ? $today->format('Y-m') : Month::shift($today->format('Y-m'), 1);
    }
}
