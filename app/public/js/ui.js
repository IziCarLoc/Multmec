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

/**
 * Lê número digitado em pt-BR ou vindo de campo preenchido pelo sistema:
 * "1.234,56" -> 1234.56 · "1.500" -> 1500 (milhar) · "200.00" e "1500.5" -> decimal com ponto · "" -> null.
 */
export function numBR(v) {
  if (v === undefined || v === null) return null;
  let s = String(v).trim().replace(/[R$\s]/g, '');
  if (!s) return null;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
  const n = Number(s);
  return Number.isFinite(n) ? n : NaN;
}
/** Número para preencher campo de formulário no formato brasileiro (vírgula decimal, sem milhar). */
export const paraCampo = (v) => (v === null || v === undefined || v === '' ? '' : String(v).replace('.', ','));

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

// Janelas empilhadas: o botão Voltar do celular fecha a de cima (cada janela ocupa uma entrada no histórico)
const pilha = [];
let ignorarPop = 0;
let voltarPendente = false;
// fechar uma janela "volta" no histórico; se outra janela abre logo em seguida (ex.: o resultado depois do formulário), ela aproveita a mesma entrada
function agendarVoltar() {
  voltarPendente = true;
  setTimeout(() => { if (voltarPendente) { voltarPendente = false; ignorarPop += 1; history.back(); } }, 0);
}
/** Fecha todas as janelas abertas (ex.: a sessão expirou e a tela de login precisa aparecer). */
export function fecharTodasAsJanelas() {
  for (const el of document.querySelectorAll('.modal-fundo')) el.remove();
  const n = pilha.length;
  pilha.length = 0;
  document.body.classList.remove('sem-rolagem');
  if (n) { ignorarPop += n; history.go(-n); }
}
/** Copia um texto; sem permissão do navegador (página sem HTTPS), usa o caminho antigo e, se falhar, mostra o texto para copiar à mão. */
export async function copiarTexto(texto) {
  try { if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(texto); return true; } } catch { /* tenta o outro caminho */ }
  const area = h('textarea', { style: 'position:fixed;opacity:0' });
  area.value = texto;
  document.body.append(area);
  area.select();
  let ok = false;
  try { ok = document.execCommand('copy'); } catch { ok = false; }
  area.remove();
  return ok;
}
if (typeof window !== 'undefined') {
  window.addEventListener('popstate', () => {
    if (ignorarPop > 0) { ignorarPop -= 1; return; }
    const topo = pilha[pilha.length - 1];
    if (topo) topo.fecharPeloHistorico();
  });
}

/** Janela sobreposta. `conteudo(fechar)` devolve o corpo. O retorno é a função de fechar, que também tem `.corpo` (para atualizar sem fechar). */
export function modal(titulo, conteudo) {
  const fundo = h('div', { class: 'modal-fundo' });
  const registro = { fecharPeloHistorico: () => encerrar(false) };
  const esc = (e) => { if (e.key === 'Escape' && pilha[pilha.length - 1] === registro) fechar(); };
  function encerrar(chamarHistorico) {
    if (!fundo.isConnected) return;
    fundo.remove();
    document.removeEventListener('keydown', esc);
    const pos = pilha.indexOf(registro);
    if (pos >= 0) pilha.splice(pos, 1);
    if (!pilha.length) document.body.classList.remove('sem-rolagem');
    if (chamarHistorico) agendarVoltar();
  }
  const fechar = () => encerrar(true);
  const corpo = h('div', { class: 'modal-corpo' });
  const caixa = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': titulo },
    h('header', { class: 'modal-topo' }, h('h2', null, titulo), h('button', { class: 'icone', type: 'button', 'aria-label': 'Fechar', onclick: fechar }, '×')), corpo);
  corpo.append(conteudo(fechar));
  fundo.append(caixa);
  fundo.addEventListener('mousedown', (e) => { if (e.target === fundo) fechar(); });
  document.addEventListener('keydown', esc);
  document.body.append(fundo);
  document.body.classList.add('sem-rolagem');
  pilha.push(registro);
  if (voltarPendente) { voltarPendente = false; history.replaceState({ modal: pilha.length }, ''); } else history.pushState({ modal: pilha.length }, '');
  const primeiro = caixa.querySelector('input:not([type=hidden]), select, textarea');
  if (primeiro && window.matchMedia('(min-width: 700px)').matches) primeiro.focus();
  fechar.corpo = corpo;
  return fechar;
}

/** Troca o conteúdo de uma janela aberta sem fechá-la e sem perder a posição da rolagem. */
export function atualizarModal(fechar, conteudo) {
  const corpo = fechar.corpo;
  if (!corpo?.isConnected) return;
  const topo = corpo.scrollTop;
  corpo.replaceChildren(conteudo);
  corpo.scrollTop = topo;
}

/**
 * Envolve um clique que fala com o servidor: mostra o erro (nada fica mudo), evita toque duplo e devolve o botão ao normal.
 * Uso: onclick: acao(async () => { ... })
 */
export function acao(fn) {
  return async (ev) => {
    const botao = ev?.currentTarget;
    if (botao?.disabled) return;
    if (botao) botao.disabled = true;
    try { await fn(ev); } catch (e) { toast(e?.message || 'Não consegui falar com o servidor. Tente de novo.', true); } finally { if (botao) botao.disabled = false; }
  };
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

export function cnpjBR(c) {
  const t = String(c ?? '');
  return t.length === 14 ? `${t.slice(0, 2)}.${t.slice(2, 5)}.${t.slice(5, 8)}/${t.slice(8, 12)}-${t.slice(12)}` : t;
}
