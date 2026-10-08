// Leitura do XML da NF-e (modelo 55, layout 4.00): só o que a auditoria precisa.
import { createHash } from 'node:crypto';
import { XMLParser } from 'fast-xml-parser';
import { lerChaveNfe, normalizarCnpj, cnpjValido } from './documentos.js';
import { r2, dataValida } from './util.js';

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
// O leitor não expande entidades (segurança); as cinco predefinidas do XML e as referências numéricas são decodificadas aqui, só no texto.
const ENTIDADES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
const decodificar = (t) => t.replace(/&(#x[0-9a-fA-F]+|#\d+|amp|lt|gt|quot|apos);/g, (m, e) => {
  if (e[0] !== '#') return ENTIDADES[e];
  const cod = e[1] === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
  return Number.isInteger(cod) && cod > 0 && cod <= 0x10ffff ? String.fromCodePoint(cod) : m;
});
const texto = (v) => (v === undefined || v === null ? null : decodificar(String(v)).trim() || null);
const dataIso = (v) => {
  const m = String(v ?? '').match(/^(\d{4}-\d{2}-\d{2})/);
  return m && dataValida(m[1]) ? m[1] : null;
};
// o hash identifica o conteúdo, não a formatação: BOM, quebras de linha e espaços entre tags não mudam o resultado
const normalizado = (xml) => xml.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n').replace(/>\s+</g, '><').trim();

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
  const hash = createHash('sha256').update(normalizado(bruto)).digest('hex');

  const evento = doc.procEventoNFe?.evento?.infEvento ?? doc.evento?.infEvento;
  if (evento) {
    if (String(evento.tpEvento) !== '110111') throw new ErroNfe(`Este XML é um evento (${evento.tpEvento}) que não altera a conciliação. Importe o XML da nota.`);
    const ch = lerChaveNfe(evento.chNFe);
    if (!ch.valida) throw new ErroNfe(`Evento de cancelamento com chave inválida: ${ch.motivo}`);
    // só vale o evento que a SEFAZ homologou (retEvento com cStat 135 ou 155); o pedido avulso pode ter sido recusado
    const ret = doc.procEventoNFe?.retEvento?.infEvento;
    if (!ret || !['135', '155'].includes(String(ret.cStat)) || (ret.chNFe && String(ret.chNFe) !== ch.chave)) {
      throw new ErroNfe('Este XML de cancelamento não traz a resposta da SEFAZ (retEvento com cStat 135). Peça o arquivo "procEventoNFe" completo, ou marque a nota como cancelada à mão com o motivo.');
    }
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
  const emitenteNaChave = emit.CPF && !emit.CNPJ ? `000${cnpjEmit}` : cnpjEmit;           // CPF ocupa as 14 posições da chave com zeros à esquerda
  if (emitenteNaChave !== chave.cnpjEmitente) {
    throw new ErroNfe('XML inconsistente: o CNPJ do emitente não bate com a chave de acesso. Não use este arquivo.');
  }
  if (String(Number(ide.nNF)) !== chave.numero || String(Number(ide.serie)) !== chave.serie) {
    throw new ErroNfe('XML inconsistente: número ou série não batem com a chave de acesso. Não use este arquivo.');
  }
  const dataEmissao = dataIso(ide.dhEmi ?? ide.dEmi);
  if (!dataEmissao) throw new ErroNfe('A nota não traz uma data de emissão válida. Não use este arquivo.');
  if (chave.modelo !== String(ide.mod) || (ide.cUF && chave.uf !== String(ide.cUF)) || chave.aamm !== `${dataEmissao.slice(2, 4)}${dataEmissao.slice(5, 7)}`
    || (ide.tpEmis && chave.chave[34] !== String(ide.tpEmis)) || (ide.cNF && chave.chave.slice(35, 43) !== String(ide.cNF).padStart(8, '0')) || (ide.cDV && chave.chave[43] !== String(ide.cDV))) {
    throw new ErroNfe('XML inconsistente: a chave de acesso contradiz os dados da própria nota (estado, mês, modelo ou código). Não use este arquivo.');
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
    const nItem = Number(d['@_nItem'] ?? i + 1);
    if (!Number.isInteger(nItem) || nItem < 1) throw new ErroNfe('A nota tem um item sem número válido. Não use este arquivo.');
    const imp = d.imposto ?? {};
    const icms = Object.values(imp.ICMS ?? {})[0] ?? {};
    const ipi = num(imp.IPI?.IPITrib?.vIPI) ?? 0;
    const st = (num(icms.vICMSST) ?? 0) + (num(icms.vFCPST) ?? 0);
    const vProd = num(p.vProd) ?? 0;
    const desc = num(p.vDesc) ?? 0;
    // o que o item custa de verdade (sem tributo recuperável): produto - desconto + frete + seguro + outras + IPI + ICMS-ST
    const custoBase = r2(vProd - desc + (num(p.vFrete) ?? 0) + (num(p.vSeg) ?? 0) + (num(p.vOutro) ?? 0) + ipi + st);
    return {
      n_item: nItem,
      codigo: texto(p.cProd),
      descricao: texto(p.xProd) ?? '(sem descrição)',
      ncm: texto(p.NCM),
      cfop: texto(p.CFOP),
      unidade: texto(p.uCom),
      quantidade: num(p.qCom) ?? 1,
      valor_unitario: num(p.vUnCom) ?? 0,
      valor_total: vProd,
      valor_desconto: desc,
      custo_base: custoBase,
      x_ped: texto(p.xPed),
      info_adicional: texto(d.infAdProd),
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
  // forma de pagamento: tPag 15 = boleto; dinheiro, cartão e Pix quitam no ato (a nota não deve vir com boleto)
  const pagamentos = (inf.pag ?? []).flatMap((g) => g.detPag ?? []).map((p) => ({ tPag: texto(p.tPag), indPag: texto(p.indPag), cnpjReceb: normalizarCnpj(p.card?.CNPJReceb) || null }));
  const IMEDIATO = new Set(['01', '02', '03', '04', '05', '10', '11', '12', '13', '17', '18', '19']);
  const pagoNoAto = !duplicatas.length && pagamentos.length > 0 && pagamentos.every((p) => IMEDIATO.has(p.tPag) && p.indPag !== '1');
  const cnpjReceb = pagamentos.find((p) => p.tPag === '15' && p.cnpjReceb)?.cnpjReceb ?? null;
  if (pagoNoAto) avisos.push('A nota informa pagamento imediato (dinheiro, cartão ou Pix) e não traz parcelas: não deveria vir boleto dela.');
  if (cnpjReceb && cnpjReceb !== cnpjEmit) avisos.push(`A nota informa que o boleto será recebido pelo CNPJ ${cnpjReceb} (diferente do emitente): confira.`);
  if ((inf.det ?? []).some((d) => ['5917', '6917'].includes(String(d.prod?.CFOP)))) avisos.push('Remessa em CONSIGNAÇÃO: a cobrança vem depois, na nota de venda. Esta nota ainda não gera boleto.');
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
    data_emissao: dataEmissao,
    natureza: texto(ide.natOp),
    finalidade: (inf.det ?? []).some((d) => ['5917', '6917'].includes(String(d.prod?.CFOP))) ? 'ajuste' : (FINALIDADE[Number(ide.finNFe)] ?? 'normal'),
    pago_no_ato: pagoNoAto ? 1 : 0,
    cnpj_receb: cnpjReceb,
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
