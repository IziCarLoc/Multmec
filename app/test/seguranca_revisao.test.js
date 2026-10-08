// Achados da revisão adversarial: sessão revogável, cookie malformado, corpo lixo, corpo grande antes do login, entradas mal tipadas.
import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco } from '../src/db.js';
import { criarApp } from '../src/app.js';

async function subir() {
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: 'senha-de-teste-123', segredo: 'z'.repeat(32), agora: () => new Date('2026-10-08T15:00:00Z') });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  const bruto = (metodo, caminho, { corpo, cookie, tipo = 'application/json' } = {}) =>
    fetch(base + caminho, { method: metodo, headers: { ...(metodo !== 'GET' ? { 'content-type': tipo } : {}), ...(cookie ? { cookie } : {}) }, body: corpo });
  const entrar = async () => {
    const r = await bruto('POST', '/api/login', { corpo: JSON.stringify({ senha: 'senha-de-teste-123' }) });
    return r.headers.get('set-cookie').split(';')[0];
  };
  return { db, bruto, entrar, fechar: () => server.close() };
}

test('sair revoga a sessão de verdade: o cookie antigo deixa de valer', async () => {
  const { bruto, entrar, fechar } = await subir();
  try {
    const cookie = await entrar();
    assert.equal((await bruto('GET', '/api/compras/resumo', { cookie })).status, 200);
    assert.equal((await bruto('POST', '/api/logout', { cookie, corpo: '{}' })).status, 200);
    assert.equal((await bruto('GET', '/api/compras/resumo', { cookie })).status, 401);
    assert.equal((await (await bruto('GET', '/api/sessao', { cookie })).json()).logado, false);
  } finally { fechar(); }
});

test('cookie malformado ou com assinatura multibyte dá 401, não 500', async () => {
  const { bruto, fechar } = await subir();
  try {
    for (const c of ['multmec_sid=%E0%A4%A', `multmec_sid=a.${encodeURIComponent('é'.repeat(43))}`, 'multmec_sid=.', 'multmec_sid=abc']) {
      assert.equal((await bruto('GET', '/api/sessao', { cookie: c })).status, 200, c);
      assert.equal((await bruto('GET', '/api/compras/resumo', { cookie: c })).status, 401, c);
    }
  } finally { fechar(); }
});

test('corpo lixo no login não tranca o dono para fora; senha errada continua contando; corpo grande é recusado cedo', async () => {
  const { bruto, entrar, fechar } = await subir();
  try {
    for (let i = 0; i < 8; i++) assert.equal((await bruto('POST', '/api/login', { corpo: '[1,2,3]' })).status, 400);
    assert.ok(await entrar());                                                                   // ainda entra
    for (let i = 0; i < 5; i++) assert.equal((await bruto('POST', '/api/login', { corpo: JSON.stringify({ senha: 'errada' }) })).status, 401);
    assert.equal((await bruto('POST', '/api/login', { corpo: JSON.stringify({ senha: 'errada' }) })).status, 429);
  } finally { fechar(); }
  const outro = await subir();
  try {
    const grande = JSON.stringify({ senha: 'x'.repeat(5000) });
    assert.equal((await outro.bruto('POST', '/api/login', { corpo: grande })).status, 413);
  } finally { outro.fechar(); }
});

test('entradas mal tipadas dão 400, não 500; mês futuro não gera contas recorrentes', async () => {
  const { db, bruto, entrar, fechar } = await subir();
  try {
    const cookie = await entrar();
    const j = (m, c, corpo) => bruto(m, c, { cookie, corpo: corpo === undefined ? undefined : JSON.stringify(corpo) });
    assert.equal((await j('GET', '/api/vendas?mes[]=2026-10')).status, 400);
    assert.equal((await j('GET', '/api/compras/notas?mes[]=2026-10')).status, 400);
    assert.equal((await j('POST', '/api/compras/boletos/1/conciliar', { itens: { a: 1 } })).status, 400);
    assert.equal((await j('POST', '/api/compras/boletos/1/conciliar', { itens: [null] })).status, 400);
    assert.equal((await j('POST', '/api/compras/notas', { numero: '1', valorTotal: 10, dataEmissao: '2026-10-01', fornecedorNome: 'X', duplicatas: { a: 1 } })).status, 400);
    assert.equal((await j('POST', '/api/compras/notas/1/aplicar-sugestoes', { itens: 5 })).status, 400);
    assert.equal((await j('POST', '/api/compras/fornecedores', { nome: { a: 1 } })).status, 400);
    await j('POST', '/api/recorrentes', { descricao: 'Aluguel', categoriaId: 6, valor: 1000, diaVencimento: 5 });
    await j('GET', '/api/saidas?mes=2099-12');
    await j('GET', '/api/painel?mes=2088-05');
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM saidas WHERE substr(vencimento,1,4) IN ('2099','2088','2089')").get().n, 0);
  } finally { fechar(); }
});
