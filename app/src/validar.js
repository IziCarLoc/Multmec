// Validação de entrada da API. Cada função devolve o valor limpo ou lança ErroValidacao (HTTP 400).
import { dataValida, lerValor, r2 } from './util.js';

export class ErroValidacao extends Error {}

export const texto = (v, { campo, max = 200, obrigatorio = false } = {}) => {
  const s = v === undefined || v === null ? '' : String(v).trim();
  if (obrigatorio && !s) throw new ErroValidacao(`Preencha ${campo}.`);
  if (s.length > max) throw new ErroValidacao(`${campo} está muito longo.`);
  return s || null;
};
export const dinheiro = (v, { campo, obrigatorio = false, minimo = 0, nulo = false } = {}) => {
  if (v === undefined || v === null || v === '') {
    if (obrigatorio) throw new ErroValidacao(`Preencha ${campo}.`);
    return nulo ? null : 0;
  }
  const n = lerValor(v);
  if (n === null) throw new ErroValidacao(`${campo} não é um valor válido.`);
  if (n < minimo) throw new ErroValidacao(`${campo} não pode ser menor que ${minimo}.`);
  if (n > 10_000_000) throw new ErroValidacao(`${campo} está grande demais.`);
  return r2(n);
};
export const data = (v, { campo, obrigatorio = true } = {}) => {
  if (!v) {
    if (obrigatorio) throw new ErroValidacao(`Informe ${campo}.`);
    return null;
  }
  if (!dataValida(v)) throw new ErroValidacao(`${campo} inválida (use AAAA-MM-DD).`);
  return v;
};
export const mes = (v, campo = 'mês') => {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(v || '')) throw new ErroValidacao(`${campo} inválido (use AAAA-MM).`);
  return v;
};
export const opcao = (v, validos, { campo, padrao } = {}) => {
  if (v === undefined || v === null || v === '') {
    if (padrao !== undefined) return padrao;
    throw new ErroValidacao(`Escolha ${campo}.`);
  }
  if (!validos.includes(v)) throw new ErroValidacao(`${campo} inválido.`);
  return v;
};
export const inteiro = (v, { campo, min = 0, max = 100000, padrao = 0 } = {}) => {
  if (v === undefined || v === null || v === '') return padrao;
  const n = Number(v);
  if (!Number.isInteger(n) || n < min || n > max) throw new ErroValidacao(`${campo} inválido.`);
  return n;
};
export const idDe = (v) => {
  const n = Number(v);
  if (!Number.isInteger(n) || n <= 0) throw new ErroValidacao('Identificador inválido.');
  return n;
};
