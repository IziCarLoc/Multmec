// Regras financeiras do Multmec. Funções puras sobre o banco: sem estado escondido,
// "hoje" sempre entra como parâmetro (facilita teste e evita surpresa de fuso).
import { lerConfig } from './db.js';
import { movimentoDeCaixa } from './grupo.js';
import { r2, diasUteis, diasEntre, somarDias, somarMeses, mesDe, ultimoDiaDoMes } from './util.js';

const reais = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const CONCLUIDA = "v.situacao = 'concluida'";
const COBRAVEL = "v.situacao IN ('concluida', 'saldo')";   // gera valor a receber

// ---------------------------------------------------------------- vendas do mês

/** Totais de OS concluídas em um mês (competência = data da OS). */
export function resumoMes(db, ym) {
  const r = db.prepare(`
    SELECT COUNT(*) AS os,
           COALESCE(SUM(valor_total), 0)    AS faturamento,
           COALESCE(SUM(valor_mao_obra), 0) AS mao_obra,
           COALESCE(SUM(COALESCE(custo_pecas, 0)), 0) AS custo_pecas,
           COALESCE(SUM(custo_frete), 0)    AS custo_frete,
           COALESCE(SUM(custo_insumos), 0)  AS custo_insumos,
           COALESCE(SUM(CASE WHEN custo_pecas IS NULL AND valor_total - valor_mao_obra > 50 THEN 1 ELSE 0 END), 0) AS sem_custo,
           COALESCE(SUM(CASE WHEN custo_pecas IS NULL AND valor_total - valor_mao_obra > 50 THEN valor_total ELSE 0 END), 0) AS fat_sem_custo
      FROM vendas v WHERE ${CONCLUIDA} AND substr(data, 1, 7) = ?`).get(ym);
  const custos = r.custo_pecas + r.custo_frete + r.custo_insumos;
  const lucroBruto = r.faturamento - custos;
  return {
    mes: ym,
    os: r.os,
    faturamento: r2(r.faturamento),
    ticket: r.os ? r2(r.faturamento / r.os) : 0,
    maoObra: r2(r.mao_obra),
    custoPecas: r2(r.custo_pecas),
    custoFrete: r2(r.custo_frete),
    custoInsumos: r2(r.custo_insumos),
    custos: r2(custos),
    lucroBruto: r2(lucroBruto),
    lucroBrutoPct: r.faturamento ? lucroBruto / r.faturamento : 0,
    margemPecas: r2(lucroBruto - r.mao_obra),
    osSemCusto: r.sem_custo,
    faturamentoSemCusto: r2(r.fat_sem_custo),
  };
}

/** Custo de mercadoria (peças+frete+insumos) como % do faturamento nos últimos meses fechados
 *  com dados confiáveis (poucas OS sem custo). Cai para 0.33 se não houver histórico. */
export function cmvMedido(db, ymAtual, meses = 3) {
  const pcts = [];
  for (let i = 1; i <= 12 && pcts.length < meses; i++) {
    const r = resumoMes(db, somarMeses(ymAtual, -i));
    if (r.os >= 20 && r.faturamento > 0 && r.osSemCusto / r.os <= 0.10) pcts.push(r.custos / r.faturamento);
  }
  if (!pcts.length) return { pct: 0.33, base: 'padrão (sem histórico confiável)' };
  return { pct: pcts.reduce((a, b) => a + b, 0) / pcts.length, base: `média de ${pcts.length} mês(es) fechado(s)` };
}

// ---------------------------------------------------------------- recorrentes e saídas

/** Cria as contas fixas do mês a partir dos modelos ativos (idempotente). */
export function gerarRecorrentes(db, ym) {
  const modelos = db.prepare('SELECT * FROM recorrentes WHERE ativo = 1').all();
  const ins = db.prepare(`INSERT OR IGNORE INTO saidas
      (descricao, categoria_id, valor, vencimento, recorrente_id, competencia) VALUES (?, ?, ?, ?, ?, ?)`);
  let criadas = 0;
  db.transaction(() => {
    for (const m of modelos) {
      const dia = Math.min(m.dia_vencimento, ultimoDiaDoMes(ym));
      const venc = `${ym}-${String(dia).padStart(2, '0')}`;
      criadas += ins.run(m.descricao, m.categoria_id, m.valor, venc, m.id, ym).changes;
    }
  })();
  return criadas;
}

export function saidasDoMes(db, ym) {
  return db.prepare(`
    SELECT s.*, c.nome AS categoria, c.grupo
      FROM saidas s JOIN categorias c ON c.id = s.categoria_id
     WHERE substr(s.vencimento, 1, 7) = ? ORDER BY s.vencimento, s.id`).all(ym);
}

/** Resultado do mês em cascata: do faturamento até o que sobra para os sócios. */
export function cascata(db, ym, hojeStr, cfg = lerConfig(db)) {
  const m = resumoMes(db, ym);
  const cmv = cmvMedido(db, ym);
  // OS sem custo informado: estima pelo CMV médio para não inflar a sobra
  const custoEstimado = r2(m.faturamentoSemCusto * cmv.pct);
  const custos = r2(m.custos + custoEstimado);
  const impostos = r2(m.faturamento * (cfg.impostoPct + cfg.taxaCartaoPct));
  const margemContribuicao = r2(m.faturamento - custos - impostos);
  const saidas = saidasDoMes(db, ym);
  const soma = (g) => r2(saidas.filter((s) => s.grupo === g).reduce((a, s) => a + s.valor, 0));
  const folha = soma('folha');
  const fixos = soma('fixo');
  const outros = soma('outros');
  const resultado = r2(margemContribuicao - folha - fixos - outros);
  const reserva = r2(Math.max(0, m.faturamento * cfg.reservaPct));
  const disponivelSocios = r2(resultado - reserva);
  const retirado = r2(saidas.filter((s) => s.grupo === 'socios' && s.pago_em).reduce((a, s) => a + (s.valor_pago ?? s.valor), 0));
  return {
    mes: ym,
    faturamento: m.faturamento,
    custos, custoEstimado, impostos,
    margemContribuicao,
    folha, fixos, outros,
    resultado,
    semContas: saidas.length === 0,
    reserva,
    disponivelSocios,
    retirado,
    metaRetirada: cfg.retiradaSociosMeta,
    cmvPct: cmv.pct, cmvBase: cmv.base,
    osSemCusto: m.osSemCusto,
  };
}

/** Quanto precisa faturar no mês para cobrir os custos fixos (e, opcionalmente, uma retirada). */
export function pontoEquilibrio(db, ym, cfg = lerConfig(db), retirada = 0) {
  const saidas = saidasDoMes(db, ym);
  const fixos = saidas.filter((s) => ['folha', 'fixo', 'outros'].includes(s.grupo)).reduce((a, s) => a + s.valor, 0);
  const cmv = cfg.margemContribuicaoPct !== null ? null : cmvMedido(db, ym);
  const mc = cfg.margemContribuicaoPct !== null
    ? cfg.margemContribuicaoPct
    : 1 - cmv.pct - cfg.impostoPct - cfg.taxaCartaoPct - cfg.reservaPct;
  return {
    custosFixos: r2(fixos),
    margemContribuicaoPct: mc,
    equilibrio: mc > 0 ? r2(fixos / mc) : null,
    paraRetirada: mc > 0 ? r2((fixos + retirada) / mc) : null,
    retirada,
  };
}

// ---------------------------------------------------------------- meta do mês

export function termometro(db, ym, hojeStr, cfg = lerConfig(db)) {
  const m = resumoMes(db, ym);
  const uteis = diasUteis(ym, cfg.feriados, cfg.sabadoConta);
  const total = uteis.reduce((a, d) => a + d.peso, 0);
  const ate = uteis.filter((d) => d.data <= hojeStr).reduce((a, d) => a + d.peso, 0);
  const depois = uteis.filter((d) => d.data > hojeStr).reduce((a, d) => a + d.peso, 0);
  const emCurso = hojeStr.slice(0, 7) === ym;
  const passado = hojeStr.slice(0, 7) > ym;
  const diasContados = passado ? total : ate;
  const ritmo = diasContados > 0 ? m.faturamento / diasContados : 0;
  const projecao = passado ? m.faturamento : r2(ritmo * total);
  const falta = Math.max(0, cfg.metaFaturamento - m.faturamento);
  const osProjetadas = passado ? m.os : (diasContados > 0 ? Math.round((m.os / diasContados) * total) : 0);
  return {
    mes: ym,
    meta: cfg.metaFaturamento,
    faturamento: m.faturamento,
    pctMeta: cfg.metaFaturamento ? m.faturamento / cfg.metaFaturamento : 0,
    os: m.os,
    ticket: m.ticket,
    diasUteisTotal: total,
    diasUteisPassados: ate,
    diasUteisRestantes: emCurso ? depois : (passado ? 0 : total),
    ritmoDiario: r2(ritmo),
    projecao,
    necessarioPorDia: emCurso && depois > 0 ? r2(falta / depois) : null,
    metaPorDia: total ? r2(cfg.metaFaturamento / total) : 0,
    // alavancas: o que a meta exige em OS ou em ticket mantendo o outro
    osNecessarias: m.ticket > 0 ? Math.ceil(cfg.metaFaturamento / m.ticket) : null,
    osProjetadas: osProjetadas || null,
    ticketNecessario: osProjetadas > 0 ? r2(cfg.metaFaturamento / osProjetadas) : null,
  };
}

// ---------------------------------------------------------------- caixa e recebíveis

export function caixa(db, hojeStr, cfg = lerConfig(db)) {
  const desde = cfg.saldoCaixaInicialData;
  if (!desde) return { configurado: false, saldo: null, entrou: 0, saiu: 0, desde: null };
  // recebimentos "histórico" vêm da importação da planilha (corte): não são dinheiro que entrou no caixa
  const entrou = db.prepare("SELECT COALESCE(SUM(valor), 0) AS t FROM recebimentos WHERE data > ? AND data <= ? AND COALESCE(forma, '') <> 'histórico'").get(desde, hojeStr).t;
  const saiuContas = db.prepare('SELECT COALESCE(SUM(COALESCE(valor_pago, valor)), 0) AS t FROM saidas WHERE pago_em IS NOT NULL AND pago_em > ? AND pago_em <= ?').get(desde, hojeStr).t;
  // o que a oficina pagou por outra empresa do grupo e o que ela devolveu também mexem no saldo da conta
  const grupo = movimentoDeCaixa(db, desde, hojeStr);
  const saiu = saiuContas + grupo.saiu;
  const entrouTotal = entrou + grupo.entrou;
  return { configurado: true, saldo: r2(cfg.saldoCaixaInicial + entrouTotal - saiu), entrou: r2(entrouTotal), saiu: r2(saiu), desde };
}

/** OS concluídas com saldo em aberto, já com vencimento efetivo e dias de atraso. */
export function emAberto(db, hojeStr, clienteId = null) {
  const linhas = db.prepare(`
    SELECT v.id, v.numero, v.data, v.placa, v.veiculo, v.valor_total, v.vencimento, v.cliente_id,
           c.nome AS cliente, c.prazo_dias, c.tipo,
           v.valor_total - COALESCE((SELECT SUM(valor) FROM recebimentos r WHERE r.venda_id = v.id), 0) AS aberto
      FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE ${COBRAVEL} ${clienteId ? 'AND v.cliente_id = ?' : ''}
     ORDER BY v.data, v.id`).all(...(clienteId ? [clienteId] : []));
  return linhas
    .filter((l) => l.aberto > 0.004)
    .map((l) => {
      const venc = l.vencimento || somarDias(l.data, l.prazo_dias || 0);
      return { ...l, aberto: r2(l.aberto), vencimentoEfetivo: venc, diasAtraso: Math.max(0, diasEntre(venc, hojeStr)) };
    });
}

const FAIXAS = [
  ['aVencer', (d) => d <= 0], ['ate7', (d) => d >= 1 && d <= 7], ['de8a15', (d) => d >= 8 && d <= 15],
  ['de16a30', (d) => d >= 16 && d <= 30], ['mais30', (d) => d > 30],
];

/** Situação de crédito de um cliente: exposição, vencido, faixas de atraso, limite e trava. */
export function situacaoCliente(db, cliente, hojeStr, cfg = lerConfig(db)) {
  const abertas = emAberto(db, hojeStr, cliente.id);
  const faixas = Object.fromEntries(FAIXAS.map(([k]) => [k, 0]));
  let vencido = 0;
  let maiorAtraso = 0;
  for (const o of abertas) {
    for (const [k, teste] of FAIXAS) if (teste(o.diasAtraso)) faixas[k] += o.aberto;
    if (o.diasAtraso > 0) vencido += o.aberto;
    maiorAtraso = Math.max(maiorAtraso, o.diasAtraso);
  }
  const exposicao = abertas.reduce((a, o) => a + o.aberto, 0);
  const usoLimite = cliente.limite_credito > 0 ? exposicao / cliente.limite_credito : null;
  let status = 'ok';
  let motivo = '';
  if (cliente.limite_credito > 0 && exposicao > cliente.limite_credito) { status = 'travado'; motivo = 'passou do limite de crédito'; }
  else if (maiorAtraso > cfg.diasTrava) { status = 'travado'; motivo = `atraso de ${maiorAtraso} dias (máx. ${cfg.diasTrava})`; }
  else if ((usoLimite !== null && usoLimite >= cfg.pctAvisoLimite) || maiorAtraso > 0) {
    status = 'atencao'; motivo = maiorAtraso > 0 ? `${maiorAtraso} dia(s) de atraso` : 'perto do limite';
  }
  if (cliente.limite_credito === 0 && cliente.prazo_dias === 0 && exposicao > 0.004 && status === 'ok') {
    status = 'atencao'; motivo = 'cliente sem crédito com valor em aberto';
  }
  return {
    cliente: { id: cliente.id, nome: cliente.nome, tipo: cliente.tipo, prazoDias: cliente.prazo_dias, limite: cliente.limite_credito },
    exposicao: r2(exposicao), vencido: r2(vencido),
    faixas: Object.fromEntries(Object.entries(faixas).map(([k, v]) => [k, r2(v)])),
    maiorAtraso, usoLimite, status, motivo,
    qtdAbertas: abertas.length,
  };
}

/** Carteira: clientes que compram a prazo (prazo, limite ou tipo locadora/frota/revenda), mais exposto primeiro. */
export function carteira(db, hojeStr, cfg = lerConfig(db)) {
  const clientes = db.prepare(`SELECT * FROM clientes WHERE ativo = 1`).all();
  return clientes
    .filter((c) => c.prazo_dias > 0 || c.limite_credito > 0 || ['locadora', 'frota', 'revenda'].includes(c.tipo))
    .map((c) => situacaoCliente(db, c, hojeStr, cfg))
    .sort((a, b) => b.exposicao - a.exposicao);
}

/** Registra um pagamento do cliente quitando primeiro o saldo antigo e depois as OS mais velhas. */
export function receberDoCliente(db, clienteId, { valor, data, forma = null, obs = null }) {
  if (!(valor > 0)) throw new Error('Valor do pagamento precisa ser maior que zero.');
  const cliente = db.prepare('SELECT * FROM clientes WHERE id = ?').get(clienteId);
  if (!cliente) throw new Error('Cliente não encontrado.');
  let resta = r2(valor);
  const aplicado = [];
  db.transaction(() => {
    const ins = db.prepare('INSERT INTO recebimentos (venda_id, data, valor, forma, obs) VALUES (?, ?, ?, ?, ?)');
    for (const o of emAberto(db, data, clienteId)) {       // mais antigas primeiro (inclui o saldo anterior)
      if (resta <= 0.004) break;
      const parte = Math.min(resta, o.aberto);
      ins.run(o.id, data, r2(parte), forma, obs);
      aplicado.push({ id: o.id, numero: o.numero, valor: r2(parte) });
      resta = r2(resta - parte);
    }
  })();
  return { aplicado, sobra: resta };
}

export function extratoCliente(db, clienteId) {
  const c = db.prepare('SELECT * FROM clientes WHERE id = ?').get(clienteId);
  const vendas = db.prepare(`SELECT id, numero, data, placa, veiculo, valor_total, situacao FROM vendas v
      WHERE ${COBRAVEL} AND cliente_id = ?`).all(clienteId);
  const pagos = db.prepare(`SELECT r.id, r.data, r.valor, r.forma, v.numero FROM recebimentos r
      JOIN vendas v ON v.id = r.venda_id WHERE v.cliente_id = ?`).all(clienteId);
  const mov = [
    ...vendas.map((v) => ({
      data: v.data, tipo: v.situacao === 'saldo' ? 'saldo' : 'os', debito: v.valor_total, credito: 0,
      desc: v.situacao === 'saldo' ? 'Saldo anterior ao sistema' : `OS ${v.numero || v.id} ${v.placa || ''} ${v.veiculo || ''}`.trim(),
    })),
    ...pagos.map((p) => ({ data: p.data, tipo: 'pagamento', desc: `Pagamento (OS ${p.numero || ''}) ${p.forma || ''}`.trim(), debito: 0, credito: p.valor })),
  ].sort((a, b) => a.data.localeCompare(b.data) || (a.tipo === 'pagamento' ? 1 : -1));
  let saldo = 0;
  return { cliente: c, movimentos: mov.map((m) => { saldo = r2(saldo + m.debito - m.credito); return { ...m, saldo }; }) };
}

/** Texto pronto para colar no WhatsApp: fechamento do que o cliente deve. */
export function textoCobranca(db, clienteId, hojeStr) {
  const c = db.prepare('SELECT * FROM clientes WHERE id = ?').get(clienteId);
  const s = situacaoCliente(db, c, hojeStr);
  const abertas = emAberto(db, hojeStr, clienteId);
  const brl = reais;
  const dt = (d) => d.split('-').reverse().join('/');
  const linhas = [`*Multmec x ${c.nome}* - fechamento em ${dt(hojeStr)}`, ''];
  for (const o of abertas) {
    linhas.push(`${o.numero === 'SALDO' ? 'Saldo anterior' : `OS ${o.numero || o.id}`} (${dt(o.data)}) ${o.placa || ''}: ${brl(o.aberto)}${o.diasAtraso ? ` - vencida há ${o.diasAtraso} dia(s)` : ''}`);
  }
  linhas.push('', `*Total em aberto: ${brl(s.exposicao)}*`);
  if (s.vencido > 0) linhas.push(`Vencido: ${brl(s.vencido)}`);
  if (c.limite_credito > 0) linhas.push(`Limite combinado: ${brl(c.limite_credito)}`);
  return linhas.join('\n');
}

// ---------------------------------------------------------------- agenda e alertas

export function agenda(db, hojeStr, dias = 7) {
  const ate = somarDias(hojeStr, dias);
  const pagar = db.prepare(`
    SELECT s.id, s.descricao, s.valor, s.vencimento, c.nome AS categoria, c.grupo
      FROM saidas s JOIN categorias c ON c.id = s.categoria_id
     WHERE s.pago_em IS NULL AND s.vencimento <= ? ORDER BY s.vencimento`).all(ate)
    .map((s) => ({ ...s, atrasada: s.vencimento < hojeStr }));
  const receber = emAberto(db, hojeStr).filter((o) => o.vencimentoEfetivo <= ate && o.tipo !== 'avulso')
    .map((o) => ({ id: o.id, cliente: o.cliente, numero: o.numero, aberto: o.aberto, vencimento: o.vencimentoEfetivo, atrasada: o.diasAtraso > 0 }));
  return {
    pagar, receber,
    totalPagar: r2(pagar.reduce((a, s) => a + s.valor, 0)),
    totalReceber: r2(receber.reduce((a, o) => a + o.aberto, 0)),
    pagarAtrasado: r2(pagar.filter((s) => s.atrasada).reduce((a, s) => a + s.valor, 0)),
  };
}

export function alertas(db, hojeStr, cfg = lerConfig(db)) {
  const lista = [];
  for (const s of carteira(db, hojeStr, cfg)) {
    if (s.status === 'travado') lista.push({ nivel: 'critico', tipo: 'cliente_travado', texto: `${s.cliente.nome}: ${s.motivo}. Deve ${reais(s.exposicao)} (vencido ${reais(s.vencido)}).`, clienteId: s.cliente.id });
  }
  const avulsas = emAberto(db, hojeStr).filter((o) => o.tipo === 'avulso' || o.tipo === null);
  if (avulsas.length) {
    const total = r2(avulsas.reduce((a, o) => a + o.aberto, 0));
    lista.push({ nivel: 'aviso', tipo: 'avulso_em_aberto', texto: `${avulsas.length} OS de clientes avulsos sem recebimento registrado (${reais(total)}). Confirme se já pagaram e dê baixa em Vendas > Em aberto.` });
  }
  const ym = hojeStr.slice(0, 7);
  const m = resumoMes(db, ym);
  if (m.osSemCusto > 0) lista.push({ nivel: 'aviso', tipo: 'os_sem_custo', texto: `${m.osSemCusto} OS do mês sem custo de peças informado: a margem real está escondida.` });
  const orc = db.prepare(`SELECT COUNT(*) AS n, COALESCE(SUM(valor_total), 0) AS t FROM vendas WHERE situacao = 'orcamento' AND data >= ?`).get(somarDias(hojeStr, -30));
  if (orc.n > 0) lista.push({ nivel: 'info', tipo: 'orcamentos', texto: `${orc.n} orçamento(s) dos últimos 30 dias sem resposta (${reais(orc.t)}). Ligue hoje.` });
  const ag = agenda(db, hojeStr, 7);
  if (ag.pagarAtrasado > 0) lista.push({ nivel: 'critico', tipo: 'contas_atrasadas', texto: `Contas a pagar vencidas: ${reais(ag.pagarAtrasado)}.` });
  return lista;
}

// ---------------------------------------------------------------- relatórios

export function serieMensal(db, ymFim, n = 13) {
  const out = [];
  for (let i = n - 1; i >= 0; i--) {
    const ym = somarMeses(ymFim, -i);
    const r = resumoMes(db, ym);
    const rec = db.prepare("SELECT COALESCE(SUM(valor), 0) AS t FROM recebimentos WHERE substr(data, 1, 7) = ?").get(ym).t;
    const pago = db.prepare("SELECT COALESCE(SUM(COALESCE(valor_pago, valor)), 0) AS t FROM saidas WHERE pago_em IS NOT NULL AND substr(pago_em, 1, 7) = ?").get(ym).t;
    out.push({ ...r, recebido: r2(rec), pago: r2(pago) });
  }
  return out;
}

const FAIXAS_TICKET = [[0, 150], [150, 300], [300, 600], [600, 1000], [1000, 2000], [2000, 5000], [5000, Infinity]];
const rotuloFaixa = ([a, b]) => (b === Infinity ? `acima de ${a}` : `${a} a ${b}`);

export function analiseOs(db, de, ate) {
  const vs = db.prepare(`SELECT v.*, c.nome AS cliente, m.nome AS mecanico FROM vendas v
      LEFT JOIN clientes c ON c.id = v.cliente_id LEFT JOIN mecanicos m ON m.id = v.mecanico_id
     WHERE ${CONCLUIDA} AND v.data >= ? AND v.data <= ? AND v.valor_total > 0`).all(de, ate);
  const total = vs.reduce((a, v) => a + v.valor_total, 0);
  const faixas = FAIXAS_TICKET.map((f) => {
    const g = vs.filter((v) => v.valor_total >= f[0] && v.valor_total < f[1]);
    const fat = g.reduce((a, v) => a + v.valor_total, 0);
    return { faixa: rotuloFaixa(f), os: g.length, faturamento: r2(fat), pctOs: vs.length ? g.length / vs.length : 0, pctFaturamento: total ? fat / total : 0 };
  });
  const porCliente = new Map();
  const porMecanico = new Map();
  for (const v of vs) {
    const c = porCliente.get(v.cliente || '(sem cliente)') || { cliente: v.cliente || '(sem cliente)', os: 0, faturamento: 0, lucro: 0 };
    c.os++; c.faturamento += v.valor_total; c.lucro += v.valor_total - (v.custo_pecas || 0) - v.custo_frete - v.custo_insumos;
    porCliente.set(c.cliente, c);
    const mn = v.mecanico || '(não informado)';
    const m = porMecanico.get(mn) || { mecanico: mn, os: 0, faturamento: 0 };
    m.os++; m.faturamento += v.valor_total; porMecanico.set(mn, m);
  }
  const clientes = [...porCliente.values()].sort((a, b) => b.faturamento - a.faturamento).slice(0, 15)
    .map((c) => ({ ...c, faturamento: r2(c.faturamento), lucro: r2(c.lucro), pct: total ? c.faturamento / total : 0 }));
  const mecanicos = [...porMecanico.values()].sort((a, b) => b.faturamento - a.faturamento)
    .map((m) => ({ ...m, faturamento: r2(m.faturamento), ticket: r2(m.faturamento / m.os) }));
  // acréscimo sobre o custo das peças por faixa de custo
  const FC = [[0, 100], [100, 300], [300, 700], [700, 1500], [1500, Infinity]];
  const markup = FC.map((f) => {
    const g = vs.filter((v) => v.custo_pecas > 0 && v.custo_pecas >= f[0] && v.custo_pecas < f[1]);
    const custo = g.reduce((a, v) => a + v.custo_pecas, 0);
    const venda = g.reduce((a, v) => a + (v.valor_total - v.valor_mao_obra - v.custo_frete - v.custo_insumos), 0);
    return { faixaCusto: rotuloFaixa(f), os: g.length, markup: custo ? venda / custo - 1 : null };
  });
  return { os: vs.length, faturamento: r2(total), faixas, clientes, mecanicos, markup };
}

export function orcamentosAbertos(db) {
  return db.prepare(`SELECT v.id, v.numero, v.data, v.placa, v.veiculo, v.valor_total, c.nome AS cliente
      FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE v.situacao = 'orcamento' ORDER BY v.data DESC LIMIT 100`).all();
}

/** Simulador: o que a meta exige combinando mais OS e ticket maior. */
export function simulador({ os, ticket, meta, margemContribuicaoPct, custosFixos }) {
  const fat = os * ticket;
  const mc = fat * margemContribuicaoPct;
  return {
    faturamento: r2(fat), margemContribuicao: r2(mc), sobra: r2(mc - custosFixos),
    faltaParaMeta: r2(Math.max(0, meta - fat)),
    ticketParaMeta: os > 0 ? r2(meta / os) : null,
    osParaMeta: ticket > 0 ? Math.ceil(meta / ticket) : null,
  };
}

export { mesDe };
