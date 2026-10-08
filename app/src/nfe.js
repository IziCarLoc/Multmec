// Leitura do XML da NF-e (modelo 55, layout 4.00): só o que a auditoria precisa.
import { createHash } from 'node:crypto';
import { XMLParser } from 'fast-xml-parser';
import { lerChaveNfe, normalizarCnpj, cnpjValido } from './documentos.js';
import { r2 } from './util.js';

const TAMANHO_MAXIMO = 3_000_000;
const SEMPRE_LISTA = new Set(['det', 'dup', 'pag', 'detPag', 'NFref']);

const parser = new XMLParser({
  ignoreAttributes: false,
  attributeNamePrefix: '@_',
  parseTagValue: false,          // tudo como texto: valores e CNPJ nunca viram número sem querer
  parseAttributeValue: false,
  trimValues: true,
  removeNSPrefix: true,
  processEntities: false,        // a NF-e não usa entidades; nada é expandido
  isArray: (nome) => SEMPRE_LISTA.has(nome),
});

export class ErroNfe extends Error {}

const num = (v) => {
  if (v === undefined || v === null || v === '') return null;
  const n = Number(String(v).replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};
const texto = (v) => (v === undefined || v === null ? null : String(v).trim() || null);
const dataIso = (v) => {
  const m = String(v ?? '').match(/^(\d{4}-\d{2}-\d{2})/);
  return m ? m[1] : null;
};

// 5 (nota de crédito) abate o que se deve, como a devolução; 6 (nota de débito) acrescenta, como a complementar
const FINALIDADE = { 1: 'normal', 2: 'complementar', 3: 'ajuste', 4: 'devolucao', 5: 'devolucao', 6: 'complementar' };
// cStat do protocolo: 100/150 autorizada; 101/151 cancelada; 110/301/302/303 uso denegado (a nota existe mas não vale)
const CSTAT_CANCELADA = new Set(['101', '151']);
const CSTAT_DENEGADA = new Set(['110', '301', '302', '303']);

/** Lê o XML. Aceita nfeProc (com protocolo), NFe solta e o XML de evento de cancelamento. */
export function lerXmlNfe(xml) {
  const bruto = String(xml ?? '');
  if (!bruto.trim()) throw new ErroNfe('O arquivo está vazio.');
  if (bruto.length > TAMANHO_MAXIMO) throw new ErroNfe('Arquivo grande demais para ser uma NF-e.');
  if (/<!DOCTYPE|<!ENTITY/i.test(bruto)) throw new ErroNfe('Este XML tem declarações que uma NF-e não usa; não vou ler por segurança.');
  let doc;
  try { doc = parser.parse(bruto); } catch { throw new ErroNfe('Não consegui ler este arquivo como XML.'); }
  const hash = createHash('sha256').update(bruto).digest('hex');

  const evento = doc.procEventoNFe?.evento?.infEvento ?? doc.evento?.infEvento;
  if (evento) {
    if (String(evento.tpEvento) !== '110111') throw new ErroNfe(`Este XML é um evento (${evento.tpEvento}) que não altera a conciliação. Importe o XML da nota.`);
    const ch = lerChaveNfe(evento.chNFe);
    if (!ch.valida) throw new ErroNfe(`Evento de cancelamento com chave inválida: ${ch.motivo}`);
    return { tipo: 'cancelamento', chave: ch.chave, hash, motivo: texto(evento.detEvento?.xJust) };
  }

  const nfe = doc.nfeProc?.NFe ?? doc.NFe ?? doc.procNFe?.NFe;
  const inf = nfe?.infNFe;
  if (!inf) throw new ErroNfe('Não encontrei uma NF-e neste XML (esperava nfeProc/NFe/infNFe).');
  const ide = inf.ide ?? {};
  if (String(ide.mod) !== '55') throw new ErroNfe(`Só leio NF-e modelo 55 (este é o modelo ${ide.mod ?? '?'}). NFC-e e CT-e não entram aqui.`);

  const chave = lerChaveNfe(String(inf['@_Id'] ?? '').replace(/^NFe/i, '') || doc.nfeProc?.protNFe?.infProt?.chNFe);
  if (!chave.valida) throw new ErroNfe(chave.motivo);
  const emit = inf.emit ?? {};
  const cnpjEmit = normalizarCnpj(emit.CNPJ ?? emit.CPF);
  if (cnpjEmit !== chave.cnpjEmitente && normalizarCnpj(emit.CNPJ) !== chave.cnpjEmitente) {
    throw new ErroNfe('XML inconsistente: o CNPJ do emitente não bate com a chave de acesso. Não use este arquivo.');
  }
  if (String(Number(ide.nNF)) !== chave.numero || String(Number(ide.serie)) !== chave.serie) {
    throw new ErroNfe('XML inconsistente: número ou série não batem com a chave de acesso. Não use este arquivo.');
  }

  if (String(ide.tpAmb) === '2') throw new ErroNfe('Esta nota foi emitida em ambiente de HOMOLOGAÇÃO (teste) e não tem valor fiscal. Peça a nota verdadeira ao fornecedor.');

  const dest = inf.dest ?? {};
  const total = inf.total?.ICMSTot ?? {};
  const valorNf = num(total.vNF);
  if (valorNf === null) throw new ErroNfe('A nota não traz o valor total (vNF).');
  // Reforma tributária: IBS/CBS "por fora" ficam em vNFTot; o boleto pode vir por qualquer um dos dois valores
  const valorComTributos = num(inf.total?.vNFTot);

  const itens = (inf.det ?? []).map((d, i) => {
    const p = d.prod ?? {};
    return {
      n_item: Number(d['@_nItem'] ?? i + 1),
      codigo: texto(p.cProd),
      descricao: texto(p.xProd) ?? '(sem descrição)',
      ncm: texto(p.NCM),
      cfop: texto(p.CFOP),
      unidade: texto(p.uCom),
      quantidade: num(p.qCom) ?? 1,
      valor_unitario: num(p.vUnCom) ?? 0,
      valor_total: num(p.vProd) ?? 0,
      valor_desconto: num(p.vDesc) ?? 0,
      x_ped: texto(p.xPed),
    };
  });
  if (!itens.length) throw new ErroNfe('A nota não tem itens.');

  const cobr = inf.cobr ?? {};
  const duplicatas = (cobr.dup ?? []).map((d) => ({ numero: texto(d.nDup), vencimento: dataIso(d.dVenc), valor: num(d.vDup) }))
    .filter((d) => d.vencimento && d.valor !== null);

  const prot = doc.nfeProc?.protNFe?.infProt ?? null;
  const avisos = [];
  const cstat = prot ? String(prot.cStat) : null;
  let situacao = 'ativa';
  if (!prot) avisos.push('XML sem protocolo de autorização (não é o arquivo "nfeProc"): ainda não dá para dizer que a nota foi autorizada. Confirme pela chave no portal da NF-e.');
  else if (prot.chNFe && String(prot.chNFe) !== chave.chave) throw new ErroNfe('XML inconsistente: a chave do protocolo é diferente da chave da nota. Não use este arquivo.');
  else if (CSTAT_CANCELADA.has(cstat)) { situacao = 'cancelada'; avisos.push('O protocolo diz que esta nota foi CANCELADA. Boleto dela não é devido.'); }
  else if (CSTAT_DENEGADA.has(cstat)) { situacao = 'cancelada'; avisos.push(`O protocolo diz que o uso desta nota foi DENEGADO (${cstat}): ela não vale como documento fiscal. Boleto dela não é devido.`); }
  else if (cstat !== '100' && cstat !== '150') avisos.push(`Situação no protocolo: ${cstat} ${prot.xMotivo ?? ''}. Só 100 e 150 são nota autorizada.`);
  if (String(ide.tpNF) === '0') avisos.push('Esta nota é de ENTRADA (emitida pela própria oficina), não uma venda do fornecedor.');
  if (cnpjEmit && !cnpjValido(cnpjEmit) && cnpjEmit.length === 14) avisos.push('O CNPJ do emitente não passa na validação do dígito verificador.');
  const fin = Number(ide.finNFe);
  if (fin === 3) avisos.push('Nota de AJUSTE: não gera cobrança. Se veio boleto com ela, desconfie.');
  if (fin === 4 || fin === 5) avisos.push('Nota de devolução/crédito: reduz o que se deve ao fornecedor; não gera boleto.');
  if (fin === 2 || fin === 6) avisos.push('Nota complementar/de débito: cobra um valor a mais sobre outra nota e pode vir com boleto próprio.');
  const somaParcelas = duplicatas.reduce((a, d) => a + d.valor, 0);
  if (duplicatas.length && Math.abs(somaParcelas - valorNf) > 0.05 && !(valorComTributos !== null && Math.abs(somaParcelas - valorComTributos) <= 0.05)) {
    avisos.push(`As parcelas da nota somam ${r2(somaParcelas).toFixed(2)} e o total da nota é ${valorNf.toFixed(2)}. Confira antes de pagar.`);
  }
  if (valorComTributos !== null && Math.abs(valorComTributos - valorNf) > 0.05) {
    avisos.push(`A nota traz um valor com tributos por fora (${valorComTributos.toFixed(2)}) diferente do total (${valorNf.toFixed(2)}). O boleto pode vir por qualquer um dos dois.`);
  }

  return {
    tipo: 'nota',
    hash,
    chave: chave.chave,
    numero: chave.numero,
    serie: chave.serie === '0' ? '' : chave.serie,
    data_emissao: dataIso(ide.dhEmi ?? ide.dEmi),
    natureza: texto(ide.natOp),
    finalidade: FINALIDADE[Number(ide.finNFe)] ?? 'normal',
    valor_total: r2(valorNf),
    valor_com_tributos: valorComTributos !== null && Math.abs(valorComTributos - valorNf) > 0.05 ? r2(valorComTributos) : null,
    situacao,
    valor_produtos: num(total.vProd),
    valor_frete: num(total.vFrete),
    valor_desconto: num(total.vDesc),
    cnpj_emitente: cnpjEmit,
    nome_emitente: texto(emit.xNome),
    nome_fantasia: texto(emit.xFant),
    cnpj_destinatario: normalizarCnpj(dest.CNPJ ?? dest.CPF) || null,
    nome_destinatario: texto(dest.xNome),
    info_compl: texto(inf.infAdic?.infCpl),
    protocolo_status: cstat,
    itens,
    duplicatas,
    avisos,
  };
}
