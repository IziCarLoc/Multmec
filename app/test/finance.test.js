import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco, lerConfig, gravarConfig } from '../src/db.js';
import * as F from '../src/finance.js';

function banco() {
  const db = abrirBanco(':memory:');
  const cli = (nome, tipo = 'avulso', prazo = 0, limite = 0) =>
    Number(db.prepare('INSERT INTO clientes (nome, tipo, prazo_dias, limite_credito) VALUES (?, ?, ?, ?)').run(nome, tipo, prazo, limite).lastInsertRowid);
  const venda = (clienteId, data, total, { mo = 0, custo = null, frete = 0, sit = 'concluida', numero = null } = {}) =>
    Number(db.prepare(`INSERT INTO vendas (numero, data, cliente_id, situacao, valor_total, valor_mao_obra, custo_pecas, custo_frete) VALUES (?, ?, ?, ?, ?, ?, ?, ?)`)
      .run(numero, data, clienteId, sit, total, mo, custo, frete).lastInsertRowid);
  return { db, cli, venda };
}

test('resumoMes soma só OS concluídas do mês e calcula lucro bruto', () => {
  const { db, cli, venda } = banco();
  const c = cli('JOAO');
  venda(c, '2026-09-10', 1000, { mo: 300, custo: 400, frete: 20 });
  venda(c, '2026-09-11', 500, { mo: 100, custo: 150 });
  venda(c, '2026-09-12', 900, { sit: 'orcamento' });
  venda(c, '2026-08-30', 700, { mo: 100, custo: 200 });
  const r = F.resumoMes(db, '2026-09');
  assert.equal(r.os, 2);
  assert.equal(r.faturamento, 1500);
  assert.equal(r.lucroBruto, 1500 - 550 - 20);
  assert.equal(r.margemPecas, 930 - 400);
  assert.equal(r.ticket, 750);
});

test('OS sem custo de peças é sinalizada e a cascata estima o custo, em vez de inflar a sobra', () => {
  const { db, cli, venda } = banco();
  const c = cli('ANA');
  venda(c, '2026-09-10', 1000, { mo: 200, custo: null });
  const r = F.resumoMes(db, '2026-09');
  assert.equal(r.osSemCusto, 1);
  const cas = F.cascata(db, '2026-09', '2026-09-30');
  assert.equal(cas.custoEstimado, 330);                           // 33% padrão de 1000
  assert.equal(cas.custos, 330);
});

test('termômetro: ritmo, projeção e quanto falta por dia útil', () => {
  const { db, cli, venda } = banco();
  const c = cli('ANA');
  venda(c, '2026-10-01', 4000);
  venda(c, '2026-10-02', 6000);
  const t = F.termometro(db, '2026-10', '2026-10-02');            // sexta; 2 dias úteis passados de 22
  assert.equal(t.diasUteisTotal, 22);
  assert.equal(t.diasUteisPassados, 2);
  assert.equal(t.ritmoDiario, 5000);
  assert.equal(t.projecao, 110000);
  assert.equal(t.necessarioPorDia, 4500);                          // faltam 90.000 em 20 dias
});

test('recebimento do cliente quita primeiro o saldo anterior e depois as OS mais antigas', () => {
  const { db, cli, venda } = banco();
  const c = cli('IZICAR', 'locadora', 7, 5000);
  db.prepare("INSERT INTO vendas (numero, data, cliente_id, situacao, valor_total) VALUES ('SALDO', '2026-09-01', ?, 'saldo', 2000)").run(c);
  const a = venda(c, '2026-09-10', 800, { numero: '1' });
  venda(c, '2026-09-20', 600, { numero: '2' });
  const r = F.receberDoCliente(db, c, { valor: 2500, data: '2026-09-25', forma: 'pix' });
  assert.equal(r.aplicado.length, 2);
  assert.equal(r.aplicado[0].valor, 2000);
  assert.equal(r.aplicado[1].valor, 500);
  const abertas = F.emAberto(db, '2026-09-25', c);
  assert.deepEqual(abertas.map((o) => [o.numero, o.aberto]), [['1', 300], ['2', 600]]);
  assert.ok(a);
});

test('cliente a prazo fica travado ao passar do limite ou do atraso máximo', () => {
  const { db, cli, venda } = banco();
  const c = cli('IZICAR', 'locadora', 7, 3000);
  venda(c, '2026-09-01', 2000, { numero: '10' });                  // vence 08/09
  const cliente = () => db.prepare('SELECT * FROM clientes WHERE id = ?').get(c);
  assert.equal(F.situacaoCliente(db, cliente(), '2026-09-05').status, 'ok');
  assert.equal(F.situacaoCliente(db, cliente(), '2026-09-12').status, 'atencao');   // 4 dias de atraso
  const s = F.situacaoCliente(db, cliente(), '2026-09-30');                         // 22 dias de atraso
  assert.equal(s.status, 'travado');
  assert.equal(s.faixas.de16a30, 2000);
  venda(c, '2026-09-29', 1500, { numero: '11' });
  const s2 = F.situacaoCliente(db, cliente(), '2026-09-29');
  assert.equal(s2.status, 'travado');                                               // 3.500 > limite 3.000
  assert.match(s2.motivo, /limite/);
});

test('contas recorrentes são geradas uma vez por mês e entram na cascata e no equilíbrio', () => {
  const { db, cli, venda } = banco();
  const cat = (nome) => db.prepare('SELECT id FROM categorias WHERE nome = ?').get(nome).id;
  db.prepare('INSERT INTO recorrentes (descricao, categoria_id, valor, dia_vencimento) VALUES (?, ?, ?, ?)').run('Salários', cat('Salários e encargos'), 20000, 5);
  db.prepare('INSERT INTO recorrentes (descricao, categoria_id, valor, dia_vencimento) VALUES (?, ?, ?, ?)').run('Aluguel', cat('Aluguel'), 5000, 10);
  assert.equal(F.gerarRecorrentes(db, '2026-10'), 2);
  assert.equal(F.gerarRecorrentes(db, '2026-10'), 0);
  const c = cli('ANA');
  venda(c, '2026-10-02', 100000, { mo: 25000, custo: 30000 });
  gravarConfig(db, { impostoPct: 0.06, taxaCartaoPct: 0.02, reservaPct: 0.03, retiradaSociosMeta: 10000 });
  const cas = F.cascata(db, '2026-10', '2026-10-31');
  assert.equal(cas.folha, 20000);
  assert.equal(cas.fixos, 5000);
  assert.equal(cas.impostos, 8000);
  assert.equal(cas.margemContribuicao, 100000 - 30000 - 8000);
  assert.equal(cas.resultado, 62000 - 25000);
  assert.equal(cas.disponivelSocios, 37000 - 3000);
  const eq = F.pontoEquilibrio(db, '2026-10', lerConfig(db), 10000);
  assert.equal(eq.custosFixos, 25000);
  assert.ok(Math.abs(eq.margemContribuicaoPct - (1 - 0.33 - 0.06 - 0.02 - 0.03)) < 1e-9);
  assert.ok(eq.paraRetirada > eq.equilibrio);
});

test('texto de cobrança lista o que está em aberto e o vencido', () => {
  const { db, cli, venda } = banco();
  const c = cli('IZICAR', 'locadora', 7, 5000);
  venda(c, '2026-09-01', 1234.5, { numero: '77' });
  const t = F.textoCobranca(db, c, '2026-09-20');
  assert.match(t, /OS 77/);
  assert.match(t, /1\.234,50/);
  assert.match(t, /vencida há 12 dia/);
});

test('caixa só conta dinheiro real: ignora recebimento "histórico" e exige data do saldo inicial', () => {
  const { db, cli, venda } = banco();
  const c = cli('ANA');
  const v1 = venda(c, '2026-10-01', 1000);
  const v2 = venda(c, '2026-10-02', 400);
  db.prepare("INSERT INTO recebimentos (venda_id, data, valor, forma) VALUES (?, '2026-10-01', 1000, 'histórico')").run(v1);
  db.prepare("INSERT INTO recebimentos (venda_id, data, valor, forma) VALUES (?, '2026-10-02', 400, 'pix')").run(v2);
  assert.equal(F.caixa(db, '2026-10-03').configurado, false);
  gravarConfig(db, { saldoCaixaInicialData: '2026-09-30', saldoCaixaInicial: 100 });
  const cx = F.caixa(db, '2026-10-03');
  assert.equal(cx.entrou, 400);
  assert.equal(cx.saldo, 500);
});

test('cliente avulso sem recebimento vira um aviso só, não "cliente travado"', () => {
  const { db, cli, venda } = banco();
  venda(cli('ANA'), '2026-09-01', 300);
  venda(cli('BETO'), '2026-09-02', 200);
  const a = F.alertas(db, '2026-10-03');
  assert.equal(a.filter((x) => x.tipo === 'cliente_travado').length, 0);
  assert.equal(a.filter((x) => x.tipo === 'avulso_em_aberto').length, 1);
  assert.equal(F.carteira(db, '2026-10-03').length, 0);
});
