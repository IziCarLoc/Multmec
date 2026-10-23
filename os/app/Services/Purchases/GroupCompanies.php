<?php

namespace App\Services\Purchases;

use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\GroupCompany;
use App\Models\GroupSettlement;
use App\Models\GroupSettlementReturn;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\FinanceInput;
use App\Support\Document;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Empresas do grupo (locadora, oficina do sócio) e o acerto entre elas e a oficina (doc 09 §11). Nota ou boleto no
 * CNPJ de uma empresa cadastrada não é alerta grave, mas também não é despesa da oficina: o boleto dela sai do "A pagar"
 * e, se a oficina pagar, o valor fica a receber da empresa. Tudo aqui é do gestor.
 *
 * Como tratar (mútuo, repasse, venda) é decisão do contador: o sistema só registra o que aconteceu.
 */
final class GroupCompanies
{
    /** Empresa ativa do grupo dona deste CNPJ. A própria oficina nunca é "do grupo". */
    public function companyFor(?string $cnpj): ?GroupCompany
    {
        $cnpj = Document::normalizeCnpj($cnpj);

        if ($cnpj === '' || in_array($cnpj, PurchaseSettings::workshopCnpjs(), true)) {
            return null;
        }

        return GroupCompany::query()->active()->where('cnpj', $cnpj)->first();
    }

    /** @param  array{name?: ?string, cnpj?: ?string, role?: ?string}  $data */
    public function create(array $data, User $by): GroupCompany
    {
        $this->onlyGestor($by);
        $name = trim((string) ($data['name'] ?? ''));
        $cnpj = Document::normalizeCnpj($data['cnpj'] ?? '');

        if ($name === '') {
            throw new DomainException('Informe o nome da empresa.');
        }

        if (! Document::isValidCnpj($cnpj)) {
            throw new DomainException('O CNPJ da empresa não passa na validação (confira os números).');
        }

        if (in_array($cnpj, PurchaseSettings::workshopCnpjs(), true)) {
            throw new DomainException('Este é o CNPJ da própria oficina (Ajustes > Oficina e numeração). Cadastre aqui só as OUTRAS empresas.');
        }

        if (Supplier::query()->where('document', $cnpj)->exists()) {
            throw new DomainException('Este CNPJ já é de um fornecedor. Uma empresa do grupo não pode ser também fornecedor de peças.');
        }

        if (GroupCompany::query()->where('cnpj', $cnpj)->exists()) {
            throw new DomainException('Esta empresa já está cadastrada.');
        }

        $company = GroupCompany::create([
            'name' => mb_substr($name, 0, 80), 'cnpj' => $cnpj, 'created_by' => $by->id, 'active' => true,
            'role' => array_key_exists($data['role'] ?? '', GroupCompany::ROLES) ? $data['role'] : 'outra',
        ]);
        AuditLog::record($company, 'group.company_created', null, ['cnpj' => $cnpj, 'papel' => $company->role]);
        $this->reassign();

        return $company;
    }

    /** @param  array{name?: ?string, role?: ?string, active?: bool}  $data */
    public function update(GroupCompany $company, array $data, User $by): GroupCompany
    {
        $this->onlyGestor($by);
        $before = $company->only(['name', 'role', 'active']);
        $active = array_key_exists('active', $data) ? (bool) $data['active'] : $company->active;

        if (! $active && $company->active && $this->balance($company)['open'] > 0) {
            throw new DomainException('Esta empresa ainda tem acerto em aberto com a oficina. Registre as devoluções antes de desativá-la.');
        }

        $company->update([
            'name' => filled($data['name'] ?? null) ? mb_substr(trim($data['name']), 0, 80) : $company->name,
            'role' => array_key_exists($data['role'] ?? '', GroupCompany::ROLES) ? $data['role'] : $company->role,
            'active' => $active,
        ]);
        AuditLog::record($company, 'group.company_updated', null, ['antes' => $before, 'depois' => $company->only(['name', 'role', 'active'])]);
        $this->reassign();

        return $company;
    }

    /** Depois de cadastrar, ativar ou desativar uma empresa: refaz a empresa das notas e dos boletos em aberto. */
    public function reassign(): void
    {
        $companies = GroupCompany::query()->active()->pluck('id', 'cnpj');
        $workshop = PurchaseSettings::workshopCnpjs();

        PurchaseInvoice::query()->whereNotNull('recipient_cnpj')->orWhereNotNull('group_company_id')->get(['id', 'recipient_cnpj', 'group_company_id'])
            ->each(function (PurchaseInvoice $invoice) use ($companies, $workshop) {
                $target = in_array($invoice->recipient_cnpj, $workshop, true) ? null : ($companies[$invoice->recipient_cnpj] ?? null);

                if ($target !== $invoice->group_company_id) {
                    $invoice->update(['group_company_id' => $target]);
                }
            });

        $slips = app(BankSlips::class);
        BankSlip::query()->where('status', BankSlip::OPEN)->pluck('id')->each(fn ($id) => $slips->applyCompany(BankSlip::find($id)));
    }

    /** A oficina pagou um boleto de outra empresa do grupo: fica a receber. O dinheiro saiu da conta da oficina nesse dia. */
    public function settlementFromSlip(GroupCompany $company, BankSlip $slip, int $amountCents, string $date, User $by): GroupSettlement
    {
        $settlement = GroupSettlement::create([
            'group_company_id' => $company->id, 'direction' => GroupSettlement::RECEIVE, 'source' => 'boleto', 'bank_slip_id' => $slip->id,
            'description' => 'Boleto pago pela oficina'.($slip->document_number ? ' (doc '.mb_substr($slip->document_number, 0, 20).')' : '').', vencimento '.$slip->due_date->format('d/m/Y'),
            'amount_cents' => $amountCents, 'occurred_on' => $date, 'cash' => true, 'created_by' => $by->id,
        ]);
        AuditLog::record($company, 'group.settlement_created', null, ['acerto' => $settlement->id, 'boleto' => $slip->id, 'valor' => $amountCents]);

        return $settlement;
    }

    /** Peça comprada no CNPJ da oficina e entregue a outra empresa do grupo: a oficina tem a receber o custo dela. */
    public function settlementFromPart(GroupCompany $company, PurchaseAllocation $allocation, string $description, string $date, User $by): GroupSettlement
    {
        $settlement = GroupSettlement::create([
            'group_company_id' => $company->id, 'direction' => GroupSettlement::RECEIVE, 'source' => 'peca', 'purchase_allocation_id' => $allocation->id,
            'description' => mb_substr($description, 0, 160), 'amount_cents' => $allocation->cost_cents, 'occurred_on' => $date, 'cash' => false, 'created_by' => $by->id,
        ]);
        AuditLog::record($company, 'group.settlement_created', null, ['acerto' => $settlement->id, 'peca' => $allocation->id, 'valor' => $allocation->cost_cents]);

        return $settlement;
    }

    /**
     * Acerto lançado à mão (que não veio de boleto nem de peça). `cash`: saiu dinheiro da conta da oficina nesse dia
     * (só no "a receber"; no "a pagar" o dinheiro sai na devolução).
     *
     * @param  array{group_company_id?: mixed, direction?: ?string, amount?: mixed, occurred_on?: ?string, description?: ?string, cash?: bool, notes?: ?string}  $data
     */
    public function createManual(array $data, User $by): GroupSettlement
    {
        $this->onlyGestor($by);
        $company = GroupCompany::find((int) ($data['group_company_id'] ?? 0)) ?? throw new DomainException('Escolha a empresa.');
        $direction = ($data['direction'] ?? '') === GroupSettlement::PAY ? GroupSettlement::PAY : GroupSettlement::RECEIVE;
        $amount = FinanceInput::cents($data['amount'] ?? null, 'valor');
        $date = FinanceInput::date((string) ($data['occurred_on'] ?? '') ?: PurchaseSettings::today(), 'Data inválida.');
        $description = trim((string) ($data['description'] ?? ''));

        if ($amount <= 0) {
            throw new DomainException('Informe o valor.');
        }

        if ($date > PurchaseSettings::today()) {
            throw new DomainException('A data não pode ser futura: registre o acerto no dia em que acontecer.');
        }

        if ($description === '') {
            throw new DomainException('Descreva o acerto.');
        }

        $settlement = GroupSettlement::create([
            'group_company_id' => $company->id, 'direction' => $direction, 'source' => 'manual', 'description' => mb_substr($description, 0, 160),
            'amount_cents' => $amount, 'occurred_on' => $date, 'cash' => $direction === GroupSettlement::RECEIVE && ($data['cash'] ?? false) === true,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'created_by' => $by->id,
        ]);
        AuditLog::record($company, 'group.settlement_created', null, ['acerto' => $settlement->id, 'sentido' => $direction, 'valor' => $amount, 'manual' => true]);

        return $settlement;
    }

    public function deleteManual(GroupSettlement $settlement, User $by): void
    {
        $this->onlyGestor($by);

        if ($settlement->source !== 'manual') {
            throw new DomainException('Este valor nasceu de um boleto ou de uma peça e se desfaz lá (desfazendo o pagamento ou o destino da peça).');
        }

        if ($settlement->returns()->exists()) {
            throw new DomainException('Já houve devolução neste valor. Desfaça as devoluções antes de apagar.');
        }

        AuditLog::record($settlement->company, 'group.settlement_deleted', null, ['acerto' => $settlement->id, 'valor' => $settlement->amount_cents, 'descricao' => $settlement->description]);
        $settlement->delete();
    }

    /** Desfaz o acerto que nasceu de um boleto ou de uma peça (pagamento ou destino desfeito). Com devolução feita, recusa. */
    public function removeFromOrigin(?BankSlip $slip = null, ?PurchaseAllocation $allocation = null): void
    {
        $settlements = GroupSettlement::query()
            ->when($slip, fn ($q) => $q->where('source', 'boleto')->where('bank_slip_id', $slip->id))
            ->when($allocation, fn ($q) => $q->where('source', 'peca')->where('purchase_allocation_id', $allocation->id))
            ->get();

        foreach ($settlements as $settlement) {
            if ($settlement->returns()->exists()) {
                throw new DomainException('A empresa já devolveu parte deste valor. Desfaça a devolução em Compras > Grupo antes.');
            }
        }

        foreach ($settlements as $settlement) {
            AuditLog::record($settlement->company, 'group.settlement_undone', null, ['acerto' => $settlement->id, 'valor' => $settlement->amount_cents]);
            $settlement->delete();
        }
    }

    /** Dinheiro que voltou (ou, no "a pagar", que a oficina devolveu). Parcial ou total; nunca passa do que falta. */
    public function addReturn(GroupSettlement $settlement, array $data, User $by): GroupSettlementReturn
    {
        $this->onlyGestor($by);
        $missing = $settlement->balanceCents();

        if ($missing <= 0) {
            throw new DomainException('Este valor já foi acertado por inteiro.');
        }

        $amount = filled($data['amount'] ?? null) ? FinanceInput::cents($data['amount'], 'valor devolvido') : $missing;
        $date = FinanceInput::date((string) ($data['returned_on'] ?? '') ?: PurchaseSettings::today(), 'Data inválida.');

        if ($amount <= 0) {
            throw new DomainException('Informe o valor devolvido.');
        }

        if ($amount > $missing + PurchaseSettings::toleranceCents()) {
            throw new DomainException('Só faltam '.Money::brl($missing).' neste acerto.');
        }

        if ($date > PurchaseSettings::today()) {
            throw new DomainException('A data não pode ser futura: registre a devolução no dia em que o dinheiro voltar.');
        }

        if ($date < $settlement->occurred_on->toDateString()) {
            throw new DomainException('A devolução não pode ser anterior ao dia em que o valor nasceu.');
        }

        $return = $settlement->returns()->create(['returned_on' => $date, 'amount_cents' => min($amount, $missing), 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 200) ?: null, 'created_by' => $by->id]);
        AuditLog::record($settlement->company, 'group.settlement_return', null, ['acerto' => $settlement->id, 'valor' => $return->amount_cents, 'data' => $date]);

        return $return;
    }

    public function deleteReturn(GroupSettlementReturn $return, User $by): void
    {
        $this->onlyGestor($by);
        AuditLog::record($return->settlement->company, 'group.settlement_return_undone', null, ['acerto' => $return->group_settlement_id, 'valor' => $return->amount_cents, 'data' => $return->returned_on->toDateString()]);
        $return->delete();
    }

    /** @return array{receivable: int, payable: int, net: int, open: int} */
    public function balance(GroupCompany $company): array
    {
        $receivable = 0;
        $payable = 0;

        foreach (GroupSettlement::query()->where('group_company_id', $company->id)->with('returns')->get() as $settlement) {
            $settlement->direction === GroupSettlement::RECEIVE ? $receivable += $settlement->balanceCents() : $payable += $settlement->balanceCents();
        }

        return ['receivable' => $receivable, 'payable' => $payable, 'net' => $receivable - $payable, 'open' => $receivable + $payable];
    }

    /**
     * Tudo o que está em aberto entre a oficina e cada empresa, com os acertos atrasados (passaram do prazo combinado).
     *
     * @return array{deadline_days: int, companies: list<array<string, mixed>>, receivable: int, payable: int, late: int}
     */
    public function overview(?string $today = null): array
    {
        $today ??= PurchaseSettings::today();
        $deadline = PurchaseSettings::groupReturnDays();
        $companies = [];
        $late = 0;

        foreach (GroupCompany::query()->orderByDesc('active')->orderBy('name')->get() as $company) {
            $settlements = GroupSettlement::query()->where('group_company_id', $company->id)->with('returns')->orderByDesc('occurred_on')->orderByDesc('id')->get()
                ->map(function (GroupSettlement $s) use ($today, $deadline, &$late) {
                    $days = (int) $s->occurred_on->diffInDays(CarbonImmutable::parse($today));
                    $s->setAttribute('balance_cents', $s->balanceCents());
                    $s->setAttribute('days', $days);
                    $s->setAttribute('late', $s->balanceCents() > 0 && $days > $deadline);
                    $late += $s->late && $s->direction === GroupSettlement::RECEIVE ? $s->balance_cents : 0;

                    return $s;
                });
            $receivable = (int) $settlements->where('direction', GroupSettlement::RECEIVE)->sum('balance_cents');
            $payable = (int) $settlements->where('direction', GroupSettlement::PAY)->sum('balance_cents');
            $companies[] = ['company' => $company, 'settlements' => $settlements, 'receivable' => $receivable, 'payable' => $payable, 'net' => $receivable - $payable];
        }

        return [
            'deadline_days' => $deadline, 'companies' => $companies, 'late' => $late,
            'receivable' => array_sum(array_column($companies, 'receivable')), 'payable' => array_sum(array_column($companies, 'payable')),
        ];
    }

    private function onlyGestor(User $by): void
    {
        if (! $by->isGestor()) {
            throw new DomainException('Empresas do grupo e o acerto entre elas são do gestor.');
        }
    }
}
