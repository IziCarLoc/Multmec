import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco } from '../src/db.js';
import { criarApp } from '../src/app.js';

async function subir() {
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: 'senha-de-teste-123', segredo: 'x'.repeat(32), agora: () => new Date('2026-10-02T15:00:00Z') });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  let cookie = '';
  const chamar = async (metodo, caminho, corpo, extra = {}) => {
    const r = await fetch(base + caminho, {
      method: metodo,
      headers: { ...(corpo !== undefined ? { 'content-type': 'application/json' } : {}), ...(cookie ? { cookie } : {}), ...extra },
      body: corpo !== undefined ? JSON.stringify(corpo) : undefined,
    });
    const set = r.headers.get('set-cookie');
    if (set) cookie = set.split(';')[0];
    let json = null;
    try { json = await r.json(); } catch { /* sem corpo */ }
    return { status: r.status, json };
  };
  return { db, chamar, fechar: () => server.close() };
}

test('API exige login e bloqueia senha errada', async () => {
  const { chamar, fechar } = await subir();
  try {
    assert.equal((await chamar('GET', '/api/painel')).status, 401);
    assert.equal((await chamar('POST', '/api/login', { senha: 'errada' })).status, 401);
    assert.equal((await chamar('POST', '/api/login', { senha: 'senha-de-teste-123' })).status, 200);
    assert.equal((await chamar('GET', '/api/painel')).status, 200);
  } finally { fechar(); }
});

test('POST que não é JSON é recusado (proteção contra formulário de outro site)', async () => {
  const { chamar, fechar } = await subir();
  try {
    await chamar('POST', '/api/login', { senha: 'senha-de-teste-123' });
    const r = await chamar('POST', '/api/clientes', undefined, { 'content-type': 'application/x-www-form-urlencoded' });
    assert.equal(r.status, 415);
  } finally { fechar(); }
});

test('login erra 5 vezes e passa a responder 429', async () => {
  const { chamar, fechar } = await subir();
  try {
    for (let i = 0; i < 5; i++) assert.equal((await chamar('POST', '/api/login', { senha: 'x' })).status, 401);
    assert.equal((await chamar('POST', '/api/login', { senha: 'senha-de-teste-123' })).status, 429);
  } finally { fechar(); }
});

test('fluxo: cria cliente a prazo, lança OS, recebe e vê no painel', async () => {
  const { chamar, fechar } = await subir();
  try {
    await chamar('POST', '/api/login', { senha: 'senha-de-teste-123' });
    const c = await chamar('POST', '/api/clientes', { nome: 'izicar', tipo: 'locadora', prazoDias: 7, limite: 10000 });
    assert.equal(c.status, 201);
    const dup = await chamar('POST', '/api/clientes', { nome: 'IZICAR' });
    assert.equal(dup.status, 400);

    const v = await chamar('POST', '/api/vendas', { numero: '1200', data: '2026-10-01', clienteId: c.json.id, placa: 'abc-1d23', veiculo: 'kwid', valorTotal: 1200, valorMaoObra: 300, custoPecas: 500 });
    assert.equal(v.status, 201);
    const ruim = await chamar('POST', '/api/vendas', { data: '2026-10-01', valorTotal: 100, valorMaoObra: 300 });
    assert.equal(ruim.status, 400);
    assert.match(ruim.json.erro, /mão de obra/);

    assert.equal((await chamar('PUT', '/api/config', { saldoCaixaInicialData: '2026-09-01', saldoCaixaInicial: 500 })).status, 200);
    let painel = (await chamar('GET', '/api/painel')).json;
    assert.equal(painel.mes, '2026-10');
    assert.equal(painel.termometro.faturamento, 1200);
    assert.equal(painel.carteira[0].exposicao, 1200);

    const r = await chamar('POST', `/api/clientes/${c.json.id}/pagamento`, { valor: 1200, forma: 'pix', data: '2026-10-02' });
    assert.equal(r.status, 200);
    painel = (await chamar('GET', '/api/painel')).json;
    assert.equal(painel.carteira[0].exposicao, 0);
    assert.equal(painel.caixa.entrou, 1200);
    assert.equal(painel.caixa.saldo, 1700);

    const lista = (await chamar('GET', '/api/vendas?mes=2026-10')).json;
    assert.equal(lista[0].placa, 'ABC1D23');
    assert.equal(lista[0].lucro_bruto, 700);
  } finally { fechar(); }
});

test('importação por API aplica corte e valida data', async () => {
  const { chamar, fechar } = await subir();
  try {
    await chamar('POST', '/api/login', { senha: 'senha-de-teste-123' });
    const csv = 'O.S,DATA,VEÍCULO,PLACA,OPERADOR,CUSTO PEÇAS,M DE OBRA,INSUMOS,FRETE,VL SERVIÇO,LUCRO PÇ,PROPRIETA,DESC,LUCRO BRUTO,OBSERVAÇÃO,OBSERVAÇÃO,LOCADORA\n' +
      Array.from({ length: 8 }, (_, i) => `${i + 1},${String(i + 1).padStart(2, '0')}/09/2026,GOL,AAA111${i},ROBSON,"R$ 100,00",,,,"R$ 300,00",,JOAO,,,,,`).join('\n');
    assert.equal((await chamar('POST', '/api/importar', { csv, quitadasAte: '2026-31-99' })).status, 400);
    const ok = await chamar('POST', '/api/importar', { csv, quitadasAte: '2026-09-04' });
    assert.equal(ok.status, 200);
    assert.equal(ok.json.novas, 8);
    assert.equal(ok.json.quitadasPorCorte, 4);
  } finally { fechar(); }
});
