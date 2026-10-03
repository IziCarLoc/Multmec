import { h, brl, brl0, mil, pct, dataBR, dataCurta, nomeMes, mesCurto, somarMes, selo, vazio, carregando, toast, montar } from '../ui.js';
import { GET, POST } from '../api.js';
import { barrasMensais, progresso } from '../charts.js';

function cartao(titulo, ...corpo) {
  return h('section', { class: 'cartao' }, titulo ? h('h2', null, titulo) : null, ...corpo);
}

function blocoMeta(p) {
  const t = p.termometro;
  const emCurso = p.mes === p.hoje.slice(0, 7);
  const esperado = t.diasUteisTotal ? t.meta * (t.diasUteisPassados / t.diasUteisTotal) : 0;
  const cedo = emCurso && t.diasUteisPassados < 3;          // com 1 ou 2 dias a projeção é ruído
  const noRitmo = t.projecao >= t.meta;
  return cartao('Meta do mês',
    h('div', { class: 'meta-valor' }, h('strong', null, brl0(t.faturamento)), h('span', null, ` de ${brl0(t.meta)} (${pct(t.pctMeta)})`)),
    progresso(t.faturamento, t.meta, emCurso ? esperado : null),
    h('div', { class: 'linhas' },
      h('div', null, h('span', null, 'Meta por dia útil'), h('b', null, `${brl0(t.metaPorDia)}/dia`)),
      cedo ? h('div', null, h('span', null, 'Projeção do mês'), h('b', null, 'ainda é cedo')) : [
        h('div', null, h('span', null, 'Ritmo'), h('b', null, `${brl0(t.ritmoDiario)}/dia útil`)),
        h('div', null, h('span', null, 'Projeção do mês'), h('b', { class: noRitmo ? 'bom' : 'ruim' }, brl0(t.projecao))),
        t.necessarioPorDia !== null ? h('div', null, h('span', null, `Precisa nos ${t.diasUteisRestantes} dias úteis que faltam`), h('b', null, `${brl0(t.necessarioPorDia)}/dia`)) : null,
      ],
      h('div', null, h('span', null, 'OS no mês · ticket médio'), h('b', null, `${t.os} · ${brl0(t.ticket)}`)),
      !cedo && t.ticketNecessario ? h('div', null, h('span', null, `Com ${t.osProjetadas} OS, o ticket para a meta é`), h('b', null, brl0(t.ticketNecessario))) : null,
    ));
}

function linhaCascata(rotulo, valor, base, { tipo = 'item', nota = '' } = {}) {
  const largura = base > 0 ? Math.max(0, Math.min(1, Math.abs(valor) / base)) : 0;
  const texto = tipo === 'sub' ? `− ${brl0(valor)}` : brl0(valor);
  return h('div', { class: `cascata-linha ${tipo}` },
    h('div', { class: 'cascata-topo' }, h('span', null, rotulo), h('b', { class: valor < 0 ? 'ruim' : '' }, texto)),
    h('div', { class: 'cascata-trilho' }, h('div', { class: `cascata-barra ${tipo} ${valor < 0 ? 'neg' : ''}`, style: `width:${(largura * 100).toFixed(1)}%` })),
    nota ? h('small', null, nota) : null);
}

function blocoCascata(p) {
  const c = p.cascata;
  const base = c.faturamento || 1;
  const eq = p.equilibrio;
  const passou = eq.equilibrio !== null && c.faturamento >= eq.equilibrio;
  return cartao('Para onde vai o dinheiro do mês',
    h('p', { class: 'dica' }, 'Competência do mês: OS concluídas menos o que elas custam e as contas que vencem no mês.'),
    linhaCascata('Faturamento', c.faturamento, base, { tipo: 'total' }),
    linhaCascata('Peças, frete e insumos', c.custos, base, { tipo: 'sub', nota: c.custoEstimado > 0 ? `inclui ${brl0(c.custoEstimado)} estimados em ${c.osSemCusto} OS sem custo informado` : '' }),
    linhaCascata('Impostos e taxas de cartão', c.impostos, base, { tipo: 'sub' }),
    linhaCascata('Sobra depois dos custos da venda', c.margemContribuicao, base, { tipo: 'total' }),
    c.semContas ? h('div', { class: 'equilibrio' }, h('b', null, 'Sem contas lançadas neste mês'), h('span', null, ' · o resultado abaixo ainda não desconta folha e custos fixos. Lance as contas em Contas.')) : null,
    linhaCascata('Folha e comissões', c.folha, base, { tipo: 'sub' }),
    linhaCascata('Custos fixos e outros', c.fixos + c.outros, base, { tipo: 'sub' }),
    linhaCascata('Resultado do mês', c.resultado, base, { tipo: 'total' }),
    linhaCascata('Reserva da oficina', c.reserva, base, { tipo: 'sub' }),
    linhaCascata('Disponível para os sócios', c.disponivelSocios, base, { tipo: 'final', nota: `meta de retirada ${brl0(c.metaRetirada)} · já retirado ${brl0(c.retirado)}` }),
    eq.equilibrio ? h('div', { class: `equilibrio ${passou ? 'ok' : ''}` },
      h('b', null, passou ? 'Passou do ponto de equilíbrio' : 'Ainda não cobriu os custos fixos'),
      h('span', null, ` · equilíbrio ${brl0(eq.equilibrio)} (folha + fixos ${brl0(eq.custosFixos)} ÷ ${pct(eq.margemContribuicaoPct)} de margem)`),
      h('span', null, ` · para retirar ${brl0(eq.retirada)}: faturar ${brl0(eq.paraRetirada)}`)) : h('p', { class: 'dica' }, 'Cadastre os custos fixos em Contas > Modelos fixos para calcular o ponto de equilíbrio.'));
}

function blocoCaixa(p) {
  const c = p.caixa;
  if (!c.configurado) {
    return cartao('Caixa', h('p', { class: 'dica' }, 'Informe o saldo do banco de um dia (em Metas > "Saldo do banco/caixa em") e o sistema passa a mostrar o caixa real a partir dele.'),
      h('a', { class: 'botao', href: '#/metas' }, 'Informar saldo'));
  }
  return cartao('Caixa',
    h('div', { class: 'meta-valor' }, h('strong', { class: c.saldo < 0 ? 'ruim' : '' }, brl0(c.saldo))),
    h('p', { class: 'dica' }, `Desde ${dataBR(c.desde)}: entrou ${brl0(c.entrou)}, saiu ${brl0(c.saiu)}.`));
}

function blocoAgenda(p) {
  const a = p.agenda;
  const itemPagar = (s) => h('li', { class: s.atrasada ? 'atrasada' : '' }, h('span', null, `${dataCurta(s.vencimento)} · ${s.descricao}`), h('b', null, brl0(s.valor)));
  const itemReceber = (o) => h('li', { class: o.atrasada ? 'atrasada' : '' }, h('span', null, `${dataCurta(o.vencimento)} · ${o.cliente} · OS ${o.numero || ''}`), h('b', null, brl0(o.aberto)));
  return cartao('Próximos 7 dias',
    h('h3', null, `A pagar ${brl0(a.totalPagar)}`),
    a.pagar.length ? h('ul', { class: 'lista-simples' }, a.pagar.slice(0, 8).map(itemPagar)) : vazio('Nada a pagar.'),
    a.pagar.length > 8 ? h('small', null, `+ ${a.pagar.length - 8} contas`) : null,
    h('h3', null, `A receber de clientes a prazo ${brl0(a.totalReceber)}`),
    a.receber.length ? h('ul', { class: 'lista-simples' }, a.receber.slice(0, 6).map(itemReceber)) : vazio('Nada vencendo.'),
    a.receber.length > 6 ? h('small', null, `+ ${a.receber.length - 6} OS`) : null);
}

function blocoCarteira(p) {
  const itens = p.carteira.filter((s) => s.exposicao > 0 || s.status !== 'ok');
  return cartao('Clientes a prazo',
    itens.length ? itens.map((s) => h('a', { class: 'cliente-mini', href: '#/clientes' },
      h('div', null, h('b', null, s.cliente.nome), selo(s.status === 'travado' ? 'TRAVADO' : s.status === 'atencao' ? 'atenção' : 'em dia', s.status === 'travado' ? 'critico' : s.status === 'atencao' ? 'aviso' : 'ok')),
      h('div', { class: 'cliente-mini-valores' }, h('span', null, `deve ${brl0(s.exposicao)}`), s.vencido > 0 ? h('span', { class: 'ruim' }, `vencido ${brl0(s.vencido)}`) : null,
        s.cliente.limite > 0 ? h('span', null, `limite ${brl0(s.cliente.limite)}`) : null))) : vazio('Nenhum cliente a prazo com valor em aberto.'));
}

export async function painel(el, estado) {
  montar(el, carregando());
  const mesPedido = estado.mes;
  const p = await GET(`/painel${mesPedido ? `?mes=${mesPedido}` : ''}`);
  estado.mes = p.mes;
  const antes = somarMes(p.mes, -1);
  const depois = somarMes(p.mes, 1);
  const seletor = h('div', { class: 'seletor-mes' },
    h('button', { class: 'icone', type: 'button', 'aria-label': 'Mês anterior', onclick: () => { estado.mes = antes; painel(el, estado); } }, '‹'),
    h('strong', null, nomeMes(p.mes)),
    h('button', { class: 'icone', type: 'button', 'aria-label': 'Próximo mês', onclick: () => { estado.mes = depois; painel(el, estado); } }, '›'));
  const ordem = { critico: 0, aviso: 1, info: 2 };
  const ordenados = [...p.alertas].sort((a, b) => ordem[a.nivel] - ordem[b.nivel]);
  const alertas = ordenados.length ? h('section', { class: 'alertas' }, ordenados.slice(0, 5).map((a) => h('div', { class: `alerta ${a.nivel}` }, a.texto)),
    ordenados.length > 5 ? h('small', { class: 'dica' }, `+ ${ordenados.length - 5} avisos`) : null) : null;
  const serie = p.serie.map((m) => ({ rotulo: mesCurto(m.mes).slice(0, 3), valor: m.faturamento, destaque: m.mes === p.mes }));
  const grafico = cartao('Faturamento nos últimos 6 meses', barrasMensais(serie, { meta: p.config.metaFaturamento }));
  montar(el, 
    seletor, alertas,
    h('div', { class: 'grade' }, blocoMeta(p), blocoCascata(p), blocoCaixa(p), blocoAgenda(p), blocoCarteira(p), grafico));
}
