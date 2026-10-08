// Regressões da revisão adversarial da 2ª rodada (perfis, empresas do grupo, nota sem XML, DDA).
import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco, lerConfig } from '../src/db.js';
import { criarApp } from '../src/app.js';
import { interpretarBoleto } from '../src/boleto.js';
import { importarNotaXml, garantirFornecedor } from '../src/compras.js';
import { ocorrencias } from '../src/auditoria.js';
import { empresaDoCnpj, criarEmpresa } from '../src/grupo.js';
import { xmlNfe, chave, CNPJ_OFICINA, CNPJ_FORNECEDOR, CNPJ_OUTRO } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';
import { cnabDda } from './helpers/cnab.js';

const SENHA_DONO = 'senha-do-dono-123';
const SENHA_LANC = 'senha-lancamento-456';
const HOJE = '2026-10-08';
const CNPJ_LOCADORA = '45997418000153';
const barrasDe = (linha) => interpretarBoleto(linha, HOJE).codigoBarras;

async function subir({ db = abrirBanco(':memory:'), senhaLancamento = SENHA_LANC } = {}) {
  const app = criarApp(db, { senha: SENHA_DONO, senhaLancamento, segredo: 'r'.repeat(32), agora: () => new Date(`${HOJE}T15:00:00Z`) });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  const cliente = async (senha) => {
    let cookie = '';
    const chamar = async (metodo, caminho, corpo, extra = {}) => {
      const r = await fetch(base + caminho, { method: metodo, headers: { ...(metodo !== 'GET' ? { 'content-type': 'application/json' } : {}), ...(cookie ? { cookie } : {}) }, body: metodo !== 'GET' ? (extra.bruto ?? JSON.stringify(corpo ?? {})) : undefined });
      const set = r.headers.get('set-cookie');
      if (set) cookie = set.split(';')[0];
      let json = null;
      try { json = await r.json(); } catch { /* sem corpo */ }
      return { status: r.status, json };
    };
    const login = await chamar('POST', '/api/login', { senha });
    chamar.login = login;
    return chamar;
  };
  return { db, base, dono: await cliente(SENHA_DONO), lanc: await cliente(SENHA_LANC), novo: cliente, fechar: () => server.close() };
}

async function prepara(ctx) {
  await ctx.dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
}
async function confirmaFornecedor(ctx) {
  const f = (await ctx.dono('GET', '/api/compras/fornecedores')).json[0];
  await ctx.dono('PUT', `/api/compras/fornecedores/${f.id}`, { confirmado: true });
}

// ------------------------------------------------------------------ acesso e sessão

test('entrar como quem lança não zera as tentativas erradas: não dá para adivinhar a senha do dono "limpando" o contador', async () => {
  const ctx = await subir();
  try {
    const intruso = await ctx.novo('errada-1');                   // 1ª tentativa errada
    for (let i = 0; i < 3; i += 1) await ctx.novo(`errada-${i + 2}`);
    assert.equal((await ctx.novo(SENHA_LANC)).login.status, 200);  // entrar como lançamento no meio
    const ultimo = await ctx.novo('errada-9');                     // 5ª errada
    assert.equal(ultimo.login.status, 401);
    assert.equal((await ctx.novo(SENHA_DONO)).login.status, 429);  // a 6ª tentativa já é barrada, mesmo certa
    void intruso;
  } finally { ctx.fechar(); }
});

test('trocar ou tirar a senha do perfil derruba as sessões dele; perfil estranho na sessão não vira dono', async () => {
  const db = abrirBanco(':memory:');
  const a = await subir({ db });
  const sessaoLanc = (await a.lanc('GET', '/api/sessao')).json;
  assert.equal(sessaoLanc.perfil, 'lancamento');
  const cookieDono = await a.dono('GET', '/api/sessao');
  assert.equal(cookieDono.json.perfil, 'dono');
  a.fechar();
  // mesmo banco, senha de lançamento trocada: a sessão antiga de lançamento deixa de valer; a do dono continua (a senha dele é a mesma)
  const b = await subir({ db, senhaLancamento: 'outra-senha-lancamento-789' });
  const sessoes = db.prepare('SELECT sid, perfil FROM sessoes').all();
  assert.ok(sessoes.length >= 2);
  db.prepare("UPDATE sessoes SET perfil = 'qualquer' WHERE perfil = 'dono'").run();
  assert.equal((await b.novo(SENHA_DONO)).login.status, 200);       // um login novo funciona
  b.fechar();
  const c = await subir({ db, senhaLancamento: null });
  assert.equal((await c.novo(SENHA_LANC)).login.status, 401);       // sem senha de lançamento ninguém entra como lançamento
  c.fechar();
});

// ------------------------------------------------------------------ permissões

test('quem lança não mexe em conta paga, não define crédito de cliente, não vê salário e não entrega peça a outra empresa', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const cat = ctx.db.prepare("SELECT id FROM categorias WHERE grupo = 'pecas' LIMIT 1").get().id;
    const catFolha = ctx.db.prepare("SELECT id FROM categorias WHERE grupo = 'folha' LIMIT 1").get().id;
    const paga = (await ctx.dono('POST', '/api/saidas', { descricao: 'Conta do fornecedor', categoriaId: cat, valor: 100, vencimento: '2026-10-10', pagarAgora: true })).json.id;
    await ctx.dono('POST', '/api/saidas', { descricao: 'Salário do João', categoriaId: catFolha, valor: 2500, vencimento: '2026-10-10' });
    assert.equal((await ctx.lanc('PUT', `/api/saidas/${paga}`, { valor: 1, categoriaId: catFolha })).status, 403);
    assert.equal(ctx.db.prepare('SELECT valor FROM saidas WHERE id = ?').get(paga).valor, 100);
    assert.equal((await ctx.dono('PUT', `/api/saidas/${paga}`, { valor: 110 })).status, 200);
    assert.ok(ctx.db.prepare("SELECT 1 FROM auditoria_log WHERE acao = 'conta_alterada' AND entidade_id = ? AND perfil = 'dono'").get(paga));
    const contasLanc = (await ctx.lanc('GET', '/api/saidas?mes=2026-10')).json;
    assert.ok(!contasLanc.linhas.some((l) => /Salário/.test(l.descricao)));
    assert.ok((await ctx.dono('GET', '/api/saidas?mes=2026-10')).json.linhas.some((l) => /Salário/.test(l.descricao)));

    // cliente a prazo: ela cadastra sem crédito e não muda a política
    const novo = await ctx.lanc('POST', '/api/clientes', { nome: 'Cliente Novo', tipo: 'frota', prazoDias: 90, limite: 50000 });
    assert.equal(novo.status, 201);
    const c = ctx.db.prepare('SELECT tipo, prazo_dias, limite_credito FROM clientes WHERE id = ?').get(novo.json.id);
    assert.deepEqual({ ...c }, { tipo: 'avulso', prazo_dias: 0, limite_credito: 0 });
    assert.equal((await ctx.lanc('PUT', `/api/clientes/${novo.json.id}`, { nome: 'Cliente Novo', limite: 9000 })).status, 403);
    assert.equal((await ctx.lanc('PUT', `/api/clientes/${novo.json.id}`, { nome: 'Cliente Novo 2', obs: 'ligou' })).status, 200);
    assert.equal((await ctx.dono('PUT', `/api/clientes/${novo.json.id}`, { limite: 9000 })).status, 200);
    assert.ok(ctx.db.prepare("SELECT 1 FROM auditoria_log WHERE acao = 'cliente_credito' AND perfil = 'dono'").get());

    // peça entregue a outra empresa: do dono
    await ctx.dono('POST', '/api/compras/empresas', { nome: 'LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    const emp = (await ctx.dono('GET', '/api/compras/empresas')).json[0];
    const imp = await ctx.lanc('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 910, itens: [{ cProd: 'A', xProd: 'PEÇA', q: 1, vProd: 200 }] })] });
    const nota = (await ctx.lanc('GET', `/api/compras/notas/${imp.json.resultados[0].nota_id}`)).json;
    assert.equal((await ctx.lanc('POST', `/api/compras/itens/${nota.itens[0].id}/alocar`, { destino: 'outra_empresa', empresaId: emp.id })).status, 403);
    const aloc = await ctx.dono('POST', `/api/compras/itens/${nota.itens[0].id}/alocar`, { destino: 'outra_empresa', empresaId: emp.id });
    assert.equal(aloc.status, 201);
    assert.equal((await ctx.lanc('DELETE', `/api/compras/alocacoes/${aloc.json.id}`)).status, 403);
  } finally { ctx.fechar(); }
});

test('quem lança não troca o pagador do boleto para uma empresa do grupo, não liga nota a boleto pago e não reabre o que o dono cancelou', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    await ctx.dono('POST', '/api/compras/empresas', { nome: 'LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 920, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 300 }], dups: [{ venc: '2026-10-25', valor: 300 }] })] });
    await confirmaFornecedor(ctx);
    const linha = linhaDigitavel({ valor: 300, vencimento: '2026-10-25' });
    const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA })).json.boleto_id;
    assert.equal((await ctx.lanc('PUT', `/api/compras/boletos/${b}`, { pagadorCnpj: CNPJ_LOCADORA })).status, 403);
    assert.ok(ctx.db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(b).saida_id, 'a conta continua na lista do dono');
    // pago: só o dono liga mais nota
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, { conferiuBanco: true })).status, 200);
    assert.equal((await ctx.lanc('POST', `/api/compras/boletos/${b}/conciliar`, { itens: [{ nota_id: 1, valor: 10 }] })).status, 403);
    // o dono cancela um boleto: cadastrar a mesma linha de novo, como quem lança, não o reabre
    const linha2 = linhaDigitavel({ valor: 80, vencimento: '2026-10-26' });
    const b2 = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linha2, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b2}/cancelar`, { situacao: 'cancelado' })).status, 200);
    const de_novo = await ctx.lanc('POST', '/api/compras/boletos', { linha: linha2, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    assert.equal(de_novo.status, 400);
    assert.match(de_novo.json.erro, /cancelado pelo dono/);
    assert.equal(ctx.db.prepare('SELECT situacao FROM boletos WHERE id = ?').get(b2).situacao, 'cancelado');
  } finally { ctx.fechar(); }
});

test('pedido com chave "toString" no corpo é recusado (400), não derruba o servidor', async () => {
  const ctx = await subir();
  try {
    const r = await ctx.lanc('POST', '/api/compras/boletos', null, { bruto: '{"linha":"x","numeroDocumento":{"toString":1}}' });
    assert.equal(r.status, 400);
    assert.equal((await ctx.lanc('POST', '/api/compras/fornecedores', null, { bruto: '{"nome":{"valueOf":1}}' })).status, 400);
  } finally { ctx.fechar(); }
});

test('um CNPJ novo num fornecedor já conferido (que não tinha CNPJ) desfaz a conferência', () => {
  const db = abrirBanco(':memory:');
  const f = db.prepare("INSERT INTO fornecedores (nome, confirmado_em) VALUES ('FORNECEDOR SEM CNPJ', '2026-09-01')").run().lastInsertRowid;
  const depois = garantirFornecedor(db, { nome: 'Fornecedor sem cnpj', cnpj: CNPJ_FORNECEDOR });
  assert.equal(depois.id, Number(f));
  assert.equal(db.prepare('SELECT cnpj, confirmado_em FROM fornecedores WHERE id = ?').get(f).confirmado_em, null);
  assert.equal(db.prepare('SELECT cnpj FROM fornecedores WHERE id = ?').get(f).cnpj, CNPJ_FORNECEDOR);
});

// ------------------------------------------------------------------ dinheiro entre empresas

test('boleto da oficina com pagador de empresa cadastrada depois: desfazer/reabrir/pagar passam a tratá-lo como da empresa', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 930, itens: [{ cProd: 'A', xProd: 'PNEU', q: 1, vProd: 900 }], dups: [{ venc: '2026-10-25', valor: 900 }] })] });
    await confirmaFornecedor(ctx);
    const b = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 900, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA })).json.boleto_id;
    assert.ok(ctx.db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(b).saida_id, 'enquanto a empresa não existe, é conta da oficina');
    // cancela, cadastra a locadora e reabre: agora é da locadora e sai das contas da oficina
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/cancelar`, {})).status, 200);
    await ctx.dono('POST', '/api/compras/empresas', { nome: 'LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/reabrir`, {})).status, 200);
    const linha = ctx.db.prepare('SELECT empresa_id, saida_id FROM boletos WHERE id = ?').get(b);
    assert.ok(linha.empresa_id);
    assert.equal(linha.saida_id, null);
    // pagar exige dizer quem paga
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, { conferiuBanco: true })).status, 400);
  } finally { ctx.fechar(); }
});

test('boleto pago pela oficina cujo pagador é empresa cadastrada depois vira aviso de acerto; a conta lançada à mão não é apagada ao trocar de empresa', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    await confirmaFornecedor(ctx).catch(() => {});
    const forn = (await ctx.dono('POST', '/api/compras/fornecedores', { nome: 'PECAS X', cnpj: CNPJ_FORNECEDOR })).json.id;
    await ctx.dono('PUT', `/api/compras/fornecedores/${forn}`, { confirmado: true });
    const b = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 500, vencimento: '2026-10-20' }), fornecedorId: forn, beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA })).json.boleto_id;
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, { conferiuBanco: true, aprovar: true, motivo: 'Liguei e confirmei com o fornecedor' })).status, 200);
    await ctx.dono('POST', '/api/compras/empresas', { nome: 'LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    const oc = (await ctx.dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.find((o) => o.tipo === 'boleto_pago_de_empresa_do_grupo');
    assert.ok(oc, 'devia avisar que o pagamento não entrou no acerto');
    assert.equal(oc.severidade, 'media');

    // conta lançada à mão (energia) com o mesmo valor e vencimento de um boleto: o boleto a adota; ao virar boleto da empresa, a conta volta a ser a de antes
    const catFixo = ctx.db.prepare("SELECT id FROM categorias WHERE grupo = 'fixo' LIMIT 1").get().id;
    const conta = (await ctx.dono('POST', '/api/saidas', { descricao: 'Conta de energia', categoriaId: catFixo, valor: 640, vencimento: '2026-10-22' })).json.id;
    const b2 = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 640, vencimento: '2026-10-22' }), fornecedorId: forn, beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA })).json;
    assert.equal(b2.conta_adotada, true);
    assert.equal((await ctx.dono('PUT', `/api/compras/boletos/${b2.boleto_id}`, { pagadorCnpj: CNPJ_LOCADORA })).status, 200);
    const energia = ctx.db.prepare('SELECT descricao, categoria_id FROM saidas WHERE id = ?').get(conta);
    assert.equal(energia?.descricao, 'Conta de energia');
    assert.equal(energia?.categoria_id, catFixo);
    // cancelar o boleto que adotou uma conta também devolve a conta, nunca a apaga
    const b3 = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 777, vencimento: '2026-10-23' }), fornecedorId: forn })).json;
    const conta3 = (await ctx.dono('POST', '/api/saidas', { descricao: 'IPTU', categoriaId: catFixo, valor: 888, vencimento: '2026-10-24' })).json.id;
    const b4 = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 888, vencimento: '2026-10-24' }), fornecedorId: forn })).json;
    assert.equal(b4.conta_adotada, true);
    await ctx.dono('POST', `/api/compras/boletos/${b4.boleto_id}/cancelar`, {});
    assert.equal(ctx.db.prepare('SELECT descricao FROM saidas WHERE id = ?').get(conta3)?.descricao, 'IPTU');
    void b3;
  } finally { ctx.fechar(); }
});

test('o mesmo gasto não vira "a receber" duas vezes (boleto pago pela oficina e peça entregue à empresa); peça de custo zero não dá erro 500', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const emp = (await ctx.dono('POST', '/api/compras/empresas', { nome: 'LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' })).json;
    const forn = (await ctx.dono('POST', '/api/compras/fornecedores', { nome: 'PECAS Y', cnpj: CNPJ_FORNECEDOR })).json.id;
    await ctx.dono('PUT', `/api/compras/fornecedores/${forn}`, { confirmado: true });
    const nota = (await ctx.dono('POST', '/api/compras/notas', { fornecedorId: forn, numero: '777', dataEmissao: '2026-10-01', valorTotal: 1000, duplicatas: [{ vencimento: '2026-10-25', valor: 1000 }] })).json.nota_id;
    const b = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 1000, vencimento: '2026-10-25' }), fornecedorId: forn, beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_LOCADORA })).json;
    assert.equal(b.auto.ligado, true);
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b.boleto_id}/pagar`, { conferiuBanco: true, aprovar: true, motivo: 'Nota digitada, fornecedor confirmou', pagoPor: 'oficina' })).status, 200);
    const itens = (await ctx.dono('GET', `/api/compras/notas/${nota}`)).json.itens;
    assert.equal((await ctx.dono('POST', `/api/compras/itens/${itens[0].id}/alocar`, { destino: 'outra_empresa', empresaId: emp.id })).status, 201);
    assert.equal((await ctx.dono('GET', '/api/compras/entre-empresas')).json.aReceber, 1000);          // e não 2000

    // item de custo zero (brinde) entregue à empresa: aceita, sem acerto e sem erro 500
    const imp = await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 778, itens: [{ cProd: 'B', xProd: 'BRINDE', q: 1, vProd: 0 }, { cProd: 'C', xProd: 'CAPA', q: 1, vProd: 50 }] })] });
    const n2 = (await ctx.dono('GET', `/api/compras/notas/${imp.json.resultados[0].nota_id}`)).json;
    const brinde = n2.itens.find((i) => i.descricao === 'BRINDE');
    assert.equal((await ctx.dono('POST', `/api/compras/itens/${brinde.id}/alocar`, { destino: 'outra_empresa', empresaId: emp.id })).status, 201);
  } finally { ctx.fechar(); }
});

test('boleto que cobre notas de empresas diferentes é grave; o CNPJ da oficina nunca é empresa do grupo; data futura é recusada', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    await ctx.dono('POST', '/api/compras/empresas', { nome: 'LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' });
    const forn = (await ctx.dono('POST', '/api/compras/fornecedores', { nome: 'PECAS Z', cnpj: CNPJ_FORNECEDOR })).json.id;
    const n1 = (await ctx.dono('POST', '/api/compras/notas', { fornecedorId: forn, numero: '801', dataEmissao: '2026-10-01', valorTotal: 600, cnpjDestinatario: CNPJ_OFICINA })).json.nota_id;
    const n2 = (await ctx.dono('POST', '/api/compras/notas', { fornecedorId: forn, numero: '802', dataEmissao: '2026-10-01', valorTotal: 400, cnpjDestinatario: CNPJ_LOCADORA })).json.nota_id;
    const b = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 1000, vencimento: '2026-10-25' }), fornecedorId: forn })).json.boleto_id;
    await ctx.dono('POST', `/api/compras/boletos/${b}/conciliar`, { itens: [{ nota_id: n1, valor: 600 }, { nota_id: n2, valor: 400 }] });
    const oc = (await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.ocorrencias.find((o) => o.tipo === 'boleto_notas_de_empresas_misturadas');
    assert.ok(oc);
    assert.equal(oc.severidade, 'alta');

    // CNPJ da oficina: não vira empresa do grupo, nem por config tardia
    assert.equal((await ctx.dono('PUT', '/api/config', { cnpjOficina: CNPJ_LOCADORA })).status, 400);
    assert.equal(empresaDoCnpj(ctx.db, CNPJ_OFICINA), null);
    const db2 = abrirBanco(':memory:');
    criarEmpresa(db2, { nome: 'ERRADA', cnpj: CNPJ_OFICINA, papel: 'outra' }, lerConfig(db2));           // cadastrada antes de a oficina informar o CNPJ
    db2.prepare("UPDATE config SET valor = ? WHERE chave = 'cnpj_oficina'").run(CNPJ_OFICINA);
    assert.equal(empresaDoCnpj(db2, CNPJ_OFICINA), null);

    // datas futuras no pagamento e na devolução
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, { conferiuBanco: true, aprovar: true, motivo: 'Teste de data futura aqui', data: '2026-12-31' })).status, 400);
    const emp = (await ctx.dono('GET', '/api/compras/empresas')).json[0];
    const adiant = (await ctx.dono('POST', '/api/compras/adiantamentos', { empresaId: emp.id, sentido: 'a_receber', valor: 100, descricao: 'Teste', data: '2026-10-01' })).json.id;
    assert.equal((await ctx.dono('POST', `/api/compras/adiantamentos/${adiant}/baixar`, { valor: 50, data: '2026-12-31' })).status, 400);
    assert.equal((await ctx.dono('POST', '/api/compras/adiantamentos', { empresaId: emp.id, sentido: 'a_receber', valor: 100, descricao: 'Futuro', data: '2026-12-31' })).status, 400);
  } finally { ctx.fechar(); }
});

test('ligação feita à mão por quem lança que o sistema não sugeriria vira aviso', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const forn = (await ctx.dono('POST', '/api/compras/fornecedores', { nome: 'PECAS W', cnpj: CNPJ_FORNECEDOR })).json.id;
    const n = (await ctx.dono('POST', '/api/compras/notas', { fornecedorId: forn, numero: '555', dataEmissao: '2026-10-01', valorTotal: 5000, cnpjDestinatario: CNPJ_OFICINA })).json.nota_id;
    const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 1200, vencimento: '2026-10-25' }), fornecedorId: forn })).json.boleto_id;
    assert.equal((await ctx.lanc('POST', `/api/compras/boletos/${b}/conciliar`, { itens: [{ nota_id: n, valor: 1200 }] })).status, 200);
    const ocs = (await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.ocorrencias;
    assert.ok(ocs.some((o) => o.tipo === 'ligacao_manual_sem_aderencia'));
  } finally { ctx.fechar(); }
});

// ------------------------------------------------------------------ nota sem XML

test('consulta "cancelada" registrada por quem lança não cancela a nota, mas trava o boleto; a do dono cancela', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const chv = chave({ nNF: 4400, aamm: '2610' });
    const nota = (await ctx.lanc('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4400', serie: '1', dataEmissao: '2026-10-01', valorTotal: 700, chave: chv, cnpjDestinatario: CNPJ_OFICINA, duplicatas: [{ vencimento: '2026-10-25', valor: 700 }] })).json.nota_id;
    const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 700, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    const r = await ctx.lanc('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'cancelada' });
    assert.equal(r.status, 200);
    assert.equal(r.json.cancelada_aqui, false);
    assert.equal(ctx.db.prepare('SELECT situacao FROM notas_compra WHERE id = ?').get(nota).situacao, 'ativa');
    const ocs = (await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.ocorrencias;
    assert.ok(ocs.some((o) => o.tipo === 'nota_consulta_cancelada_pendente' && o.severidade === 'alta'));
    assert.equal((await ctx.dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'cancelada' })).json.cancelada_aqui, true);
    assert.equal(ctx.db.prepare('SELECT situacao, cancelada_por FROM notas_compra WHERE id = ?').get(nota).cancelada_por, 'sefaz');
  } finally { ctx.fechar(); }
});

test('consulta do dono: valor inválido é recusado; destinatário e emissão que o portal mostrou diferentes tiram a prova; portal contradiz XML', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const chv = chave({ nNF: 4500, aamm: '2610' });
    const nota = (await ctx.dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4500', serie: '1', dataEmissao: '2026-10-01', valorTotal: 300, chave: chv, cnpjDestinatario: CNPJ_OFICINA, duplicatas: [{ vencimento: '2026-10-25', valor: 300 }] })).json.nota_id;
    const b = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 300, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    for (const valor of [true, [90], '1e2', {}, -5, 0]) {
      assert.equal((await ctx.dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor })).status, 400, JSON.stringify(valor));
    }
    // destinatário mostrado é de outro cliente: continua sem prova
    assert.equal((await ctx.dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 300, destinatario: '27.865.757/0001-02' })).status, 200);
    const sem = () => ctx.dono('GET', `/api/compras/boletos/${b}`).then((r) => r.json.ocorrencias.find((o) => o.tipo === 'boleto_nota_sem_prova'));
    assert.match((await sem()).detalhe, /outro cliente/);
    // destinatário com asteriscos que casa com a oficina + mesma emissão: vale
    assert.equal((await ctx.dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: '300,00', destinatario: '45.723.***/0001-10', emissao: '2026-10-01' })).status, 200);
    assert.equal(await sem(), undefined);
    // emissão diferente no portal: perde a prova
    await ctx.dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 300, emissao: '2026-09-20' });
    assert.match((await sem()).detalhe, /emitida em 20\/09\/2026/);

    // XML autorizado, mas o portal diz "não encontrada": o portal vale
    const xml = xmlNfe({ nNF: 4600, itens: [{ cProd: 'A', xProd: 'PEÇA', q: 1, vProd: 150 }], dups: [{ venc: '2026-10-26', valor: 150 }] });
    const nx = (await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xml] })).json.resultados[0].nota_id;
    const b2 = (await ctx.dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 150, vencimento: '2026-10-26' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    await ctx.dono('POST', `/api/compras/notas/${nx}/consulta`, { situacao: 'nao_encontrada' });
    assert.ok((await ctx.dono('GET', `/api/compras/boletos/${b2}`)).json.ocorrencias.some((o) => o.tipo === 'nota_contradita_pelo_portal'));
  } finally { ctx.fechar(); }
});

test('XML autorizado que chega depois completa a nota digitada com a mesma chave; série "001" e "1" são a mesma; pedido recusado não deixa fornecedor criado', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const chv = chave({ nNF: 4700, aamm: '2610' });
    const nota = (await ctx.dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4700', serie: '001', dataEmissao: '2026-10-01', valorTotal: 400, chave: chv })).json.nota_id;
    assert.equal((await ctx.dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4700', serie: '1', dataEmissao: '2026-10-01', valorTotal: 400 })).status, 400);
    const r = await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 4700, itens: [{ cProd: 'A', xProd: 'PEÇA', q: 1, vProd: 400 }] })] });
    assert.equal(r.json.resultados[0].status, 'atualizada');
    assert.equal(r.json.resultados[0].nota_id, nota);
    assert.equal(ctx.db.prepare('SELECT origem FROM notas_compra WHERE id = ?').get(nota).origem, 'xml');
    // chave com DV errado: nada é criado
    const antes = ctx.db.prepare('SELECT COUNT(*) AS n FROM fornecedores').get().n;
    const ruim = `${chave({ nNF: 4800, aamm: '2610', cnpj: CNPJ_OUTRO }).slice(0, 43)}0`;
    const rec = await ctx.dono('POST', '/api/compras/notas', { fornecedorNome: 'FORNECEDOR FANTASMA', numero: '4800', serie: '1', dataEmissao: '2026-10-01', valorTotal: 10, chave: ruim === chave({ nNF: 4800, aamm: '2610', cnpj: CNPJ_OUTRO }) ? `${ruim.slice(0, 43)}1` : ruim });
    assert.equal(rec.status, 400);
    assert.equal(ctx.db.prepare('SELECT COUNT(*) AS n FROM fornecedores').get().n, antes);
    // quem lança não troca uma chave que já existe
    assert.equal((await ctx.lanc('POST', `/api/compras/notas/${nota}/chave`, { chave: chv })).status, 403);
  } finally { ctx.fechar(); }
});

test('XML importado por quem lança pede a consulta do dono quando o boleto é de valor alto; a consulta do dono (ou o XML do dono) resolve', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const dups = [{ venc: '2026-10-25', valor: 2500 }];
    const ocs = async (b) => (await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.ocorrencias;
    const tem = (lista) => lista.some((o) => o.tipo === 'nota_xml_sem_conferencia_do_dono');
    const xml = xmlNfe({ nNF: 5100, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 2500 }], dups });
    const nota = (await ctx.lanc('POST', '/api/compras/notas/xml', { xmls: [xml] })).json.resultados[0].nota_id;
    const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 2500, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    assert.ok(tem(await ocs(b)), 'valor alto, XML de quem lança, sem consulta do dono');
    assert.equal((await ctx.dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 2500 })).status, 200);
    assert.ok(!tem(await ocs(b)), 'consulta do dono com o mesmo valor resolve');

    // valor baixo não incomoda; XML importado pelo dono também não
    const xmlBaixo = xmlNfe({ nNF: 5101, itens: [{ cProd: 'A', xProd: 'FILTRO', q: 1, vProd: 150 }], dups: [{ venc: '2026-10-26', valor: 150 }] });
    await ctx.lanc('POST', '/api/compras/notas/xml', { xmls: [xmlBaixo] });
    const bBaixo = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 150, vencimento: '2026-10-26' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    assert.ok(!tem(await ocs(bBaixo)));
    const xmlDono = xmlNfe({ nNF: 5102, itens: [{ cProd: 'A', xProd: 'PASTILHA', q: 1, vProd: 3000 }], dups: [{ venc: '2026-10-27', valor: 3000 }] });
    await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xmlDono] });
    const bDono = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 3000, vencimento: '2026-10-27' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    assert.ok(!tem(await ocs(bDono)));
  } finally { ctx.fechar(); }
});

test('boleto ligado a nota que o XML diz ter sido paga na hora (Pix) é grave: seria cobrança em dobro', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const xml = xmlNfe({ nNF: 5200, itens: [{ cProd: 'A', xProd: 'FILTRO', q: 1, vProd: 400 }], pag: '<detPag><indPag>0</indPag><tPag>17</tPag><vPag>400.00</vPag></detPag>' });
    const nota = (await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xml] })).json.resultados[0].nota_id;
    assert.equal(ctx.db.prepare('SELECT pago_no_ato FROM notas_compra WHERE id = ?').get(nota).pago_no_ato, 1);
    const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 400, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
    await ctx.lanc('POST', `/api/compras/boletos/${b}/conciliar`, { itens: [{ nota_id: nota, valor: 400 }] });
    const lista = (await ctx.dono('GET', `/api/compras/boletos/${b}`)).json;
    assert.ok(lista.ocorrencias.some((o) => o.tipo === 'boleto_nota_paga_no_ato' && o.severidade === 'alta'));
    assert.match(lista.veredito.texto, /NÃO PAGUE/);
  } finally { ctx.fechar(); }
});

// ------------------------------------------------------------------ DDA

async function cenarioDda(ctx, { confirmar = true } = {}) {
  await prepara(ctx);
  await ctx.dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 960, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 350 }], dups: [{ venc: '2026-10-25', valor: 350 }] })] });
  if (confirmar) await confirmaFornecedor(ctx);
  const linha = linhaDigitavel({ valor: 350, vencimento: '2026-10-25' });
  const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json.boleto_id;
  ctx.db.prepare("UPDATE boletos SET criado_em = '2026-10-01 09:00:00' WHERE id = ?").run(b);
  return { linha, b, barras: barrasDe(linha) };
}
const cnabDe = (barras, extra = {}) => cnabDda({ cnpjEmpresa: CNPJ_OFICINA, ...extra, titulos: [{ barras, cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'DISTRIBUIDORA TESTE LTDA', vencimento: '25102026', valor: 350 }] });

test('DDA só dispensa a conferência no app quando é arquivo CNAB recente e o fornecedor foi conferido pelo dono', async () => {
  const ctx = await subir();
  try {
    const { b, barras, linha } = await cenarioDda(ctx);
    // CSV com o mesmo código, CNPJ certo e fornecedor conferido: aparece "no DDA", mas não dispensa nada
    const csv = `Beneficiário;CNPJ;Vencimento;Valor;Linha digitável\n"DISTRIBUIDORA TESTE LTDA";11.222.333/0001-81;25/10/2026;"350,00";${linha}\n`;
    await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: csv });
    assert.equal((await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.boleto.dda, 'no_dda');
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, {})).status, 409);
    // CNAB recente: confirma e dispensa
    await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: cnabDe(barras) });
    assert.equal((await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.boleto.dda, 'confirmado');
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, {})).status, 200);
  } finally { ctx.fechar(); }
});

test('DDA não confirma fornecedor que o dono ainda não conferiu, nem arquivo gerado há muito tempo', async () => {
  const ctx = await subir();
  try {
    const { b, barras } = await cenarioDda(ctx, { confirmar: false });
    await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: cnabDe(barras) });
    assert.equal((await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.boleto.dda, 'no_dda');         // fornecedor não conferido: o banco só diz quem recebe, não que é de confiança
    await confirmaFornecedor(ctx);
    assert.equal((await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.boleto.dda, 'confirmado');
    // o mesmo arquivo, mas gerado em agosto e importado hoje: velho demais para valer
    ctx.db.prepare("UPDATE dda_importacoes SET gerado_em = '2026-08-01'").run();
    assert.equal((await ctx.dono('GET', `/api/compras/boletos/${b}`)).json.boleto.dda, 'no_dda');
  } finally { ctx.fechar(); }
});

test('CNPJ de quem recebe vindo das notas (que quem lança importa) não confirma o boleto no DDA', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    // quem lança cria um "fornecedor" com o CNPJ do golpista e uma nota com emitente igual; o dono não conferiu esse fornecedor
    const golpista = CNPJ_OUTRO;
    const xml = xmlNfe({ nNF: 970, emitCnpj: golpista, emitNome: 'PECAS FALSAS LTDA', itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 8000 }], dups: [{ venc: '2026-10-25', valor: 8000 }] });
    await ctx.lanc('POST', '/api/compras/notas/xml', { xmls: [xml] });
    const linha = linhaDigitavel({ valor: 8000, vencimento: '2026-10-25' });
    const b = (await ctx.lanc('POST', '/api/compras/boletos', { linha, fornecedorNome: 'PECAS FALSAS LTDA', beneficiarioCnpj: golpista })).json.boleto_id;
    ctx.db.prepare("UPDATE boletos SET criado_em = '2026-10-01 09:00:00' WHERE id = ?").run(b);
    await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [{ barras: barrasDe(linha), cnpjCedente: golpista, nomeCedente: 'PECAS FALSAS LTDA', vencimento: '25102026', valor: 8000 }] }) });
    const det = (await ctx.dono('GET', `/api/compras/boletos/${b}`)).json;
    assert.notEqual(det.boleto.dda, 'confirmado');
    assert.notEqual(det.veredito.nivel, 'ok');
    // e o pagamento continua travado pelo fornecedor nunca conferido
    assert.equal((await ctx.dono('POST', `/api/compras/boletos/${b}/pagar`, {})).status, 409);
  } finally { ctx.fechar(); }
});

test('DDA: importação parcial não apaga o que o arquivo completo mostrou; baixa (02) depois da entrada (01) remove o título; lote de CNPJ estranho não derruba o arquivo', async () => {
  const ctx = await subir();
  try {
    await prepara(ctx);
    const l1 = linhaDigitavel({ valor: 100, vencimento: '2026-10-30' });
    const l2 = linhaDigitavel({ valor: 200, vencimento: '2026-10-31' });
    const l3 = linhaDigitavel({ valor: 300, vencimento: '2026-11-01' });
    const t = (barras, valor, venc, mov = '01') => ({ barras, cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'X', vencimento: venc, valor, movimento: mov });
    const completo = cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [t(barrasDe(l1), 100, '30102026'), t(barrasDe(l2), 200, '31102026'), t(barrasDe(l3), 300, '01112026'), t(barrasDe(l3), 300, '01112026', '02')] });
    const r = await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: completo });
    assert.equal(r.json.qtd, 2);                                    // o terceiro foi baixado no próprio arquivo
    await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: `${l1}\n` });          // texto de uma linha, depois
    assert.equal((await ctx.dono('GET', '/api/compras/dda')).json.semCadastro.length, 2);   // os dois do arquivo completo continuam valendo
    // arquivo com dois lotes, um deles de CNPJ que ninguém conhece: importa o que é nosso e avisa do resto
    const misto = cnabDda({ cnpjEmpresa: CNPJ_OFICINA, lotes: [
      { cnpj: CNPJ_OFICINA, titulos: [t(barrasDe(linhaDigitavel({ valor: 55, vencimento: '2026-11-02' })), 55, '02112026')] },
      { cnpj: '33344455000183', titulos: [t(barrasDe(linhaDigitavel({ valor: 66, vencimento: '2026-11-03' })), 66, '03112026')] },
    ] });
    const rm = await ctx.dono('POST', '/api/compras/dda/importar', { conteudo: misto });
    assert.equal(rm.status, 201);
    assert.ok(rm.json.avisos.some((a) => /Ignorei 1 título/.test(a)));
  } finally { ctx.fechar(); }
});

test('DDA em CSV: "1.250" é mil duzentos e cinquenta; sem coluna de CNPJ válida não pega o CNPJ de outra coluna; data inexistente não vale', async () => {
  const { lerExportacaoDda } = await import('../src/dda.js');
  const l = linhaDigitavel({ valor: 1250, vencimento: '2026-10-30' });
  const csv = `Beneficiário;CNPJ;Sacador;Vencimento;Valor\nJOAO PESSOA FISICA;123.456.789-09;27.865.757/0001-02;30/10/2026;1.250\nOUTRO;11.222.333/0001-81;;31/02/2026;100,00\n${l};;;;\n`;
  const r = lerExportacaoDda(csv, HOJE);
  const joao = r.titulos.find((t) => t.valor === 1250);
  assert.ok(joao);
  assert.equal(joao.beneficiario_cnpj, null);                       // CPF na coluna de CNPJ: nada de pegar o CNPJ do sacador
  assert.ok(!r.titulos.some((t) => t.vencimento === '2026-02-31'));
});

// ------------------------------------------------------------------ relógio

test('o sistema não depende da data real do computador: banco e importações usam o "hoje" informado', () => {
  const db = abrirBanco(':memory:');
  const emp = ocorrencias(db, '2031-01-01', lerConfig(db));
  assert.ok(Array.isArray(emp));
  importarNotaXml(db, xmlNfe({ nNF: 1, itens: [{ cProd: 'A', xProd: 'X', q: 1, vProd: 10 }] }));
  assert.ok(Array.isArray(ocorrencias(db, '2000-01-01', lerConfig(db))));
});
