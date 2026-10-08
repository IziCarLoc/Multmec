// CNPJ (numérico e alfanumérico, em vigor desde 31/07/2026) e chave de acesso da NF-e.
// Algoritmo do DV: módulo 11, cada caractere vale o código ASCII menos 48 (dígitos mantêm o valor, A = 17 ... Z = 42).

const valor = (ch) => ch.charCodeAt(0) - 48;

export function normalizarCnpj(txt) {
  return String(txt ?? '').toUpperCase().replace(/[^0-9A-Z]/g, '');
}

function dvCnpj(base, pesos) {
  let soma = 0;
  for (let i = 0; i < pesos.length; i++) soma += valor(base[i]) * pesos[i];
  const resto = soma % 11;
  return resto < 2 ? 0 : 11 - resto;
}

export function cnpjValido(txt) {
  const c = normalizarCnpj(txt);
  if (!/^[0-9A-Z]{12}[0-9]{2}$/.test(c)) return false;
  if (/^(.)\1{13}$/.test(c)) return false;                   // 00000000000000 etc.
  const d1 = dvCnpj(c.slice(0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
  const d2 = dvCnpj(c.slice(0, 12) + d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
  return c.endsWith(`${d1}${d2}`);
}

export function formatarCnpj(txt) {
  const c = normalizarCnpj(txt);
  return c.length === 14 ? `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5, 8)}/${c.slice(8, 12)}-${c.slice(12)}` : c;
}

/** Dígito verificador da chave da NF-e (43 posições -> 1): módulo 11, pesos 2 a 9 da direita para a esquerda. */
export function dvChaveNfe(base43) {
  let soma = 0;
  let peso = 2;
  for (let i = base43.length - 1; i >= 0; i--) {
    soma += valor(base43[i]) * peso;
    peso = peso === 9 ? 2 : peso + 1;
  }
  const resto = soma % 11;
  return resto < 2 ? 0 : 11 - resto;
}

/**
 * Valida e decompõe a chave de acesso de 44 posições.
 * Com CNPJ alfanumérico (NT Conjunta 2025.001) a máscara é [0-9]{6}[A-Z0-9]{12}[0-9]{26} e o DV usa a mesma
 * regra, com cada caractere valendo o código ASCII menos 48.
 */
export function lerChaveNfe(txt) {
  const chave = String(txt ?? '').replace(/[\s.-]/g, '').toUpperCase();
  if (!/^[0-9]{6}[0-9A-Z]{12}[0-9]{26}$/.test(chave)) {
    return { valida: false, motivo: 'A chave de acesso precisa ter 44 caracteres (só números; letras apenas no CNPJ).' };
  }
  const dv = dvChaveNfe(chave.slice(0, 43));
  const dvConfere = String(dv) === chave[43];
  const cnpjEmitente = chave.slice(6, 20);
  return {
    valida: dvConfere,
    motivo: dvConfere ? null : 'O dígito verificador da chave não confere: a chave está errada ou foi adulterada.',
    chave,
    uf: chave.slice(0, 2),
    aamm: chave.slice(2, 6),
    cnpjEmitente,
    modelo: chave.slice(20, 22),
    serie: String(Number(chave.slice(22, 25))),
    numero: String(Number(chave.slice(25, 34))),
  };
}
