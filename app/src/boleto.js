// Leitura e validação de boleto bancário (linha digitável de 47 dígitos, código de barras de 44) e de
// boleto de arrecadação/convênio (48 dígitos). Só usa o que a linha traz: banco, valor e vencimento.
// O que NÃO vem na linha: CNPJ/nome do beneficiário, nº da nota. Isso se confere no app do banco, no DDA ou no papel.

const BASE_FATOR = Date.UTC(1997, 9, 7);                      // 07/10/1997 = fator 0
const DIA = 86400000;

const soDigitos = (t) => String(t ?? '').replace(/\D/g, '');

/** Módulo 10: pesos 2 e 1 alternados da direita para a esquerda; produto >= 10 soma os algarismos. */
export function dvModulo10(digitos) {
  let soma = 0;
  let peso = 2;
  for (let i = digitos.length - 1; i >= 0; i--) {
    const p = Number(digitos[i]) * peso;
    soma += p > 9 ? Math.floor(p / 10) + (p % 10) : p;
    peso = peso === 2 ? 1 : 2;
  }
  return (10 - (soma % 10)) % 10;
}

/** DV geral do código de barras bancário: módulo 11, pesos 2 a 9; resultado 0, 10 ou 11 vira 1. */
export function dvBarrasBanco(base43) {
  let soma = 0;
  let peso = 2;
  for (let i = base43.length - 1; i >= 0; i--) {
    soma += Number(base43[i]) * peso;
    peso = peso === 9 ? 2 : peso + 1;
  }
  const dv = 11 - (soma % 11);
  return dv === 0 || dv === 10 || dv === 11 ? 1 : dv;
}

/** Módulo 11 dos boletos de convênio (identificador de valor 8 ou 9): resto 0 ou 1 -> 0; resto 10 -> 1. */
export function dvModulo11Convenio(digitos) {
  let soma = 0;
  let peso = 2;
  for (let i = digitos.length - 1; i >= 0; i--) {
    soma += Number(digitos[i]) * peso;
    peso = peso === 9 ? 2 : peso + 1;
  }
  const resto = soma % 11;
  if (resto === 0 || resto === 1) return 0;
  if (resto === 10) return 1;
  return 11 - resto;
}

const iso = (ms) => new Date(ms).toISOString().slice(0, 10);

/**
 * Fator de vencimento -> data. O fator tem 4 dígitos e se repete a cada 9.000 dias: em 22/02/2025 voltou a 1000.
 * Entre as datas candidatas (ciclo antigo e seguintes) escolhe a mais perto de "hoje".
 */
export function fatorParaData(fator, hojeStr) {
  const f = Number(fator);
  if (!Number.isInteger(f) || f <= 0) return null;
  const hoje = Date.parse(`${hojeStr}T12:00:00Z`);
  let melhor = null;
  for (let ciclo = 0; ciclo <= 3; ciclo++) {
    const ms = BASE_FATOR + (f + ciclo * 9000) * DIA;
    if (melhor === null || Math.abs(ms - hoje) < Math.abs(melhor - hoje)) melhor = ms;
  }
  return iso(melhor);
}

/** Data -> fator (útil para montar casos de teste). Datas a partir de 22/02/2025 usam o ciclo novo. */
export function dataParaFator(dataStr) {
  const dias = Math.round((Date.parse(`${dataStr}T00:00:00Z`) - BASE_FATOR) / DIA);
  return String(((dias - 1000) % 9000 + 9000) % 9000 + 1000).padStart(4, '0');
}

const BANCOS = { '001': 'Banco do Brasil', '033': 'Santander', '041': 'Banrisul', '070': 'BRB', '077': 'Inter', '104': 'Caixa', '136': 'Unicred', '237': 'Bradesco', '260': 'Nubank', '290': 'PagBank', '336': 'C6', '341': 'Itaú', '348': 'XP', '364': 'Gerencianet', '380': 'PicPay', '403': 'Cora', '748': 'Sicredi', '756': 'Sicoob', '085': 'Ailos', '655': 'Votorantim', '422': 'Safra', '756 ': 'Sicoob' };
export const nomeBanco = (cod) => BANCOS[cod] || null;

function linhaParaBarras47(l) {
  return l.slice(0, 4) + l[32] + l.slice(33, 47) + l.slice(4, 9) + l.slice(10, 20) + l.slice(21, 31);
}
function barrasParaLinha47(b) {
  const campo1 = b.slice(0, 4) + b.slice(19, 24);
  const campo2 = b.slice(24, 34);
  const campo3 = b.slice(34, 44);
  return campo1 + dvModulo10(campo1) + campo2 + dvModulo10(campo2) + campo3 + dvModulo10(campo3) + b[4] + b.slice(5, 19);
}

/**
 * Interpreta o que o usuário colou/digitou. Devolve sempre um objeto; `ok` só é true quando todos os
 * dígitos verificadores conferem. `erros` impedem o cadastro por linha; `avisos` são para conferir.
 */
export function interpretarBoleto(texto, hojeStr) {
  const d = soDigitos(texto);
  const r = { ok: false, tipo: null, entrada: d, codigoBarras: null, linhaDigitavel: null, banco: null, bancoNome: null, valor: null, vencimento: null, fator: null, erros: [], avisos: [] };
  if (![44, 47, 48].includes(d.length)) {
    r.erros.push(`Número de dígitos inválido (${d.length}). A linha digitável tem 47 dígitos (48 em boleto de convênio) e o código de barras tem 44.`);
    return r;
  }
  if (d.length === 48 || (d.length === 44 && d[0] === '8')) return interpretarConvenio(d, r);

  let barras;
  if (d.length === 47) {
    r.linhaDigitavel = d;
    if (dvModulo10(d.slice(0, 9)) !== Number(d[9])) r.erros.push('Dígito verificador do 1º campo não confere: algum número da linha está errado.');
    if (dvModulo10(d.slice(10, 20)) !== Number(d[20])) r.erros.push('Dígito verificador do 2º campo não confere: algum número da linha está errado.');
    if (dvModulo10(d.slice(21, 31)) !== Number(d[31])) r.erros.push('Dígito verificador do 3º campo não confere: algum número da linha está errado.');
    barras = linhaParaBarras47(d);
  } else {
    barras = d;
    r.linhaDigitavel = barrasParaLinha47(d);
  }
  r.tipo = 'banco';
  r.codigoBarras = barras;
  if (dvBarrasBanco(barras.slice(0, 4) + barras.slice(5)) !== Number(barras[4])) {
    r.erros.push('Dígito verificador geral não confere: a linha foi digitada errada ou foi adulterada.');
  }
  r.banco = barras.slice(0, 3);
  r.bancoNome = nomeBanco(r.banco);
  if (barras[3] !== '9') r.avisos.push('Moeda diferente de real (9).');
  r.fator = barras.slice(5, 9);
  r.valor = Number(barras.slice(9, 19)) / 100;
  if (r.fator === '0000') r.avisos.push('Boleto sem fator de vencimento (pagável a qualquer momento): confira a data impressa.');
  else {
    r.vencimento = fatorParaData(r.fator, hojeStr);
    const dias = Math.round((Date.parse(`${r.vencimento}T12:00:00Z`) - Date.parse(`${hojeStr}T12:00:00Z`)) / DIA);
    if (dias > 400 || dias < -400) r.avisos.push(`Vencimento muito distante de hoje (${r.vencimento}): confira.`);
  }
  if (r.valor === 0) r.avisos.push('Boleto sem valor definido (qualquer valor): informe o valor combinado.');
  r.ok = r.erros.length === 0;
  return r;
}

function interpretarConvenio(d, r) {
  r.tipo = 'convenio';
  let barras;
  if (d.length === 48) {
    r.linhaDigitavel = d;
    const blocos = [0, 12, 24, 36].map((i) => d.slice(i, i + 12));
    const id = d[2];
    const dvDe = id === '6' || id === '7' ? dvModulo10 : dvModulo11Convenio;
    blocos.forEach((b, i) => {
      if (dvDe(b.slice(0, 11)) !== Number(b[11])) r.erros.push(`Dígito verificador do bloco ${i + 1} não confere: algum número da linha está errado.`);
    });
    barras = blocos.map((b) => b.slice(0, 11)).join('');
  } else {
    barras = d;
  }
  r.codigoBarras = barras;
  if (barras.length === 44) {
    const dvGeral = barras[2] === '6' || barras[2] === '7' ? dvModulo10 : dvModulo11Convenio;
    if (dvGeral(barras.slice(0, 3) + barras.slice(4)) !== Number(barras[3])) r.erros.push('Dígito verificador geral do código de barras não confere.');
  }
  if (barras[2] === '6' || barras[2] === '8') r.valor = Number(barras.slice(4, 15)) / 100;
  else r.avisos.push('O valor não vem em reais na linha deste boleto de convênio: digite o valor.');
  r.avisos.push('Boleto de convênio/arrecadação (tributo, água, luz, taxa): o vencimento não vem na linha. Confira o papel.');
  r.ok = r.erros.length === 0;
  return r;
}

/** Monta uma linha digitável bancária válida (47 dígitos). Serve para testes e para o banco de demonstração. */
export function montarLinhaDigitavel({ banco = '341', valor, vencimento, campoLivre }) {
  const fator = dataParaFator(vencimento);
  const centavos = String(Math.round(valor * 100)).padStart(10, '0');
  const livre = String(campoLivre ?? '1').padStart(25, '0').slice(-25);
  const barras = `${banco}9${'0'}${fator}${centavos}${livre}`;
  const dv = dvBarrasBanco(barras.slice(0, 4) + barras.slice(5));
  const completo = barras.slice(0, 4) + dv + barras.slice(5);
  return barrasParaLinha47(completo);
}
