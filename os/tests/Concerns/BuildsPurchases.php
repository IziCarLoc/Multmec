<?php

namespace Tests\Concerns;

use App\Support\Purchases\AccessKey;
use App\Support\Purchases\BoletoCode;

/**
 * Documentos de compra para os testes (porte dos helpers do sistema em Node, `app/test/helpers`): NF-e montada por
 * nós (leiaute 4.00 simplificado) com chave válida, linha digitável válida e arquivo CNAB 240 do DDA. Os CNPJs são
 * inventados (dígito verificador válido); o do fornecedor é o exemplo clássico de livro.
 */
trait BuildsPurchases
{
    protected const SUPPLIER_CNPJ = '11222333000181';

    protected const SUPPLIER_BRANCH_CNPJ = '11222333000262';

    protected const WORKSHOP_CNPJ = '55666777000181';

    protected const OTHER_CNPJ = '44555666000181';

    protected const RENTAL_CNPJ = '66777888000181';

    protected const UNKNOWN_CNPJ = '77888999000181';

    protected const SUPPLIER_NAME = 'DISTRIBUIDORA TESTE LTDA';

    /** Chave de acesso válida (padrões: RS, outubro de 2026, fornecedor de teste, modelo 55, série 1, nota 1234). */
    protected function nfeKey(array $o = []): string
    {
        return AccessKey::build($o['uf'] ?? '43', $o['yymm'] ?? '2610', $o['cnpj'] ?? self::SUPPLIER_CNPJ, $o['model'] ?? '55', $o['series'] ?? 1, $o['number'] ?? 1234, $o['emission'] ?? '1', $o['code'] ?? '12345678');
    }

    /**
     * XML de NF-e. Itens: [['code' => 'P1', 'name' => 'PASTILHA', 'qty' => 1, 'total' => 100.0, 'order' => 'OS1043']];
     * parcelas: [['due' => '2026-10-31', 'amount' => 150.0]]. Valores em reais (como no XML).
     */
    protected function nfeXml(array $o = []): string
    {
        $number = $o['number'] ?? 1234;
        $series = $o['series'] ?? 1;
        $issuer = $o['issuer'] ?? self::SUPPLIER_CNPJ;
        $issuerName = $o['issuer_name'] ?? self::SUPPLIER_NAME;
        $recipient = $o['recipient'] ?? self::WORKSHOP_CNPJ;
        $model = $o['model'] ?? '55';
        $items = $o['items'] ?? [['code' => 'P1', 'name' => 'PASTILHA DE FREIO', 'qty' => 1, 'total' => 100.0]];
        $installments = $o['installments'] ?? [];
        $freight = $o['freight'] ?? 0.0;
        $issuedAt = $o['issued_at'] ?? '2026-10-01T09:12:26-03:00';
        $cfop = $o['cfop'] ?? '5102';
        $key = $o['key'] ?? $this->nfeKey(['number' => $number, 'series' => $series, 'cnpj' => $issuer, 'model' => $model, 'yymm' => substr($issuedAt, 2, 2).substr($issuedAt, 5, 2)]);
        $products = array_sum(array_column($items, 'total'));
        $total = $o['total'] ?? $products + $freight;
        $f2 = fn ($v) => number_format((float) $v, 2, '.', '');

        $det = '';
        foreach ($items as $k => $item) {
            $det .= '<det nItem="'.($k + 1).'"><prod><cProd>'.$item['code'].'</cProd><cEAN>SEM GTIN</cEAN><xProd>'.$item['name'].'</xProd><NCM>87083090</NCM><CFOP>'.$cfop.'</CFOP><uCom>UN</uCom>'
                .'<qCom>'.number_format($item['qty'], 4, '.', '').'</qCom><vUnCom>'.number_format($item['total'] / $item['qty'], 10, '.', '').'</vUnCom><vProd>'.$f2($item['total']).'</vProd>'
                .(isset($item['order']) ? '<xPed>'.$item['order'].'</xPed><nItemPed>'.($k + 1).'</nItemPed>' : '').'</prod>'.($item['tax'] ?? '').'</det>';
        }

        $cobr = '';
        if ($installments !== []) {
            $cobr = '<cobr><fat><nFat>'.$number.'</nFat><vOrig>'.$f2($total).'</vOrig><vLiq>'.$f2($total).'</vLiq></fat>';
            foreach ($installments as $k => $dup) {
                $cobr .= '<dup><nDup>'.str_pad((string) ($k + 1), 3, '0', STR_PAD_LEFT).'</nDup><dVenc>'.$dup['due'].'</dVenc><vDup>'.$f2($dup['amount']).'</vDup></dup>';
            }
            $cobr .= '</cobr>';
        }

        $pag = isset($o['payment']) ? '<pag>'.$o['payment'].'</pag>' : '';
        $nfe = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.$key.'" versao="4.00"><ide><cUF>43</cUF><cNF>12345678</cNF><natOp>VENDA DE MERCADORIA</natOp>'
            .'<mod>'.$model.'</mod><serie>'.$series.'</serie><nNF>'.$number.'</nNF><dhEmi>'.$issuedAt.'</dhEmi><tpNF>1</tpNF><finNFe>'.($o['purpose'] ?? 1).'</finNFe></ide>'
            .'<emit><CNPJ>'.$issuer.'</CNPJ><xNome>'.$issuerName.'</xNome></emit><dest><CNPJ>'.$recipient.'</CNPJ><xNome>OFICINA TESTE LTDA</xNome></dest>'.$det
            .'<total><ICMSTot><vProd>'.$f2($products).'</vProd><vFrete>'.$f2($freight).'</vFrete><vDesc>0.00</vDesc><vNF>'.$f2($total).'</vNF></ICMSTot></total>'
            .$cobr.$pag.'<infAdic><infCpl>'.($o['info'] ?? '').'</infCpl></infAdic></infNFe></NFe>';

        if (! ($o['protocol'] ?? true)) {
            return '<?xml version="1.0" encoding="UTF-8"?>'.$nfe;
        }

        return '<?xml version="1.0" encoding="UTF-8"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.$nfe
            .'<protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><chNFe>'.$key.'</chNFe><cStat>'.($o['status'] ?? '100').'</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo>'
            .'<nProt>143260000000001</nProt></infProt></protNFe></nfeProc>';
    }

    protected function cancellationXml(string $key, bool $homologated = true): string
    {
        $answer = $homologated
            ? '<retEvento versao="1.00"><infEvento><tpAmb>1</tpAmb><cStat>135</cStat><xMotivo>Evento registrado e vinculado a NF-e</xMotivo><chNFe>'.$key.'</chNFe><tpEvento>110111</tpEvento></infEvento></retEvento>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8"?><procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><evento versao="1.00"><infEvento Id="ID1101114'.$key.'01">'
            .'<cOrgao>43</cOrgao><tpAmb>1</tpAmb><chNFe>'.$key.'</chNFe><tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento><detEvento versao="1.00"><descEvento>Cancelamento</descEvento>'
            .'<nProt>143260000000001</nProt><xJust>Erro na emissão da nota</xJust></detEvento></infEvento></evento>'.$answer.'</procEventoNFe>';
    }

    /** Linha digitável válida; cada chamada usa um campo livre diferente (como o contador do helper do Node). */
    protected function slipLine(float $amount, string $due, string $bank = '341', int|string|null $free = null): string
    {
        static $counter = 0;
        $counter++;

        return BoletoCode::buildLine($bank, (int) round($amount * 100), $due, $free ?? $counter * 7919);
    }

    protected function barcodeOf(string $line): string
    {
        return BoletoCode::lineToBarcode($line);
    }

    /**
     * Arquivo CNAB 240 do DDA (leiaute FEBRABAN, segmentos G, H e Y-03). Títulos: ['barcode', 'payee' (CNPJ), 'payee_name',
     * 'due' (ddmmaaaa), 'amount' (reais), 'document', 'movement', 'drawer', 'drawer_name', 'payer'].
     */
    protected function cnabDda(string $companyCnpj, array $titles = [], ?array $lots = null, string $generated = '08102026', string $bank = '341'): string
    {
        $num = fn ($v, $n) => substr(str_pad((string) preg_replace('/\D/', '', (string) $v), $n, '0', STR_PAD_LEFT), -$n);
        $alpha = fn ($v, $n) => substr(str_pad((string) $v, $n), 0, $n);
        $record = function (array $fields) {
            $line = str_repeat(' ', 240);
            foreach ($fields as [$start, $end, $value]) {
                $line = substr_replace($line, substr(str_pad((string) $value, $end - $start + 1), 0, $end - $start + 1), $start - 1, $end - $start + 1);
            }

            return $line;
        };

        $lines = [$record([[1, 3, $bank], [4, 7, '0000'], [8, 8, '0'], [18, 18, '2'], [19, 32, $num($companyCnpj, 14)], [73, 102, $alpha('EMPRESA TESTE LTDA', 30)],
            [103, 132, $alpha('BANCO TESTE', 30)], [143, 143, '2'], [144, 151, $generated], [152, 157, '101500'], [158, 163, '000001'], [164, 166, '084']])];
        $lots ??= [['cnpj' => $companyCnpj, 'titles' => $titles]];
        $seq = 0;

        foreach ($lots as $k => $lot) {
            $lotNumber = str_pad((string) ($k + 1), 4, '0', STR_PAD_LEFT);
            $lines[] = $record([[1, 3, $bank], [4, 7, $lotNumber], [8, 8, '1'], [9, 9, 'I'], [10, 11, '03'], [14, 16, '022'], [18, 18, '2'], [19, 33, $num($lot['cnpj'], 15)], [74, 103, $alpha('EMPRESA TESTE LTDA', 30)]]);

            foreach ($lot['titles'] as $t) {
                $movement = $t['movement'] ?? '01';
                $lines[] = $record([[1, 3, $bank], [4, 7, $lotNumber], [8, 8, '3'], [9, 13, $num(++$seq, 5)], [14, 14, 'G'], [16, 17, $movement], [18, 61, $t['barcode']], [62, 62, '2'],
                    [63, 77, $num($t['payee'], 15)], [78, 107, $alpha($t['payee_name'] ?? 'BENEFICIARIO', 30)], [108, 115, $t['due']], [116, 130, $num((int) round($t['amount'] * 100), 15)],
                    [146, 147, '09'], [148, 162, $alpha($t['document'] ?? '', 15)], [182, 189, $t['issued'] ?? '01102026']]);
                $lines[] = $record([[1, 3, $bank], [4, 7, $lotNumber], [8, 8, '3'], [9, 13, $num(++$seq, 5)], [14, 14, 'H'], [16, 17, $movement], [18, 18, isset($t['drawer']) ? '2' : '0'],
                    [19, 33, $num($t['drawer'] ?? 0, 15)], [34, 73, $alpha($t['drawer_name'] ?? '', 40)]]);
                $lines[] = $record([[1, 3, $bank], [4, 7, $lotNumber], [8, 8, '3'], [9, 13, $num(++$seq, 5)], [14, 14, 'Y'], [16, 17, $movement], [18, 19, '03'], [20, 20, '2'],
                    [21, 35, $num($t['payer'] ?? $lot['cnpj'], 15)], [36, 75, $alpha('EMPRESA TESTE LTDA', 40)]]);
            }

            $lines[] = $record([[1, 3, $bank], [4, 7, $lotNumber], [8, 8, '5'], [18, 23, $num(count($lot['titles']) * 3 + 2, 6)]]);
        }

        $lines[] = $record([[1, 3, $bank], [4, 7, '9999'], [8, 8, '9'], [18, 23, $num(count($lots), 6)], [24, 29, $num($seq + 2 * count($lots) + 2, 6)]]);

        return implode("\r\n", $lines)."\r\n\x1a";
    }
}
