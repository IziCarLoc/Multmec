// Utilitários de dinheiro, datas (fuso de Brasília) e CSV.

export const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;

/** "R$ 1.234,56" | "1234.56" | "-R$ 28,00" | "" -> número (ou null se vazio/inválido). */
export function lerValor(txt) {
  if (txt === null || txt === undefined) return null;
  if (typeof txt === 'number') return Number.isFinite(txt) ? r2(txt) : null;
  let s = String(txt).trim();
  if (!s || s === '-' || s === '\\-') return null;
  const negativo = s.includes('-');
  s = s.replace(/[^0-9,.]/g, '');
  if (!s) return null;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');   // formato brasileiro
  const v = Number(s);
  if (!Number.isFinite(v)) return null;
  return r2(negativo ? -v : v);
}

const FUSO = 'America/Sao_Paulo';
const fmtData = new Intl.DateTimeFormat('en-CA', { timeZone: FUSO, year: 'numeric', month: '2-digit', day: '2-digit' });

/** Data de hoje em Brasília, "YYYY-MM-DD". */
export const hoje = (agora = new Date()) => fmtData.format(agora);
export const mesDe = (data) => data.slice(0, 7);

export function somarDias(data, dias) {
  const d = new Date(`${data}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + dias);
  return d.toISOString().slice(0, 10);
}
export function diasEntre(a, b) {           // b - a, em dias
  return Math.round((new Date(`${b}T12:00:00Z`) - new Date(`${a}T12:00:00Z`)) / 86400000);
}
export function ultimoDiaDoMes(ym) {
  const [y, m] = ym.split('-').map(Number);
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}
export function somarMeses(ym, n) {
  const [y, m] = ym.split('-').map(Number);
  const d = new Date(Date.UTC(y, m - 1 + n, 1));
  return d.toISOString().slice(0, 7);
}
export function dataValida(s) {
  return typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(Date.parse(`${s}T12:00:00Z`))
    && new Date(`${s}T12:00:00Z`).toISOString().slice(0, 10) === s;
}

/** Dias úteis (seg–sex, descontando feriados) de um mês: lista de datas. */
export function diasUteis(ym, feriados = [], sabadoConta = 0) {
  const lista = [];
  const n = ultimoDiaDoMes(ym);
  for (let d = 1; d <= n; d++) {
    const data = `${ym}-${String(d).padStart(2, '0')}`;
    const dow = new Date(`${data}T12:00:00Z`).getUTCDay();     // 0 dom .. 6 sáb
    if (feriados.includes(data)) continue;
    if (dow >= 1 && dow <= 5) lista.push({ data, peso: 1 });
    else if (dow === 6 && sabadoConta > 0) lista.push({ data, peso: sabadoConta });
  }
  return lista;
}

/** Parser de CSV (RFC 4180): aspas duplas, vírgulas e quebras de linha dentro de campos. */
export function lerCsv(texto) {
  const linhas = [];
  let campo = '';
  let linha = [];
  let aspas = false;
  const t = texto.replace(/^﻿/, '');
  for (let i = 0; i < t.length; i++) {
    const c = t[i];
    if (aspas) {
      if (c === '"') {
        if (t[i + 1] === '"') { campo += '"'; i++; } else aspas = false;
      } else campo += c;
    } else if (c === '"') aspas = true;
    else if (c === ',') { linha.push(campo); campo = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && t[i + 1] === '\n') i++;
      linha.push(campo); campo = '';
      linhas.push(linha); linha = [];
    } else campo += c;
  }
  if (campo !== '' || linha.length) { linha.push(campo); linhas.push(linha); }
  return linhas;
}

export const normalizarPlaca = (p) => (p || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
