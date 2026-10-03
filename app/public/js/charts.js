// Gráficos em SVG simples, sem biblioteca. Cores vêm de variáveis CSS (claro/escuro).
const NS = 'http://www.w3.org/2000/svg';
function s(tag, attrs, ...filhos) {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs || {})) el.setAttribute(k, v);
  for (const f of filhos) if (f != null) el.append(f);
  return el;
}
const t = (txt) => document.createTextNode(txt);

/** Barras mensais com linha de meta. dados: [{rotulo, valor, destaque}] */
export function barrasMensais(dados, { meta = 0, altura = 150, formato = (v) => String(Math.round(v / 1000)) } = {}) {
  const w = 320, padB = 22, padT = 16, h = altura;
  const max = Math.max(meta * 1.05, ...dados.map((d) => d.valor), 1);
  const bw = (w - 8) / dados.length;
  const svg = s('svg', { viewBox: `0 0 ${w} ${h}`, class: 'grafico', role: 'img', 'aria-label': 'Faturamento por mês' });
  const y = (v) => padT + (h - padT - padB) * (1 - v / max);
  if (meta) {
    svg.append(s('line', { x1: 0, x2: w, y1: y(meta), y2: y(meta), class: 'g-meta' }));
    svg.append(s('text', { x: w - 2, y: y(meta) - 3, 'text-anchor': 'end', class: 'g-txt' }, t(`meta ${formato(meta)}`)));
  }
  dados.forEach((d, i) => {
    const x = 4 + i * bw;
    const yy = y(d.valor);
    svg.append(s('rect', { x: x + bw * 0.14, y: yy, width: bw * 0.72, height: Math.max(0, h - padB - yy), rx: 3, class: d.destaque ? 'g-barra destaque' : 'g-barra' }, s('title', {}, t(`${d.rotulo}: ${d.valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`))));
    svg.append(s('text', { x: x + bw / 2, y: yy - 3, 'text-anchor': 'middle', class: 'g-val' }, t(formato(d.valor))));
    svg.append(s('text', { x: x + bw / 2, y: h - 6, 'text-anchor': 'middle', class: 'g-txt' }, t(d.rotulo)));
  });
  return svg;
}

/** Barra de progresso com marcador do "esperado até hoje". */
export function progresso(valor, total, esperado = null) {
  const p = total ? Math.min(1, Math.max(0, valor / total)) : 0;
  const el = document.createElement('div');
  el.className = 'progresso';
  el.setAttribute('role', 'progressbar');
  el.setAttribute('aria-valuenow', String(Math.round(p * 100)));
  el.setAttribute('aria-valuemin', '0');
  el.setAttribute('aria-valuemax', '100');
  const barra = document.createElement('div');
  barra.className = 'progresso-barra';
  barra.style.width = `${p * 100}%`;
  el.append(barra);
  if (esperado !== null && total) {
    const m = document.createElement('div');
    m.className = 'progresso-marca';
    m.style.left = `${Math.min(1, Math.max(0, esperado / total)) * 100}%`;
    m.title = 'onde deveria estar hoje';
    el.append(m);
  }
  return el;
}
