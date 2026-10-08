import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco, lerConfig } from '../src/db.js';
import { criarApp } from '../src/app.js';
import { interpretarBoleto } from '../src/boleto.js';
import { lerExportacaoDda, importarDda, cruzarDda } from '../src/dda.js';
import { ocorrencias } from '../src/auditoria.js';
import { xmlNfe, CNPJ_OFICINA, CNPJ_FORNECEDOR, CNPJ_OUTRO } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';
import { cnabDda } from './helpers/cnab.js';

const SENHA_DONO = 'senha-do-dono-123';
const SENHA_LANC = 'senha-lancamento-456';
const HOJE = '2026-10-08';
const CNPJ_LOCADORA = '45997418000153';

const barrasDe = (linha) => interpretarBoleto(linha, HOJE).codigoBarras;
const ddmm = (iso) => iso.split('-').reverse().join('');

test('CNAB 240 do DDA: lê código de barras, quem recebe, valor, vencimento, documento e quem paga; ignora baixa de título', () => {
  const l1 = linhaDigitavel({ valor: 1234.56, vencimento: '2026-10-30' });
  const l2 = linhaDigitavel({ valor: 80, vencimento: '2026-11-05' });
  const l3 = linhaDigitavel({ valor: 99.9, vencimento: '2026-10-20' });
  const arquivo = cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [
    { barras: barrasDe(l1), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'DISTRIBUIDORA TESTE LTDA', vencimento: '30102026', valor: 1234.56, documento: '004321/01' },
    { barras: barrasDe(l2), cnpjCedente: CNPJ_OUTRO, nomeCedente: 'OUTRA PECAS SA', vencimento: '05112026', valor: 80, cnpjSacador: CNPJ_FORNECEDOR, nomeSacador: 'DISTRIBUIDORA TESTE LTDA' },
    { barras: barrasDe(l3), cnpjCedente: CNPJ_OUTRO, nomeCedente: 'BAIXADO', vencimento: '20102026', valor: 99.9, movimento: '02' },
  ] });
  const r = lerExportacaoDda(arquivo, HOJE);
  assert.equal(r.formato, 'cnab240');
  assert.equal(r.cnpjArquivo, CNPJ_OFICINA);
  assert.equal(r.geradoEm, '2026-10-08');
  assert.equal(r.titulos.length, 2);
  const [a, b] = r.titulos;
  assert.equal(a.codigo_barras, barrasDe(l1));
  assert.equal(a.beneficiario_cnpj, CNPJ_FORNECEDOR);
  assert.equal(a.beneficiario_nome, 'DISTRIBUIDORA TESTE LTDA');
  assert.equal(a.valor, 1234.56);
  assert.equal(a.vencimento, '2026-10-30');
  assert.equal(a.numero_documento, '004321/01');
  assert.equal(a.pagador_cnpj, CNPJ_OFICINA);
  assert.equal(a.banco, '341');
  assert.equal(b.sacador_cnpj, CNPJ_FORNECEDOR);
  assert.equal(b.beneficiario_cnpj, CNPJ_OUTRO);
});

test('CNAB 240 com código de barras adulterado ou valor diferente do código: avisa e usa o do código', () => {
  const l = linhaDigitavel({ valor: 500, vencimento: '2026-10-30' });
  const barras = barrasDe(l);
  const ruim = `${barras.slice(0, 20)}${barras[20] === '9' ? '8' : '9'}${barras.slice(21)}`;
  const arquivo = cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [
    { barras: ruim, cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'A', vencimento: '30102026', valor: 500 },
    { barras, cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'B', vencimento: '30102026', valor: 450 },
  ] });
  const r = lerExportacaoDda(arquivo, HOJE);
  assert.ok(r.avisos.some((a) => /não passou na validação/.test(a)));
  assert.ok(r.avisos.some((a) => /difere do valor do código de barras/.test(a)));
  const comCodigo = r.titulos.find((t) => t.codigo_barras === barras);
  assert.equal(comCodigo.valor, 500);
});

test('CSV de banco: acha as colunas pelo nome e a linha digitável em qualquer célula', () => {
  const l1 = linhaDigitavel({ valor: 300, vencimento: '2026-10-25' });
  const csv = `Beneficiário;CNPJ do beneficiário;Data de vencimento;Valor (R$);Linha digitável\n"DISTRIBUIDORA TESTE LTDA";11.222.333/0001-81;25/10/2026;"300,00";${l1}\n"SEM CODIGO LTDA";27.865.757/0001-02;12/11/2026;"1.250,50";\nTotal;;;1.550,50;\n`;
  const r = lerExportacaoDda(csv, HOJE);
  assert.equal(r.formato, 'csv');
  assert.equal(r.titulos.length, 2);
  assert.equal(r.titulos[0].codigo_barras, barrasDe(l1));
  assert.equal(r.titulos[0].beneficiario_cnpj, CNPJ_FORNECEDOR);
  assert.equal(r.titulos[0].beneficiario_nome, 'DISTRIBUIDORA TESTE LTDA');
  assert.equal(r.titulos[1].codigo_barras, null);
  assert.equal(r.titulos[1].valor, 1250.5);
  assert.equal(r.titulos[1].vencimento, '2026-11-12');
  assert.equal(r.titulos[1].beneficiario_cnpj, CNPJ_OUTRO);
});

test('texto colado: acha as linhas digitáveis e o CNPJ perto de cada uma; repetidas valem uma vez', () => {
  const l1 = linhaDigitavel({ valor: 300, vencimento: '2026-10-25' });
  const l2 = linhaDigitavel({ valor: 75.5, vencimento: '2026-11-02' });
  const texto = `Boletos DDA\nDISTRIBUIDORA TESTE LTDA 11.222.333/0001-81\n${l1}\nvence 25/10/2026\n\nOUTRA PECAS SA 27.865.757/0001-02\n${l2}\n${l1}\n`;
  const r = lerExportacaoDda(texto, HOJE);
  assert.equal(r.formato, 'texto');
  assert.equal(r.titulos.length, 2);
  assert.equal(r.titulos[0].beneficiario_cnpj, CNPJ_FORNECEDOR);
  assert.equal(r.titulos[1].beneficiario_cnpj, CNPJ_OUTRO);
  assert.throws(() => lerExportacaoDda('   \n  ', HOJE), /vazio/);
  const db = abrirBanco(':memory:');
  assert.throws(() => importarDda(db, { conteudo: 'nada de boleto aqui' }, HOJE), /Não encontrei boletos/);
});

async function subir() {
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: SENHA_DONO, senhaLancamento: SENHA_LANC, segredo: 'd'.repeat(32), agora: () => new Date(`${HOJE}T15:00:00Z`) });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  const cliente = async (senha) => {
    let cookie = '';
    const chamar = async (metodo, caminho, corpo) => {
      const r = await fetch(base + caminho, { method: metodo, headers: { ...(metodo !== 'GET' ? { 'content-type': 'application/json' } : {}), ...(cookie ? { cookie } : {}) }, body: metodo !== 'GET' ? JSON.stringify(corpo ?? {}) : undefined });
      const set = r.headers.get('set-cookie');
      if (set) cookie = set.split(';')[0];
      let json = null;
      try { json = await r.json(); } catch { /* sem corpo */ }
      return { status: r.status, json };
    };
    await chamar('POST', '/api/login', { senha });
    return chamar;
  };
  return { db, dono: await cliente(SENHA_DONO), lanc: await cliente(SENHA_LANC), fechar: () => server.close() };
}

test('DDA x boletos: confere o que bate, preenche quem recebe, acusa o que o banco mostra e ninguém cadastrou e dispensa a conferência no app do banco', async () => {
  const { db, dono, lanc, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 701, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 300 }], dups: [{ venc: '2026-10-25', valor: 300 }] })] });
    await dono('PUT', `/api/compras/fornecedores/${(await dono('GET', '/api/compras/fornecedores')).json[0].id}`, { confirmado: true });
    const lA = linhaDigitavel({ valor: 300, vencimento: '2026-10-25' });          // cadastrado por quem lança, SEM informar quem recebe
    const lB = linhaDigitavel({ valor: 410, vencimento: '2026-10-28' });          // cadastrado com um CNPJ de recebedor diferente do banco
    const lC = linhaDigitavel({ valor: 88, vencimento: '2026-10-29' });           // cadastrado, mas o banco não mostra
    const lD = linhaDigitavel({ valor: 999, vencimento: '2026-10-27' });          // o banco mostra, ninguém cadastrou
    const a = (await lanc('POST', '/api/compras/boletos', { linha: lA, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json;
    const b = (await lanc('POST', '/api/compras/boletos', { linha: lB, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_OUTRO })).json;
    const c = (await lanc('POST', '/api/compras/boletos', { linha: lC, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' })).json;
    assert.equal(a.auto.ligado, true);
    db.prepare("UPDATE boletos SET criado_em = '2026-10-01 09:00:00' WHERE id IN (?, ?, ?)").run(a.boleto_id, b.boleto_id, c.boleto_id);     // cadastrados antes do dia do arquivo
    // antes do DDA: boleto A está "não conferido" (falta quem recebe) e o pagamento exige conferir no app do banco
    assert.equal((await dono('GET', `/api/compras/boletos/${a.boleto_id}`)).json.veredito.nivel, 'atencao');
    assert.equal((await dono('POST', `/api/compras/boletos/${a.boleto_id}/pagar`, {})).status, 409);

    // quem só lança não importa nem vê o DDA
    const arquivo = cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [
      { barras: barrasDe(lA), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'DISTRIBUIDORA TESTE LTDA', vencimento: '25102026', valor: 300, documento: '000701' },
      { barras: barrasDe(lB), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'DISTRIBUIDORA TESTE LTDA', vencimento: '28102026', valor: 410 },
      { barras: barrasDe(lD), cnpjCedente: CNPJ_OUTRO, nomeCedente: 'PECAS DO FULANO ME', vencimento: '27102026', valor: 999 },
    ] });
    assert.equal((await lanc('POST', '/api/compras/dda/importar', { conteudo: arquivo })).status, 403);
    assert.equal((await lanc('GET', '/api/compras/dda')).status, 403);
    const imp = await dono('POST', '/api/compras/dda/importar', { conteudo: arquivo, arquivo: 'dda.rem' });
    assert.equal(imp.status, 201);
    assert.deepEqual([imp.json.qtd, imp.json.novos, imp.json.ok, imp.json.divergentes, imp.json.semCadastro, imp.json.foraDoDda], [3, 3, 1, 1, 1, 1]);

    // A: o banco preencheu quem recebe e quem paga, e o boleto ficou "No DDA"
    const dA = (await dono('GET', `/api/compras/boletos/${a.boleto_id}`)).json;
    assert.equal(dA.boleto.beneficiario_cnpj, CNPJ_FORNECEDOR);
    assert.equal(dA.boleto.pagador_cnpj, CNPJ_OFICINA);
    assert.equal(dA.boleto.dda, 'confirmado');
    assert.equal(dA.veredito.nivel, 'ok');
    assert.match(dA.veredito.texto, /confirmado no DDA/);
    assert.ok(db.prepare("SELECT 1 FROM auditoria_log WHERE acao = 'dda_preencheu' AND entidade_id = ?").get(a.boleto_id));
    // ... e o dono paga sem precisar marcar "conferi no app do banco" (o DDA é o app do banco)
    assert.equal((await dono('POST', `/api/compras/boletos/${a.boleto_id}/pagar`, {})).status, 200);

    // B: o que foi digitado difere do banco (quem recebe) => grave e pagamento travado
    const dB = (await dono('GET', `/api/compras/boletos/${b.boleto_id}`)).json;
    assert.ok(dB.ocorrencias.some((o) => o.tipo === 'dda_diverge' && /quem recebe/.test(o.detalhe)));
    assert.equal(dB.veredito.nivel, 'ruim');
    // C: cadastrado e fora do DDA => para conferir
    const dC = (await dono('GET', `/api/compras/boletos/${c.boleto_id}`)).json;
    assert.ok(dC.ocorrencias.some((o) => o.tipo === 'boleto_fora_do_dda' && o.severidade === 'media'));
    // D: sem cadastro => grave, aparece na lista do DDA com o beneficiário
    const lista = (await dono('GET', '/api/compras/dda')).json;
    assert.equal(lista.semCadastro.length, 1);
    assert.equal(lista.semCadastro[0].beneficiario_nome, 'PECAS DO FULANO ME');
    assert.equal(lista.semCadastro[0].empresa, 'Oficina');
    const oc = (await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.find((o) => o.tipo === 'dda_sem_cadastro');
    assert.equal(oc.severidade, 'alta');
    assert.equal(oc.valor, 999);

    // importar de novo o mesmo arquivo não duplica nada
    const de_novo = await dono('POST', '/api/compras/dda/importar', { conteudo: arquivo });
    assert.equal(de_novo.json.novos, 0);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM dda_titulos').get().n, 3);
    // cadastrar o que faltava resolve o alerta
    const d = await dono('POST', '/api/compras/boletos', { linha: lD, fornecedorNome: 'PECAS DO FULANO' });
    assert.equal(d.status, 201);
    assert.ok(!(await dono('GET', '/api/compras/ocorrencias')).json.ocorrencias.some((o) => o.tipo === 'dda_sem_cadastro'));
  } finally { fechar(); }
});

test('DDA: arquivo de outro CNPJ é recusado; DDA da locadora fica separado do da oficina', async () => {
  const { dono, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    const l = linhaDigitavel({ valor: 120, vencimento: '2026-10-30' });
    const doutro = cnabDda({ cnpjEmpresa: CNPJ_OUTRO, titulos: [{ barras: barrasDe(l), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'X', vencimento: '30102026', valor: 120 }] });
    const recusado = await dono('POST', '/api/compras/dda/importar', { conteudo: doutro });
    assert.equal(recusado.status, 400);
    assert.match(recusado.json.erro, /não é o da oficina.*nem de uma empresa cadastrada/);
    const emp = (await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: CNPJ_LOCADORA, papel: 'locadora' })).json;
    const daLocadora = cnabDda({ cnpjEmpresa: CNPJ_LOCADORA, titulos: [{ barras: barrasDe(l), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'DISTRIBUIDORA TESTE LTDA', vencimento: '30102026', valor: 120 }] });
    assert.equal((await dono('POST', '/api/compras/dda/importar', { conteudo: daLocadora, empresaId: null })).status, 201);     // o cabeçalho manda: é da locadora
    assert.equal((await dono('POST', '/api/compras/dda/importar', { conteudo: daLocadora, empresaId: 999 })).status, 400);       // empresa que não existe
    const lista = (await dono('GET', '/api/compras/dda')).json;
    assert.equal(lista.importacoes.length, 1);
    assert.equal(lista.importacoes[0].empresa, 'IZICAR LOCADORA');
    assert.equal(lista.semCadastro[0].empresa, 'IZICAR LOCADORA');
    // o boleto da locadora, cadastrado depois, casa com o título dela
    const b = await dono('POST', '/api/compras/boletos', { linha: l, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    assert.equal(b.status, 201);
    const det = (await dono('GET', `/api/compras/boletos/${b.json.boleto_id}`)).json;
    assert.ok(det.ocorrencias.some((o) => o.tipo === 'dda_diverge' && /outro CNPJ/.test(o.detalhe)));     // cadastrado como da oficina, o banco mostra no DDA da locadora
    void emp;
  } finally { fechar(); }
});

test('DDA antigo vira lembrete; boleto cadastrado no mesmo dia do arquivo não é cobrado; boleto de convênio fica de fora', () => {
  const db = abrirBanco(':memory:');
  const cfg = lerConfig(db);
  db.prepare("UPDATE config SET valor = ? WHERE chave = 'cnpj_oficina'").run(CNPJ_OFICINA);
  const l = linhaDigitavel({ valor: 50, vencimento: '2026-10-30' });
  importarDda(db, { conteudo: cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [{ barras: barrasDe(l), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'X', vencimento: '30102026', valor: 50 }] }) }, HOJE, lerConfig(db));
  const f = db.prepare("INSERT INTO fornecedores (nome, cnpj) VALUES ('F', ?)").run(CNPJ_FORNECEDOR).lastInsertRowid;
  // boleto cadastrado hoje (mesmo dia do arquivo) e fora do DDA: não cobra
  db.prepare("INSERT INTO boletos (fornecedor_id, codigo_barras, valor, vencimento) VALUES (?, ?, 77, '2026-10-30')").run(f, barrasDe(linhaDigitavel({ valor: 77, vencimento: '2026-10-30' })));
  // boleto de convênio (começa com 8): não passa pelo DDA
  db.prepare("INSERT INTO boletos (fornecedor_id, codigo_barras, valor, vencimento, criado_em) VALUES (?, '84670000001435900240200240359402975401440015', 143.59, '2026-10-30', '2026-10-01 10:00:00')").run(f);
  assert.equal(cruzarDda(db, HOJE, cfg).fora_do_dda.length, 0);
  // dez dias depois: o DDA tem 10 dias, vira lembrete (baixa); a 20 dias, média; e não confere mais "fora do DDA" (DDA velho demais)
  const em10 = ocorrencias(db, '2026-10-18', cfg).filter((o) => o.tipo === 'dda_desatualizado');
  assert.equal(em10[0].severidade, 'baixa');
  assert.equal(ocorrencias(db, '2026-10-30', cfg).find((o) => o.tipo === 'dda_desatualizado').severidade, 'media');
  assert.equal(cruzarDda(db, '2026-10-30', cfg).fora_do_dda.length, 0);
  // sem nenhum DDA importado, nenhuma ocorrência de DDA
  const limpo = abrirBanco(':memory:');
  assert.ok(!ocorrencias(limpo, HOJE, lerConfig(limpo)).some((o) => o.tipo.startsWith('dda_') || o.tipo === 'boleto_fora_do_dda'));
});

test('CNAB 240 com um lote por CNPJ (matriz e filiais ou outra empresa): cada título vai para o dono do lote', () => {
  const db = abrirBanco(':memory:');
  db.prepare("UPDATE config SET valor = ? WHERE chave = 'cnpj_oficina'").run(CNPJ_OFICINA);
  const emp = db.prepare("INSERT INTO empresas_grupo (nome, cnpj, papel) VALUES ('LOCADORA', ?, 'locadora')").run(CNPJ_LOCADORA).lastInsertRowid;
  const l1 = linhaDigitavel({ valor: 100, vencimento: '2026-10-30' });
  const l2 = linhaDigitavel({ valor: 200, vencimento: '2026-10-31' });
  const arquivo = cnabDda({ cnpjEmpresa: CNPJ_OFICINA, lotes: [
    { cnpj: CNPJ_OFICINA, titulos: [{ barras: barrasDe(l1), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'A', vencimento: '30102026', valor: 100 }] },
    { cnpj: CNPJ_LOCADORA, titulos: [{ barras: barrasDe(l2), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'B', vencimento: '31102026', valor: 200 }] },
  ] });
  const lido = lerExportacaoDda(arquivo, HOJE);
  assert.deepEqual(lido.titulos.map((t) => t.lote_cnpj), [CNPJ_OFICINA, CNPJ_LOCADORA]);
  const r = importarDda(db, { conteudo: arquivo, empresaId: emp }, HOJE, lerConfig(db));        // a escolha da pessoa não vale quando o arquivo tem mais de um CNPJ
  assert.equal(r.importacoes.length, 2);
  assert.deepEqual(r.importacoes.map((i) => i.empresa_id).sort(), [null, Number(emp)].sort());
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM dda_titulos WHERE escopo = 0').get().n, 1);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM dda_titulos WHERE escopo = ?').get(emp).n, 1);
});

test('vencimento "à vista" (11111111) ou "contra apresentação" (99999999) no CNAB não vira data inventada', () => {
  const l = linhaDigitavel({ valor: 100, vencimento: '2026-10-30' });
  const r = lerExportacaoDda(cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [
    { barras: barrasDe(l), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'A', vencimento: '11111111', valor: 100 },
    { barras: barrasDe(linhaDigitavel({ valor: 50, vencimento: '2026-11-10' })), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'B', vencimento: '99999999', valor: 50 },
  ] }), HOJE);
  assert.deepEqual(r.titulos.map((t) => t.vencimento), ['2026-10-30', '2026-11-10']);        // vale o vencimento do código de barras
});

test('mexeram no valor do código de barras mantendo o campo livre: o título é o mesmo e a diferença aparece como divergência grave', async () => {
  const { dono, lanc, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    const verdadeiro = linhaDigitavel({ valor: 300, vencimento: '2026-10-25', livre: 1234567 });
    const adulterado = linhaDigitavel({ valor: 3000, vencimento: '2026-10-25', livre: 1234567 });          // mesmo campo livre, valor 10x
    const b = (await lanc('POST', '/api/compras/boletos', { linha: adulterado, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA })).json;
    await dono('POST', '/api/compras/dda/importar', { conteudo: cnabDda({ cnpjEmpresa: CNPJ_OFICINA, titulos: [{ barras: barrasDe(verdadeiro), cnpjCedente: CNPJ_FORNECEDOR, nomeCedente: 'DISTRIBUIDORA TESTE LTDA', vencimento: '25102026', valor: 300 }] }) });
    const det = (await dono('GET', `/api/compras/boletos/${b.boleto_id}`)).json;
    const oc = det.ocorrencias.find((o) => o.tipo === 'dda_diverge');
    assert.ok(oc, 'devia acusar divergência');
    assert.match(oc.detalhe, /valor: cadastrado R\$\s3\.000,00, banco R\$\s300,00/);
    assert.equal(det.veredito.nivel, 'ruim');
    assert.notEqual(det.boleto.dda, 'confirmado');
    const lista = (await dono('GET', '/api/compras/dda')).json;
    assert.equal(lista.semCadastro.length, 0);                       // não é "sem cadastro": é o mesmo título, adulterado
    assert.equal(lista.divergentes.length, 1);
  } finally { fechar(); }
});

test('DDA sem código de barras (só valor, vencimento e CNPJ): casa por esses três e não dispensa a conferência no app do banco', async () => {
  const { dono, lanc, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    await dono('POST', '/api/compras/notas/xml', { xmls: [xmlNfe({ nNF: 810, itens: [{ cProd: 'A', xProd: 'DISCO', q: 1, vProd: 450 }], dups: [{ venc: '2026-10-26', valor: 450 }] })] });
    await dono('PUT', `/api/compras/fornecedores/${(await dono('GET', '/api/compras/fornecedores')).json[0].id}`, { confirmado: true });
    const b = (await lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 450, vencimento: '2026-10-26' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA })).json;
    const csv = 'Beneficiário;CNPJ;Vencimento;Valor\n"DISTRIBUIDORA TESTE LTDA";11.222.333/0001-81;26/10/2026;"450,00"\n';
    const r = await dono('POST', '/api/compras/dda/importar', { conteudo: csv, arquivo: 'dda.csv' });
    assert.equal(r.status, 201);
    assert.equal(r.json.ok, 1);
    assert.equal(r.json.semCadastro, 0);
    const det = (await dono('GET', `/api/compras/boletos/${b.boleto_id}`)).json;
    assert.equal(det.boleto.dda, 'no_dda');                        // aparece no DDA, mas sem o código de barras não há como provar que é o mesmo papel
    assert.equal((await dono('POST', `/api/compras/boletos/${b.boleto_id}/pagar`, {})).status, 409);        // ainda pede para conferir no app do banco
  } finally { fechar(); }
});
