import { h, toast, montar } from './ui.js';
import { GET, POST, definirLogin } from './api.js';
import { painel } from './views/painel.js';
import { vendas } from './views/vendas.js';
import { contas } from './views/contas.js';
import { clientes } from './views/clientes.js';
import { metas } from './views/metas.js';
import { relatorios } from './views/relatorios.js';
import { compras } from './views/compras.js';
import { importar } from './views/importar.js';

const ROTAS = {
  '/': { titulo: 'Painel', icone: '◔', vista: painel },
  '/vendas': { titulo: 'Vendas', icone: '≣', vista: vendas },
  '/compras': { titulo: 'Compras', icone: '⚖', vista: compras },
  '/contas': { titulo: 'Contas', icone: '▤', vista: contas },
  '/clientes': { titulo: 'A prazo', icone: '◷', vista: clientes },
  '/metas': { titulo: 'Metas', icone: '◎', vista: metas, mais: true },
  '/relatorios': { titulo: 'Relatórios', icone: '▥', vista: relatorios, mais: true },
  '/importar': { titulo: 'Importar', icone: '⇪', vista: importar, mais: true },
};
const estado = {};
const raiz = document.getElementById('raiz');

function telaLogin() {
  const erro = h('p', { class: 'ruim', role: 'alert' });
  const f = h('form', { class: 'login' },
    h('div', { class: 'logo' }, 'M'),
    h('h1', null, 'Multmec Financeiro'),
    h('label', { class: 'campo' }, h('span', { class: 'rotulo' }, 'Senha'), h('input', { type: 'password', name: 'senha', autocomplete: 'current-password', required: true, autofocus: true })),
    erro,
    h('button', { type: 'submit', class: 'primario grande' }, 'Entrar'));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await POST('/login', { senha: f.senha.value }); iniciar(); } catch (err) { erro.textContent = err.message; }
  });
  montar(raiz, f);
}

function rotaAtual() {
  const caminho = location.hash.replace(/^#/, '') || '/';
  return ROTAS[caminho] ? caminho : '/';
}

async function desenhar() {
  const caminho = rotaAtual();
  const rota = ROTAS[caminho];
  document.title = `${rota.titulo} · Multmec`;
  for (const a of document.querySelectorAll('[data-rota]')) a.classList.toggle('ativo', a.dataset.rota === caminho);
  const conteudo = document.getElementById('conteudo');
  try { await rota.vista(conteudo, estado); } catch (e) { if (e.message !== 'Entre com a senha.') montar(conteudo, h('p', { class: 'vazio ruim' }, e.message)); }
  window.scrollTo(0, 0);
}

function esqueleto() {
  const link = (c, r) => h('a', { href: `#${c}`, 'data-rota': c, class: 'aba' }, h('span', { class: 'aba-icone', 'aria-hidden': 'true' }, r.icone), h('span', null, r.titulo));
  const principais = Object.entries(ROTAS).filter(([, r]) => !r.mais);
  const mais = Object.entries(ROTAS).filter(([, r]) => r.mais);
  montar(raiz, 
    h('header', { class: 'topo' }, h('div', { class: 'marca' }, h('span', { class: 'logo pequeno' }, 'M'), h('strong', null, 'Multmec')),
      h('nav', { class: 'menu-desktop', 'aria-label': 'Principal' }, [...principais, ...mais].map(([c, r]) => link(c, r))),
      h('button', { class: 'sair', onclick: async () => { await POST('/logout', {}); location.hash = '#/'; telaLogin(); } }, 'Sair')),
    h('div', { class: 'mais-mobile' }, mais.map(([c, r]) => h('a', { href: `#${c}`, 'data-rota': c }, r.titulo))),
    h('main', { id: 'conteudo' }),
    h('nav', { class: 'menu-mobile', 'aria-label': 'Principal' }, principais.map(([c, r]) => link(c, r))));
}

let ouvindo = false;
async function iniciar() {
  esqueleto();
  if (!ouvindo) { window.addEventListener('hashchange', desenhar); ouvindo = true; }
  await desenhar();
}

definirLogin(() => { telaLogin(); });
(async () => {
  try { const s = await GET('/sessao'); if (s.logado) iniciar(); else telaLogin(); } catch { telaLogin(); }
})();
