import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { abrirBanco } from '../src/db.js';
import { criarApp } from '../src/app.js';
import { xmlNfe, CNPJ_OFICINA, CNPJ_OUTRO, CNPJ_FORNECEDOR } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';

const SENHA_DONO = 'senha-do-dono-123';
const SENHA_LANC = 'senha-lancamento-456';
const HOJE = '2026-10-08';
const CNPJ_LOCADORA = '45997418000153';                // CNPJ de teste (DV válido)

async function subir({ comLancamento = true } = {}) {
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: SENHA_DONO, senhaLancamento: comLancamento ? SENHA_LANC : null, segredo: 'p'.repeat(32), agora: () => new Date(`${HOJE}T15:00:00Z`) });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  const cliente = () => {
    let cookie = '';
    const chamar = async (metodo, caminho, corpo) => {
      const r = await fetch(base + caminho, { method: metodo, headers: { ...(metodo !== 'GET' ? { 'content-type': 'application/json' } : {}), ...(cookie ? { cookie } : {}) }, body: metodo !== 'GET' ? JSON.stringify(corpo ?? {}) : undefined });
      const set = r.headers.get('set-cookie');
      if (set) cookie = set.split(';')[0];
      let json = null;
      try { json = await r.json(); } catch { /* sem corpo */ }
      return { status: r.status, json };
    };
    return { chamar, entrar: (senha) => chamar('POST', '/api/login', { senha }) };
  };
  return { db, cliente, fechar: () => server.close() };
}

async function comoDono(ctx) { const c = ctx.cliente(); assert.equal((await c.entrar(SENHA_DONO)).status, 200); return c.chamar; }
async function comoLancamento(ctx) { const c = ctx.cliente(); assert.equal((await c.entrar(SENHA_LANC)).status, 200); return c.chamar; }

// ------------------------------------------------------------------ perfis

test('login por perfil: cada senha entra no seu perfil, a sessão diz qual é e o perfil não vem do cookie', async () => {
  const ctx = await subir();
  try {
    const dono = ctx.cliente();
    const lanc = ctx.cliente();
    const intruso = ctx.cliente();
    assert.equal((await dono.entrar(SENHA_DONO)).json.perfil, 'dono');
    assert.equal((await lanc.entrar(SENHA_LANC)).json.perfil, 'lancamento');
    assert.equal((await intruso.entrar('qualquer-outra-coisa')).status, 401);
    assert.deepEqual((await dono.chamar('GET', '/api/sessao')).json, { logado: true, perfil: 'dono' });
    assert.deepEqual((await lanc.chamar('GET', '/api/sessao')).json, { logado: true, perfil: 'lancamento' });
    assert.deepEqual((await intruso.chamar('GET', '/api/sessao')).json, { logado: false, perfil: null });
    // dono e lançamento têm sessões separadas no banco
    assert.deepEqual(ctx.db.prepare('SELECT perfil FROM sessoes ORDER BY perfil').all().map((x) => x.perfil), ['dono', 'lancamento']);
  } finally { ctx.fechar(); }
});

test('sem senha de lançamento só o dono entra; senha igual à do dono vale como dono', async () => {
  const ctx = await subir({ comLancamento: false });
  try {
    const c = ctx.cliente();
    assert.equal((await c.entrar(SENHA_LANC)).status, 401);
    assert.equal((await c.entrar(SENHA_DONO)).json.perfil, 'dono');
  } finally { ctx.fechar(); }
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: SENHA_DONO, senhaLancamento: SENHA_DONO, segredo: 'q'.repeat(32) });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  try {
    const r = await fetch(`http://127.0.0.1:${server.address().port}/api/login`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ senha: SENHA_DONO }) });
    assert.equal((await r.json()).perfil, 'dono');
  } finally { server.close(); }
});

test('quem só lança: cadastra nota e boleto, mas não paga, não aceita, não confirma fornecedor e não vê o resultado da oficina', async () => {
  const ctx = await subir();
  try {
    const dono = await comoDono(ctx);
    const lanc = await comoLancamento(ctx);
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });

    // telas financeiras do dono
    const cfgLanc = (await lanc('GET', '/api/config')).json;
    assert.equal(cfgLanc.cnpjOficina, CNPJ_OFICINA);
    assert.ok(!('metaFaturamento' in cfgLanc) && !('retiradaSociosMeta' in cfgLanc) && !('saldoCaixaInicial' in cfgLanc));
    assert.ok('metaFaturamento' in (await dono('GET', '/api/config')).json);
    for (const [m, c] of [['GET', '/api/painel'], ['PUT', '/api/config'], ['GET', '/api/relatorios'], ['POST', '/api/simulador'], ['GET', '/api/exportar/vendas.csv'],
      ['POST', '/api/importar'], ['GET', '/api/recorrentes'], ['GET', '/api/compras/entre-empresas']]) {
      assert.equal((await lanc(m, c, {})).status, 403, `${m} ${c}`);
      assert.notEqual((await dono(m, c, {})).status, 403, `dono ${m} ${c}`);
    }

    // cadastrar é com ela
    const xml = xmlNfe({ nNF: 501, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 300 }], dups: [{ venc: '2026-10-20', valor: 300 }] });
    const imp = await lanc('POST', '/api/compras/notas/xml', { xmls: [xml] });
    assert.equal(imp.status, 201);
    const nota = imp.json.resultados[0].nota_id;
    const b = await lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 300, vencimento: '2026-10-20' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA });
    assert.equal(b.status, 201);
    const id = b.json.boleto_id;
    assert.equal(ctx.db.prepare('SELECT criado_por FROM boletos WHERE id = ?').get(id).criado_por, 'lancamento');
    assert.equal(ctx.db.prepare('SELECT criado_por FROM notas_compra WHERE id = ?').get(nota).criado_por, 'lancamento');

    // a trilha guarda o perfil de quem fez
    const hist = (await lanc('GET', `/api/compras/boletos/${id}`)).json.historico;
    assert.ok(hist.some((h) => h.acao === 'cadastrar' && h.perfil === 'lancamento'));

    // pagar, aceitar, confirmar, apagar, cancelar o que não é dela: só o dono
    const forn = (await lanc('GET', '/api/compras/fornecedores')).json[0];
    assert.equal((await lanc('PUT', `/api/compras/fornecedores/${forn.id}`, { confirmado: true })).status, 403);
    assert.equal((await lanc('POST', '/api/compras/fornecedores', { nome: 'NOVA PECAS', confirmado: true })).status, 403);
    assert.equal((await lanc('POST', '/api/compras/fornecedores', { nome: 'NOVA PECAS', beneficiariosAutorizados: CNPJ_OUTRO })).status, 403);
    assert.equal((await lanc('POST', '/api/compras/fornecedores', { nome: 'NOVA PECAS' })).status, 201);
    assert.equal((await lanc('POST', `/api/compras/boletos/${id}/pagar`, { conferiuBanco: true })).status, 403);
    assert.equal((await lanc('POST', `/api/compras/boletos/${id}/reabrir`, {})).status, 403);
    assert.equal((await lanc('POST', `/api/compras/boletos/${id}/desfazer-pagamento`, {})).status, 403);
    assert.equal((await lanc('POST', '/api/compras/ocorrencias/aceitar', { chave: 'x:boleto:1', motivo: 'conferido com o fornecedor' })).status, 403);
    assert.equal((await lanc('DELETE', '/api/compras/ocorrencias/aceite?chave=x:boleto:1')).status, 403);
    assert.equal((await lanc('PUT', `/api/compras/notas/${nota}`, { situacao: 'cancelada', obs: 'teste de cancelamento' })).status, 403);
    assert.equal((await lanc('DELETE', `/api/compras/notas/${nota}`)).status, 403);
    assert.equal((await lanc('POST', '/api/compras/empresas', { nome: 'X', cnpj: CNPJ_LOCADORA })).status, 403);
    const saida = ctx.db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(id).saida_id;
    assert.equal((await lanc('POST', `/api/saidas/${saida}/pagar`, {})).status, 403);
    assert.equal((await lanc('DELETE', `/api/saidas/${saida}`)).status, 403);
    const cat = ctx.db.prepare('SELECT id FROM categorias LIMIT 1').get().id;
    assert.equal((await lanc('POST', '/api/saidas', { descricao: 'Conta qualquer', categoriaId: cat, valor: 50, vencimento: '2026-10-15', pagarAgora: true })).status, 403);
    assert.equal((await lanc('POST', '/api/saidas', { descricao: 'Conta qualquer', categoriaId: cat, valor: 50, vencimento: '2026-10-15' })).status, 201);

    // ela desfaz só o próprio cadastro errado: boleto em aberto, criado por ela, sem contestar
    assert.equal((await lanc('POST', `/api/compras/boletos/${id}/cancelar`, { situacao: 'contestado' })).status, 403);
    const doDono = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 80, vencimento: '2026-10-22' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    assert.equal((await lanc('POST', `/api/compras/boletos/${doDono.json.boleto_id}/cancelar`, {})).status, 403);
    assert.equal((await lanc('POST', `/api/compras/boletos/${id}/cancelar`, {})).status, 200);
    const rastro = (await dono('GET', `/api/compras/boletos/${id}`)).json.historico;
    assert.ok(rastro.some((h) => h.acao === 'cancelar' && h.perfil === 'lancamento'));
  } finally { ctx.fechar(); }
});

test('o dono paga normalmente e a trilha registra "dono"', async () => {
  const ctx = await subir();
  try {
    const dono = await comoDono(ctx);
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    const imp = await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 77, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 120 }], dups: [{ venc: '2026-10-20', valor: 120 }] })] });
    assert.equal(imp.status, 201);
    await dono('PUT', `/api/compras/fornecedores/${(await dono('GET', '/api/compras/fornecedores')).json[0].id}`, { confirmado: true });
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 120, vencimento: '2026-10-20' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA });
    const pago = await dono('POST', `/api/compras/boletos/${b.json.boleto_id}/pagar`, { conferiuBanco: true });
    assert.equal(pago.status, 200);
    assert.ok(ctx.db.prepare("SELECT 1 FROM auditoria_log WHERE acao = 'pagar' AND entidade_id = ? AND perfil = 'dono'").get(b.json.boleto_id));
    // desfazer o pagamento também é do dono e funciona pela tela de Compras
    assert.equal((await dono('POST', `/api/compras/boletos/${b.json.boleto_id}/desfazer-pagamento`, {})).status, 200);
    assert.equal(ctx.db.prepare('SELECT situacao FROM boletos WHERE id = ?').get(b.json.boleto_id).situacao, 'aberto');
  } finally { ctx.fechar(); }
});

test('primeiro pagamento a fornecedor não confirmado é grave; depois de pagar uma vez vira só conferência', async () => {
  const ctx = await subir();
  try {
    const dono = await comoDono(ctx);
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 90, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 100 }], dups: [{ venc: '2026-10-20', valor: 100 }] })] });
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 100, vencimento: '2026-10-20' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA });
    const oc = b.json.ocorrencias.find((o) => o.tipo === 'fornecedor_nao_confirmado');
    assert.equal(oc.severidade, 'alta');
    const travado = await dono('POST', `/api/compras/boletos/${b.json.boleto_id}/pagar`, { conferiuBanco: true });
    assert.equal(travado.status, 409);
    assert.ok(travado.json.ocorrencias.some((o) => o.tipo === 'fornecedor_nao_confirmado'));
    assert.equal((await dono('POST', `/api/compras/boletos/${b.json.boleto_id}/pagar`, { conferiuBanco: true, aprovar: true, motivo: 'Liguei no fornecedor e confirmei o CNPJ' })).status, 200);
    // o segundo boleto do mesmo fornecedor (que já recebeu) não bloqueia mais: só pede para confirmar
    await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 91, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 110 }], dups: [{ venc: '2026-10-25', valor: 110 }] })] });
    const b2 = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 110, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA });
    assert.equal(b2.json.ocorrencias.find((o) => o.tipo === 'fornecedor_nao_confirmado').severidade, 'media');
  } finally { ctx.fechar(); }
});

// ------------------------------------------------------------------ empresas do grupo

async function prepararGrupo(ctx) {
  const dono = await comoDono(ctx);
  await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
  return dono;
}

test('CNPJ desconhecido na nota e no boleto é grave; cadastrado como empresa do grupo deixa de alertar', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    const imp = await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 310, destCnpj: CNPJ_LOCADORA, itens: [{ cProd: 'A', xProd: 'BATERIA', q: 1, vProd: 400 }], dups: [{ venc: '2026-10-21', valor: 400 }] })] });
    const nota = imp.json.resultados[0].nota_id;
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 400, vencimento: '2026-10-21' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA });
    const antes = (await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias;
    assert.equal(antes.find((o) => o.tipo === 'nota_destinatario_diverge').severidade, 'alta');
    assert.equal(antes.find((o) => o.tipo === 'boleto_pagador_diverge').severidade, 'alta');
    assert.ok(ctx.db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(b.json.boleto_id).saida_id, 'enquanto é desconhecido, entra nas contas da oficina');

    // cadastra a locadora: nota e boleto passam a ser dela, o alerta some e a conta sai da oficina
    assert.equal((await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_OFICINA, papel: 'locadora' })).status, 400);       // é a própria oficina
    assert.equal((await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: '11222333000299', papel: 'locadora' })).status, 400);     // CNPJ inválido
    assert.equal((await dono('POST', '/api/compras/empresas', { nome: 'DISTRIBUIDORA', cnpj: CNPJ_FORNECEDOR, papel: 'outra' })).status, 400);           // já é fornecedor
    const emp = await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    assert.equal(emp.status, 201);
    assert.equal((await dono('POST', '/api/compras/empresas', { nome: 'OUTRA', cnpj: CNPJ_LOCADORA })).status, 400);                                      // repetida
    const depois = (await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias;
    assert.ok(!depois.some((o) => o.tipo === 'nota_destinatario_diverge' || o.tipo === 'boleto_pagador_diverge'));
    const bol = ctx.db.prepare('SELECT empresa_id, saida_id FROM boletos WHERE id = ?').get(b.json.boleto_id);
    assert.equal(bol.empresa_id, emp.json.id);
    assert.equal(bol.saida_id, null);
    assert.equal(ctx.db.prepare('SELECT empresa_id FROM notas_compra WHERE id = ?').get(nota).empresa_id, emp.json.id);
    await dono('PUT', `/api/compras/fornecedores/${(await dono('GET', '/api/compras/fornecedores')).json[0].id}`, { confirmado: true });
    const lista = (await dono('GET', '/api/compras/boletos')).json;
    assert.equal(lista[0].empresa_nome, 'IZICAR LOCADORA');
    assert.match(lista[0].veredito.texto + lista[0].veredito.curto, /IZICAR/);
    // fora das contas a pagar da oficina
    assert.equal((await dono('GET', '/api/saidas?mes=2026-10')).json.linhas.filter((s) => /Boleto/.test(s.descricao)).length, 0);
  } finally { ctx.fechar(); }
});

test('boleto da empresa do grupo: pagar exige dizer quem paga; a oficina pagando vira "a receber" e entra no caixa', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    await dono('PUT', '/api/config', { saldoCaixaInicial: 10000, saldoCaixaInicialData: '2026-10-01' });
    const emp = (await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' })).json;
    await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 320, destCnpj: CNPJ_LOCADORA, itens: [{ cProd: 'A', xProd: 'PNEU', q: 1, vProd: 900 }], dups: [{ venc: '2026-10-21', valor: 900 }] })] });
    await dono('PUT', `/api/compras/fornecedores/${(await dono('GET', '/api/compras/fornecedores')).json[0].id}`, { confirmado: true });
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 900, vencimento: '2026-10-21' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA });
    const id = b.json.boleto_id;
    assert.equal(b.json.ocorrencias.length, 0);

    const sem = await dono('POST', `/api/compras/boletos/${id}/pagar`, { conferiuBanco: true });
    assert.equal(sem.status, 400);
    assert.match(sem.json.erro, /IZICAR LOCADORA.*quem está pagando/);
    assert.equal((await dono('POST', `/api/compras/boletos/${id}/pagar`, { conferiuBanco: true, pagoPor: 'oficina', data: '2026-10-08' })).status, 200);

    const ac = (await dono('GET', '/api/compras/entre-empresas')).json;
    assert.equal(ac.aReceber, 900);
    assert.equal(ac.empresas[0].itens[0].origem, 'boleto');
    assert.equal(ac.empresas[0].itens[0].caixa, 1);
    // saiu dinheiro da conta da oficina: o caixa já desconta
    assert.equal((await dono('GET', '/api/painel')).json.caixa.saldo, 9100);

    // devolução parcial e total; não passa do que falta; não volta no tempo
    const adiant = ac.empresas[0].itens[0].id;
    assert.equal((await dono('POST', `/api/compras/adiantamentos/${adiant}/baixar`, { valor: 1000, data: '2026-10-09' })).status, 400);
    assert.equal((await dono('POST', `/api/compras/adiantamentos/${adiant}/baixar`, { valor: 300, data: '2026-10-05' })).status, 400);
    const b1 = await dono('POST', `/api/compras/adiantamentos/${adiant}/baixar`, { valor: 300, data: '2026-10-08' });
    assert.equal(b1.status, 201);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 600);
    assert.equal((await dono('GET', '/api/painel')).json.caixa.saldo, 9400);
    // com devolução feita, o pagamento do boleto não se desfaz (primeiro desfaz a devolução)
    assert.equal((await dono('POST', `/api/compras/boletos/${id}/desfazer-pagamento`, {})).status, 400);
    assert.equal((await dono('DELETE', `/api/compras/adiantamentos/baixas/${b1.json.id}`)).status, 200);
    assert.equal((await dono('POST', `/api/compras/adiantamentos/${adiant}/baixar`, { data: '2026-10-08' })).status, 201);           // sem valor = o que falta
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 0);
    assert.equal((await dono('POST', `/api/compras/adiantamentos/${adiant}/baixar`, { valor: 1 })).status, 400);                    // já acertado
    // empresa com saldo zerado pode ser desativada
    assert.equal((await dono('PUT', `/api/compras/empresas/${emp.id}`, { ativo: false })).status, 200);
  } finally { ctx.fechar(); }
});

test('boleto pago pela própria empresa não gera valor a receber; desfazer o pagamento da oficina desfaz o acerto', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    await dono('PUT', `/api/compras/fornecedores/${(await dono('POST', '/api/compras/fornecedores', { nome: 'DISTRIBUIDORA TESTE LTDA', cnpj: CNPJ_FORNECEDOR })).json.id}`, { confirmado: true });
    const mk = async (valor, venc) => (await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor, vencimento: venc }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA })).json.boleto_id;
    const aprovar = { conferiuBanco: true, aprovar: true, motivo: 'Sem nota ainda, fornecedor confirmou' };
    const a = await mk(200, '2026-10-25');
    assert.equal((await dono('POST', `/api/compras/boletos/${a}/pagar`, { ...aprovar, pagoPor: 'empresa' })).status, 200);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 0);
    const b = await mk(250, '2026-10-26');
    assert.equal((await dono('POST', `/api/compras/boletos/${b}/pagar`, { ...aprovar, pagoPor: 'oficina' })).status, 200);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 250);
    assert.equal((await dono('POST', `/api/compras/boletos/${b}/desfazer-pagamento`, {})).status, 200);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 0);
  } finally { ctx.fechar(); }
});

test('desativar a empresa devolve o boleto às contas da oficina; empresa com acerto em aberto não se desativa', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    const emp = (await dono('POST', '/api/compras/empresas', { nome: 'OFICINA DO MATEUS', cnpj: CNPJ_LOCADORA, papel: 'socio' })).json;
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 150, vencimento: '2026-10-30' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA });
    assert.equal(ctx.db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(b.json.boleto_id).saida_id, null);
    assert.equal((await dono('PUT', `/api/compras/empresas/${emp.id}`, { ativo: false })).status, 200);
    // sem a empresa, o boleto volta a ser da oficina (e o CNPJ vira "desconhecido": grave)
    const bol = ctx.db.prepare('SELECT empresa_id, saida_id FROM boletos WHERE id = ?').get(b.json.boleto_id);
    assert.equal(bol.empresa_id, null);
    assert.ok(bol.saida_id);
    assert.ok((await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.some((o) => o.tipo === 'boleto_pagador_diverge' && o.severidade === 'alta'));
    // reativa e cria um acerto manual: a empresa não pode ser desativada com valor em aberto
    assert.equal((await dono('PUT', `/api/compras/empresas/${emp.id}`, { ativo: true })).status, 200);
    const adiant = await dono('POST', '/api/compras/adiantamentos', { empresaId: emp.id, sentido: 'a_receber', valor: 120, descricao: 'Transferi para pagar o aluguel do pátio', data: '2026-10-02', caixa: true });
    assert.equal(adiant.status, 201);
    assert.equal((await dono('PUT', `/api/compras/empresas/${emp.id}`, { ativo: false })).status, 400);
    assert.equal((await dono('DELETE', `/api/compras/adiantamentos/${adiant.json.id}`)).status, 200);
    assert.equal((await dono('PUT', `/api/compras/empresas/${emp.id}`, { ativo: false })).status, 200);
  } finally { ctx.fechar(); }
});

test('peça comprada no CNPJ da oficina e entregue a outra empresa vira valor a receber; desfazer o destino desfaz o acerto', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    const emp = (await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' })).json;
    const imp = await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 330, itens: [{ cProd: 'F1', xProd: 'FILTRO DE OLEO', q: 2, vProd: 80 }, { cProd: 'F2', xProd: 'FILTRO DE AR', q: 1, vProd: 70 }] })] });
    const nota = (await dono('GET', `/api/compras/notas/${imp.json.resultados[0].nota_id}`)).json;
    const [i1, i2] = nota.itens;
    assert.equal((await dono('POST', `/api/compras/itens/${i1.id}/alocar`, { destino: 'outra_empresa' })).status, 400);               // falta a empresa
    const aloc = await dono('POST', `/api/compras/itens/${i1.id}/alocar`, { destino: 'outra_empresa', empresaId: emp.id });
    assert.equal(aloc.status, 201);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 80);
    // item em nome da própria empresa não gera acerto: não foi a oficina que comprou
    const imp2 = await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 331, destCnpj: CNPJ_LOCADORA, itens: [{ cProd: 'F3', xProd: 'FILTRO', q: 1, vProd: 55 }] })] });
    const n2 = (await dono('GET', `/api/compras/notas/${imp2.json.resultados[0].nota_id}`)).json;
    assert.equal((await dono('POST', `/api/compras/itens/${n2.itens[0].id}/alocar`, { destino: 'outra_empresa', empresaId: emp.id })).status, 201);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 80);
    // devolve parte; remover a peça com devolução feita é recusado
    const adiant = (await dono('GET', '/api/compras/entre-empresas')).json.empresas[0].itens[0];
    assert.equal(adiant.origem, 'peca');
    assert.equal(adiant.caixa, 0);
    const baixa = await dono('POST', `/api/compras/adiantamentos/${adiant.id}/baixar`, { valor: 30 });
    assert.equal((await dono('DELETE', `/api/compras/alocacoes/${aloc.json.id}`)).status, 400);
    await dono('DELETE', `/api/compras/adiantamentos/baixas/${baixa.json.id}`);
    assert.equal((await dono('DELETE', `/api/compras/alocacoes/${aloc.json.id}`)).status, 200);
    assert.equal((await dono('GET', '/api/compras/entre-empresas')).json.aReceber, 0);
    void i2;
  } finally { ctx.fechar(); }
});

test('peça de nota em nome da locadora usada em OS da oficina gera aviso de acerto; acerto antigo gera aviso de cobrança', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    const emp = (await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' })).json;
    const venda = await dono('POST', '/api/vendas', { numero: '2001', data: '2026-10-06', clienteNome: 'CLIENTE TESTE', placa: 'ABC1D23', valorTotal: 800, valorMaoObra: 300, situacao: 'concluida' });
    const imp = await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 340, destCnpj: CNPJ_LOCADORA, itens: [{ cProd: 'K', xProd: 'KIT EMBREAGEM', q: 1, vProd: 500 }] })] });
    const nota = (await dono('GET', `/api/compras/notas/${imp.json.resultados[0].nota_id}`)).json;
    assert.equal((await dono('POST', `/api/compras/itens/${nota.itens[0].id}/alocar`, { destino: 'os', vendaId: venda.json.id })).status, 201);
    const oc = (await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.find((o) => o.tipo === 'peca_de_empresa_na_os');
    assert.ok(oc);
    assert.equal(oc.severidade, 'media');
    assert.match(oc.titulo, /IZICAR LOCADORA/);

    // acerto com mais de 30 dias
    await dono('POST', '/api/compras/adiantamentos', { empresaId: emp.id, sentido: 'a_receber', valor: 700, descricao: 'Peças e serviço pagos pela oficina', data: '2026-08-01' });
    const velho = (await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.find((o) => o.tipo === 'adiantamento_antigo');
    assert.ok(velho);
    assert.match(velho.titulo, /deve à oficina há 68 dias/);
    assert.equal((await dono('PUT', '/api/config', { diasDevolucaoAdiantamento: 90 })).json.diasDevolucaoAdiantamento, 90);
    assert.ok(!(await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.some((o) => o.tipo === 'adiantamento_antigo'));
  } finally { ctx.fechar(); }
});

test('nota e boleto em nome de empresas diferentes avisam', async () => {
  const ctx = await subir();
  try {
    const dono = await prepararGrupo(ctx);
    await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 350, destCnpj: CNPJ_LOCADORA, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 260 }], dups: [{ venc: '2026-10-28', valor: 260 }] })] });
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 260, vencimento: '2026-10-28' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA });
    assert.ok(b.json.ocorrencias.some((o) => o.tipo === 'boleto_nota_empresas_diferentes'));
  } finally { ctx.fechar(); }
});

// ------------------------------------------------------------------ migração de banco antigo

test('banco antigo sem "outra_empresa" no destino das peças é refeito sem perder dados nem quebrar as tabelas novas', () => {
  const pasta = mkdtempSync(join(tmpdir(), 'multmec-mig-'));
  const caminho = join(pasta, 'velho.db');
  try {
    let db = abrirBanco(caminho);
    db.pragma('foreign_keys = OFF');
    db.exec('DROP TABLE alocacoes');
    db.exec(`CREATE TABLE alocacoes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      item_id INTEGER NOT NULL REFERENCES nota_itens(id) ON DELETE CASCADE,
      destino TEXT NOT NULL DEFAULT 'os' CHECK (destino IN ('os','estoque','uso_interno','devolvido')),
      venda_id INTEGER REFERENCES vendas(id) ON DELETE CASCADE,
      quantidade REAL NOT NULL CHECK (quantidade > 0),
      valor REAL NOT NULL,
      obs TEXT,
      criado_em TEXT NOT NULL DEFAULT (datetime('now')),
      CHECK (destino <> 'os' OR venda_id IS NOT NULL)
    )`);
    db.pragma('foreign_keys = ON');
    const forn = Number(db.prepare("INSERT INTO fornecedores (nome, cnpj) VALUES ('F', ?)").run(CNPJ_FORNECEDOR).lastInsertRowid);
    const nota = Number(db.prepare("INSERT INTO notas_compra (fornecedor_id, numero, data_emissao, valor_total) VALUES (?, '1', '2026-10-01', 100)").run(forn).lastInsertRowid);
    const item = Number(db.prepare("INSERT INTO nota_itens (nota_id, n_item, descricao, quantidade, valor_unitario, valor_total, custo_total) VALUES (?, 1, 'X', 1, 100, 100, 100)").run(nota).lastInsertRowid);
    db.prepare("INSERT INTO alocacoes (item_id, destino, quantidade, valor) VALUES (?, 'estoque', 1, 100)").run(item);
    db.close();

    db = abrirBanco(caminho);
    assert.match(db.prepare("SELECT sql FROM sqlite_master WHERE name = 'alocacoes'").get().sql, /outra_empresa/);
    assert.deepEqual(db.prepare('SELECT item_id, destino, valor FROM alocacoes').all(), [{ item_id: item, destino: 'estoque', valor: 100 }]);
    // a tabela nova aceita o destino novo e as tabelas que apontavam para ela continuam íntegras
    const emp = Number(db.prepare("INSERT INTO empresas_grupo (nome, cnpj, papel) VALUES ('L', ?, 'locadora')").run(CNPJ_LOCADORA).lastInsertRowid);
    db.prepare("INSERT INTO alocacoes (item_id, destino, empresa_id, quantidade, valor) VALUES (?, 'outra_empresa', ?, 1, 10)").run(item, emp);
    db.prepare("INSERT INTO adiantamentos (empresa_id, origem, alocacao_id, descricao, valor, data) VALUES (?, 'peca', 1, 'x', 10, '2026-10-01')").run(emp);
    assert.deepEqual(db.pragma('foreign_key_check'), []);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM sqlite_master WHERE name LIKE 'alocacoes_%'").get().n, 0);
    db.close();
    db = abrirBanco(caminho);                                   // abrir de novo não refaz nada
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM alocacoes').get().n, 2);
    db.close();
  } finally { rmSync(pasta, { recursive: true, force: true }); }
});
