<?php

namespace App\Services\Purchases;

use App\Models\AuditAcceptance;
use App\Models\AuditLog;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceInstallment;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseLink;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\FinanceInput;
use App\Support\Document;
use App\Support\Money;
use App\Support\Purchases\AccessKey;
use App\Support\Purchases\NfeReader;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Notas fiscais de compra (doc 09 §2 e §12): importar o XML (nunca duplica: a chave é única; o XML que chega depois
 * completa a nota digitada), digitar a nota sem XML (com a chave da DANFE, conferida contra o que foi digitado),
 * informar a chave depois, registrar a consulta no portal da NF-e (só a do gestor, com o mesmo valor, vale como
 * prova), cancelar/reativar e apagar. Dinheiro em centavos.
 *
 * Porte de `app/src/compras.js` (parte das notas).
 */
final class Invoices
{
    public function __construct(private PurchaseSuppliers $suppliers, private GroupCompanies $companies, private Allocations $allocations) {}

    /**
     * Importa um XML de NF-e (ou de cancelamento).
     *
     * @return array{status: string, invoice_id: ?int, warnings: list<string>}
     */
    public function importXml(string $xml, User $by): array
    {
        $read = NfeReader::read($xml);

        if ($read['type'] === 'cancelamento') {
            $invoice = PurchaseInvoice::query()->where('access_key', $read['key'])->first()
                ?? throw new DomainException('Este é o cancelamento de uma nota que ainda não foi importada. Importe primeiro o XML da nota.');

            DB::transaction(function () use ($invoice) {
                $invoice->update(['status' => PurchaseInvoice::CANCELED, 'canceled_source' => 'sefaz']);
                AuditLog::record($invoice, 'purchase.invoice_canceled_xml');
            });
            $this->allocations->recalculateForInvoice($invoice);

            return ['status' => 'cancelada', 'invoice_id' => $invoice->id, 'warnings' => []];
        }

        return DB::transaction(function () use ($read, $xml, $by) {
            $supplier = $this->suppliers->ensure($read['trade_name'] ?: $read['issuer_name'], $read['issuer_cnpj']);
            $stored = base64_encode((string) gzencode($xml, 9));

            // Nota digitada (com ou sem chave) que agora chegou em XML: o XML completa a nota em vez de ser descartado.
            $manual = PurchaseInvoice::query()->where('supplier_id', $supplier->id)->where('number', $read['number'])->where('source', PurchaseInvoice::SOURCE_MANUAL)
                ->where(fn ($q) => $q->whereNull('access_key')->orWhere('access_key', $read['key']))->first();

            if ($manual) {
                return $this->completeManual($manual, $read, $stored, $by);
            }

            $existing = PurchaseInvoice::query()->where('access_key', $read['key'])->first()
                ?? PurchaseInvoice::query()->where('supplier_id', $supplier->id)->where('number', $read['number'])->where('series', $read['series'])->first();

            if ($existing) {
                $warnings = $existing->xml_sha256 && $existing->xml_sha256 !== $read['hash']
                    ? ['Já existia uma nota com esta chave, mas o conteúdo do XML é DIFERENTE do que foi importado antes. Desconfie.']
                    : [];

                return ['status' => 'ja_existia', 'invoice_id' => $existing->id, 'warnings' => $warnings];
            }

            $invoice = $this->insert($supplier, $read + ['source' => PurchaseInvoice::SOURCE_XML, 'xml_gz' => $stored], $by);

            return ['status' => 'importada', 'invoice_id' => $invoice->id, 'warnings' => $read['warnings']];
        });
    }

    /**
     * Vários arquivos de uma vez: um arquivo ruim não derruba o lote.
     *
     * @param  array<string, string>  $files  nome => conteúdo
     * @return list<array{file: string, status: string, invoice_id: ?int, warnings: list<string>, error?: string}>
     */
    public function importMany(array $files, User $by): array
    {
        if (count($files) > 100) {
            throw new DomainException('No máximo 100 arquivos por vez.');
        }

        $results = [];

        foreach ($files as $name => $content) {
            try {
                $results[] = ['file' => (string) $name] + $this->importXml($content, $by);
            } catch (DomainException $e) {
                $results[] = ['file' => (string) $name, 'status' => 'erro', 'invoice_id' => null, 'warnings' => [], 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $results[] = ['file' => (string) $name, 'status' => 'erro', 'invoice_id' => null, 'warnings' => [], 'error' => 'Não consegui importar este arquivo. Confira se é o XML da nota fiscal.'];
            }
        }

        return $results;
    }

    /**
     * Nota lançada à mão (sem XML). Sem itens, nasce um item único "Peças (total da nota)" para poder dar destino.
     * Tudo ou nada: um pedido recusado no meio não deixa fornecedor criado para trás.
     *
     * @param  array<string, mixed>  $data  supplier_id ou supplier_name, issuer_cnpj, number, series, issue_date, total, key,
     *                                      recipient_cnpj, info, installments: list<array{due_date, amount}>, items
     */
    public function createManual(array $data, User $by): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $by) {
            $number = (string) preg_replace('/^0+(?=\d)/', '', (string) preg_replace('/\D/', '', (string) ($data['number'] ?? '')));

            if ($number === '') {
                throw new DomainException('Informe o número da nota.');
            }

            $total = FinanceInput::cents($data['total'] ?? null, 'valor total');

            if ($total <= 0) {
                throw new DomainException('Informe o valor total da nota.');
            }

            $issueDate = FinanceInput::date((string) ($data['issue_date'] ?? ''), 'Informe a data de emissão.');
            $key = null;

            if (filled($data['key'] ?? null)) {
                $key = AccessKey::read((string) $data['key']);

                if (! $key['valid']) {
                    throw new DomainException($key['reason']);
                }

                if (! Document::isValidCnpj($key['issuer_cnpj']) && ! Document::isValidCpf(substr($key['issuer_cnpj'], 3))) {
                    throw new DomainException('O CNPJ que está dentro da chave de acesso não passa na validação: a chave está errada.');
                }
            }

            $cnpj = Document::normalizeCnpj($data['issuer_cnpj'] ?? '') ?: null;

            if ($cnpj && ! Document::isValidCnpj($cnpj)) {
                throw new DomainException('O CNPJ do fornecedor não passa na validação.');
            }

            $supplier = filled($data['supplier_id'] ?? null)
                ? (Supplier::find((int) $data['supplier_id']) ?? throw new DomainException('Fornecedor não encontrado.'))
                : $this->suppliers->ensure((string) ($data['supplier_name'] ?? ''), $cnpj ?? $key['issuer_cnpj'] ?? null);
            $series = self::series($data['series'] ?? '');

            // A chave é a prova da nota: precisa dizer o mesmo que foi digitado (fornecedor, número, série, mês e modelo).
            if ($key) {
                $this->assertKeyMatches($key, $supplier, $number, $series, $issueDate);
            }

            $recipient = Document::normalizeCnpj($data['recipient_cnpj'] ?? '') ?: null;

            if ($recipient && ! Document::isValidCnpj($recipient)) {
                throw new DomainException('O CNPJ do destinatário (quem comprou) não passa na validação (confira os números).');
            }

            $sameNumber = PurchaseInvoice::query()->where('supplier_id', $supplier->id)->where('number', $number)->pluck('series');

            if ($sameNumber->isNotEmpty() && ($series === '' || $sameNumber->contains(fn ($s) => self::series($s) === '' || self::series($s) === $series))) {
                throw new DomainException('Já existe uma nota com este número deste fornecedor. Confira antes de lançar de novo.');
            }

            if ($key && PurchaseInvoice::query()->where('access_key', $key['key'])->exists()) {
                throw new DomainException('Esta chave já está em outra nota.');
            }

            $installments = [];

            foreach ($data['installments'] ?? [] as $installment) {
                $amount = FinanceInput::cents($installment['amount'] ?? null, 'valor da parcela');

                if ($amount <= 0) {
                    throw new DomainException('Informe o valor de cada parcela.');
                }

                $installments[] = ['number' => null, 'due_date' => FinanceInput::date((string) ($installment['due_date'] ?? ''), 'Informe o vencimento de cada parcela.'), 'amount_cents' => $amount];
            }

            $sum = array_sum(array_column($installments, 'amount_cents'));

            if ($installments !== [] && abs($sum - $total) > PurchaseSettings::toleranceCents()) {
                throw new DomainException('As parcelas somam '.Money::brl($sum).' e a nota é de '.Money::brl($total).'. Confira.');
            }

            return $this->insert($supplier, [
                'key' => $key['key'] ?? null, 'number' => $number, 'series' => $series, 'issue_date' => $issueDate, 'total_cents' => $total,
                'issuer_cnpj' => $supplier->document ?: ($cnpj ?? $key['issuer_cnpj'] ?? null), 'issuer_name' => $supplier->name,
                'recipient_cnpj' => $recipient, 'additional_info' => trim((string) ($data['info'] ?? '')) ?: null,
                'purpose' => array_key_exists($data['purpose'] ?? '', PurchaseInvoice::PURPOSES) ? $data['purpose'] : 'normal',
                'source' => PurchaseInvoice::SOURCE_MANUAL, 'installments' => $installments,
                'items' => [['item_number' => 1, 'description' => 'Peças (total da nota)', 'quantity' => 1.0, 'unit_price_cents' => $total, 'total_cents' => $total, 'base_cost_cents' => $total]],
            ], $by);
        });
    }

    /** Nota digitada sem chave que agora tem a chave da DANFE: confere se a chave diz o mesmo que a nota. */
    public function setKey(PurchaseInvoice $invoice, string $text, User $by): PurchaseInvoice
    {
        if ($invoice->access_key && ! $by->isGestor()) {
            throw new DomainException('Esta nota já tem chave: só o gestor troca.');
        }

        if ($invoice->source !== PurchaseInvoice::SOURCE_MANUAL) {
            throw new DomainException('Esta nota veio de um XML e já tem a chave dela.');
        }

        $key = AccessKey::read($text);

        if (! $key['valid']) {
            throw new DomainException($key['reason']);
        }

        $this->assertKeyMatches($key, $invoice->supplier, $invoice->number, self::series($invoice->series), $invoice->issue_date->toDateString(), true);

        if (PurchaseInvoice::query()->where('access_key', $key['key'])->whereKeyNot($invoice->id)->exists()) {
            throw new DomainException('Esta chave já está em outra nota.');
        }

        DB::transaction(function () use ($invoice, $key) {
            // Chave nova invalida qualquer consulta antiga no portal.
            $invoice->update([
                'access_key' => $key['key'], 'issuer_cnpj' => $invoice->issuer_cnpj ?: $key['issuer_cnpj'],
                'portal_checked_on' => null, 'portal_status' => null, 'portal_amount_cents' => null, 'portal_issue_date' => null,
                'portal_recipient' => null, 'portal_checked_by' => null, 'portal_checked_role' => null,
            ]);
            AuditLog::record($invoice, 'purchase.invoice_key', null, ['chave' => $key['key']]);
        });

        return $invoice->fresh();
    }

    /**
     * Alguém consultou a chave no portal da NF-e e leu a situação e o valor: é a prova da nota que não veio com XML.
     * Só vale quando é o GESTOR e o valor do portal é o da nota. Cancelada ou denegada na consulta do gestor cancela a
     * nota aqui; na do atendimento a nota continua, mas o boleto trava até o gestor repetir a consulta.
     *
     * @param  array{status?: ?string, amount?: mixed, issue_date?: ?string, recipient?: ?string}  $data
     * @return array{confirms: bool, canceled_here: bool}
     */
    public function registerPortalCheck(PurchaseInvoice $invoice, array $data, User $by): array
    {
        if (! $invoice->access_key) {
            throw new DomainException('Sem a chave de 44 números não dá para consultar a nota no portal. Digite a chave que está na DANFE (use "Informar a chave").');
        }

        $status = (string) ($data['status'] ?? '');

        if (! array_key_exists($status, PurchaseInvoice::PORTAL_STATUSES)) {
            throw new DomainException('Escolha o que o portal mostrou: autorizada, cancelada, denegada ou não encontrada.');
        }

        $amount = null;

        if ($status === 'autorizada') {
            $amount = is_int($data['amount'] ?? null) ? $data['amount'] : (is_string($data['amount'] ?? null) && preg_match('/^\s*(R\$\s*)?\d[\d.]*(,\d{1,2})?\s*$|^\s*\d+(\.\d{1,2})?\s*$/u', $data['amount']) === 1 ? FinanceInput::cents($data['amount'], 'valor do portal') : 0);

            if ($amount <= 0) {
                throw new DomainException('Informe o "Valor total da nota" que o portal mostrou.');
            }
        }

        $issueDate = filled($data['issue_date'] ?? null) ? FinanceInput::date((string) $data['issue_date'], 'A data de emissão que o portal mostrou está em formato inválido.') : null;
        $recipient = filled($data['recipient'] ?? null) ? mb_substr((string) preg_replace('/[^0-9A-Z*]/', '', mb_strtoupper((string) $data['recipient'])), 0, 14) : null;
        $byGestor = $by->isGestor();
        $cancels = in_array($status, ['cancelada', 'denegada'], true) && $byGestor;

        DB::transaction(function () use ($invoice, $status, $amount, $issueDate, $recipient, $by, $cancels) {
            $invoice->update([
                'portal_checked_on' => PurchaseSettings::today(), 'portal_status' => $status, 'portal_amount_cents' => $amount,
                'portal_issue_date' => $issueDate, 'portal_recipient' => $recipient ?: null, 'portal_checked_by' => $by->id,
                'portal_checked_role' => PurchaseSettings::role($by),
            ]);

            if ($cancels) {
                $invoice->update(['status' => PurchaseInvoice::CANCELED, 'canceled_source' => 'sefaz', 'protocol_status' => $status === 'denegada' ? '110' : '101']);
            }

            AuditLog::record($invoice, 'purchase.invoice_portal_check', null, [
                'situacao' => $status, 'valor' => $amount, 'valor_da_nota' => $invoice->total_cents, 'emissao' => $issueDate, 'destinatario' => $recipient,
            ]);
        });

        if ($cancels) {
            $this->allocations->recalculateForInvoice($invoice);
        }

        return [
            'confirms' => $status === 'autorizada' && abs($amount - $invoice->total_cents) <= PurchaseSettings::toleranceCents(),
            'canceled_here' => $cancels,
        ];
    }

    /** Marca a nota como cancelada ou ativa (gestor). Cancelamento que veio da SEFAZ não se desfaz aqui. */
    public function setStatus(PurchaseInvoice $invoice, string $status, string $reason, User $by): PurchaseInvoice
    {
        if (! $by->isGestor()) {
            throw new DomainException('Cancelar ou reativar a nota é do gestor.');
        }

        if (! in_array($status, [PurchaseInvoice::ACTIVE, PurchaseInvoice::CANCELED], true) || $status === $invoice->status) {
            return $invoice;
        }

        if ($status === PurchaseInvoice::ACTIVE && $invoice->canceled_source === 'sefaz') {
            throw new DomainException('Esta nota foi cancelada pela SEFAZ (consta no protocolo, no XML de cancelamento ou na consulta do portal). Não dá para reativar aqui: peça a nota correta ao fornecedor.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new DomainException('Explique o motivo em poucas palavras (mínimo 5 letras).');
        }

        DB::transaction(function () use ($invoice, $status, $reason) {
            $invoice->update(['status' => $status, 'canceled_source' => $status === PurchaseInvoice::CANCELED ? 'manual' : null, 'notes' => mb_substr($reason, 0, 300)]);
            AuditLog::record($invoice, $status === PurchaseInvoice::CANCELED ? 'purchase.invoice_canceled' : 'purchase.invoice_reactivated', $reason);
        });
        $this->allocations->recalculateForInvoice($invoice);

        return $invoice->fresh();
    }

    /** Apagar (gestor): só nota sem boleto e sem peça ligada. O aceite das ocorrências dela vai junto; a trilha fica. */
    public function delete(PurchaseInvoice $invoice, User $by): void
    {
        if (! $by->isGestor()) {
            throw new DomainException('Apagar nota é do gestor.');
        }

        if ($invoice->links()->exists() || $invoice->items()->whereHas('allocations')->exists()) {
            throw new DomainException('Esta nota já tem boleto ou peça ligada. Desfaça as ligações antes de apagar (ou marque como cancelada).');
        }

        DB::transaction(function () use ($invoice) {
            AuditLog::record($invoice, 'purchase.invoice_deleted', null, ['numero' => $invoice->number, 'chave' => $invoice->access_key, 'valor' => $invoice->total_cents, 'fornecedor' => $invoice->supplier_id]);
            AuditAcceptance::query()->where('occurrence_key', 'like', '%:nota:'.$invoice->id)->delete();
            $invoice->delete();
        });
    }

    /**
     * Parcelas da nota com o saldo ainda sem boleto. A ligação feita só com a nota (sem escolher parcela) abate as
     * parcelas na ordem dos vencimentos.
     *
     * @return list<array{installment: PurchaseInvoiceInstallment, balance_cents: int}>
     */
    public function installmentsWithBalance(PurchaseInvoice $invoice): array
    {
        $byInstallment = PurchaseLink::query()->where('purchase_invoice_id', $invoice->id)
            ->selectRaw('installment_id, SUM(amount_cents) AS total')->groupBy('installment_id')->pluck('total', 'installment_id');
        $free = (int) ($byInstallment[''] ?? 0);
        $out = [];

        foreach ($invoice->installments()->get() as $installment) {
            $balance = $installment->amount_cents - (int) ($byInstallment[$installment->id] ?? 0);

            if ($balance > 0 && $free > 0) {
                $taken = min($balance, $free);
                $balance -= $taken;
                $free -= $taken;
            }

            $out[] = ['installment' => $installment, 'balance_cents' => $balance];
        }

        return $out;
    }

    /** Valor da nota já coberto por boletos. */
    public function linkedCents(PurchaseInvoice $invoice): int
    {
        return (int) PurchaseLink::query()->where('purchase_invoice_id', $invoice->id)->sum('amount_cents');
    }

    /**
     * Reparte o total da nota entre os itens: cada item com o custo próprio quando o XML traz (produto − desconto +
     * frete + seguro + outras + IPI + ICMS-ST); o que sobrar do total (frete só no total, por exemplo) é repartido pelo
     * valor dos produtos. A soma fecha exatamente com o total (o resto vai no último item).
     *
     * @param  list<array{total_cents?: int, discount_cents?: int, base_cost_cents?: ?int}>  $items
     * @return list<int>
     */
    public static function costSplit(array $items, int $totalCents): array
    {
        $base = array_map(fn ($i) => (int) ($i['base_cost_cents'] ?? 0), $items);
        $rest = $totalCents - array_sum($base);
        $weights = array_map(fn ($i) => max(0, (int) ($i['total_cents'] ?? 0) - (int) ($i['discount_cents'] ?? 0)), $items);
        $weightSum = array_sum($weights);
        $count = count($items);
        $parts = [];
        $accumulated = 0;

        foreach ($items as $k => $item) {
            if ($k === $count - 1) {
                $parts[] = $totalCents - $accumulated;
                break;
            }

            $part = (int) round($base[$k] + ($weightSum > 0 ? $rest * $weights[$k] / $weightSum : $rest / $count));
            $parts[] = $part;
            $accumulated += $part;
        }

        return $parts;
    }

    /** Série "001" e "1" são a mesma; série 0 fica vazia. */
    public static function series(mixed $value): string
    {
        return ltrim((string) preg_replace('/\D/', '', (string) $value), '0');
    }

    /** @param  array<string, mixed>  $data */
    private function insert(Supplier $supplier, array $data, User $by): PurchaseInvoice
    {
        $recipient = $data['recipient_cnpj'] ?? null;
        $costs = self::costSplit($data['items'], $data['total_cents']);
        $invoice = PurchaseInvoice::create([
            'supplier_id' => $supplier->id, 'access_key' => $data['key'] ?? null, 'number' => $data['number'], 'series' => self::series($data['series'] ?? ''),
            'issue_date' => $data['issue_date'], 'total_cents' => $data['total_cents'], 'total_with_taxes_cents' => $data['total_with_taxes_cents'] ?? null,
            'products_cents' => $data['products_cents'] ?? null, 'freight_cents' => $data['freight_cents'] ?? null, 'discount_cents' => $data['discount_cents'] ?? null,
            'paid_upfront' => (bool) ($data['paid_upfront'] ?? false), 'payee_cnpj' => $data['payee_cnpj'] ?? null,
            'group_company_id' => $recipient ? $this->companies->companyFor($recipient)?->id : null,
            'issuer_cnpj' => $data['issuer_cnpj'] ?? null, 'issuer_name' => isset($data['issuer_name']) ? mb_substr($data['issuer_name'], 0, 120) : null,
            'recipient_cnpj' => $recipient, 'recipient_name' => isset($data['recipient_name']) ? mb_substr($data['recipient_name'], 0, 120) : null,
            'purpose' => $data['purpose'] ?? 'normal', 'status' => $data['status'] ?? PurchaseInvoice::ACTIVE,
            'canceled_source' => ($data['status'] ?? null) === PurchaseInvoice::CANCELED ? 'sefaz' : null, 'protocol_status' => $data['protocol_status'] ?? null,
            'nature' => isset($data['nature']) ? mb_substr($data['nature'], 0, 120) : null, 'additional_info' => $data['additional_info'] ?? null,
            'source' => $data['source'], 'xml_sha256' => $data['hash'] ?? null, 'xml_gz' => $data['xml_gz'] ?? null,
            'created_by' => $by->id, 'created_role' => PurchaseSettings::role($by),
        ]);

        $this->writeChildren($invoice, $data, $costs);
        AuditLog::record($invoice, 'purchase.invoice_created', null, ['origem' => $data['source'], 'numero' => $invoice->number, 'valor' => $invoice->total_cents]);

        return $invoice;
    }

    /**
     * Chegou o XML de uma nota que tinha sido digitada: completa a nota existente em vez de duplicar. Só troca parcelas
     * e itens quando nada está ligado a eles; senão mantém o que existe e avisa.
     *
     * @param  array<string, mixed>  $read
     * @return array{status: string, invoice_id: int, warnings: list<string>}
     */
    private function completeManual(PurchaseInvoice $invoice, array $read, string $stored, User $by): array
    {
        if (abs($invoice->total_cents - $read['total_cents']) > PurchaseSettings::toleranceCents()) {
            return ['status' => 'divergente_manual', 'invoice_id' => $invoice->id, 'warnings' => [
                'Já existe uma nota digitada com este número, de '.Money::brl($invoice->total_cents).', e o XML diz '.Money::brl($read['total_cents']).'. Confira e corrija ou apague a nota digitada antes de importar.',
            ]];
        }

        $conflict = PurchaseInvoice::query()->whereKeyNot($invoice->id)
            ->where(fn ($q) => $q->where('access_key', $read['key'])->orWhere(fn ($w) => $w->where('supplier_id', $invoice->supplier_id)->where('number', $invoice->number)->where('series', $read['series'])))
            ->exists();

        if ($conflict) {
            return ['status' => 'divergente_manual', 'invoice_id' => $invoice->id, 'warnings' => ['Já existe outra nota com esta chave ou com este número e série. Confira as notas deste fornecedor antes de importar.']];
        }

        $warnings = [];
        $invoice->update([
            'access_key' => $read['key'], 'series' => $read['series'], 'issue_date' => $read['issue_date'], 'total_cents' => $read['total_cents'],
            'total_with_taxes_cents' => $read['total_with_taxes_cents'], 'products_cents' => $read['products_cents'], 'freight_cents' => $read['freight_cents'],
            'discount_cents' => $read['discount_cents'], 'issuer_cnpj' => $read['issuer_cnpj'], 'issuer_name' => mb_substr((string) $read['issuer_name'], 0, 120) ?: null,
            'recipient_cnpj' => $read['recipient_cnpj'], 'recipient_name' => mb_substr((string) $read['recipient_name'], 0, 120) ?: null,
            'purpose' => $read['purpose'], 'protocol_status' => $read['protocol_status'], 'nature' => mb_substr((string) $read['nature'], 0, 120) ?: null,
            'additional_info' => $read['additional_info'], 'source' => PurchaseInvoice::SOURCE_XML, 'xml_sha256' => $read['hash'], 'xml_gz' => $stored,
            'status' => $read['status'] === PurchaseInvoice::CANCELED ? PurchaseInvoice::CANCELED : $invoice->status,
            'canceled_source' => $read['status'] === PurchaseInvoice::CANCELED ? 'sefaz' : $invoice->canceled_source,
            'paid_upfront' => $read['paid_upfront'], 'payee_cnpj' => $read['payee_cnpj'],
            'group_company_id' => $read['recipient_cnpj'] ? $this->companies->companyFor($read['recipient_cnpj'])?->id : null,
        ]);

        $installmentsLinked = PurchaseLink::query()->where('purchase_invoice_id', $invoice->id)->whereNotNull('installment_id')->exists();
        $itemsAllocated = $invoice->items()->whereHas('allocations')->exists();

        if ($installmentsLinked) {
            $warnings[] = 'As parcelas da nota digitada já estão ligadas a boletos e foram mantidas.';
        } else {
            PurchaseInvoiceInstallment::query()->where('purchase_invoice_id', $invoice->id)->delete();
        }

        if ($itemsAllocated) {
            $warnings[] = 'Já há peças da nota digitada com destino: os itens do XML não substituíram os da nota; confira.';
        } else {
            PurchaseInvoiceItem::query()->where('purchase_invoice_id', $invoice->id)->delete();
        }

        $this->writeChildren($invoice, [
            'installments' => $installmentsLinked ? [] : $read['installments'],
            'items' => $itemsAllocated ? [] : $read['items'],
        ], self::costSplit($read['items'], $read['total_cents']));
        AuditLog::record($invoice, 'purchase.invoice_completed_xml', null, ['chave' => $read['key']]);

        return ['status' => 'atualizada', 'invoice_id' => $invoice->id, 'warnings' => [...$read['warnings'], ...$warnings]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $costs
     */
    private function writeChildren(PurchaseInvoice $invoice, array $data, array $costs): void
    {
        foreach ($data['installments'] ?? [] as $installment) {
            $invoice->installments()->create(['number' => $installment['number'] ?? null, 'due_date' => $installment['due_date'], 'amount_cents' => $installment['amount_cents']]);
        }

        foreach ($data['items'] ?? [] as $k => $item) {
            $invoice->items()->create([
                'item_number' => $item['item_number'] ?? $k + 1, 'code' => isset($item['code']) ? mb_substr($item['code'], 0, 60) : null,
                'description' => mb_substr((string) ($item['description'] ?? '(sem descrição)'), 0, 200), 'ncm' => isset($item['ncm']) ? mb_substr($item['ncm'], 0, 10) : null,
                'cfop' => isset($item['cfop']) ? mb_substr($item['cfop'], 0, 4) : null, 'unit' => isset($item['unit']) ? mb_substr($item['unit'], 0, 10) : null,
                'quantity' => $item['quantity'] ?? 1, 'unit_price_cents' => max(0, (int) ($item['unit_price_cents'] ?? 0)), 'total_cents' => max(0, (int) ($item['total_cents'] ?? 0)),
                'discount_cents' => max(0, (int) ($item['discount_cents'] ?? 0)), 'cost_cents' => $costs[$k] ?? 0,
                'customer_order' => isset($item['customer_order']) ? mb_substr($item['customer_order'], 0, 60) : null, 'additional_info' => $item['additional_info'] ?? null,
            ]);
        }
    }

    /** @param  array<string, mixed>  $key */
    private function assertKeyMatches(array $key, Supplier $supplier, string $number, string $series, string $issueDate, bool $seriesOptional = false): void
    {
        if ($key['model'] !== '55') {
            throw new DomainException("A chave é de uma nota modelo {$key['model']}; aqui só entra NF-e modelo 55.");
        }

        if ($supplier->document && $supplier->document !== $key['issuer_cnpj'] && '000'.$supplier->document !== $key['issuer_cnpj']) {
            throw new DomainException('O CNPJ que está dentro da chave de acesso não é o do fornecedor escolhido. A chave é de outra empresa.');
        }

        if (! $supplier->document && ($owner = Supplier::query()->where('document', $key['issuer_cnpj'])->first())) {
            throw new DomainException('O CNPJ que está dentro da chave de acesso é de '.$owner->name.', não do fornecedor escolhido.');
        }

        if ($key['number'] !== $number) {
            throw new DomainException("O número dentro da chave ({$key['number']}) não é o número da nota ({$number}).");
        }

        if (! ($seriesOptional && $series === '') && $key['series'] !== ($series === '' ? '0' : $series)) {
            throw new DomainException("A série dentro da chave ({$key['series']}) não é a da nota (".($series === '' ? '0' : $series).').');
        }

        if ($key['yymm'] !== substr($issueDate, 2, 2).substr($issueDate, 5, 2)) {
            throw new DomainException('O mês dentro da chave ('.substr($key['yymm'], 2).'/20'.substr($key['yymm'], 0, 2).') não é o mês da data de emissão da nota.');
        }
    }
}
