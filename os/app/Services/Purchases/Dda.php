<?php

namespace App\Services\Purchases;

use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\DdaImport;
use App\Models\DdaTitle;
use App\Models\GroupCompany;
use App\Models\User;
use App\Support\Document;
use App\Support\Money;
use App\Support\Purchases\BoletoCode;
use App\Support\Purchases\DdaFile;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DDA (doc 09 §13, BETA): o gestor importa o arquivo do banco e o sistema compara com os boletos cadastrados. É a
 * conferência independente de quem lança: boleto no DDA que ninguém cadastrou, ou cadastrado diferente do que o banco
 * mostra, vira ocorrência grave; e boleto que bate com um CNAB recente, de fornecedor conferido pelo gestor, dispensa a
 * conferência no app do banco. Valem juntos os arquivos dos últimos 7 dias de cada CNPJ.
 *
 * Porte da parte de importação e cruzamento de `app/src/dda.js`.
 */
final class Dda
{
    /** Boleto cadastrado há menos disto antes do arquivo ainda pode não estar no banco. */
    public const GRACE_DAYS = 2;

    /** O DDA que dispensa a conferência no app do banco precisa ter chegado (e sido gerado) há no máximo isto. */
    public const VALID_DAYS = 7;

    /** Arquivos dos últimos dias valem juntos (uma exportação parcial não apaga o que o arquivo completo mostrou). */
    public const WINDOW_DAYS = 7;

    /** Boleto vencido há mais disto não é cobrado como "fora do DDA" (provavelmente já pago por fora). */
    public const OVERDUE_WINDOW_DAYS = 60;

    private const ORDER = [BankSlip::OPEN => 0, BankSlip::PAID => 1, BankSlip::CONTESTED => 2, BankSlip::CANCELED => 3];

    /**
     * @return array{imports: list<array{id: int, company: ?int, count: int, new: int}>, format: string, count: int, new: int,
     *               warnings: list<string>, filled: list<int>, ok: int, diverge: int, without_registration: int, outside: int}
     */
    public function import(string $content, ?string $fileName, ?int $companyId, User $by): array
    {
        if (! $by->isGestor()) {
            throw new DomainException('Importar o DDA do banco é do gestor (é a conferência independente de quem lança).');
        }

        if ($companyId && ! GroupCompany::query()->whereKey($companyId)->exists()) {
            throw new DomainException('Empresa não encontrada.');
        }

        $today = PurchaseSettings::today();
        $read = DdaFile::read($content, $today);

        if ($read['titles'] === []) {
            throw new DomainException(trim('Não encontrei boletos neste arquivo. '.($read['warnings'][0] ?? '')));
        }

        // O CNPJ de cada lote manda; arquivo sem CNPJ (CSV, texto) vale para a empresa escolhida (padrão: a oficina).
        $cnpjs = array_values(array_unique(array_filter(array_map(fn ($t) => $t['lot_cnpj'] ?? $read['file_cnpj'], $read['titles']))));
        $byScope = [];
        $ignored = [];
        $firstError = null;

        foreach ($read['titles'] as $title) {
            $cnpj = $title['lot_cnpj'] ?? $read['file_cnpj'];

            try {
                $scope = $cnpj ? $this->scopeOf($cnpj, count($cnpjs) === 1 ? $companyId : null) : $companyId;
            } catch (DomainException $e) {
                if (count($cnpjs) === 1) {
                    throw $e;      // arquivo de um CNPJ só: o erro vale; com vários lotes, um lote estranho não derruba os outros
                }

                $firstError ??= $e;
                $ignored[$cnpj] = ($ignored[$cnpj] ?? 0) + 1;

                continue;
            }

            $byScope[(int) $scope][] = $title;
        }

        if ($byScope === [] && $firstError) {
            throw $firstError;
        }

        foreach ($ignored as $cnpj => $count) {
            $read['warnings'][] = "Ignorei {$count} título(s) do CNPJ ".Document::format((string) $cnpj).': não é o da oficina nem o de uma empresa cadastrada em Compras > Grupo.';
        }

        $result = ['imports' => [], 'filled' => [], 'new' => 0];

        DB::transaction(function () use ($byScope, $read, $fileName, $today, $by, &$result) {
            foreach ($byScope as $scope => $titles) {
                $import = DdaImport::create([
                    'group_company_id' => $scope ?: null, 'scope' => $scope, 'file_name' => $fileName ? mb_substr($fileName, 0, 120) : null,
                    'format' => $read['format'], 'generated_on' => $read['generated_on'], 'imported_on' => $today, 'titles_count' => count($titles), 'imported_by' => $by->id,
                ]);
                $new = 0;

                foreach ($titles as $t) {
                    $key = DdaFile::titleKey($t);
                    $existing = DdaTitle::query()->where('scope', $scope)->where('title_key', $key)->first();
                    $fill = array_filter([
                        'bank_code' => $t['bank'] ?? null, 'payee_cnpj' => $t['payee_cnpj'] ?? null, 'payee_name' => isset($t['payee_name']) ? mb_substr($t['payee_name'], 0, 80) : null,
                        'drawer_cnpj' => $t['drawer_cnpj'] ?? null, 'drawer_name' => isset($t['drawer_name']) ? mb_substr($t['drawer_name'], 0, 80) : null,
                        'payer_cnpj' => $t['payer_cnpj'] ?? null, 'document_number' => isset($t['document_number']) ? mb_substr($t['document_number'], 0, 30) : null,
                    ], fn ($v) => $v !== null);

                    if ($existing) {
                        $existing->update(['last_import_id' => $import->id, 'format' => $t['format'], ...$fill]);
                    } else {
                        DdaTitle::create([
                            'group_company_id' => $scope ?: null, 'scope' => $scope, 'title_key' => $key, 'barcode' => $t['barcode'] ?? null,
                            'amount_cents' => $t['amount_cents'], 'due_date' => $t['due_date'], 'issue_date' => $t['issue_date'] ?? null,
                            'format' => $t['format'], 'first_import_id' => $import->id, 'last_import_id' => $import->id, ...$fill,
                        ]);
                        $new++;
                    }
                }

                $import->update(['new_count' => $new]);

                // O banco informa quem recebe e quem paga: preenche o que quem cadastrou deixou em branco (nunca sobrescreve o
                // digitado). Só o arquivo estruturado do banco (CNAB) preenche; CSV e texto são lidos por aproximação.
                if ($read['format'] === 'cnab240') {
                    foreach ($titles as $t) {
                        if (! ($t['barcode'] ?? null)) {
                            continue;
                        }

                        $slip = BankSlip::query()->where('barcode', $t['barcode'])->where('status', '!=', BankSlip::CANCELED)
                            ->when($scope, fn ($q) => $q->where('group_company_id', $scope), fn ($q) => $q->whereNull('group_company_id'))->first();
                        $changes = $slip ? array_filter([
                            'payee_cnpj' => $slip->payee_cnpj ? null : ($t['payee_cnpj'] ?? null),
                            'payee_name' => $slip->payee_name ? null : (isset($t['payee_name']) ? mb_substr($t['payee_name'], 0, 80) : null),
                            'payer_cnpj' => $slip->payer_cnpj ? null : ($t['payer_cnpj'] ?? null),
                            'document_number' => $slip->document_number ? null : ($t['document_number'] ?? null),
                        ], fn ($v) => $v !== null) : [];

                        if ($changes !== []) {
                            $slip->update($changes);
                            AuditLog::record($slip, 'purchase.slip_filled_by_dda', null, $changes);
                            $result['filled'][] = $slip->id;
                        }
                    }
                }

                AuditLog::record($import, 'purchase.dda_imported', null, ['arquivo' => $fileName, 'formato' => $read['format'], 'titulos' => count($titles), 'novos' => $new, 'empresa' => $scope ?: null]);
                $result['imports'][] = ['id' => $import->id, 'company' => $scope ?: null, 'count' => count($titles), 'new' => $new];
                $result['new'] += $new;
            }
        });

        // O pagador que o banco informou pode mostrar de qual empresa é o boleto.
        foreach ($result['filled'] as $id) {
            app(BankSlips::class)->applyCompany(BankSlip::find($id));
        }

        $cross = $this->cross($today);

        return [
            'imports' => $result['imports'], 'format' => $read['format'], 'count' => count($read['titles']), 'new' => $result['new'],
            'warnings' => $read['warnings'], 'filled' => $result['filled'],
            'ok' => count($cross['ok']), 'diverge' => count($cross['diverge']), 'without_registration' => count($cross['without_registration']), 'outside' => count($cross['outside']),
        ];
    }

    /**
     * Cruza os DDA importados (os arquivos dos últimos 7 dias de cada CNPJ) com os boletos cadastrados.
     * ok = bate; diverge = achou o boleto mas valor/vencimento/quem recebe/empresa diferem; without_registration = o banco
     * mostra e ninguém cadastrou; outside = cadastrado e em aberto, mas o banco não mostra.
     *
     * @return array{ok: list<array>, diverge: list<array>, without_registration: list<array>, outside: list<array>, imports: list<DdaImport>}
     */
    public function cross(?string $today = null): array
    {
        $today ??= PurchaseSettings::today();
        $tolerance = PurchaseSettings::toleranceCents();
        $imports = DdaImport::query()->orderBy('id')->get();
        $lastByScope = $imports->keyBy('scope');
        $out = ['ok' => [], 'diverge' => [], 'without_registration' => [], 'outside' => [], 'imports' => []];

        foreach ($lastByScope as $import) {
            $import->setAttribute('days', $this->days($import->imported_on->toDateString(), $today));
            $out['imports'][] = $import;
        }

        if ($lastByScope->isEmpty()) {
            return $out;
        }

        $slips = BankSlip::query()->with('supplier:id,name,document,authorized_payees,confirmed_at')->get();
        $byBarcode = [];
        $byTitle = [];

        foreach ($slips as $slip) {
            if ($slip->barcode) {
                $byBarcode[$slip->barcode][] = $slip;
                $byTitle[BoletoCode::titleKey($slip->barcode)][] = $slip;
            }
        }

        $choose = function (?array $list, int $scope): ?BankSlip {
            if (! $list) {
                return null;
            }

            usort($list, fn (BankSlip $a, BankSlip $b) => (self::ORDER[$a->status] <=> self::ORDER[$b->status])
                ?: ((int) ($a->group_company_id ?? 0) === $scope ? 0 : 1) <=> ((int) ($b->group_company_id ?? 0) === $scope ? 0 : 1));

            return $list[0];
        };
        $importsById = $imports->keyBy('id');
        $matched = [];

        foreach ($lastByScope as $scope => $import) {
            $since = $import->imported_on->subDays(self::WINDOW_DAYS)->toDateString();
            $ids = $imports->where('scope', $scope)->filter(fn ($i) => $i->imported_on->toDateString() >= $since)->pluck('id');
            $titles = DdaTitle::query()->where('scope', $scope)->whereIn('last_import_id', $ids)->orderBy('due_date')->orderBy('id')->get();
            $scopeSlips = $slips->filter(fn ($s) => (int) ($s->group_company_id ?? 0) === (int) $scope);

            foreach ($titles as $title) {
                // 1º o código de barras inteiro; 2º banco + campo livre (se mexeram no valor ou no vencimento do código, o título é o
                // mesmo e a diferença aparece); 3º título sem código (muitos bancos não mostram): valor + vencimento (+ CNPJ de quem
                // recebe, quando os dois têm), que nunca confirma sozinho.
                $slip = $title->barcode ? $choose($byBarcode[$title->barcode] ?? null, (int) $scope) : null;

                if (! $slip && $title->barcode) {
                    $slip = $choose($byTitle[BoletoCode::titleKey($title->barcode)] ?? null, (int) $scope);
                }

                if (! $slip && ! $title->barcode) {
                    $slip = $scopeSlips->first(fn (BankSlip $s) => ! isset($matched[$s->id]) && in_array($s->status, [BankSlip::OPEN, BankSlip::PAID], true)
                        && $s->due_date->toDateString() === $title->due_date->toDateString() && abs($s->amount_cents - $title->amount_cents) <= $tolerance
                        && (! $title->payee_cnpj || ! $s->payee_cnpj || $title->payee_cnpj === $s->payee_cnpj));
                }

                if (! $slip) {
                    $out['without_registration'][] = ['title' => $title, 'scope' => (int) $scope];

                    continue;
                }

                $matched[$slip->id] = true;
                $differences = [];

                if (abs($slip->amount_cents - $title->amount_cents) > $tolerance) {
                    $differences[] = 'valor: cadastrado '.Money::brl($slip->amount_cents).', banco '.Money::brl($title->amount_cents);
                }

                if ($slip->due_date->toDateString() !== $title->due_date->toDateString()) {
                    $differences[] = 'vencimento: cadastrado '.$slip->due_date->format('d/m/Y').', banco '.$title->due_date->format('d/m/Y');
                }

                if ($title->payee_cnpj && $slip->payee_cnpj && $title->payee_cnpj !== $slip->payee_cnpj) {
                    $differences[] = 'quem recebe: cadastrado '.Document::format($slip->payee_cnpj).', banco '.Document::format($title->payee_cnpj);
                }

                if ((int) ($slip->group_company_id ?? 0) !== (int) $scope) {
                    $differences[] = 'empresa: o banco mostra este boleto no DDA de outro CNPJ';
                }

                // O banco diz quem recebe; só vale como "confirmado" se o fornecedor foi conferido pelo GESTOR e o CNPJ é o dele
                // (ou um recebedor que o gestor autorizou). O CNPJ das notas não conta: quem lança é quem importa as notas.
                $supplier = $slip->supplier;
                $payeeOk = $title->payee_cnpj && $supplier?->isConfirmed()
                    && ($title->payee_cnpj === $supplier->document || in_array($title->payee_cnpj, $supplier->authorizedPayees(), true));
                $file = $importsById[$title->last_import_id] ?? $import;
                $recent = $this->days($file->imported_on->toDateString(), $today) <= self::VALID_DAYS
                    && (! $file->generated_on || $this->days($file->generated_on->toDateString(), $file->imported_on->toDateString()) <= self::VALID_DAYS);
                // Só o arquivo estruturado do banco (CNAB 240) dispensa conferir no app: CSV e texto são lidos por aproximação.
                $confirmed = $payeeOk && $recent && $title->format === 'cnab240' && $title->barcode && $title->barcode === $slip->barcode
                    && in_array($slip->status, [BankSlip::OPEN, BankSlip::PAID], true);

                if ($differences !== []) {
                    $out['diverge'][] = ['title' => $title, 'slip' => $slip, 'differences' => $differences];
                } else {
                    $out['ok'][] = ['title' => $title, 'slip' => $slip, 'confirmed' => (bool) $confirmed];
                }
            }

            // Cadastrado, em aberto, e o banco não mostra (só com DDA recente e boleto que já existia quando o arquivo foi gerado).
            if ($this->days($import->imported_on->toDateString(), $today) <= self::VALID_DAYS) {
                $graceLimit = $import->imported_on->subDays(self::GRACE_DAYS)->toDateString();
                $overdueLimit = $import->imported_on->subDays(self::OVERDUE_WINDOW_DAYS)->toDateString();

                foreach ($scopeSlips as $slip) {
                    if ($slip->status !== BankSlip::OPEN || isset($matched[$slip->id]) || $slip->isConvenio()
                        || $slip->created_at->toDateString() > $graceLimit || $slip->due_date->toDateString() < $overdueLimit) {
                        continue;
                    }

                    $out['outside'][] = ['slip' => $slip, 'import' => $import];
                }
            }
        }

        return $out;
    }

    /** @return array<int, string> boleto => confirmado | no_dda | diverge | fora */
    public function statusBySlip(?array $cross = null): array
    {
        $cross ??= $this->cross();
        $map = [];

        foreach ($cross['ok'] as $row) {
            $map[$row['slip']->id] = $row['confirmed'] ? 'confirmado' : 'no_dda';
        }

        foreach ($cross['diverge'] as $row) {
            $map[$row['slip']->id] = 'diverge';
        }

        foreach ($cross['outside'] as $row) {
            $map[$row['slip']->id] = 'fora';
        }

        return $map;
    }

    /** O boleto está num DDA recente do banco (CNAB, mesmo código), e quem recebe é o fornecedor conferido pelo gestor? */
    public function isConfirmed(BankSlip $slip, ?string $today = null): bool
    {
        return $slip->barcode !== null && ($this->statusBySlip($this->cross($today))[$slip->id] ?? null) === 'confirmado';
    }

    /** @return Collection<int, string> empresas (a oficina e as do grupo) que ainda não têm DDA importado */
    public function companiesWithoutDda(): Collection
    {
        $with = DdaImport::query()->distinct()->pluck('scope')->map(fn ($s) => (int) $s)->all();
        $list = collect([0 => 'Oficina'])->union(GroupCompany::query()->active()->orderBy('name')->pluck('name', 'id'));

        return $list->reject(fn ($name, $id) => in_array((int) $id, $with, true));
    }

    /** Qual empresa é dona do DDA: o CNPJ do cabeçalho (ou do lote) manda; sem ele, vale o que a pessoa escolheu. */
    private function scopeOf(string $cnpj, ?int $chosen): ?int
    {
        $root = Document::cnpjRoot($cnpj);

        if (in_array($root, array_map(fn ($c) => Document::cnpjRoot($c), PurchaseSettings::workshopCnpjs()), true)) {
            if ($chosen) {
                throw new DomainException('Este DDA é do CNPJ da própria oficina, mas você escolheu uma empresa do grupo. Escolha "Oficina".');
            }

            return null;
        }

        $company = GroupCompany::query()->active()->get()->first(fn (GroupCompany $c) => Document::cnpjRoot($c->cnpj) === $root);

        if ($company) {
            if ($chosen && $chosen !== $company->id) {
                throw new DomainException('Este DDA é da '.$company->name.', mas você escolheu outra empresa.');
            }

            return $company->id;
        }

        throw new DomainException('Este DDA é do CNPJ '.Document::format($cnpj).', que não é o da oficina (Ajustes > Oficina e numeração) nem de uma empresa cadastrada em Compras > Grupo. Confira o arquivo ou cadastre a empresa.');
    }

    private function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);
    }
}
