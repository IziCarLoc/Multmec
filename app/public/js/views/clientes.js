import { h, brl, brl0, pct, dataBR, selo, vazio, carregando, toast, modal, campo, entrada, selecao, lerForm, montar, numBR as num, paraCampo } from '../ui.js';
import { GET, POST, PUT } from '../api.js';

const hojeISO = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const FAIXAS = [['aVencer', 'a vencer / no prazo', ''], ['ate7', '1 a 7 dias', ''], ['de8a15', '8 a 15 dias', 'aviso'], ['de16a30', '16 a 30 dias', 'critico'], ['mais30', 'mais de 30', 'critico']];
const TIPOS = [['avulso', 'Avulso (paga na retirada)'], ['frota', 'Empresa / frota a prazo'], ['locadora', 'Locadora'], ['revenda', 'Revenda / garagem']];

function barraFaixas(s) {
  const total = Object.values(s.faixas).reduce((a, b) => a + b, 0) || 1;
  const ativas = FAIXAS.filter(([k]) => s.faixas[k] > 0);
  return h('div', null,
    h('div', { class: 'faixas', role: 'img', 'aria-label': 'Valor em aberto por faixa de atraso' },
      ativas.map(([k, rot, tipo]) => h('div', { class: `faixa ${tipo}`, style: `flex:${s.faixas[k] / total}`, title: `${rot}: ${brl(s.faixas[k])}` }))),
    h('ul', { class: 'legenda-faixas' }, ativas.map(([k, rot, tipo]) => h('li', null, h('span', { class: `ponto ${tipo}` }), `${rot}: `, h('b', null, brl0(s.faixas[k]))))));
}

export async function formCliente(cliente, aoSalvar) {
  modal(cliente?.id ? 'Editar cliente' : 'Novo cliente', (fechar) => {
    const c = cliente || { tipo: 'avulso', prazo_dias: 0, limite_credito: 0, ativo: 1 };
    const f = h('form', { class: 'formulario' },
      campo('Nome', entrada('nome', c.nome || '', { required: true })),
      campo('Tipo', selecao('tipo', TIPOS, c.tipo)),
      h('div', { class: 'duas' },
        campo('Prazo de pagamento (dias)', entrada('prazoDias', c.prazo_dias, { type: 'number', min: 0, max: 180 }), '0 = paga na retirada do carro'),
        campo('Limite de crédito (R$)', entrada('limite', c.limite_credito || '', { inputmode: 'decimal' }), 'máximo em aberto; 0 = sem crédito')),
      cliente?.id ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'ativo', checked: !!c.ativo }), ' Cliente ativo') : null,
      campo('Observação', entrada('obs', c.obs || '')),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const corpo = { nome: d.nome, tipo: d.tipo, prazoDias: Number(d.prazoDias || 0), limite: d.limite ? num(d.limite) : 0, obs: d.obs };
      if (cliente?.id) corpo.ativo = d.ativo;
      try { if (cliente?.id) await PUT(`/clientes/${cliente.id}`, corpo); else await POST('/clientes', corpo); toast('Cliente salvo.'); fechar(); aoSalvar(); } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

function receber(cliente, aoSalvar) {
  modal(`Recebimento de ${cliente.nome}`, (fechar) => {
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'O valor quita primeiro as OS mais antigas (inclusive o saldo anterior).'),
      h('div', { class: 'duas' }, campo('Valor recebido', entrada('valor', '', { inputmode: 'decimal', required: true })), campo('Data', entrada('data', hojeISO(), { type: 'date', required: true }))),
      campo('Forma', selecao('forma', [['pix', 'Pix'], ['transferência', 'Transferência'], ['dinheiro', 'Dinheiro'], ['boleto', 'Boleto']], 'pix')),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Registrar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      try { const r = await POST(`/clientes/${cliente.id}/pagamento`, { valor: num(d.valor), data: d.data, forma: d.forma }); toast(r.sobra > 0 ? `Registrado. Sobrou ${brl(r.sobra)} sem OS em aberto.` : 'Recebimento registrado.'); fechar(); aoSalvar(); } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

async function extrato(cliente) {
  const d = await GET(`/clientes/${cliente.id}/extrato`);
  const cob = await GET(`/clientes/${cliente.id}/cobranca`);
  modal(`Extrato · ${cliente.nome}`, () => h('div', null,
    h('div', { class: 'botoes' },
      h('button', { class: 'primario', onclick: async () => { try { await navigator.clipboard.writeText(cob.texto); toast('Texto copiado. Cole no WhatsApp.'); } catch { toast('Não consegui copiar; selecione o texto abaixo.', true); } } }, 'Copiar cobrança (WhatsApp)')),
    h('pre', { class: 'cobranca' }, cob.texto),
    h('h3', null, 'Movimentação'),
    d.extrato.movimentos.length ? h('table', { class: 'tabela' }, h('thead', null, h('tr', null, ['Data', 'Descrição', 'Débito', 'Crédito', 'Saldo'].map((t) => h('th', null, t)))),
      h('tbody', null, d.extrato.movimentos.slice(-60).map((m) => h('tr', null, h('td', null, dataBR(m.data)), h('td', null, m.desc), h('td', { class: 'num' }, m.debito ? brl(m.debito) : ''), h('td', { class: 'num' }, m.credito ? brl(m.credito) : ''), h('td', { class: 'num' }, brl(m.saldo)))))) : vazio('Sem movimentação.')));
}

function saldoAnterior(cliente, aoSalvar) {
  modal(`Saldo anterior · ${cliente.nome}`, (fechar) => {
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Quanto esse cliente já devia antes de começar a usar o sistema. Entra como uma dívida antiga e é quitada primeiro nos recebimentos.'),
      h('div', { class: 'duas' }, campo('Valor devido', entrada('valor', '', { inputmode: 'decimal', required: true })), campo('Desde', entrada('data', hojeISO(), { type: 'date', required: true }))),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      try { await POST(`/clientes/${cliente.id}/saldo-anterior`, { valor: num(d.valor), data: d.data }); toast('Saldo anterior registrado.'); fechar(); aoSalvar(); } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

export async function clientes(el, estado) {
  montar(el, carregando());
  const [cart, todos, cfg] = await Promise.all([GET('/carteira'), GET('/clientes'), GET('/config')]);
  const recarregar = () => clientes(el, estado);
  const card = (s) => {
    const c = todos.find((x) => x.id === s.cliente.id) || s.cliente;
    const tipo = s.status === 'travado' ? 'critico' : s.status === 'atencao' ? 'aviso' : 'ok';
    return h('section', { class: `cartao cliente ${s.status}` },
      h('div', { class: 'cliente-topo' }, h('h2', null, s.cliente.nome), selo(s.status === 'travado' ? 'TRAVADO' : s.status === 'atencao' ? 'atenção' : 'em dia', tipo)),
      s.motivo ? h('p', { class: tipo === 'critico' ? 'ruim' : 'dica' }, s.status === 'travado' ? `Só atender com pagamento adiantado: ${s.motivo}.` : s.motivo) : null,
      h('div', { class: 'linhas' },
        h('div', null, h('span', null, 'Deve'), h('b', null, brl0(s.exposicao))),
        h('div', null, h('span', null, 'Vencido'), h('b', { class: s.vencido > 0 ? 'ruim' : '' }, brl0(s.vencido))),
        s.cliente.limite > 0 ? h('div', null, h('span', null, `Limite (${pct(s.usoLimite || 0)} usado)`), h('b', null, brl0(s.cliente.limite))) : null,
        h('div', null, h('span', null, 'Prazo'), h('b', null, s.cliente.prazoDias ? `${s.cliente.prazoDias} dias` : 'na retirada'))),
      s.exposicao > 0 ? barraFaixas(s) : null,
      h('div', { class: 'botoes' },
        h('button', { class: 'primario', onclick: () => receber(s.cliente, recarregar) }, 'Recebi'),
        h('button', { onclick: () => extrato(s.cliente) }, 'Extrato / cobrança'),
        h('button', { onclick: () => saldoAnterior(s.cliente, recarregar) }, 'Saldo anterior'),
        h('button', { onclick: () => formCliente(c, recarregar) }, 'Editar')));
  };
  const outros = todos.filter((c) => !cart.some((s) => s.cliente.id === c.id));
  montar(el, 
    h('div', { class: 'botoes-topo' }, h('h2', { class: 'titulo-pagina' }, 'Clientes a prazo'), h('button', { class: 'primario', onclick: () => formCliente(null, recarregar) }, '+ Cliente')),
    h('p', { class: 'dica' }, `Regra: cliente trava ao passar do limite ou de ${cfg.diasTrava} dias de atraso (ajuste em Metas). Travado = só atende com pagamento na hora.`),
    cart.length ? cart.map(card) : vazio('Nenhum cliente a prazo ainda. Edite um cliente e defina prazo e limite.'),
    h('details', { class: 'cartao' }, h('summary', null, `Todos os clientes (${outros.length})`),
      h('div', { class: 'lista' }, outros.slice(0, 200).map((c) => h('button', { class: 'linha-simples', onclick: () => formCliente(c, recarregar) }, h('span', null, c.nome), h('small', null, c.tipo + (c.ativo ? '' : ' · inativo')))))));
}
