// Lê o TEXTO de uma DANFE (copiado do PDF, ou só a chave digitada) quando o fornecedor não manda o XML.
// O que sai daqui é sugestão para a pessoa conferir. A prova de que a nota existe vem da consulta da chave no portal da NF-e.
import { lerChaveNfe, normalizarCnpj, cnpjValido } from './documentos.js';

const semAcento = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Procura a chave de 44 caracteres (a DANFE imprime em blocos de 4, o PDF às vezes solta tudo junto) e devolve a primeira que passa no dígito verificador. */
export function acharChaveNoTexto(texto) {
  const t = String(texto ?? '');
  const vistos = new Set();
  let primeiraInvalida = null;
  for (const m of t.matchAll(/(?:[0-9A-Za-z][ .\-]?){44,70}/g)) {
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
  if (doBloco.length) return { valor: Math.max(...doBloco), candidatos: [...new Set(doBloco)].sort((a, b) => b - a).slice(0, 4) };
  // sem o bloco (texto picado): usa o rótulo "VALOR TOTAL DA NOTA" e o número logo depois
  const m = t.match(new RegExp(String.raw`(?:valor|v\.?)\s*total\s*(?:da\s*)?(?:nota|nf-?e?)[^0-9]{0,40}${NUM}`));
  return m ? { valor: paraNumero(m[1]), candidatos: [paraNumero(m[1])] } : { valor: null, candidatos: [] };
}

/** A data de emissão tem de cair no mês da chave de acesso; entre as datas do texto, vale a primeira que cai. */
function dataEmissao(t, aamm) {
  const datas = [...t.matchAll(/(\d{2})\/(\d{2})\/(\d{4})/g)].map((m) => ({ iso: `${m[3]}-${m[2]}-${m[1]}`, aamm: `${m[3].slice(2)}${m[2]}` })).filter((d) => !Number.isNaN(Date.parse(d.iso)));
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
  const posDest = t.indexOf('destinatario');
  let destinatarioCnpj = null;
  if (posDest >= 0) {
    // posições do texto sem acento batem com o original: a remoção de acentos só apaga marcas combinantes depois de NFD, então recalcula no original
    const idx = semAcento(original).indexOf('destinatario');
    destinatarioCnpj = cnpjs.find((c) => c.cnpj !== emitente && c.pos > idx)?.cnpj ?? null;
  }
  if (!destinatarioCnpj) {
    const outros = cnpjs.filter((c) => c.cnpj !== emitente);
    if (outros.length === 1) destinatarioCnpj = outros[0].cnpj;
    else if (outros.length > 1) avisos.push('Há mais de um CNPJ no texto além do emitente; digite o do destinatário (quem comprou) à mão.');
  }
  const total = valorTotal(t);
  if (total.valor === null) avisos.push('Não consegui ler o valor total da nota. Digite o que está em "VALOR TOTAL DA NOTA".');
  const emissao = dataEmissao(t, chave?.valida ? chave.aamm : null);
  if (chave?.valida && !emissao) avisos.push('Não achei no texto uma data de emissão dentro do mês da chave. Digite a data que está na nota.');
  return {
    chave, valorTotal: total.valor, valoresPossiveis: total.candidatos, dataEmissao: emissao, destinatarioCnpj,
    cnpjs: cnpjs.map((c) => c.cnpj), avisos,
  };
}
