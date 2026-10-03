import { h, brl0, pct, mesCurto, vazio, carregando, selo, dataBR, montar } from '../ui.js';
import { GET } from '../api.js';
import { barrasMensais } from '../charts.js';

export async function relatorios(el) {
  montar(el, carregando());
  const r = await GET('/relatorios');
  const a = r.analise;
  const meta = (await GET('/config')).metaFaturamento;
  const tabela = (cab, linhas) => h('div', { class: 'rolagem' }, h('table', { class: 'tabela' }, h('thead', null, h('tr', null, cab.map((c) => h('th', null, c)))), h('tbody', null, linhas)));
  const serie = r.serie.map((m) => ({ rotulo: mesCurto(m.mes).slice(0, 3), valor: m.faturamento }));
  montar(el, 
    h('div', { class: 'botoes-topo' }, h('h2', { class: 'titulo-pagina' }, 'Relatórios'), h('a', { class: 'botao', href: '/api/exportar/vendas.csv', download: 'vendas.csv' }, 'Baixar vendas (CSV)')),
    h('section', { class: 'cartao' }, h('h2', null, 'Mês a mês'), barrasMensais(serie, { meta }),
      tabela(['Mês', 'OS', 'Faturou', 'Ticket', 'Lucro bruto', '% lucro', 'Recebido', 'Pagou'],
        r.serie.slice().reverse().map((m) => h('tr', null, h('td', null, mesCurto(m.mes)), h('td', { class: 'num' }, m.os), h('td', { class: 'num' }, brl0(m.faturamento)), h('td', { class: 'num' }, brl0(m.ticket)),
          h('td', { class: 'num' }, brl0(m.lucroBruto)), h('td', { class: 'num' }, pct(m.lucroBrutoPct)), h('td', { class: 'num' }, brl0(m.recebido)), h('td', { class: 'num' }, brl0(m.pago)))))),
    h('section', { class: 'cartao' }, h('h2', null, `Tamanho das OS (${dataBR(r.de)} a ${dataBR(r.ate)})`),
      h('p', { class: 'dica' }, 'OS pequenas ocupam a bancada e rendem pouco. Veja quanto do faturamento vem de cada faixa.'),
      tabela(['Faixa (R$)', 'OS', '% das OS', 'Faturamento', '% do fat.'], a.faixas.map((f) => h('tr', null, h('td', null, f.faixa), h('td', { class: 'num' }, f.os), h('td', { class: 'num' }, pct(f.pctOs)), h('td', { class: 'num' }, brl0(f.faturamento)), h('td', { class: 'num' }, pct(f.pctFaturamento)))))),
    h('section', { class: 'cartao' }, h('h2', null, 'Acréscimo sobre o custo das peças'),
      h('p', { class: 'dica' }, 'Quanto você cobra a mais que o custo, por faixa de custo da peça. Peça cara costuma ficar com acréscimo menor: confira se está de propósito.'),
      tabela(['Custo das peças na OS', 'OS', 'Acréscimo médio'], a.markup.map((m) => h('tr', null, h('td', null, m.faixaCusto), h('td', { class: 'num' }, m.os), h('td', { class: 'num' }, m.markup === null ? '—' : pct(m.markup)))))),
    h('section', { class: 'cartao' }, h('h2', null, 'Clientes que mais faturam'),
      tabela(['Cliente', 'OS', 'Faturou', '% do total', 'Lucro bruto'], a.clientes.map((c) => h('tr', null, h('td', null, c.cliente), h('td', { class: 'num' }, c.os), h('td', { class: 'num' }, brl0(c.faturamento)), h('td', { class: 'num' }, pct(c.pct, 1)), h('td', { class: 'num' }, brl0(c.lucro)))))),
    h('section', { class: 'cartao' }, h('h2', null, 'Por mecânico'),
      tabela(['Mecânico', 'OS', 'Faturou', 'Ticket'], a.mecanicos.map((m) => h('tr', null, h('td', null, m.mecanico), h('td', { class: 'num' }, m.os), h('td', { class: 'num' }, brl0(m.faturamento)), h('td', { class: 'num' }, brl0(m.ticket)))))),
    h('section', { class: 'cartao' }, h('h2', null, `Orçamentos sem resposta (${r.orcamentos.length})`),
      r.orcamentos.length ? tabela(['Data', 'Cliente', 'Veículo', 'Valor'], r.orcamentos.slice(0, 30).map((o) => h('tr', null, h('td', null, dataBR(o.data)), h('td', null, o.cliente || ''), h('td', null, `${o.veiculo || ''} ${o.placa || ''}`), h('td', { class: 'num' }, brl0(o.valor_total))))) : vazio('Nenhum orçamento pendente.')));
}
