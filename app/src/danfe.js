// Lê o TEXTO de uma DANFE (copiado do PDF, ou só a chave digitada) quando o fornecedor não manda o XML.
// O que sai daqui é sugestão para a pessoa conferir. A prova de que a nota existe vem da consulta da chave no portal da NF-e.
import { lerChaveNfe, normalizarCnpj, cnpjValido } from './documentos.js';

const semAcento = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Procura a chave de 44 caracteres (a DANFE imprime em blocos de 4, o PDF às vezes solta tudo junto) e devolve a primeira que passa no dígito verificador. */
export function acharChaveNoTexto(texto) {
  const t = String(texto ?? '');
  const vistos = new Set();
  let primeiraInvalida = null;
  for (const m of t.matchAll(/(?:[0-9A-Za-z][ .\-]?){44,400}/g)) {
    const compacto = m[0].replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    for (let ini = 0; ini + 44 <= compacto.length; ini++) {
      const c = compacto.slice(ini, ini + 44);
      if (vistos.has(c)) continue;
      vistos.add(c);
      const r = lerChaveNfe(c);
      if (r.valida && r.modelo === '55') return r;
      if (!primeiraInvalida && /^\d{44}$/.test(c) && /^\d{2}\d{4}/.test(c) && r.chave) primeiraInvalida = r;
    }
  }
  return primeiraInvalida;
}

const NUM = String.raw`(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})`;
const paraNumero = (s) => Number(String(s).replace(/\./g, '').replace(',', '.'));

/** Valores em dinheiro do bloco "Cálculo do imposto" da DANFE; o total da nota é o maior deles (os outros são partes). */
function valoresDoBloco(t) {
  const ini = t.indexOf('calculo do imposto');
  if (ini < 0) return [];
  const fim = t.indexOf('transportador', ini);
  const bloco = t.slice(ini, fim > ini ? fim : ini + 1500);
  return [...bloco.matchAll(new RegExp(`${NUM}(?!\\d)`, 'g'))].map((m) => paraNumero(m[1])).filter(Number.isFinite);
}

function valorTotal(t) {
  const doBloco = valoresDoBloco(t);
  const maiorDoBloco = doBloco.length ? Math.max(...doBloco) : null;
  // outras fontes do mesmo número: o canhoto ("VALOR TOTAL: R$ x") e o rótulo "VALOR TOTAL DA NOTA" seguido de número
  const canhoto = t.match(new RegExp(String.raw`(?<![a-z])valor\s+total\s*:\s*(?:r\$\s*)?${NUM}`));
  const rotulo = t.match(new RegExp(String.raw`(?:valor|v\.?)\s*total\s*(?:da\s*)?(?:nota|nf-?e?)[^0-9]{0,40}${NUM}`));
  const fontes = [maiorDoBloco, canhoto ? paraNumero(canhoto[1]) : null, rotulo ? paraNumero(rotulo[1]) : null].filter((v) => v !== null && v > 0);
  const votos = new Map();
  for (const v of fontes) votos.set(v, (votos.get(v) ?? 0) + 1);
  const melhor = [...votos.entries()].sort((a, b) => b[1] - a[1] || b[0] - a[0])[0];
  const candidatos = [...new Set([...doBloco, ...fontes])].sort((a, b) => b - a).slice(0, 4);
  // com desconto, ICMS-ST ou IPI o maior número do bloco pode não ser o total: só se confirma com duas fontes iguais
  return { valor: melhor ? melhor[0] : null, candidatos, confirmado: !!melhor && melhor[1] >= 2 };
}

/** A data de emissão tem de cair no mês da chave de acesso; entre as datas do texto, vale a primeira que cai. */
function dataEmissao(t, aamm) {
  // a data do protocolo de autorização vem com hora ("01/10/2026 09:12:30") e não é a de emissão
  const datas = [...t.matchAll(/(\d{2})\/(\d{2})\/(\d{4})(?!\s*\d{2}:\d{2})/g)].map((m) => ({ iso: `${m[3]}-${m[2]}-${m[1]}`, aamm: `${m[3].slice(2)}${m[2]}` })).filter((d) => !Number.isNaN(Date.parse(d.iso)));
  if (aamm) return datas.find((d) => d.aamm === aamm)?.iso ?? null;
  const m = t.match(/(?:data\s*(?:da|de)?\s*emiss[aã]o|emiss[aã]o)[^0-9]{0,30}(\d{2})\/(\d{2})\/(\d{4})/);
  return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
}

/** CNPJs válidos do texto (com ou sem pontuação), na ordem em que aparecem. */
function cnpjsDoTexto(original) {
  const achados = [];
  for (const m of String(original).matchAll(/(?<![0-9A-Za-z])(?:\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}|[0-9A-Za-z]{2}\.[0-9A-Za-z]{3}\.[0-9A-Za-z]{3}\/[0-9A-Za-z]{4}-\d{2})(?![0-9A-Za-z])/g)) {
    const c = normalizarCnpj(m[0]);
    if (cnpjValido(c) && !achados.some((a) => a.cnpj === c)) achados.push({ cnpj: c, pos: m.index });
  }
  return achados;
}

/**
 * @returns {{ chave: object|null, valorTotal: number|null, valoresPossiveis: number[], dataEmissao: string|null, destinatarioCnpj: string|null, cnpjs: string[], avisos: string[] }}
 */
export function lerTextoDanfe(texto) {
  const original = String(texto ?? '').slice(0, 60000);
  const t = semAcento(original).replace(/[ \t]+/g, ' ');
  const avisos = [];
  const chave = acharChaveNoTexto(original);
  if (!chave) avisos.push('Não encontrei uma chave de acesso de 44 números no texto. Ela fica no rodapé do cabeçalho da DANFE, em baixo do código de barras.');
  else if (!chave.valida) avisos.push(chave.motivo);
  else if (chave.modelo !== '55') avisos.push(`A chave é de uma nota modelo ${chave.modelo}; aqui só entra NF-e (modelo 55).`);
  // o CNPJ do emitente está dentro da chave; o destinatário é o primeiro outro CNPJ depois do rótulo "destinatário"
  const cnpjs = cnpjsDoTexto(original);
  const emitente = chave?.cnpjEmitente ?? null;
  // o destinatário é o primeiro CNPJ do bloco "DESTINATÁRIO / REMETENTE" (que termina na fatura, no cálculo do imposto ou no transportador);
  // fora desse bloco há o CNPJ do transportador, que nunca é o de quem comprou
  const semAc = semAcento(original);
  const iniDest = semAc.indexOf('destinatario');
  let destinatarioCnpj = null;
  if (iniDest >= 0) {
    const fins = ['fatura', 'calculo do imposto', 'transportador', 'dados dos produtos', 'dados do produto'].map((m) => semAc.indexOf(m, iniDest + 12)).filter((k) => k > 0);
    const fimDest = fins.length ? Math.min(...fins) : iniDest + 600;
    const bloco = original.slice(iniDest, fimDest);
    destinatarioCnpj = cnpjs.find((c) => c.cnpj !== emitente && c.pos >= iniDest && c.pos < fimDest)?.cnpj ?? null;
    if (!destinatarioCnpj && /(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/.test(bloco)) avisos.push('O destinatário parece ser pessoa física (CPF): não é a oficina nem empresa do grupo.');
  } else {
    const outros = cnpjs.filter((c) => c.cnpj !== emitente);
    if (outros.length === 1) destinatarioCnpj = outros[0].cnpj;
    else if (outros.length > 1) avisos.push('Há mais de um CNPJ no texto além do emitente; digite o do destinatário (quem comprou) à mão.');
  }
  const total = valorTotal(t);
  if (total.valor === null) avisos.push('Não consegui ler o valor total da nota. Digite o que está em "VALOR TOTAL DA NOTA".');
  else if (!total.confirmado) avisos.push('Confira o "VALOR TOTAL DA NOTA" na DANFE: escolhi o maior número do bloco de impostos, que com desconto ou ICMS-ST pode não ser o total.');
  const emissao = dataEmissao(t, chave?.valida ? chave.aamm : null);
  if (chave?.valida && !emissao) avisos.push('Não achei no texto uma data de emissão dentro do mês da chave. Digite a data que está na nota.');
  return {
    chave, valorTotal: total.valor, valorConfirmado: total.confirmado, valoresPossiveis: total.candidatos, dataEmissao: emissao, destinatarioCnpj,
    cnpjs: cnpjs.map((c) => c.cnpj), avisos,
  };
}
