<?php

namespace App\Support\Purchases;

use App\Support\Document;
use App\Support\Money;
use DomainException;
use DOMDocument;
use DOMElement;

/**
 * Leitura do XML da NF-e (modelo 55, leiaute 4.00): só o que a conferência precisa. Aceita o nfeProc (com
 * protocolo), a NFe solta e o XML do evento de cancelamento. Confere que chave, número, série, CNPJ e protocolo
 * são coerentes entre si; NÃO confere a assinatura digital nem consulta a SEFAZ (a prova forte é a consulta da
 * chave no portal da NF-e). Erro de leitura é DomainException com a mensagem para a pessoa.
 *
 * Porte de `app/src/nfe.js`. Dinheiro em centavos.
 */
final class NfeReader
{
    private const MAX_BYTES = 3_000_000;

    /** 5 (nota de crédito) abate o que se deve, como a devolução; 6 (nota de débito) acrescenta, como a complementar. */
    private const PURPOSE = [1 => 'normal', 2 => 'complementar', 3 => 'ajuste', 4 => 'devolucao', 5 => 'devolucao', 6 => 'complementar'];

    /** cStat do protocolo: 101/151 cancelada; 110/301/302/303 uso denegado (a nota existe mas não vale). */
    public const STATUS_CANCELED = ['101', '151'];

    public const STATUS_DENIED = ['110', '301', '302', '303'];

    /** Formas de pagamento que quitam no ato (dinheiro, cheque, cartões, vales, Pix...): a nota não deveria vir com boleto. */
    private const IMMEDIATE = ['01', '02', '03', '04', '05', '10', '11', '12', '13', '17', '18', '19'];

    /** @return array<string, mixed> */
    public static function read(?string $xml): array
    {
        $raw = (string) $xml;

        if (trim($raw) === '') {
            throw new DomainException('O arquivo está vazio.');
        }

        if (strlen($raw) > self::MAX_BYTES) {
            throw new DomainException('Arquivo grande demais para ser uma NF-e.');
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $raw) === 1) {
            throw new DomainException('Este XML tem declarações que uma NF-e não usa; não vou ler por segurança.');
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $dom->documentElement) {
            throw new DomainException('Não consegui ler este arquivo como XML.');
        }

        $hash = hash('sha256', self::normalized($raw));
        $root = $dom->documentElement;

        $event = self::path($root, $root->localName === 'procEventoNFe' ? 'evento/infEvento' : null)
            ?? ($root->localName === 'evento' ? self::path($root, 'infEvento') : null);

        if ($event) {
            return self::cancellation($root, $event, $hash);
        }

        $nfe = match ($root->localName) {
            'nfeProc', 'procNFe' => self::path($root, 'NFe'),
            'NFe' => $root,
            default => null,
        };
        $inf = $nfe ? self::path($nfe, 'infNFe') : null;

        if (! $inf) {
            throw new DomainException('Não encontrei uma NF-e neste XML (esperava nfeProc/NFe/infNFe).');
        }

        return self::invoice($root, $inf, $hash);
    }

    /** O hash identifica o conteúdo, não a formatação: BOM, quebras de linha e espaços entre tags não mudam o resultado. */
    public static function normalized(string $xml): string
    {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
        $xml = preg_replace('/\r\n?/', "\n", $xml) ?? $xml;

        return trim(preg_replace('/>\s+</', '><', $xml) ?? $xml);
    }

    /** @return array<string, mixed> */
    private static function cancellation(DOMElement $root, DOMElement $event, string $hash): array
    {
        $type = self::text($event, 'tpEvento');

        if ($type !== '110111') {
            throw new DomainException("Este XML é um evento ({$type}) que não altera a conferência. Importe o XML da nota.");
        }

        $key = AccessKey::read(self::text($event, 'chNFe'));

        if (! $key['valid']) {
            throw new DomainException('Evento de cancelamento com chave inválida: '.$key['reason']);
        }

        // Só vale o evento que a SEFAZ homologou (retEvento com cStat 135 ou 155); o pedido avulso pode ter sido recusado.
        $answer = $root->localName === 'procEventoNFe' ? self::path($root, 'retEvento/infEvento') : null;
        $answerKey = $answer ? self::text($answer, 'chNFe') : null;

        if (! $answer || ! in_array(self::text($answer, 'cStat'), ['135', '155'], true) || ($answerKey !== null && $answerKey !== $key['key'])) {
            throw new DomainException('Este XML de cancelamento não traz a resposta da SEFAZ (retEvento com cStat 135). Peça o arquivo "procEventoNFe" completo, ou marque a nota como cancelada à mão com o motivo.');
        }

        return ['type' => 'cancelamento', 'key' => $key['key'], 'hash' => $hash, 'reason' => self::text($event, 'detEvento/xJust')];
    }

    /** @return array<string, mixed> */
    private static function invoice(DOMElement $root, DOMElement $inf, string $hash): array
    {
        $ide = self::path($inf, 'ide');
        $model = $ide ? self::text($ide, 'mod') : null;

        if ($model !== '55') {
            throw new DomainException('Só leio NF-e modelo 55 (este é o modelo '.($model ?? '?').'). NFC-e e CT-e não entram aqui.');
        }

        $protocol = $root->localName === 'nfeProc' ? self::path($root, 'protNFe/infProt') : null;
        $keyText = preg_replace('/^NFe/i', '', $inf->getAttribute('Id')) ?: ($protocol ? self::text($protocol, 'chNFe') : null);
        $key = AccessKey::read($keyText);

        if (! $key['valid']) {
            throw new DomainException($key['reason']);
        }

        $emit = self::path($inf, 'emit');
        $issuerCnpj = Document::normalizeCnpj($emit ? (self::text($emit, 'CNPJ') ?? self::text($emit, 'CPF')) : null);
        // CPF ocupa as 14 posições da chave com zeros à esquerda.
        $issuerInKey = $emit && self::text($emit, 'CPF') !== null && self::text($emit, 'CNPJ') === null ? '000'.$issuerCnpj : $issuerCnpj;

        if ($issuerInKey !== $key['issuer_cnpj']) {
            throw new DomainException('XML inconsistente: o CNPJ do emitente não bate com a chave de acesso. Não use este arquivo.');
        }

        if ((string) (int) self::text($ide, 'nNF') !== $key['number'] || (string) (int) self::text($ide, 'serie') !== $key['series']) {
            throw new DomainException('XML inconsistente: número ou série não batem com a chave de acesso. Não use este arquivo.');
        }

        $issueDate = self::isoDate(self::text($ide, 'dhEmi') ?? self::text($ide, 'dEmi'));

        if (! $issueDate) {
            throw new DomainException('A nota não traz uma data de emissão válida. Não use este arquivo.');
        }

        $uf = self::text($ide, 'cUF');
        $emission = self::text($ide, 'tpEmis');
        $code = self::text($ide, 'cNF');
        $dv = self::text($ide, 'cDV');

        if ($key['model'] !== $model || ($uf !== null && $key['uf'] !== $uf) || $key['yymm'] !== substr($issueDate, 2, 2).substr($issueDate, 5, 2)
            || ($emission !== null && $key['key'][34] !== $emission) || ($code !== null && substr($key['key'], 35, 8) !== str_pad($code, 8, '0', STR_PAD_LEFT))
            || ($dv !== null && $key['key'][43] !== $dv)) {
            throw new DomainException('XML inconsistente: a chave de acesso contradiz os dados da própria nota (estado, mês, modelo ou código). Não use este arquivo.');
        }

        if (self::text($ide, 'tpAmb') === '2') {
            throw new DomainException('Esta nota foi emitida em ambiente de HOMOLOGAÇÃO (teste) e não tem valor fiscal. Peça a nota verdadeira ao fornecedor.');
        }

        $dest = self::path($inf, 'dest');
        $totals = self::path($inf, 'total/ICMSTot');
        $total = $totals ? self::cents(self::text($totals, 'vNF')) : null;

        if ($total === null) {
            throw new DomainException('A nota não traz o valor total (vNF).');
        }

        // Reforma tributária: IBS/CBS "por fora" ficam em vNFTot; o boleto pode vir por qualquer um dos dois valores.
        $totalWithTaxes = self::cents(self::text($inf, 'total/vNFTot'));

        $items = [];
        $consignment = false;

        foreach (self::children($inf, 'det') as $i => $det) {
            $prod = self::path($det, 'prod');
            $number = $det->hasAttribute('nItem') ? $det->getAttribute('nItem') : (string) ($i + 1);

            if (preg_match('/^\d+$/', $number) !== 1 || (int) $number < 1) {
                throw new DomainException('A nota tem um item sem número válido. Não use este arquivo.');
            }

            $tax = self::path($det, 'imposto');
            $icms = $tax ? self::firstChild(self::path($tax, 'ICMS')) : null;
            $ipi = $tax ? (self::cents(self::text($tax, 'IPI/IPITrib/vIPI')) ?? 0) : 0;
            $st = $icms ? (self::cents(self::text($icms, 'vICMSST')) ?? 0) + (self::cents(self::text($icms, 'vFCPST')) ?? 0) : 0;
            $productCents = self::cents($prod ? self::text($prod, 'vProd') : null) ?? 0;
            $discount = self::cents($prod ? self::text($prod, 'vDesc') : null) ?? 0;
            $cfop = $prod ? self::text($prod, 'CFOP') : null;
            $consignment = $consignment || in_array($cfop, ['5917', '6917'], true);

            $items[] = [
                'item_number' => (int) $number,
                'code' => $prod ? self::text($prod, 'cProd') : null,
                'description' => ($prod ? self::text($prod, 'xProd') : null) ?? '(sem descrição)',
                'ncm' => $prod ? self::text($prod, 'NCM') : null,
                'cfop' => $cfop,
                'unit' => $prod ? self::text($prod, 'uCom') : null,
                'quantity' => self::number($prod ? self::text($prod, 'qCom') : null) ?? 1.0,
                'unit_price_cents' => self::cents($prod ? self::text($prod, 'vUnCom') : null) ?? 0,
                'total_cents' => $productCents,
                'discount_cents' => $discount,
                // O que o item custa de verdade: produto − desconto + frete + seguro + outras + IPI + ICMS-ST.
                'base_cost_cents' => $productCents - $discount + (self::cents($prod ? self::text($prod, 'vFrete') : null) ?? 0)
                    + (self::cents($prod ? self::text($prod, 'vSeg') : null) ?? 0) + (self::cents($prod ? self::text($prod, 'vOutro') : null) ?? 0) + $ipi + $st,
                'customer_order' => $prod ? self::text($prod, 'xPed') : null,
                'additional_info' => self::text($det, 'infAdProd'),
            ];
        }

        if ($items === []) {
            throw new DomainException('A nota não tem itens.');
        }

        $installments = [];

        foreach (self::path($inf, 'cobr') ? self::children(self::path($inf, 'cobr'), 'dup') : [] as $dup) {
            $due = self::isoDate(self::text($dup, 'dVenc'));
            $amount = self::cents(self::text($dup, 'vDup'));

            if ($due && $amount !== null) {
                $installments[] = ['number' => self::text($dup, 'nDup'), 'due_date' => $due, 'amount_cents' => $amount];
            }
        }

        $warnings = [];
        $status = 'ativa';
        $protocolStatus = $protocol ? self::text($protocol, 'cStat') : null;

        if (! $protocol) {
            $warnings[] = 'XML sem protocolo de autorização (não é o arquivo "nfeProc"): ainda não dá para dizer que a nota foi autorizada. Confirme pela chave no portal da NF-e.';
        } elseif (($protocolKey = self::text($protocol, 'chNFe')) !== null && $protocolKey !== $key['key']) {
            throw new DomainException('XML inconsistente: a chave do protocolo é diferente da chave da nota. Não use este arquivo.');
        } elseif (in_array($protocolStatus, self::STATUS_CANCELED, true)) {
            $status = 'cancelada';
            $warnings[] = 'O protocolo diz que esta nota foi CANCELADA. Boleto dela não é devido.';
        } elseif (in_array($protocolStatus, self::STATUS_DENIED, true)) {
            $status = 'cancelada';
            $warnings[] = "O protocolo diz que o uso desta nota foi DENEGADO ({$protocolStatus}): ela não vale como documento fiscal. Boleto dela não é devido.";
        } elseif ($protocolStatus !== '100' && $protocolStatus !== '150') {
            $warnings[] = "Situação no protocolo: {$protocolStatus} ".(self::text($protocol, 'xMotivo') ?? '').'. Só 100 e 150 são nota autorizada.';
        }

        if (self::text($ide, 'tpNF') === '0') {
            $warnings[] = 'Esta nota é de ENTRADA (emitida pela própria oficina), não uma venda do fornecedor.';
        }

        if ($issuerCnpj !== '' && strlen($issuerCnpj) === 14 && ! Document::isValidCnpj($issuerCnpj)) {
            $warnings[] = 'O CNPJ do emitente não passa na validação do dígito verificador.';
        }

        $purposeCode = (int) self::text($ide, 'finNFe');

        if ($purposeCode === 3) {
            $warnings[] = 'Nota de AJUSTE: não gera cobrança. Se veio boleto com ela, desconfie.';
        }

        if ($purposeCode === 4 || $purposeCode === 5) {
            $warnings[] = 'Nota de devolução/crédito: reduz o que se deve ao fornecedor; não gera boleto.';
        }

        if ($purposeCode === 2 || $purposeCode === 6) {
            $warnings[] = 'Nota complementar/de débito: cobra um valor a mais sobre outra nota e pode vir com boleto próprio.';
        }

        // Forma de pagamento: tPag 15 = boleto; dinheiro, cartão e Pix quitam no ato (a nota não deve vir com boleto).
        $payments = [];

        foreach (self::children($inf, 'pag') as $pag) {
            foreach (self::children($pag, 'detPag') as $detail) {
                $payments[] = [
                    'type' => self::text($detail, 'tPag'),
                    'indicator' => self::text($detail, 'indPag'),
                    'payee' => Document::normalizeCnpj(self::text($detail, 'card/CNPJReceb')) ?: null,
                ];
            }
        }

        $paidUpfront = $installments === [] && $payments !== []
            && array_reduce($payments, fn ($all, $p) => $all && in_array($p['type'], self::IMMEDIATE, true) && $p['indicator'] !== '1', true);
        $payee = null;

        foreach ($payments as $payment) {
            if ($payment['type'] === '15' && $payment['payee']) {
                $payee = $payment['payee'];
                break;
            }
        }

        if ($paidUpfront) {
            $warnings[] = 'A nota informa pagamento imediato (dinheiro, cartão ou Pix) e não traz parcelas: não deveria vir boleto dela.';
        }

        if ($payee && $payee !== $issuerCnpj) {
            $warnings[] = 'A nota informa que o boleto será recebido pelo CNPJ '.Document::format($payee).' (diferente do emitente): confira.';
        }

        if ($consignment) {
            $warnings[] = 'Remessa em CONSIGNAÇÃO: a cobrança vem depois, na nota de venda. Esta nota ainda não gera boleto.';
        }

        $installmentsSum = array_sum(array_column($installments, 'amount_cents'));

        if ($installments !== [] && abs($installmentsSum - $total) > 5 && ! ($totalWithTaxes !== null && abs($installmentsSum - $totalWithTaxes) <= 5)) {
            $warnings[] = 'As parcelas da nota somam '.Money::brl($installmentsSum).' e o total da nota é '.Money::brl($total).'. Confira antes de pagar.';
        }

        $taxesDiffer = $totalWithTaxes !== null && abs($totalWithTaxes - $total) > 5;

        if ($taxesDiffer) {
            $warnings[] = 'A nota traz um valor com tributos por fora ('.Money::brl($totalWithTaxes).') diferente do total ('.Money::brl($total).'). O boleto pode vir por qualquer um dos dois.';
        }

        $series = $key['series'];

        return [
            'type' => 'nota',
            'hash' => $hash,
            'key' => $key['key'],
            'number' => $key['number'],
            'series' => $series === '0' ? '' : $series,
            'issue_date' => $issueDate,
            'nature' => self::text($ide, 'natOp'),
            'purpose' => $consignment ? 'ajuste' : (self::PURPOSE[$purposeCode] ?? 'normal'),
            'paid_upfront' => $paidUpfront,
            'payee_cnpj' => $payee,
            'total_cents' => $total,
            'total_with_taxes_cents' => $taxesDiffer ? $totalWithTaxes : null,
            'status' => $status,
            'products_cents' => self::cents(self::text($totals, 'vProd')),
            'freight_cents' => self::cents(self::text($totals, 'vFrete')),
            'discount_cents' => self::cents(self::text($totals, 'vDesc')),
            'issuer_cnpj' => $issuerCnpj,
            'issuer_name' => $emit ? self::text($emit, 'xNome') : null,
            'trade_name' => $emit ? self::text($emit, 'xFant') : null,
            'recipient_cnpj' => Document::normalizeCnpj($dest ? (self::text($dest, 'CNPJ') ?? self::text($dest, 'CPF')) : null) ?: null,
            'recipient_name' => $dest ? self::text($dest, 'xNome') : null,
            'additional_info' => self::text($inf, 'infAdic/infCpl'),
            'protocol_status' => $protocolStatus,
            'items' => $items,
            'installments' => $installments,
            'warnings' => $warnings,
        ];
    }

    /** Primeiro filho com o nome local (sem olhar o namespace), seguindo "a/b/c". */
    private static function path(?DOMElement $element, ?string $path): ?DOMElement
    {
        if (! $element || $path === null) {
            return null;
        }

        foreach (explode('/', $path) as $name) {
            $element = self::children($element, $name)[0] ?? null;

            if (! $element) {
                return null;
            }
        }

        return $element;
    }

    /** @return list<DOMElement> */
    private static function children(?DOMElement $element, string $name): array
    {
        $found = [];

        foreach ($element?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private static function firstChild(?DOMElement $element): ?DOMElement
    {
        foreach ($element?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    private static function text(?DOMElement $element, string $path): ?string
    {
        $found = self::path($element, $path);

        if (! $found) {
            return null;
        }

        $value = trim($found->textContent);

        return $value === '' ? null : $value;
    }

    private static function number(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private static function cents(?string $value): ?int
    {
        $number = self::number($value);

        return $number === null ? null : (int) round($number * 100);
    }

    private static function isoDate(?string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
