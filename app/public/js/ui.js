// Helpers de interface: criação de elementos (sempre com textContent, nunca innerHTML com dados), formatação, modal e aviso.

export function h(tag, attrs, ...filhos) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === undefined || v === null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked' || k === 'selected' || k === 'disabled') el[k] = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    }
  }
  for (const f of filhos.flat(Infinity)) {
    if (f === null || f === undefined || f === false) continue;
    el.append(f instanceof Node ? f : document.createTextNode(String(f)));
  }
  return el;
}

/** Troca o conteúdo de um elemento aceitando listas aninhadas e ignorando null/false. */
export function montar(el, ...filhos) {
  el.replaceChildren(...filhos.flat(Infinity)
    .filter((f) => f !== null && f !== undefined && f !== false)
    .map((f) => (f instanceof Node ? f : document.createTextNode(String(f)))));
}

const brlFmt = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const brlInt = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
export const brl = (v) => brlFmt.format(Number(v) || 0);
export const brl0 = (v) => brlInt.format(Math.round(Number(v) || 0));
export const mil = (v) => {
  const n = Number(v) || 0;
  return Math.abs(n) >= 1000 ? `R$ ${(n / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} mil` : brl0(n);
};
export const pct = (v, casas = 0) => `${((Number(v) || 0) * 100).toLocaleString('pt-BR', { maximumFractionDigits: casas })}%`;
export const dataBR = (iso) => (iso ? iso.split('-').reverse().join('/') : '');
export const dataCurta = (iso) => (iso ? iso.slice(8) + '/' + iso.slice(5, 7) : '');
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
export const nomeMes = (ym) => `${MESES[Number(ym.slice(5)) - 1]} de ${ym.slice(0, 4)}`;
export const mesCurto = (ym) => `${MESES[Number(ym.slice(5)) - 1].slice(0, 3)}/${ym.slice(2, 4)}`;
export function somarMes(ym, n) {
  const d = new Date(Date.UTC(Number(ym.slice(0, 4)), Number(ym.slice(5)) - 1 + n, 1));
  return d.toISOString().slice(0, 7);
}

export function toast(msg, erro = false) {
  const el = h('div', { class: `toast ${erro ? 'erro' : ''}`, role: 'status' }, msg);
  document.body.append(el);
  setTimeout(() => el.remove(), erro ? 5000 : 2600);
}

/** Janela sobreposta. `conteudo(fechar)` devolve o corpo. */
export function modal(titulo, conteudo) {
  const fundo = h('div', { class: 'modal-fundo' });
  const fechar = () => { fundo.remove(); document.removeEventListener('keydown', esc); };
  const esc = (e) => { if (e.key === 'Escape') fechar(); };
  const caixa = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': titulo },
    h('header', { class: 'modal-topo' }, h('h2', null, titulo), h('button', { class: 'icone', type: 'button', 'aria-label': 'Fechar', onclick: fechar }, '×')),
    h('div', { class: 'modal-corpo' }, conteudo(fechar)));
  fundo.append(caixa);
  fundo.addEventListener('mousedown', (e) => { if (e.target === fundo) fechar(); });
  document.addEventListener('keydown', esc);
  document.body.append(fundo);
  const primeiro = caixa.querySelector('input:not([type=hidden]), select, textarea');
  if (primeiro && window.matchMedia('(min-width: 700px)').matches) primeiro.focus();
  return fechar;
}

export function confirmar(texto) { return window.confirm(texto); }

/** Campo de formulário com rótulo. */
export function campo(rotulo, input, dica) {
  return h('label', { class: 'campo' }, h('span', { class: 'rotulo' }, rotulo), input, dica ? h('small', null, dica) : null);
}
export const entrada = (nome, valor = '', extra = {}) => h('input', { name: nome, value: valor ?? '', autocomplete: 'off', ...extra });
export function selecao(nome, opcoes, atual, extra = {}) {
  return h('select', { name: nome, ...extra }, opcoes.map(([v, t]) => h('option', { value: v, selected: String(v) === String(atual ?? '') }, t)));
}
export function lerForm(form) {
  const o = {};
  for (const el of form.elements) {
    if (!el.name) continue;
    if (el.type === 'checkbox') o[el.name] = el.checked;
    else o[el.name] = el.value;
  }
  return o;
}

export function selo(texto, tipo = 'neutro') { return h('span', { class: `selo ${tipo}` }, texto); }
export function vazio(texto) { return h('p', { class: 'vazio' }, texto); }
export function carregando() { return h('p', { class: 'vazio' }, 'Carregando…'); }
