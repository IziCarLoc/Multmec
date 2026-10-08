// Fixtures de teste: NF-e construída por nós (layout 4.00 simplificado) com chave de acesso válida.
import { dvChaveNfe } from '../../src/documentos.js';

export const CNPJ_FORNECEDOR = '11222333000181';      // CNPJ de teste (DV válido)
export const CNPJ_OFICINA = '45723174000110';         // CNPJ de teste (DV válido)
export const CNPJ_OUTRO = '27865757000102';           // outro CNPJ de teste (DV válido)

export function chave({ uf = '43', aamm = '2610', cnpj = CNPJ_FORNECEDOR, mod = '55', serie = 1, nNF = 1234, cNF = '12345678', tpEmis = '1' } = {}) {
  const base = `${uf}${aamm}${cnpj}${mod}${String(serie).padStart(3, '0')}${String(nNF).padStart(9, '0')}${tpEmis}${cNF}`;
  return base + dvChaveNfe(base);
}

export function xmlNfe({
  nNF = 1234, serie = 1, emitCnpj = CNPJ_FORNECEDOR, emitNome = 'DISTRIBUIDORA TESTE LTDA', destCnpj = CNPJ_OFICINA, mod = '55',
  itens = [{ cProd: 'P1', xProd: 'PASTILHA DE FREIO', q: 1, vProd: 100 }], dups = [], vNF, vFrete = 0, infCpl = '', cStat = '100', proc = true, dhEmi = '2026-10-01T09:12:26-03:00',
  finNFe = 1, chv,
} = {}) {
  const chaveNota = chv ?? chave({ nNF, serie, cnpj: emitCnpj, mod });
  const vProd = itens.reduce((a, i) => a + i.vProd, 0);
  const total = vNF ?? vProd + vFrete;
  const det = itens.map((i, k) => `<det nItem="${k + 1}"><prod><cProd>${i.cProd}</cProd><cEAN>SEM GTIN</cEAN><xProd>${i.xProd}</xProd><NCM>87083090</NCM><CFOP>5102</CFOP><uCom>UN</uCom><qCom>${i.q.toFixed(4)}</qCom><vUnCom>${(i.vProd / i.q).toFixed(10)}</vUnCom><vProd>${i.vProd.toFixed(2)}</vProd>${i.xPed ? `<xPed>${i.xPed}</xPed><nItemPed>${k + 1}</nItemPed>` : ''}</prod></det>`).join('');
  const cobr = dups.length
    ? `<cobr><fat><nFat>${nNF}</nFat><vOrig>${total.toFixed(2)}</vOrig><vLiq>${total.toFixed(2)}</vLiq></fat>${dups.map((d, k) => `<dup><nDup>${String(k + 1).padStart(3, '0')}</nDup><dVenc>${d.venc}</dVenc><vDup>${d.valor.toFixed(2)}</vDup></dup>`).join('')}</cobr>`
    : '';
  const nfe = `<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe${chaveNota}" versao="4.00"><ide><cUF>43</cUF><cNF>12345678</cNF><natOp>VENDA DE MERCADORIA</natOp><mod>${mod}</mod><serie>${serie}</serie><nNF>${nNF}</nNF><dhEmi>${dhEmi}</dhEmi><tpNF>1</tpNF><finNFe>${finNFe}</finNFe></ide><emit><CNPJ>${emitCnpj}</CNPJ><xNome>${emitNome}</xNome></emit><dest><CNPJ>${destCnpj}</CNPJ><xNome>OFICINA TESTE LTDA</xNome></dest>${det}<total><ICMSTot><vProd>${vProd.toFixed(2)}</vProd><vFrete>${vFrete.toFixed(2)}</vFrete><vDesc>0.00</vDesc><vNF>${total.toFixed(2)}</vNF></ICMSTot></total>${cobr}<infAdic><infCpl>${infCpl}</infCpl></infAdic></infNFe></NFe>`;
  return proc
    ? `<?xml version="1.0" encoding="UTF-8"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">${nfe}<protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><chNFe>${chaveNota}</chNFe><cStat>${cStat}</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo><nProt>143260000000001</nProt></infProt></protNFe></nfeProc>`
    : `<?xml version="1.0" encoding="UTF-8"?>${nfe}`;
}

export function xmlCancelamento(chv) {
  return `<?xml version="1.0" encoding="UTF-8"?><procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><evento versao="1.00"><infEvento Id="ID1101114${chv}01"><cOrgao>43</cOrgao><tpAmb>1</tpAmb><chNFe>${chv}</chNFe><tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento><detEvento versao="1.00"><descEvento>Cancelamento</descEvento><nProt>143260000000001</nProt><xJust>Erro na emissão da nota</xJust></detEvento></infEvento></evento></procEventoNFe>`;
}
