import { h, brl0, pct, vazio, carregando, toast, campo, entrada, selecao, lerForm, montar } from '../ui.js';
import { GET, POST, PUT } from '../api.js';

const num = (v) => (v === '' ? null : Number(String(v).replace(/\./g, '').replace(',', '.')));
const perc = (v) => (v === '' ? null : Number(String(v).replace(',', '.')) / 100);

export async function metas(el) {
  montar(el, carregando());
  const [cfg, painel] = await Promise.all([GET('/config'), GET('/painel')]);
  const eq = painel.equilibrio;
  const t = painel.termometro;

  const f = h('form', { class: 'formulario cartao' },
    h('h2', null, 'Metas e regras do dinheiro'),
    h('div', { class: 'duas' },
      campo('Meta de faturamento do mês (R$)', entrada('metaFaturamento', cfg.metaFaturamento, { inputmode: 'decimal' })),
      campo('Retirada desejada dos sócios (R$/mês)', entrada('retiradaSociosMeta', cfg.retiradaSociosMeta, { inputmode: 'decimal' }))),
    h('div', { class: 'tres' },
      campo('Impostos sobre a venda (%)', entrada('impostoPct', (cfg.impostoPct * 100).toString().replace('.', ','), { inputmode: 'decimal' }), 'Simples: ~9% a 10%. Confirmar com o contador'),
      campo('Taxas de maquininha (%)', entrada('taxaCartaoPct', (cfg.taxaCartaoPct * 100).toString().replace('.', ','), { inputmode: 'decimal' }), 'média sobre todo o faturamento'),
      campo('Reserva da oficina (%)', entrada('reservaPct', (cfg.reservaPct * 100).toString().replace('.', ','), { inputmode: 'decimal' }), 'guardado todo mês')),
    h('div', { class: 'duas' },
      campo('Sábado conta como dia útil?', selecao('sabadoConta', [[0, 'Não'], [0.5, 'Meio dia'], [1, 'Dia inteiro']], cfg.sabadoConta)),
      campo('Travar cliente a prazo após (dias de atraso)', entrada('diasTrava', cfg.diasTrava, { type: 'number', min: 0 }))),
    h('div', { class: 'duas' },
      campo('Saldo do banco/caixa em (data)', entrada('saldoCaixaInicialData', cfg.saldoCaixaInicialData, { type: 'date' })),
      campo('Saldo nessa data (R$)', entrada('saldoCaixaInicial', cfg.saldoCaixaInicial, { inputmode: 'decimal' }))),
    campo('Feriados (datas AAAA-MM-DD separadas por vírgula)', entrada('feriados', cfg.feriados.join(', '))),
    h('h3', null, 'Conferência de compras'),
    campo('CNPJ da oficina', entrada('cnpjOficina', cfg.cnpjOficina, { placeholder: '00.000.000/0000-00' }), 'Para conferir se a nota e o boleto estão no nome da oficina. Está no cabeçalho das OS.'),
    h('div', { class: 'duas' },
      campo('Conferir OS sem nota a partir de', entrada('auditoriaDesde', cfg.auditoriaDesde, { type: 'date' }), 'vazio = só o mês atual'),
      campo('Dias para dar destino às peças', entrada('diasNotaSemDestino', cfg.diasNotaSemDestino, { type: 'number', min: 0 }))),
    h('div', { class: 'duas' },
      campo('Diferença aceita no valor (R$)', entrada('toleranciaValor', cfg.toleranciaValor, { inputmode: 'decimal' }), 'arredondamento de centavos'),
      campo('Alerta de preço acima de (%)', entrada('variacaoPrecoPct', (cfg.variacaoPrecoPct * 100).toString().replace('.', ','), { inputmode: 'decimal' }), 'sobre as últimas compras do mesmo item')),
    h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = lerForm(f);
    try {
      await PUT('/config', {
        metaFaturamento: num(d.metaFaturamento), retiradaSociosMeta: num(d.retiradaSociosMeta) ?? 0,
        impostoPct: perc(d.impostoPct), taxaCartaoPct: perc(d.taxaCartaoPct), reservaPct: perc(d.reservaPct),
        sabadoConta: Number(d.sabadoConta), diasTrava: Number(d.diasTrava),
        saldoCaixaInicialData: d.saldoCaixaInicialData, saldoCaixaInicial: num(d.saldoCaixaInicial) ?? 0,
        feriados: d.feriados.split(',').map((x) => x.trim()).filter(Boolean),
        cnpjOficina: d.cnpjOficina, auditoriaDesde: d.auditoriaDesde, diasNotaSemDestino: Number(d.diasNotaSemDestino),
        toleranciaValor: Number(String(d.toleranciaValor).replace(',', '.')), variacaoPrecoPct: perc(d.variacaoPrecoPct),
      });
      toast('Configuração salva.'); metas(el);
    } catch (err) { toast(err.message, true); }
  });

  // Simulador: combina OS e ticket para ver quando a meta fecha e quanto sobra.
  const mc = eq.margemContribuicaoPct;
  // ponto de partida do simulador: média dos últimos meses fechados (o mês corrente ainda é parcial)
  const fechados = painel.serie.slice(0, -1).filter((m) => m.os > 0).slice(-3);
  const osBase = fechados.length ? Math.round(fechados.reduce((a, m) => a + m.os, 0) / fechados.length) : (t.os || 80);
  const ticketBase = fechados.length ? Math.round(fechados.reduce((a, m) => a + m.faturamento, 0) / fechados.reduce((a, m) => a + m.os, 0)) : 850;
  const saida = h('div', { class: 'resumo-os' });
  const sim = h('form', { class: 'formulario cartao' },
    h('h2', null, 'Simulador: como chegar na meta'),
    h('p', { class: 'dica' }, `Margem depois dos custos da venda: ${pct(mc, 1)} · custos fixos do mês: ${brl0(eq.custosFixos)} · meta: ${brl0(cfg.metaFaturamento)}.`),
    h('div', { class: 'duas' },
      campo('OS por mês', entrada('os', osBase, { type: 'number', min: 1 }), 'média dos últimos meses'),
      campo('Ticket médio (R$)', entrada('ticket', ticketBase, { type: 'number', min: 1 }), 'média dos últimos meses')),
    saida);
  const calcular = async () => {
    const d = lerForm(sim);
    const r = await POST('/simulador', { os: Number(d.os), ticket: Number(d.ticket) });
    montar(saida, 
      h('div', { class: 'linhas' },
        h('div', null, h('span', null, 'Faturamento'), h('b', null, brl0(r.faturamento))),
        h('div', null, h('span', null, 'Sobra depois dos custos fixos'), h('b', { class: r.sobra < 0 ? 'ruim' : 'bom' }, brl0(r.sobra))),
        h('div', null, h('span', null, 'Falta para a meta'), h('b', null, brl0(r.faltaParaMeta))),
        h('div', null, h('span', null, 'Ticket que fecha a meta com essas OS'), h('b', null, brl0(r.ticketParaMeta))),
        h('div', null, h('span', null, 'OS que fecham a meta com esse ticket'), h('b', null, String(r.osParaMeta)))));
  };
  sim.addEventListener('input', calcular);
  sim.addEventListener('submit', (e) => e.preventDefault());
  montar(el, f, sim);
  calcular();
}
