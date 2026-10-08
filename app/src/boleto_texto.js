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

/** Procura a linha digitável (47), o convênio (48) ou o código de barras (44) dentro do texto. */
export function acharLinhaNoTexto(texto, hojeStr) {
  const t = String(texto ?? '');
  const vistos = new Set();
  const candidatos = [];
  for (const m of t.matchAll(/\d[\d .\-]{40,80}\d/g)) {
    const dig = m[0].replace(/\D/g, '');
    for (const n of [47, 48, 44]) {
      // um número solto antes da linha (agência, código) desloca o início: tenta alguns recuos, os dígitos verificadores filtram
      for (let ini = 0; ini <= Math.min(dig.length - n, 20); ini++) {
        const trecho = dig.slice(ini, ini + n);
        if (vistos.has(trecho)) continue;
        vistos.add(trecho);
        candidatos.push(trecho);
      }
    }
  }
  for (const c of candidatos) {
    const r = interpretarBoleto(c, hojeStr);
    if (r.ok) return r;
  }
  return null;
}

/** CNPJs válidos do texto com o papel indicado pelo rótulo mais próximo antes deles. */
export function cnpjsDoTexto(texto) {
  const t = semAcento(texto);
  const achados = [];
  for (const m of t.matchAll(/(?<!\d)\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}(?!\d)/g)) {
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
  const boleto = acharLinhaNoTexto(texto, hojeStr);
  if (!boleto) avisos.push('Não encontrei uma linha digitável válida no texto colado.');
  const cnpjs = cnpjsDoTexto(texto);
  const unico = (papel) => {
    const lista = [...new Set(cnpjs.filter((c) => c.papel === papel).map((c) => c.cnpj))];
    if (lista.length > 1) { avisos.push(`Achei mais de um CNPJ marcado como ${papel === 'pagador' ? 'pagador' : 'beneficiário'}; digite o correto.`); return null; }
    return lista[0] ?? null;
  };
  const beneficiarioCnpj = unico('beneficiario');
  const pagadorCnpj = unico('pagador');
  if (!beneficiarioCnpj && cnpjs.length) avisos.push('Há CNPJ no texto, mas não consegui dizer qual é o do beneficiário. Confira no boleto e digite.');
  if (beneficiarioCnpj && beneficiarioCnpj === pagadorCnpj) avisos.push('O CNPJ do beneficiário é igual ao do pagador: confira no boleto.');
  return { boleto, beneficiarioCnpj, pagadorCnpj, numeroDocumento: numeroDocumento(texto), cnpjs, avisos };
}
