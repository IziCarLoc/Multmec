// Lê o TEXTO de um boleto (copiado do PDF ou do e-mail) e separa o que o código de barras não traz:
// CNPJ de quem recebe (beneficiário), CNPJ de quem paga (pagador) e número do documento.
// Tudo que sai daqui é só sugestão para a pessoa conferir: o sistema nunca decide sozinho que o beneficiário é o fornecedor.
import { interpretarBoleto } from './boleto.js';
import { cnpjValido, normalizarCnpj } from './documentos.js';

const semAcento = (t) => String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const PAPEIS = [
  { papel: 'beneficiario', re: /beneficiario|cedente|favorecido/g },
  { papel: 'pagador', re: /pagador|sacado/g },
  { papel: 'avalista', re: /sacador|avalista/g },
];

/**
 * Procura a linha digitável (47), o convênio (48) ou o código de barras (44) dentro do texto.
 * Devolve o boleto lido e o trecho do texto que ocupa (para não confundir esses números com CNPJ).
 */
export function acharLinhaNoTexto(texto, hojeStr) {
  const t = String(texto ?? '');
  const vistos = new Set();
  const candidatos = [];
  for (const m of t.matchAll(/\d[\d .\-]{40,80}\d/g)) {
    const dig = m[0].replace(/\D/g, '');
    const trecho = [m.index, m.index + m[0].length];
    // 44 e 48 dígitos só valem como sequência inteira (senão uma linha de 47 com erro "vira" outro boleto);
    // a linha de 47 aceita um número solto antes dela (agência, código), desde que a moeda (4º dígito) seja 9
    if (dig.length === 44 || dig.length === 48) candidatos.push({ digitos: dig, trecho });
    for (let ini = 0; ini <= Math.min(dig.length - 47, 20); ini++) {
      const c = dig.slice(ini, ini + 47);
      if (ini > 0 && c[3] !== '9') continue;
      if (vistos.has(c)) continue;
      vistos.add(c);
      candidatos.push({ digitos: c, trecho });
    }
  }
  for (const c of candidatos) {
    const r = interpretarBoleto(c.digitos, hojeStr);
    if (r.ok) return { ...r, trecho: c.trecho };
  }
  return null;
}

/** CNPJs válidos do texto (numéricos e alfanuméricos com máscara) com o papel indicado pelo rótulo mais próximo antes deles. */
export function cnpjsDoTexto(texto) {
  const t = semAcento(texto);
  const achados = [];
  const regex = /(?<![0-9a-z])(?:\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}|[0-9a-z]{2}\.[0-9a-z]{3}\.[0-9a-z]{3}\/[0-9a-z]{4}-\d{2})(?![0-9a-z])/g;
  for (const m of t.matchAll(regex)) {
    const cnpj = normalizarCnpj(m[0]);
    if (!cnpjValido(cnpj)) continue;
    const antes = t.slice(Math.max(0, m.index - 160), m.index);
    let melhor = null;
    for (const { papel, re } of PAPEIS) {
      for (const r of antes.matchAll(re)) if (!melhor || r.index > melhor.pos) melhor = { papel, pos: r.index };
    }
    achados.push({ cnpj, papel: melhor?.papel ?? null });
  }
  return achados;
}

function numeroDocumento(texto) {
  const t = semAcento(texto);
  const m = t.match(/(?:numero|n[o.\u00ba\u00b0]?|num\.?)\s*(?:do\s*)?documento\s*[:\-]?[ \t]*([a-z0-9][a-z0-9/.\-]{0,19})(?![a-z0-9])/);
  if (!m) return null;
  const v = m[1];
  if (!/\d/.test(v) || /^\d{2}\/\d{2}\/\d{2,4}$/.test(v)) return null;
  return v.toUpperCase();
}

/**
 * @returns {{ boleto: object|null, beneficiarioCnpj: string|null, pagadorCnpj: string|null, numeroDocumento: string|null, cnpjs: Array, avisos: string[] }}
 */
export function lerTextoDoBoleto(texto, hojeStr) {
  const avisos = [];
  const original = String(texto ?? '');
  const boleto = acharLinhaNoTexto(original, hojeStr);
  if (!boleto) avisos.push('Não encontrei uma linha digitável válida no texto colado.');
  // os 14 últimos dígitos da linha (fator + valor) têm cara de CNPJ: tira o trecho da linha antes de procurar CNPJ
  const semLinha = boleto ? `${original.slice(0, boleto.trecho[0])} ${' '.repeat(Math.max(0, boleto.trecho[1] - boleto.trecho[0]))} ${original.slice(boleto.trecho[1])}` : original;
  const cnpjs = cnpjsDoTexto(semLinha);
  const unico = (papel) => {
    const lista = [...new Set(cnpjs.filter((c) => c.papel === papel).map((c) => c.cnpj))];
    if (lista.length > 1) { avisos.push(`Achei mais de um CNPJ marcado como ${papel === 'pagador' ? 'pagador' : 'beneficiário'}; digite o correto.`); return null; }
    return lista[0] ?? null;
  };
  const beneficiarioCnpj = unico('beneficiario');
  const pagadorCnpj = unico('pagador');
  if (!beneficiarioCnpj && cnpjs.length) avisos.push('Há CNPJ no texto, mas não consegui dizer qual é o do beneficiário. Confira no boleto e digite.');
  if (beneficiarioCnpj && beneficiarioCnpj === pagadorCnpj) avisos.push('O CNPJ do beneficiário é igual ao do pagador: confira no boleto.');
  const { trecho, ...leitura } = boleto ?? {};
  return { boleto: boleto ? leitura : null, beneficiarioCnpj, pagadorCnpj, numeroDocumento: numeroDocumento(original), cnpjs, avisos };
}
