<?php

namespace App\Services\Purchases;

use App\Enums\Stage;
use App\Models\AuditAcceptance;
use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\GroupCompany;
use App\Models\GroupSettlement;
use App\Models\OrderItem;
use App\Models\Payable;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseLink;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Document;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ocorrências da conferência de compras (doc 09 §3): cada regra produz um item com chave "tipo:entidade:id",
 * gravidade (alta: pode ser prejuízo ou golpe; media: conferir; baixa: organização), título, detalhe e o `state` (a
 * impressão digital do fato). O aceite "Está certo, conferi" vale só para aquele fato: se ele muda, a ocorrência volta.
 *
 * Porte de `app/src/auditoria.js`. Dinheiro em centavos.
 */
final class Occurrences
{
    private const ORDER = ['alta' => 0, 'media' => 1, 'baixa' => 2];

    /** protocolo de autorização: 100 e 150. */
    private const AUTHORIZED = ['100', '150'];

    /** Ocorrências graves que põem o dinheiro do boleto em risco (para o "dinheiro em risco" do resumo). */
    private const AT_RISK = [
        'boleto_sem_nota', 'boleto_valor_diverge', 'boleto_beneficiario_diverge', 'boleto_pagador_diverge', 'boleto_duplicado', 'boleto_mesmo_titulo',
        'nota_cancelada_com_boleto', 'boleto_nota_sem_prova', 'boleto_fornecedor_diverge', 'boleto_nota_paga_no_ato',
    ];

    public function __construct(private Dda $dda, private Invoices $invoices) {}

    /**
     * Todas as ocorrências, da mais grave para a menos (e do maior valor para o menor).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $today = null, ?array $cross = null): array
    {
        $today ??= PurchaseSettings::today();
        $tolerance = PurchaseSettings::toleranceCents();
        $out = [];
        $add = function (array $o) use (&$out) {
            $out[] = $o + ['slips' => [], 'invoices' => [], 'amount_cents' => null, 'date' => null, 'state' => ''] + ['key' => "{$o['type']}:{$o['entity']}:{$o['id']}"];
        };

        $workshop = PurchaseSettings::workshopCnpjs();
        $workshopRoots = array_map(fn ($c) => Document::cnpjRoot($c), $workshop);
        $group = GroupCompany::query()->active()->get()->keyBy('cnpj');
        $owners = [...$workshop, ...$group->keys()->all()];
        $paidSuppliers = BankSlip::query()->where('status', BankSlip::PAID)->whereNotNull('supplier_id')->distinct()->pluck('supplier_id')->flip();
        $slips = BankSlip::query()->alive()->with('supplier:id,name,document,authorized_payees,confirmed_at')->orderBy('id')->get();
        $links = PurchaseLink::query()->with(['invoice' => fn ($q) => $q->select($this->invoiceColumns()), 'installment'])->get();
        $bySlip = $links->groupBy('bank_slip_id');
        $byInvoice = $links->groupBy('purchase_invoice_id');
        $withSettlement = GroupSettlement::query()->where('source', 'boleto')->whereNotNull('bank_slip_id')->pluck('bank_slip_id')->flip();
        $name = fn (BankSlip $s) => $s->supplier?->name ?? 'Fornecedor não informado';
        $workshopLabel = $workshop === [] ? 'a oficina' : 'a oficina ('.implode(', ', array_map(fn ($c) => Document::format($c), $workshop)).')';

        if ($workshop === []) {
            $add(['type' => 'config_cnpj_oficina', 'severity' => 'media', 'entity' => 'config', 'id' => 0, 'title' => 'CNPJ da oficina não informado',
                'detail' => 'Sem o CNPJ da oficina o sistema não confere se a nota foi emitida para vocês nem se o pagador do boleto é vocês. Informe em Ajustes > Oficina e numeração.']);
        }

        // ---------------------------------------------------------------- boletos
        $previousBySupplier = $slips->filter(fn ($s) => $s->supplier_id && $s->bank_code)->groupBy('supplier_id');

        foreach ($slips as $slip) {
            $cs = $bySlip[$slip->id] ?? collect();
            $summary = $name($slip).': '.Money::brl($slip->amount_cents).', vence '.$slip->due_date->format('d/m/Y');
            $base = ['entity' => 'boleto', 'id' => $slip->id, 'slips' => [$slip->id], 'amount_cents' => $slip->amount_cents, 'date' => $slip->due_date->toDateString()];

            if ($cs->isEmpty()) {
                $add([...$base, 'type' => 'boleto_sem_nota', 'severity' => 'alta',
                    'title' => $slip->isPaid() ? 'Boleto PAGO sem nota fiscal ligada' : 'Boleto sem nota fiscal',
                    'detail' => $summary.'. Nenhuma nota fiscal explica este boleto. Peça a nota ao fornecedor (ou confirme que o boleto é da oficina) antes de pagar.']);
            } else {
                $sum = (int) $cs->sum('amount_cents');
                $difference = $slip->amount_cents - $sum;
                $invoiceIds = $cs->pluck('purchase_invoice_id')->all();

                if ($difference > $tolerance) {
                    $add([...$base, 'type' => 'boleto_valor_diverge', 'severity' => 'alta', 'invoices' => $invoiceIds, 'amount_cents' => $difference, 'state' => 'a_mais:'.$difference,
                        'title' => 'Boleto cobra mais do que as notas explicam',
                        'detail' => $summary.'. As notas ligadas explicam '.Money::brl($sum).'; sobram '.Money::brl($difference).'. Pode ser juros/multa, nota faltando ou cobrança indevida.']);
                } elseif ($difference < -$tolerance) {
                    $add([...$base, 'type' => 'boleto_valor_diverge', 'severity' => 'media', 'invoices' => $invoiceIds, 'amount_cents' => -$difference, 'state' => 'a_menos:'.(-$difference),
                        'title' => 'Valor ligado às notas é maior que o boleto',
                        'detail' => $summary.'. Você ligou '.Money::brl($sum).' de notas a um boleto de '.Money::brl($slip->amount_cents).': confira a ligação.']);
                }

                $otherDue = $cs->first(fn ($c) => $c->installment && $c->installment->due_date->toDateString() !== $slip->due_date->toDateString());

                if ($otherDue) {
                    $add([...$base, 'type' => 'boleto_venc_diverge', 'severity' => 'baixa', 'invoices' => [$otherDue->purchase_invoice_id], 'amount_cents' => null, 'state' => $otherDue->installment->due_date->toDateString(),
                        'title' => 'Vencimento diferente da parcela da nota',
                        'detail' => $summary.', mas a parcela da nota '.$otherDue->invoice->number.' vence em '.$otherDue->installment->due_date->format('d/m/Y').'.']);
                }

                $canceled = $cs->first(fn ($c) => $c->invoice->status === PurchaseInvoice::CANCELED);

                if ($canceled) {
                    $denied = in_array($canceled->invoice->protocol_status, ['110', '301', '302', '303'], true);
                    $add([...$base, 'type' => 'nota_cancelada_com_boleto', 'severity' => 'alta', 'invoices' => [$canceled->purchase_invoice_id],
                        'title' => $denied ? 'Boleto de nota DENEGADA' : 'Boleto de nota CANCELADA',
                        'detail' => $summary.'. A nota '.$canceled->invoice->number.' foi '.($denied ? 'denegada (não vale como nota fiscal)' : 'cancelada').': não deveria haver cobrança.']);
                }

                // A nota que "explica" o boleto precisa ter prova: XML com protocolo de autorização, ou a consulta do gestor no portal.
                $withoutProof = $cs->filter(fn ($c) => ! $this->hasProof($c->invoice, $owners));

                if ($withoutProof->isNotEmpty()) {
                    $add([...$base, 'type' => 'boleto_nota_sem_prova', 'severity' => 'alta', 'invoices' => $withoutProof->pluck('purchase_invoice_id')->all(),
                        'state' => $withoutProof->map(fn ($c) => $c->purchase_invoice_id.':'.$this->checkState($c->invoice))->implode(','),
                        'title' => 'Boleto ligado a nota sem comprovação',
                        'detail' => $summary.'. '.$withoutProof->map(fn ($c) => $this->whyWithoutProof($c->invoice))->implode(' ')]);
                }

                // A consulta no portal contradiz uma nota que tinha XML com protocolo (XML forjado ou chave de outra nota): vale o portal.
                $contradicted = $cs->filter(fn ($c) => $this->authorizedXml($c->invoice) && $c->invoice->portal_status
                    && ($c->invoice->portal_status === 'nao_encontrada' || ($c->invoice->portal_status === 'autorizada' && ! $this->portalAmountMatches($c->invoice))));

                if ($contradicted->isNotEmpty()) {
                    $add([...$base, 'type' => 'nota_contradita_pelo_portal', 'severity' => 'alta', 'invoices' => $contradicted->pluck('purchase_invoice_id')->all(),
                        'state' => $contradicted->map(fn ($c) => $c->purchase_invoice_id.':'.$c->invoice->portal_status.':'.$c->invoice->portal_amount_cents)->implode(','),
                        'title' => 'O portal da NF-e contradiz a nota',
                        'detail' => $summary.'. '.$contradicted->map(fn ($c) => $this->whyWithoutProof($c->invoice))->implode(' ').' O arquivo XML da nota não prova nada contra o portal.']);
                }

                // O XML que o atendimento importa não tem a assinatura digital conferida aqui: para boleto alto, o gestor consulta.
                if ($slip->isOpen() && $slip->amount_cents >= PurchaseSettings::xmlCheckCents()) {
                    $onlyFromDesk = $cs->filter(fn ($c) => $this->authorizedXml($c->invoice) && $c->invoice->created_role === 'atendimento'
                        && ! ($c->invoice->portal_status === 'autorizada' && $c->invoice->portal_checked_role === 'gestor' && $this->portalAmountMatches($c->invoice)));

                    if ($onlyFromDesk->isNotEmpty()) {
                        $add([...$base, 'type' => 'nota_xml_sem_conferencia_do_gestor', 'severity' => 'media', 'invoices' => $onlyFromDesk->pluck('purchase_invoice_id')->all(),
                            'state' => $onlyFromDesk->pluck('purchase_invoice_id')->implode(','),
                            'title' => 'Nota por XML do atendimento, sem consulta do gestor',
                            'detail' => $summary.'. A nota '.$onlyFromDesk->map(fn ($c) => $c->invoice->number)->implode(', ').' veio de um XML importado pelo atendimento, e o sistema não confere a assinatura digital do arquivo. Para um valor desses, o gestor consulta a chave no portal da NF-e (leva um minuto) e registra a consulta na nota.']);
                    }
                }

                $paidUpfront = $cs->filter(fn ($c) => $c->invoice->paid_upfront);

                if ($paidUpfront->isNotEmpty()) {
                    $add([...$base, 'type' => 'boleto_nota_paga_no_ato', 'severity' => 'alta', 'invoices' => $paidUpfront->pluck('purchase_invoice_id')->all(),
                        'state' => $paidUpfront->pluck('purchase_invoice_id')->implode(','),
                        'title' => 'Boleto de nota que já foi paga na hora',
                        'detail' => $summary.'. A nota '.$paidUpfront->map(fn ($c) => $c->invoice->number)->implode(', ').' informa pagamento imediato (Pix, dinheiro ou cartão) e não traz parcelas: não deveria haver boleto dela. Pode ser cobrança em dobro.']);
                }

                // O atendimento viu "cancelada/denegada" no portal: não cancela sozinho (quem confere é o gestor), mas o boleto trava.
                $pendingCancel = $cs->filter(fn ($c) => $c->invoice->isActive() && in_array($c->invoice->portal_status, ['cancelada', 'denegada'], true) && $c->invoice->portal_checked_role === 'atendimento');

                if ($pendingCancel->isNotEmpty()) {
                    $add([...$base, 'type' => 'nota_consulta_cancelada_pendente', 'severity' => 'alta', 'invoices' => $pendingCancel->pluck('purchase_invoice_id')->all(),
                        'state' => $pendingCancel->map(fn ($c) => $c->purchase_invoice_id.':'.$c->invoice->portal_status)->implode(','),
                        'title' => 'O atendimento viu a nota CANCELADA no portal',
                        'detail' => $summary.'. A nota '.$pendingCancel->map(fn ($c) => $c->invoice->number)->implode(', ').' aparece como '.($pendingCancel->first()->invoice->portal_status === 'denegada' ? 'denegada' : 'cancelada').' na consulta registrada pelo atendimento. O gestor repete a consulta no portal e registra (se confirmar, a nota é cancelada aqui). Não pague antes.']);
                }

                // Nota sem XML cuja prova é só uma consulta antiga: nota autorizada ainda pode ser cancelada depois.
                if ($slip->isOpen() && $slip->due_date->toDateString() <= $this->plusDays($today, 3)) {
                    $old = $cs->filter(fn ($c) => ! $this->authorizedXml($c->invoice) && $c->invoice->portal_status === 'autorizada' && $c->invoice->portal_checked_on
                        && $this->days($c->invoice->portal_checked_on->toDateString(), $today) > 2);

                    if ($old->isNotEmpty()) {
                        $add([...$base, 'type' => 'nota_consulta_antiga', 'severity' => 'media', 'invoices' => $old->pluck('purchase_invoice_id')->all(),
                            'state' => $old->map(fn ($c) => $c->purchase_invoice_id.':'.$c->invoice->portal_checked_on->toDateString())->implode(','),
                            'title' => 'Consulte a nota de novo antes de pagar',
                            'detail' => $summary.'. A nota '.$old->map(fn ($c) => $c->invoice->number)->implode(', ').' foi consultada no portal em '.$old->map(fn ($c) => $c->invoice->portal_checked_on->format('d/m/Y'))->implode(', ').'. Uma nota autorizada ainda pode ser cancelada depois: consulte de novo no dia do pagamento (leva um minuto).']);
                    }
                }

                $withoutRecipient = $cs->filter(fn ($c) => $c->invoice->source === PurchaseInvoice::SOURCE_MANUAL && ! $c->invoice->recipient_cnpj);

                if ($withoutRecipient->isNotEmpty()) {
                    $add([...$base, 'type' => 'nota_sem_destinatario', 'severity' => 'media', 'invoices' => $withoutRecipient->pluck('purchase_invoice_id')->all(),
                        'state' => $withoutRecipient->pluck('purchase_invoice_id')->implode(','),
                        'title' => 'Não se sabe para quem a nota foi emitida',
                        'detail' => $summary.'. A nota '.$withoutRecipient->map(fn ($c) => $c->invoice->number)->implode(', ').' foi digitada sem o CNPJ do destinatário (quem comprou). Informe o que está na DANFE e, na consulta do portal, o destinatário que ele mostrar: nota de outro cliente do fornecedor parece igual.']);
                }

                $otherSupplier = $slip->supplier_id ? $cs->first(fn ($c) => $c->invoice->supplier_id !== $slip->supplier_id) : null;

                if ($otherSupplier) {
                    $add([...$base, 'type' => 'boleto_fornecedor_diverge', 'severity' => 'alta', 'invoices' => [$otherSupplier->purchase_invoice_id], 'state' => (string) $otherSupplier->purchase_invoice_id,
                        'title' => 'Boleto ligado a nota de outro fornecedor',
                        'detail' => $summary.'. A nota '.$otherSupplier->invoice->number.' é de outro fornecedor. Boleto e nota precisam ser da mesma empresa.']);
                }

                // Número do documento impresso que contradiz a nota (alguns fornecedores numeram por conta própria: só "conferir").
                $docs = $this->documentNumbers($slip->document_number);

                if ($docs !== [] && ! $cs->contains(fn ($c) => collect($docs)->contains(fn ($d) => $d === $this->noZeros($c->invoice->number) || str_starts_with($d, $this->noZeros($c->invoice->number))))) {
                    $add([...$base, 'type' => 'boleto_doc_diverge', 'severity' => 'media', 'invoices' => $invoiceIds, 'state' => (string) $slip->document_number,
                        'title' => 'O documento impresso no boleto não é o da nota',
                        'detail' => $summary.'. O boleto diz "doc '.$slip->document_number.'" e a nota ligada é a '.$cs->map(fn ($c) => $c->invoice->number)->implode(', ').'. Pode ser numeração do fornecedor, mas confirme.']);
                }
            }

            // Notas de empresas diferentes no mesmo boleto: o custo de uma não é da outra e não há como dividir sozinho.
            $ownersOfInvoices = $cs->map(fn ($c) => $c->invoice->recipient_cnpj && $group->has($c->invoice->recipient_cnpj) ? 'g'.$group[$c->invoice->recipient_cnpj]->id : 'oficina')->unique()->sort()->values();

            if ($ownersOfInvoices->count() > 1) {
                $add([...$base, 'type' => 'boleto_notas_de_empresas_misturadas', 'severity' => 'alta', 'invoices' => $cs->pluck('purchase_invoice_id')->all(), 'state' => $ownersOfInvoices->implode(','),
                    'title' => 'Boleto cobre notas de empresas diferentes',
                    'detail' => $summary.'. As notas ligadas foram emitidas para empresas diferentes (oficina e empresa do grupo). Separe o boleto: o gestor divide o valor entre as empresas (Compras > Grupo > Registrar acerto) antes de pagar.']);
            }

            // Ligação feita à mão pelo atendimento que o sistema não sugeriria (nem pelo valor, nem pela parcela, nem pelo documento).
            $docsSlip = $this->documentNumbers($slip->document_number);
            $doubtful = $cs->filter(fn ($c) => $c->source === 'manual' && $c->created_role === 'atendimento'
                && ! ($c->installment && abs($c->installment->amount_cents - $slip->amount_cents) <= $tolerance)
                && ! (abs($c->invoice->total_cents - $slip->amount_cents) <= $tolerance)
                && ! in_array($this->noZeros($c->invoice->number), $docsSlip, true));

            if ($doubtful->isNotEmpty()) {
                $add([...$base, 'type' => 'ligacao_manual_sem_aderencia', 'severity' => 'media', 'invoices' => $doubtful->pluck('purchase_invoice_id')->all(),
                    'state' => $doubtful->map(fn ($c) => $c->purchase_invoice_id.':'.$c->amount_cents)->implode(','),
                    'title' => 'Ligação à mão que o sistema não sugeriria',
                    'detail' => $summary.'. O atendimento ligou a nota '.$doubtful->map(fn ($c) => $c->invoice->number)->implode(', ').' a este boleto, mas nem o valor, nem a parcela, nem o número do documento batem. Confira se a nota é mesmo deste boleto antes de pagar.']);
            }

            // Boleto já pago pela oficina, mas que é de empresa do grupo (cadastrada depois): ficou fora do acerto.
            if ($slip->isPaid() && ! $slip->group_company_id && $slip->payer_cnpj && $group->has($slip->payer_cnpj) && ! isset($withSettlement[$slip->id])) {
                $company = $group[$slip->payer_cnpj];
                $add([...$base, 'type' => 'boleto_pago_de_empresa_do_grupo', 'severity' => 'media', 'state' => $slip->payer_cnpj,
                    'title' => 'Boleto pago pela oficina é da '.$company->name,
                    'detail' => $summary.'. O pagador impresso é a '.$company->name.', mas o pagamento não entrou no acerto entre empresas. Registre em Compras > Grupo (a oficina pagou por ela) ou marque como conferido se já foi resolvido.']);
            }

            // Quem recebe × fornecedor: pode receber o fornecedor, o emitente das notas, o recebedor que a nota informa e os autorizados.
            if ($slip->payee_cnpj) {
                $permitted = array_values(array_unique(array_filter([$slip->supplier?->document, ...$cs->pluck('invoice.issuer_cnpj')->all(), ...$cs->pluck('invoice.payee_cnpj')->all(), ...($slip->supplier?->authorizedPayees() ?? [])])));
                $expected = array_values(array_unique(array_filter([$slip->supplier?->document, ...$cs->pluck('invoice.issuer_cnpj')->all()])));
                $diverging = $permitted !== [] && ! in_array($slip->payee_cnpj, $permitted, true)
                    ? (collect($expected)->first(fn ($e) => $e !== $slip->payee_cnpj) ?? $permitted[0])
                    : null;

                if ($diverging) {
                    $sameRoot = Document::cnpjRoot($diverging) === Document::cnpjRoot($slip->payee_cnpj);
                    $add([...$base, 'type' => 'boleto_beneficiario_diverge', 'severity' => $sameRoot ? 'media' : 'alta', 'state' => $slip->payee_cnpj,
                        'title' => $sameRoot ? 'Boleto emitido por outra filial do fornecedor' : 'Beneficiário do boleto NÃO é o fornecedor',
                        'detail' => $summary.'. O boleto é de '.Document::format($slip->payee_cnpj).' e o fornecedor/nota é '.Document::format($diverging).'.'
                            .($sameRoot ? ' Mesma raiz de CNPJ: confirme se é filial.' : ' Pode ser boleto trocado ou golpe: confirme por telefone com o fornecedor (num número que a oficina já tinha) antes de pagar.')]);
                }
            }

            // Pagador: a oficina, uma empresa do grupo (não é alerta: o veredito diz de quem é) ou um CNPJ desconhecido (alerta).
            if ($slip->payer_cnpj && $workshop !== [] && ! in_array($slip->payer_cnpj, $workshop, true) && ! $group->has($slip->payer_cnpj)) {
                $sameRoot = in_array(Document::cnpjRoot($slip->payer_cnpj), $workshopRoots, true);
                $add([...$base, 'type' => 'boleto_pagador_diverge', 'severity' => $sameRoot ? 'media' : 'alta', 'state' => $slip->payer_cnpj,
                    'title' => 'Boleto emitido contra outro CNPJ',
                    'detail' => $summary.'. O pagador do boleto é '.Document::format($slip->payer_cnpj).', não '.$workshopLabel.' nem uma empresa cadastrada do grupo. Este boleto pode não ser de vocês. Se for da locadora ou da oficina do sócio, cadastre a empresa em Compras > Grupo.']);
            }

            // A nota foi emitida para uma empresa e o boleto cobra outra: dois documentos que não conversam.
            if ($slip->payer_cnpj) {
                $otherRecipient = $cs->first(fn ($c) => $c->invoice->recipient_cnpj && $c->invoice->recipient_cnpj !== $slip->payer_cnpj);

                if ($otherRecipient) {
                    $add([...$base, 'type' => 'boleto_nota_empresas_diferentes', 'severity' => 'media', 'invoices' => [$otherRecipient->purchase_invoice_id],
                        'state' => $slip->payer_cnpj.'|'.$otherRecipient->invoice->recipient_cnpj,
                        'title' => 'Nota e boleto em nome de empresas diferentes',
                        'detail' => $summary.'. O boleto é contra '.Document::format($slip->payer_cnpj).' e a nota '.$otherRecipient->invoice->number.' foi emitida para '.Document::format($otherRecipient->invoice->recipient_cnpj).'. Pergunte ao fornecedor qual está certo antes de pagar.']);
                }
            }

            if ($slip->isOpen()) {
                $urgent = $slip->due_date->toDateString() <= $this->plusDays($today, 3);

                // O CNPJ de quem recebe não vem no código de barras: sem ele a conferência principal não acontece.
                if (! $slip->payee_cnpj && $slip->bank_code) {
                    $add([...$base, 'type' => 'boleto_sem_beneficiario', 'severity' => $urgent ? 'media' : 'baixa',
                        'title' => 'Falta conferir quem recebe este boleto',
                        'detail' => $summary.'. O CNPJ do beneficiário não foi informado, então o sistema não consegue dizer se o dinheiro vai para o fornecedor. Informe-o no boleto ("Informar quem recebe") ou confira no app do banco antes de pagar.']);
                }

                if (! $slip->payer_cnpj && $workshop !== [] && $slip->bank_code) {
                    $add([...$base, 'type' => 'boleto_sem_pagador', 'severity' => $urgent ? 'media' : 'baixa',
                        'title' => 'Falta conferir contra quem o boleto foi emitido',
                        'detail' => $summary.'. O CNPJ do pagador não foi informado: o sistema não sabe se o boleto é da oficina ou de outro cliente do fornecedor.']);
                }

                if (! $slip->barcode) {
                    $add([...$base, 'type' => 'boleto_sem_codigo', 'severity' => 'media',
                        'title' => 'Boleto cadastrado sem linha digitável',
                        'detail' => $summary.'. Sem a linha digitável o sistema não confere valor, vencimento, banco nem duplicidade. Cadastre de novo com a linha.']);
                }

                if ($slip->supplier_id && ! $slip->supplier?->isConfirmed()) {
                    // A primeira vez que se paga a um fornecedor é o momento de maior risco: trava até o gestor conferir.
                    $first = ! isset($paidSuppliers[$slip->supplier_id]);
                    $add(['type' => 'fornecedor_nao_confirmado', 'severity' => $first ? 'alta' : 'media', 'entity' => 'fornecedor', 'id' => $slip->supplier_id,
                        'slips' => [$slip->id], 'amount_cents' => $slip->amount_cents, 'date' => $slip->due_date->toDateString(), 'state' => $first ? 'primeira_vez' : 'ja_pago',
                        'title' => $first ? 'Primeiro pagamento a um fornecedor não conferido' : 'Fornecedor ainda não conferido',
                        'detail' => $name($slip).' nunca teve o CNPJ e o telefone conferidos por uma pessoa'.($first ? ' e ainda não recebeu nenhum pagamento por aqui' : '').'. O gestor confere em Compras > Fornecedores pelo cartão CNPJ e por um telefone que a oficina já tinha, e marca "conferido".']);
                }
            }

            // Fornecedor que sempre cobrou por um banco e aparece com outro: sinal clássico de boleto adulterado (ou troca de conta).
            if ($slip->supplier_id && $slip->bank_code) {
                $previous = ($previousBySupplier[$slip->supplier_id] ?? collect())->filter(fn ($p) => $p->id < $slip->id);

                if ($previous->count() >= 2 && $previous->every(fn ($p) => $p->bank_code !== $slip->bank_code)) {
                    $add([...$base, 'type' => 'boleto_banco_novo', 'severity' => 'media',
                        'title' => 'Banco diferente do que o fornecedor costuma usar',
                        'detail' => $summary.'. É um boleto do banco '.$slip->bank_code.'; os '.$previous->count().' anteriores deste fornecedor eram de '.$previous->pluck('bank_code')->unique()->implode(', ').'. Pode ser troca de conta, mas é também o sinal mais comum de boleto adulterado: confirme por telefone.']);
                }
            }

            if ($slip->isOpen() && $slip->due_date->toDateString() < $today) {
                $add([...$base, 'type' => 'boleto_vencido', 'severity' => 'media',
                    'title' => 'Boleto vencido e não pago',
                    'detail' => $summary.' ('.$this->days($slip->due_date->toDateString(), $today).' dia(s) de atraso). Pergunte o valor atualizado com juros e multa.']);
            }
        }

        // Duplicidade provável: mesmo fornecedor, valor e vencimento; ou o mesmo título (nosso número) com outro vencimento (2ª via).
        foreach ($slips->filter(fn ($s) => $s->supplier_id)->groupBy(fn ($s) => $s->supplier_id.'|'.$s->amount_cents.'|'.$s->due_date->toDateString()) as $list) {
            if ($list->count() > 1) {
                $first = $list->first();
                $add(['type' => 'boleto_duplicado', 'severity' => 'alta', 'entity' => 'boleto', 'id' => $first->id, 'slips' => $list->pluck('id')->sort()->values()->all(),
                    'amount_cents' => $first->amount_cents, 'date' => $first->due_date->toDateString(), 'state' => $list->pluck('id')->sort()->implode(','),
                    'title' => 'Possível boleto em duplicidade',
                    'detail' => $list->count().' boletos de '.$name($first).' com o mesmo valor ('.Money::brl($first->amount_cents).') e vencimento ('.$first->due_date->format('d/m/Y').'). Pague só um, depois de confirmar com o fornecedor.']);
            }
        }

        foreach ($slips->filter(fn ($s) => $s->supplier_id && $s->barcode)->groupBy(fn ($s) => $s->bank_code.'|'.substr($s->barcode, 19)) as $list) {
            if ($list->count() > 1 && $list->map(fn ($s) => $s->due_date->toDateString().'|'.$s->amount_cents)->unique()->count() > 1) {
                $first = $list->first();
                $add(['type' => 'boleto_mesmo_titulo', 'severity' => 'alta', 'entity' => 'boleto', 'id' => $first->id, 'slips' => $list->pluck('id')->sort()->values()->all(),
                    'amount_cents' => $first->amount_cents, 'date' => $first->due_date->toDateString(), 'state' => $list->pluck('id')->sort()->implode(','),
                    'title' => 'Segunda via do mesmo título',
                    'detail' => $list->count().' boletos de '.$name($first).' têm o mesmo número de título (nosso número) com vencimento ou valor diferente. Provavelmente é a segunda via do mesmo boleto: pague só um.']);
            }
        }

        $this->invoiceRules($add, $byInvoice, $today, $tolerance, $workshop, $workshopRoots, $group, $workshopLabel);
        $since = PurchaseSettings::auditSince($today);
        $this->orderRules($add, $since);
        $this->groupRules($add, $group, $today);
        $this->ddaRules($add, $name, $cross ?? $this->dda->cross($today));
        $this->payableRules($add, $since);

        $suppliersWithoutCnpj = Supplier::query()->where('active', true)->where(fn ($q) => $q->whereNull('document')->orWhere('document', ''))
            ->where(fn ($q) => $q->whereHas('purchaseInvoices')->orWhereIn('id', BankSlip::query()->select('supplier_id')))->get(['id', 'name']);

        foreach ($suppliersWithoutCnpj as $supplier) {
            $add(['type' => 'fornecedor_sem_cnpj', 'severity' => 'media', 'entity' => 'fornecedor', 'id' => $supplier->id,
                'title' => 'Fornecedor sem CNPJ cadastrado',
                'detail' => $supplier->name.': sem o CNPJ o sistema não consegue conferir se o beneficiário do boleto é mesmo o fornecedor. Cadastre em Fornecedores.']);
        }

        // Uma ocorrência de fornecedor pode ser gerada por vários boletos: junta numa só.
        $merged = [];

        foreach ($out as $o) {
            if (isset($merged[$o['key']])) {
                $merged[$o['key']]['slips'] = array_values(array_unique([...$merged[$o['key']]['slips'], ...$o['slips']]));
                $merged[$o['key']]['amount_cents'] = (int) $merged[$o['key']]['amount_cents'] + (int) $o['amount_cents'];
            } else {
                $merged[$o['key']] = $o;
            }
        }

        $acceptances = AuditAcceptance::query()->with('user:id,name')->get()->keyBy('occurrence_key');
        $list = array_map(function (array $o) use ($acceptances) {
            $acceptance = $acceptances[$o['key']] ?? null;
            $valid = $acceptance && $acceptance->state === $o['state'];
            $info = $acceptance ? ['reason' => $acceptance->reason, 'at' => $acceptance->updated_at, 'by' => $acceptance->user?->name] : null;

            return $o + ['accepted' => $valid ? $info : null, 'acceptance_expired' => $acceptance && ! $valid ? $info : null];
        }, array_values($merged));

        usort($list, fn ($a, $b) => (self::ORDER[$a['severity']] <=> self::ORDER[$b['severity']]) ?: ((int) $b['amount_cents'] <=> (int) $a['amount_cents']));

        return $list;
    }

    /** @return list<array<string, mixed>> */
    public function forSlip(BankSlip|int $slip, ?string $today = null): array
    {
        $id = $slip instanceof BankSlip ? $slip->id : $slip;

        return array_values(array_filter($this->all($today), fn ($o) => in_array($id, $o['slips'], true)));
    }

    /**
     * @param  list<array<string, mixed>>  $list
     * @return array{alta: int, media: int, baixa: int, total: int, accepted: int, at_risk_cents: int}
     */
    public function summary(array $list): array
    {
        $open = array_filter($list, fn ($o) => ! $o['accepted']);
        $count = fn (string $severity) => count(array_filter($open, fn ($o) => $o['severity'] === $severity));
        $atRisk = [];

        foreach ($open as $o) {
            if ($o['severity'] === 'alta' && in_array($o['type'], self::AT_RISK, true)) {
                foreach ($o['slips'] as $id) {
                    $atRisk[$id] = max($atRisk[$id] ?? 0, (int) $o['amount_cents']);       // cada boleto conta uma vez só
                }
            }
        }

        return [
            'alta' => $count('alta'), 'media' => $count('media'), 'baixa' => $count('baixa'), 'total' => count($open),
            'accepted' => count($list) - count($open), 'at_risk_cents' => array_sum($atRisk),
        ];
    }

    /** "Conferi, está certo" (gestor): só para uma ocorrência que existe agora, com motivo, e enquanto o fato for o mesmo. */
    public function accept(string $key, string $reason, User $by): void
    {
        if (! $by->isGestor()) {
            throw new DomainException('Aceitar uma ocorrência é do gestor. O atendimento avisa e o gestor confere.');
        }

        if (! PurchaseSettings::validReason($reason)) {
            throw new DomainException(PurchaseSettings::MIN_REASON);
        }

        $occurrence = collect($this->all())->firstWhere('key', $key)
            ?? throw new DomainException('Essa ocorrência não existe mais (o fato mudou). Atualize a tela.');
        $reason = mb_substr(trim($reason), 0, 300);

        DB::transaction(function () use ($occurrence, $reason, $by) {
            $acceptance = AuditAcceptance::query()->updateOrCreate(['occurrence_key' => $occurrence['key']], ['reason' => $reason, 'state' => $occurrence['state'], 'accepted_by' => $by->id]);
            $acceptance->touch();
            AuditLog::record($this->subject($occurrence) ?? $acceptance, 'purchase.occurrence_accepted', $reason, ['ocorrencia' => $occurrence['key'], 'titulo' => $occurrence['title'], 'fato' => $occurrence['state']]);
        });
    }

    public function undoAcceptance(string $key, User $by): bool
    {
        if (! $by->isGestor()) {
            throw new DomainException('Desfazer o aceite é do gestor.');
        }

        $acceptance = AuditAcceptance::query()->where('occurrence_key', $key)->first();

        if (! $acceptance) {
            return false;
        }

        [$type, $entity, $id] = array_pad(explode(':', $key), 3, null);
        $subject = $this->subject(['entity' => $entity, 'id' => (int) $id]) ?? $acceptance;
        AuditLog::record($subject, 'purchase.occurrence_unaccepted', null, ['ocorrencia' => $key, 'motivo_anterior' => $acceptance->reason, 'tipo' => $type]);
        $acceptance->delete();

        return true;
    }

    /**
     * Veredito de uma linha para o gestor bater o olho antes de pagar: ruim (NÃO PAGUE), atencao (falta conferir) ou ok.
     * "ok" quer dizer: nota com prova, recebedor e pagador batendo, nada grave aberto. Ainda assim, antes de pagar se olha
     * o app do banco (a não ser que o DDA do banco já tenha confirmado).
     *
     * @param  list<array<string, mixed>>  $occurrences  as do boleto
     * @return array{level: string, text: string, short: string}
     */
    public function verdict(BankSlip $slip, array $occurrences, ?string $ddaStatus = null): array
    {
        if ($slip->isPaid()) {
            return ['level' => 'neutro', 'text' => 'Pago', 'short' => 'Pago'];
        }

        if (in_array($slip->status, [BankSlip::CANCELED, BankSlip::CONTESTED], true)) {
            return $slip->status === BankSlip::CONTESTED
                ? ['level' => 'neutro', 'text' => 'Contestado: não pagar', 'short' => 'Contestado']
                : ['level' => 'neutro', 'text' => 'Cancelado', 'short' => 'Cancelado'];
        }

        $open = array_filter($occurrences, fn ($o) => ! $o['accepted']);
        $serious = array_values(array_filter($open, fn ($o) => $o['severity'] === 'alta'));
        $medium = array_values(array_filter($open, fn ($o) => $o['severity'] === 'media'));
        $company = $slip->groupCompany?->name;
        $supplier = $slip->supplier?->name ?? 'o fornecedor';

        if ($serious !== []) {
            return ['level' => 'ruim', 'text' => 'NÃO PAGUE: '.$serious[0]['title'].(count($serious) > 1 ? ' (+'.(count($serious) - 1).')' : ''), 'short' => 'NÃO PAGUE'];
        }

        if ($ddaStatus === 'confirmado' && $medium === []) {
            return ['level' => 'ok', 'text' => 'Conferido com a nota e confirmado no DDA do banco: valor, vencimento e quem recebe ('.$supplier.') batem'.($company ? '. Boleto da '.$company.': ao pagar, diga quem paga' : ''), 'short' => $company ? 'Da '.$company : 'No DDA'];
        }

        if (! $slip->payee_cnpj) {
            return ['level' => 'atencao', 'text' => 'NÃO CONFERIDO: falta o CNPJ de quem recebe', 'short' => 'Falta quem recebe'];
        }

        if (! $slip->payer_cnpj && PurchaseSettings::workshopCnpjs() !== []) {
            return ['level' => 'atencao', 'text' => 'NÃO CONFERIDO: falta o CNPJ do pagador', 'short' => 'Falta quem paga'];
        }

        if ($medium !== []) {
            return ['level' => 'atencao', 'text' => 'Conferir: '.$medium[0]['title'].(count($medium) > 1 ? ' (+'.(count($medium) - 1).')' : ''), 'short' => 'Conferir'];
        }

        if ($company) {
            return ['level' => 'ok', 'text' => 'Boleto da '.$company.', não da oficina. Ao pagar, diga quem paga. Veja no app do banco se quem recebe é '.$supplier, 'short' => 'Da '.$company];
        }

        return ['level' => 'ok', 'text' => 'Conferido com a nota. Antes de pagar, veja no app do banco se quem recebe é '.$supplier, 'short' => 'Conferido'];
    }

    // ------------------------------------------------------------------ notas, OS, grupo, DDA e "A pagar"

    private function invoiceRules(callable $add, $byInvoice, string $today, int $tolerance, array $workshop, array $workshopRoots, $group, string $workshopLabel): void
    {
        $invoices = PurchaseInvoice::query()->with('supplier:id,name')->select($this->invoiceColumns())->get();
        $items = DB::table('purchase_invoice_items')->whereIn('purchase_invoice_id', $invoices->pluck('id'))->orderBy('purchase_invoice_id')->orderBy('item_number')->get();
        $allocated = PurchaseAllocation::query()->valid()->selectRaw('purchase_invoice_item_id, SUM(quantity) AS qty, SUM(cost_cents) AS cost')
            ->groupBy('purchase_invoice_item_id')->get()->keyBy('purchase_invoice_item_id');
        $itemsByInvoice = $items->groupBy('purchase_invoice_id');

        // Histórico de preço por fornecedor e código, em ordem de emissão (devolução e ajuste não entram).
        $history = [];
        foreach ($invoices->filter(fn ($n) => $n->generatesCharge())->sortBy([fn ($a, $b) => $a->issue_date <=> $b->issue_date, fn ($a, $b) => $a->id <=> $b->id]) as $invoice) {
            foreach ($itemsByInvoice[$invoice->id] ?? [] as $item) {
                if ($item->code && $item->unit_price_cents > 0) {
                    $history[$invoice->supplier_id.'|'.$item->code][] = ['price' => (int) $item->unit_price_cents, 'invoice' => $invoice->id];
                }
            }
        }

        foreach ($invoices as $invoice) {
            $cs = $byInvoice[$invoice->id] ?? collect();
            $linked = (int) $cs->sum('amount_cents');
            $limit = max($invoice->total_cents, (int) $invoice->total_with_taxes_cents);
            $label = 'Nota '.$invoice->number.' de '.($invoice->supplier?->name ?? 'fornecedor');
            $base = ['entity' => 'nota', 'id' => $invoice->id, 'invoices' => [$invoice->id], 'date' => $invoice->issue_date->toDateString()];

            if ($invoice->recipient_cnpj && $workshop !== [] && ! in_array($invoice->recipient_cnpj, $workshop, true) && ! $group->has($invoice->recipient_cnpj)) {
                $add([...$base, 'type' => 'nota_destinatario_diverge', 'severity' => in_array(Document::cnpjRoot($invoice->recipient_cnpj), $workshopRoots, true) ? 'media' : 'alta',
                    'slips' => $cs->pluck('bank_slip_id')->all(), 'amount_cents' => $invoice->total_cents, 'state' => $invoice->recipient_cnpj,
                    'title' => 'Nota emitida para outro CNPJ',
                    'detail' => $label.' ('.Money::brl($invoice->total_cents).') está em nome de '.Document::format($invoice->recipient_cnpj).($invoice->recipient_name ? ' ('.$invoice->recipient_name.')' : '').', e não de '.$workshopLabel.' nem de uma empresa cadastrada do grupo. Se for da locadora ou da oficina do sócio, cadastre a empresa em Compras > Grupo.']);
            }

            if ($linked > $limit + $tolerance) {
                $add([...$base, 'type' => 'nota_cobrada_a_mais', 'severity' => 'alta', 'slips' => $cs->pluck('bank_slip_id')->all(), 'amount_cents' => $linked - $limit, 'state' => (string) $linked,
                    'title' => 'Boletos somam mais que a nota',
                    'detail' => $label.': '.Money::brl($limit).'. Os boletos ligados somam '.Money::brl($linked).'. Possível cobrança em duplicidade.']);
            }

            if (! $invoice->generatesCharge() || ! $invoice->isActive()) {
                continue;       // devolução é crédito, ajuste é fiscal e nota cancelada não se paga: nenhuma gera boleto
            }

            $balance = $invoice->total_cents - $linked;

            if ($balance > $tolerance) {
                $installments = array_values(array_filter($this->invoices->installmentsWithBalance($invoice), fn ($i) => $i['balance_cents'] > $tolerance));

                if ($installments !== []) {
                    $next = $installments[0]['installment'];

                    if ($next->due_date->toDateString() <= $this->plusDays($today, PurchaseSettings::invoiceWithoutSlipDays())) {
                        $late = $next->due_date->toDateString() < $today;
                        $add([...$base, 'type' => 'nota_sem_boleto', 'severity' => 'media', 'amount_cents' => array_sum(array_column($installments, 'balance_cents')),
                            'date' => $next->due_date->toDateString(), 'state' => $next->id.':'.$next->due_date->toDateString(),
                            'title' => $late ? 'Parcela vencida sem boleto' : 'Parcela perto de vencer sem boleto',
                            'detail' => $label.': parcela de '.Money::brl($installments[0]['balance_cents']).' '.($late ? 'venceu' : 'vence').' em '.$next->due_date->format('d/m/Y').' e nenhum boleto foi ligado. Se já foi paga de outro jeito (Pix, cartão, dinheiro), marque como conferida com o motivo.']);
                    }
                } elseif ($this->days($invoice->issue_date->toDateString(), $today) >= 3 && ! $invoice->paid_upfront) {
                    $add([...$base, 'type' => 'nota_sem_boleto', 'severity' => 'baixa', 'amount_cents' => $balance,
                        'title' => 'Nota sem boleto e sem parcelas',
                        'detail' => $label.' ('.Money::brl($balance).') não tem boleto nem parcelas informadas. Se foi paga à vista (Pix, cartão, dinheiro), marque como conferida com o motivo.']);
                }
            }

            if ($invoice->source === PurchaseInvoice::SOURCE_MANUAL && ! $invoice->access_key && $invoice->total_cents >= 10000) {
                $add([...$base, 'type' => 'nota_sem_chave', 'severity' => 'baixa', 'amount_cents' => $invoice->total_cents,
                    'title' => 'Nota digitada sem chave de acesso',
                    'detail' => $label.' foi lançada à mão. Sem a chave de 44 dígitos não dá para provar que ela existe; peça o XML ou consulte o portal da NF-e.']);
            }

            // Peças sem destino depois do prazo.
            $withoutDestination = collect($itemsByInvoice[$invoice->id] ?? [])->filter(fn ($i) => (float) $i->quantity - (float) ($allocated[$i->id]->qty ?? 0) > 0.000001);

            if ($withoutDestination->isNotEmpty() && $this->days($invoice->issue_date->toDateString(), $today) >= PurchaseSettings::allocationDays()) {
                $value = (int) $withoutDestination->sum(fn ($i) => (int) $i->cost_cents - (int) ($allocated[$i->id]->cost ?? 0));
                $add([...$base, 'type' => 'nota_sem_destino', 'severity' => $value >= 5000 ? 'media' : 'baixa', 'amount_cents' => $value,
                    'title' => 'Peças da nota sem destino',
                    'detail' => $label.': '.$withoutDestination->count().' item(ns), '.Money::brl($value).', sem OS, estoque ou uso interno. Peça comprada e não aplicada é dinheiro parado ou perdido.']);
            }

            // Preço acima do histórico.
            if ($this->days($invoice->issue_date->toDateString(), $today) <= 90) {
                foreach ($itemsByInvoice[$invoice->id] ?? [] as $item) {
                    if (! $item->code || $item->unit_price_cents <= 0) {
                        continue;
                    }

                    $past = $history[$invoice->supplier_id.'|'.$item->code] ?? [];
                    $position = collect($past)->search(fn ($h) => $h['invoice'] === $invoice->id);

                    if ($position === false || $position === 0) {
                        continue;
                    }

                    $before = array_map(fn ($h) => $h['price'], array_slice($past, max(0, $position - 5), $position - max(0, $position - 5)));
                    sort($before);

                    if (count($before) < 2) {
                        continue;
                    }

                    $median = $before[intdiv(count($before), 2)];
                    $price = (int) $item->unit_price_cents;

                    if ($price > $median * (1 + PurchaseSettings::priceAlertPct() / 100) && $price - $median >= 300) {
                        $add(['type' => 'preco_acima', 'severity' => 'baixa', 'entity' => 'item', 'id' => $item->id, 'invoices' => [$invoice->id], 'amount_cents' => $price - $median,
                            'date' => $invoice->issue_date->toDateString(),
                            'title' => 'Preço acima do que costuma ser',
                            'detail' => $item->description.' (nota '.$invoice->number.', '.($invoice->supplier?->name ?? 'fornecedor').'): '.Money::brl($price).' cada; nas últimas compras a mediana foi '.Money::brl($median).' (+'.round(($price / $median - 1) * 100).'%).']);
                    }
                }
            }
        }
    }

    private function orderRules(callable $add, string $since): void
    {
        $allocations = PurchaseAllocation::query()->where('destination', 'os')->whereNotNull('service_order_id')
            ->with('item.invoice:id,status,purpose')->get()->groupBy('service_order_id');
        $orders = ServiceOrder::query()->where('stage', '!=', Stage::Cancelada->value)
            ->where(fn ($q) => $q->whereIn('id', $allocations->keys())->orWhere('entry_date', '>=', $since))
            ->with(['vehicle:id,plate', 'items' => fn ($q) => $q->where('kind', OrderItem::KIND_PART)])->get();
        $withoutInvoice = [];

        foreach ($orders as $order) {
            $parts = $order->items->filter(fn (OrderItem $i) => ! ($i->origin?->skipsQuote() ?? false) && $i->approval !== OrderItem::REFUSED && ! $i->not_used && $i->cost_cents !== null);
            $cost = $parts->isEmpty() ? null : (int) $parts->sum(fn (OrderItem $i) => (int) $i->lineCostCents());
            $mine = $allocations[$order->id] ?? collect();
            $fromInvoices = (int) $mine->filter(fn ($a) => $a->item?->invoice?->status === PurchaseInvoice::ACTIVE)->sum('cost_cents');
            $label = trim('OS '.$order->number.' '.($order->vehicle?->plate ?? ''));

            if ($mine->isNotEmpty() && $cost !== null && abs($cost - $fromInvoices) > max(500, (int) round($cost * 0.05))) {
                $add(['type' => 'custo_os_diverge', 'severity' => 'media', 'entity' => 'os', 'id' => $order->id, 'amount_cents' => $cost - $fromInvoices,
                    'date' => $order->entry_date?->toDateString(), 'state' => $cost.'|'.$fromInvoices,
                    'title' => 'Custo da OS diferente das notas',
                    'detail' => $label.': custo das peças '.Money::brl($cost).', mas as notas ligadas somam '.Money::brl($fromInvoices).'. Um dos dois está errado.']);
            }

            if ($mine->isEmpty() && $order->entry_date && $order->entry_date->toDateString() >= $since && $cost !== null && $cost >= 3000) {
                $withoutInvoice[] = ['order' => $order, 'cost' => $cost];
            }
        }

        if ($withoutInvoice !== []) {
            $total = array_sum(array_column($withoutInvoice, 'cost'));
            usort($withoutInvoice, fn ($a, $b) => $b['cost'] <=> $a['cost']);
            $largest = implode(', ', array_map(fn ($o) => 'OS '.$o['order']->number.' ('.Money::brl($o['cost']).')', array_slice($withoutInvoice, 0, 3)));
            $add(['type' => 'os_sem_nota', 'severity' => 'baixa', 'entity' => 'os', 'id' => 0, 'amount_cents' => $total, 'date' => $since, 'state' => $since.'|'.count($withoutInvoice),
                'title' => count($withoutInvoice).' OS com custo de peça e nenhuma nota ligada',
                'detail' => 'Desde '.CarbonImmutable::parse($since)->format('d/m/Y').': '.Money::brl($total).' de custo de peças que não se prova com nota. Maiores: '.$largest.'. Ligue as peças às OS em Compras > Notas para saber o custo real e achar compra sem destino.']);
        }
    }

    private function groupRules(callable $add, $group, string $today): void
    {
        if ($group->isNotEmpty()) {
            // Peça de nota em nome de outra empresa do grupo aplicada em OS da oficina: alguém pagou por algo que é da oficina.
            $inOrders = PurchaseAllocation::query()->where('destination', 'os')->valid()
                ->whereHas('item.invoice', fn ($q) => $q->where('status', PurchaseInvoice::ACTIVE)->whereIn('recipient_cnpj', $group->keys()))
                ->with('item.invoice.supplier:id,name')->get()->groupBy('item.invoice.id');

            foreach ($inOrders as $allocations) {
                $invoice = $allocations->first()->item->invoice;
                $company = $group[$invoice->recipient_cnpj];
                $value = (int) $allocations->sum('cost_cents');
                $add(['type' => 'peca_de_empresa_na_os', 'severity' => 'media', 'entity' => 'nota', 'id' => $invoice->id, 'invoices' => [$invoice->id], 'amount_cents' => $value,
                    'date' => $invoice->issue_date->toDateString(), 'state' => (string) $value,
                    'title' => 'Peças pagas pela '.$company->name.' usadas em OS da oficina',
                    'detail' => 'Nota '.$invoice->number.' de '.($invoice->supplier?->name ?? 'fornecedor').' está em nome da '.$company->name.', mas '.Money::brl($value).' dela foram aplicados em OS da oficina. A '.$company->name.' pagou por algo que é custo da oficina: registre o acerto em Compras > Grupo (a oficina deve à '.$company->name.') ou marque como conferida se já foi combinado.']);
            }
        }

        $settlements = GroupSettlement::query()->with(['company:id,name', 'returns'])->get();
        $deadline = PurchaseSettings::groupReturnDays();

        foreach ($settlements as $settlement) {
            $missing = $settlement->balanceCents();

            if ($missing <= 0) {
                continue;
            }

            // Peça entregue a outra empresa cuja nota foi cancelada depois: o valor a receber já não tem base.
            if ($settlement->source === 'peca' && $settlement->purchase_allocation_id) {
                $allocation = PurchaseAllocation::query()->with('item.invoice:id,number,status')->find($settlement->purchase_allocation_id);

                if ($allocation?->item?->invoice?->status === PurchaseInvoice::CANCELED) {
                    $add(['type' => 'acerto_de_nota_cancelada', 'severity' => 'media', 'entity' => 'acerto', 'id' => $settlement->id, 'amount_cents' => $settlement->amount_cents, 'state' => (string) $settlement->amount_cents,
                        'title' => 'A receber da '.$settlement->company->name.' por peças de nota cancelada',
                        'detail' => 'A nota '.$allocation->item->invoice->number.' foi cancelada, mas as peças dela ainda estão como "a receber" da '.$settlement->company->name.'. Confira se o valor ainda é devido e, se não for, desfaça o destino das peças.']);
                }
            }

            $days = $this->days($settlement->occurred_on->toDateString(), $today);

            if ($days > $deadline) {
                $add(['type' => 'acerto_antigo', 'severity' => 'media', 'entity' => 'acerto', 'id' => $settlement->id, 'amount_cents' => $missing,
                    'date' => $settlement->occurred_on->toDateString(), 'state' => (string) $missing,
                    'title' => $settlement->direction === GroupSettlement::RECEIVE ? $settlement->company->name.' deve à oficina há '.$days.' dias' : 'A oficina deve à '.$settlement->company->name.' há '.$days.' dias',
                    'detail' => $settlement->description.': faltam '.Money::brl($missing).' desde '.$settlement->occurred_on->format('d/m/Y').' (prazo combinado: '.$deadline.' dias). Registre a devolução em Compras > Grupo ou combine uma data.']);
            }
        }
    }

    private function ddaRules(callable $add, callable $name, array $cross): void
    {
        foreach ($cross['without_registration'] as $row) {
            $t = $row['title'];
            $add(['type' => 'dda_sem_cadastro', 'severity' => 'alta', 'entity' => 'dda', 'id' => $t->id, 'amount_cents' => $t->amount_cents, 'date' => $t->due_date->toDateString(),
                'state' => $t->amount_cents.'|'.$t->due_date->toDateString(),
                'title' => 'Boleto no DDA do banco que ninguém cadastrou',
                'detail' => 'O banco mostra no DDA um boleto de '.Money::brl($t->amount_cents).' vencendo em '.$t->due_date->format('d/m/Y').($t->payee_name ? ', de '.$t->payee_name : '')
                    .($t->payee_cnpj ? ' (CNPJ '.Document::format($t->payee_cnpj).')' : '').', contra '.($row['scope'] ? 'a empresa do grupo' : 'o CNPJ da oficina')
                    .', e ele NÃO está cadastrado no sistema. Pode ser só falta de cadastro (peça a nota ao fornecedor e cadastre) ou cobrança que não é de vocês. Não pague antes de conferir.']);
        }

        foreach ($cross['diverge'] as $row) {
            if (! in_array($row['slip']->status, [BankSlip::OPEN, BankSlip::PAID], true)) {
                continue;
            }

            $add(['type' => 'dda_diverge', 'severity' => 'alta', 'entity' => 'boleto', 'id' => $row['slip']->id, 'slips' => [$row['slip']->id], 'amount_cents' => $row['slip']->amount_cents,
                'date' => $row['slip']->due_date->toDateString(), 'state' => implode('|', $row['differences']),
                'title' => 'Boleto cadastrado diferente do que o banco mostra no DDA',
                'detail' => $name($row['slip']).': '.implode('; ', $row['differences']).'. O DDA vem do banco; o cadastro foi digitado. Confira o papel do boleto antes de pagar.']);
        }

        foreach ($cross['outside'] as $row) {
            $add(['type' => 'boleto_fora_do_dda', 'severity' => 'media', 'entity' => 'boleto', 'id' => $row['slip']->id, 'slips' => [$row['slip']->id], 'amount_cents' => $row['slip']->amount_cents,
                'date' => $row['slip']->due_date->toDateString(),
                'title' => 'Boleto cadastrado que o banco não mostra no DDA',
                'detail' => $name($row['slip']).': '.Money::brl($row['slip']->amount_cents).', vence '.$row['slip']->due_date->format('d/m/Y').'. O DDA de '.$row['import']->imported_on->format('d/m/Y').' não lista este boleto. Pode ser boleto sem registro (alguns fornecedores usam), já pago, ainda não atualizado no banco, ou falso: confirme com o fornecedor.']);
        }

        foreach ($cross['imports'] as $import) {
            if ($import->days > 7) {
                $add(['type' => 'dda_desatualizado', 'severity' => $import->days > 15 ? 'media' : 'baixa', 'entity' => 'dda_arquivo', 'id' => $import->id, 'date' => $import->imported_on->toDateString(),
                    'state' => (string) intdiv($import->days, 8),
                    'title' => 'DDA do banco desatualizado',
                    'detail' => 'O último DDA importado'.($import->scope ? ' desta empresa' : '').' é de '.$import->imported_on->format('d/m/Y').' ('.$import->days.' dias). Exporte de novo no banco e importe em Compras > DDA: sem isso, boleto novo não é conferido contra o banco.']);
            }
        }
    }

    /** Conta de fornecedor de peças lançada direto no "A pagar", sem passar pela conferência de compras. */
    private function payableRules(callable $add, string $since): void
    {
        $direct = Payable::query()->where('due_date', '>=', $since)->whereDoesntHave('bankSlip')
            ->whereHas('supplier', fn ($q) => $q->whereIn('kind', ['pecas', 'ambos']))->with('supplier:id,name')->get();

        if ($direct->isEmpty()) {
            return;
        }

        $total = (int) $direct->sum('amount_cents');
        $add(['type' => 'pagar_pecas_sem_boleto', 'severity' => 'media', 'entity' => 'pagar', 'id' => 0, 'amount_cents' => $total, 'date' => $since,
            'state' => $since.'|'.$direct->count().'|'.$total,
            'title' => $direct->count().' conta(s) de peças lançada(s) no A pagar sem passar por Compras',
            'detail' => Money::brl($total).' em contas de fornecedores de peças que não passaram pela conferência (sem nota nem boleto ligados). A regra é: todo boleto de fornecedor entra por Compras. Maiores: '
                .$direct->sortByDesc('amount_cents')->take(3)->map(fn (Payable $p) => ($p->supplier?->name ?? 'fornecedor').' ('.Money::brl($p->amount_cents).')')->implode(', ').'.']);
    }

    // ------------------------------------------------------------------ prova da nota

    private function authorizedXml(PurchaseInvoice $invoice): bool
    {
        return $invoice->source !== PurchaseInvoice::SOURCE_MANUAL && in_array((string) $invoice->protocol_status, self::AUTHORIZED, true);
    }

    private function portalAmountMatches(PurchaseInvoice $invoice): bool
    {
        return $invoice->portal_amount_cents !== null && abs($invoice->portal_amount_cents - $invoice->total_cents) <= PurchaseSettings::toleranceCents();
    }

    /** O destinatário que o portal mostrou (pode vir com asteriscos) casa com a nota, ou com a oficina ou uma empresa do grupo. */
    private function portalRecipientOk(PurchaseInvoice $invoice, array $owners): bool
    {
        if (! $invoice->portal_recipient) {
            return true;
        }

        return $invoice->recipient_cnpj
            ? self::recipientMatches($invoice->portal_recipient, $invoice->recipient_cnpj)
            : collect($owners)->contains(fn ($cnpj) => self::recipientMatches($invoice->portal_recipient, $cnpj));
    }

    /** "45.723.***\/0001-10" casa com o CNPJ quando cada posição sem asterisco é igual. */
    public static function recipientMatches(?string $shown, ?string $cnpj): bool
    {
        $shown = (string) preg_replace('/[^0-9A-Z*]/', '', mb_strtoupper((string) $shown));

        if ($shown === '' || ! $cnpj || strlen($shown) !== strlen($cnpj)) {
            return false;
        }

        for ($i = 0; $i < strlen($shown); $i++) {
            if ($shown[$i] !== '*' && $shown[$i] !== $cnpj[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * A nota prova que existe quando tem protocolo de autorização no XML, ou quando o GESTOR consultou a chave no portal e
     * viu "autorizada" com o mesmo valor (e, se o portal mostrou, a mesma emissão e o mesmo destinatário).
     */
    private function hasProof(PurchaseInvoice $invoice, array $owners): bool
    {
        return $this->authorizedXml($invoice)
            || ($invoice->portal_status === 'autorizada' && $invoice->portal_checked_role === 'gestor' && $this->portalAmountMatches($invoice)
                && (! $invoice->portal_issue_date || $invoice->portal_issue_date->toDateString() === $invoice->issue_date->toDateString())
                && $this->portalRecipientOk($invoice, $owners));
    }

    private function checkState(PurchaseInvoice $invoice): string
    {
        return implode(':', [$invoice->portal_status, $invoice->portal_checked_role, $invoice->portal_amount_cents, $invoice->portal_issue_date?->toDateString(), $invoice->portal_recipient]);
    }

    /** Por que a nota ainda não prova nada, em palavras que a pessoa sabe resolver. */
    private function whyWithoutProof(PurchaseInvoice $invoice): string
    {
        $number = $invoice->number;

        return match (true) {
            $invoice->portal_status === 'nao_encontrada' => "A chave da nota {$number} NÃO foi encontrada no portal da NF-e: a nota pode ser falsa ou a chave estar errada.",
            $invoice->portal_status === 'autorizada' && $invoice->portal_amount_cents !== null && ! $this->portalAmountMatches($invoice) => 'O portal mostrou '.Money::brl($invoice->portal_amount_cents)." para a nota {$number}, mas ela foi digitada com ".Money::brl($invoice->total_cents).'. Um dos dois está errado.',
            $invoice->portal_status === 'autorizada' && $invoice->portal_issue_date && $invoice->portal_issue_date->toDateString() !== $invoice->issue_date->toDateString() => "O portal mostrou a nota {$number} emitida em ".$invoice->portal_issue_date->format('d/m/Y').', e ela foi digitada com outra data.',
            $invoice->portal_status === 'autorizada' && $invoice->portal_recipient && $invoice->portal_checked_role === 'gestor' => "O portal mostrou a nota {$number} para o destinatário {$invoice->portal_recipient}, que não é a oficina nem empresa do grupo: pode ser nota de outro cliente do fornecedor.",
            $invoice->portal_status === 'autorizada' && $invoice->portal_checked_role !== 'gestor' => "A nota {$number} foi consultada no portal pelo atendimento. Falta o gestor repetir a consulta (leva um minuto) e registrar.",
            default => "A nota {$number} foi digitada à mão ou o XML não traz protocolo de autorização, então não prova nada. Importe o XML autorizado ou consulte a chave no portal da NF-e e registre a consulta na nota.",
        };
    }

    // ------------------------------------------------------------------ apoio

    /** @return list<string> */
    private function invoiceColumns(): array
    {
        return ['id', 'supplier_id', 'access_key', 'number', 'series', 'issue_date', 'total_cents', 'total_with_taxes_cents', 'paid_upfront', 'payee_cnpj',
            'group_company_id', 'issuer_cnpj', 'recipient_cnpj', 'recipient_name', 'purpose', 'status', 'protocol_status', 'source', 'xml_sha256',
            'portal_checked_on', 'portal_status', 'portal_amount_cents', 'portal_issue_date', 'portal_recipient', 'portal_checked_role', 'created_role'];
    }

    /** O registro da ocorrência, para a trilha (boleto, nota, fornecedor...). */
    private function subject(array $occurrence): ?Model
    {
        return match ($occurrence['entity']) {
            'boleto' => BankSlip::find($occurrence['id']),
            'nota' => PurchaseInvoice::find($occurrence['id']),
            'fornecedor' => Supplier::find($occurrence['id']),
            'os' => $occurrence['id'] ? ServiceOrder::find($occurrence['id']) : null,
            'acerto' => GroupSettlement::find($occurrence['id']),
            default => null,
        };
    }

    /** @return list<string> números do documento impresso (sem zeros à esquerda, com 3 dígitos ou mais) */
    private function documentNumbers(?string $document): array
    {
        preg_match_all('/\d+/', (string) $document, $m);

        return array_values(array_filter(array_map(fn ($d) => $this->noZeros($d), $m[0]), fn ($d) => strlen($d) >= 3));
    }

    private function noZeros(?string $number): string
    {
        return ltrim((string) preg_replace('/\D/', '', (string) $number), '0');
    }

    private function plusDays(string $date, int $days): string
    {
        return CarbonImmutable::parse($date)->addDays($days)->toDateString();
    }

    private function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);
    }
}
