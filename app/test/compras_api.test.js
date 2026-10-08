import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco } from '../src/db.js';
import { criarApp } from '../src/app.js';
import { xmlNfe, CNPJ_OFICINA, CNPJ_OUTRO } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';

async function subir() {
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: 'senha-de-teste-123', segredo: 'y'.repeat(32), agora: () => new Date('2026-10-08T15:00:00Z') });
  const server = await new Promise((ok) => { const s = app.listen(0, '127.0.0.1', () => ok(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  let cookie = '';
  const chamar = async (metodo, caminho, corpo) => {
    const r = await fetch(base + caminho, { method: metodo, headers: { ...(metodo !== 'GET' ? { 'content-type': 'application/json' } : {}), ...(cookie ? { cookie } : {}) }, body: metodo !== 'GET' ? JSON.stringify(corpo ?? {}) : undefined });
    const set = r.headers.get('set-cookie');
    if (set) cookie = set.split(';')[0];
    let json = null;
    try { json = await r.json(); } catch { /* sem corpo */ }
    return { status: r.status, json };
  };
  return { db, chamar, fechar: () => server.close(), logar: () => chamar('POST', '/api/login', { senha: 'senha-de-teste-123' }) };
}

test('rotas de compras exigem login', async () => {
  const { chamar, fechar } = await subir();
  try {
    assert.equal((await chamar('GET', '/api/compras/resumo')).status, 401);
    assert.equal((await chamar('POST', '/api/compras/boletos', { valor: 10 })).status, 401);
  } finally { fechar(); }
});

test('fluxo completo: XML, boleto ligado, boleto suspeito travado em Contas, aceite com motivo e custo da OS pelas notas', async () => {
  const { db, chamar, fechar, logar } = await subir();
  try {
    await logar();
    assert.equal((await chamar('PUT', '/api/config', { cnpjOficina: '11222333000263' })).status, 400);
    assert.equal((await chamar('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA })).status, 200);

    // importação: uma boa, uma repetida, uma adulterada
    const boa = xmlNfe({ nNF: 4321, itens: [{ cProd: 'PAST', xProd: 'PASTILHA', q: 2, vProd: 200, xPed: '1200' }], dups: [{ venc: '2026-10-30', valor: 200 }], infCpl: 'OS 1200' });
    const ruim = boa.replace('<nNF>4321</nNF>', '<nNF>4999</nNF>');
    const imp = await chamar('POST', '/api/compras/notas/xml', { xmls: [boa, boa, ruim], nomes: ['a.xml', 'b.xml', 'c.xml'] });
    assert.equal(imp.status, 201);
    assert.deepEqual(imp.json.resultados.map((r) => r.status), ['importada', 'ja_existia', 'erro']);
    assert.match(imp.json.resultados[2].erro, /não batem com a chave/);
    const nota = imp.json.resultados[0].nota_id;

    // boleto que bate com a parcela: ligado sozinho, sem ocorrência
    const ok = await chamar('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 200, vencimento: '2026-10-30' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: '11.222.333/0001-81', pagadorCnpj: CNPJ_OFICINA });
    assert.equal(ok.status, 201);
    assert.equal(ok.json.auto.ligado, true);
    assert.deepEqual(ok.json.ocorrencias, []);

    // boleto sem nota: duplicidade de linha recusada, ocorrência grave e pagamento pela tela de Contas travado
    const linhaSuspeita = linhaDigitavel({ valor: 515.9, vencimento: '2026-10-12' });
    const suspeito = await chamar('POST', '/api/compras/boletos', { linha: linhaSuspeita, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    assert.equal(suspeito.status, 201);
    assert.equal(suspeito.json.auto.ligado, false);
    const repetido = await chamar('POST', '/api/compras/boletos', { linha: linhaSuspeita, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    assert.equal(repetido.status, 400);
    assert.equal(repetido.json.boleto_id, suspeito.json.boleto_id);
    const saidaId = db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(suspeito.json.boleto_id).saida_id;
    const travado = await chamar('POST', `/api/saidas/${saidaId}/pagar`, {});
    assert.equal(travado.status, 409);
    assert.equal(travado.json.ocorrencias[0].tipo, 'boleto_sem_nota');
    assert.equal((await chamar('DELETE', `/api/saidas/${saidaId}`)).status, 400);           // conta de boleto só sai cancelando o boleto
    const liberado = await chamar('POST', `/api/saidas/${saidaId}/pagar`, { aprovar: true, motivo: 'Fornecedor confirmou por telefone' });
    assert.equal(liberado.status, 200);
    assert.equal(liberado.json.liberado_com_ressalva, true);

    // resumo e lista de ocorrências; aceitar com motivo curto é recusado
    const oc = await chamar('GET', '/api/compras/ocorrencias');
    const semNota = oc.json.ocorrencias.find((o) => o.tipo === 'boleto_sem_nota');
    assert.ok(semNota);
    assert.equal((await chamar('POST', '/api/compras/ocorrencias/aceitar', { chave: semNota.chave, motivo: 'ok' })).status, 400);
    assert.equal((await chamar('POST', '/api/compras/ocorrencias/aceitar', { chave: 'drop table', motivo: 'xxxxxxxx' })).status, 400);
    assert.equal((await chamar('POST', '/api/compras/ocorrencias/aceitar', { chave: semNota.chave, motivo: 'Nota chegou por e-mail, lançar amanhã' })).status, 200);
    assert.equal((await chamar('GET', '/api/compras/ocorrencias')).json.aceitas.length, 1);

    // OS e custo pelas notas
    const venda = await chamar('POST', '/api/vendas', { numero: '1200', data: '2026-10-05', placa: 'abc1d23', valorTotal: 900, valorMaoObra: 200 });
    const det0 = await chamar('GET', `/api/compras/notas/${nota}`);
    assert.equal(det0.json.sugestoes_os.length, 1);                                         // xPed 1200 = OS 1200
    assert.equal(det0.json.sugestoes_os[0].venda_id, venda.json.id);
    const aplicar = await chamar('POST', `/api/compras/notas/${nota}/aplicar-sugestoes`, { itens: [{ item_id: det0.json.itens[0].id, venda_id: venda.json.id }] });
    assert.equal(aplicar.json.aplicadas, 1);
    const os = await chamar('GET', `/api/vendas/${venda.json.id}`);
    assert.equal(os.json.custo_pecas, 200);
    assert.equal(os.json.custo_notas, 200);
    assert.equal(os.json.pecas_notas[0].nota, '4321');
    assert.equal((await chamar('GET', '/api/compras/os-busca?q=1200')).json[0].id, venda.json.id);
    assert.equal((await chamar('GET', '/api/compras/itens-livres')).json.length, 0);

    // fornecedor foi criado pelo XML, com CNPJ, e aparece com totais
    const forn = (await chamar('GET', '/api/compras/fornecedores')).json;
    assert.equal(forn.length, 1);
    assert.equal(forn[0].cnpj, '11222333000181');
    assert.equal(forn[0].comprado, 200);
    const extrato = await chamar('GET', `/api/compras/fornecedores/${forn[0].id}/extrato`);
    assert.equal(extrato.json.totais.boletosSemNota, 515.9);
    // painel traz o resumo de compras
    assert.equal((await chamar('GET', '/api/painel')).json.compras.alta >= 0, true);
  } finally { fechar(); }
});

test('boleto com valor de outra pessoa na linha, nota de outro destinatário e beneficiário trocado aparecem como graves pela API', async () => {
  const { chamar, fechar, logar } = await subir();
  try {
    await logar();
    await chamar('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    await chamar('POST', '/api/compras/notas/xml', { xml: xmlNfe({ nNF: 77, destCnpj: CNPJ_OUTRO, dups: [{ venc: '2026-10-20', valor: 100 }] }) });
    const b = await chamar('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 100, vencimento: '2026-10-20' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_OUTRO });
    const tipos = b.json.ocorrencias.map((o) => o.tipo).sort();
    assert.deepEqual(tipos, ['boleto_beneficiario_diverge', 'nota_destinatario_diverge']);
    const resumo = (await chamar('GET', '/api/compras/resumo')).json;
    assert.ok(resumo.auditoria.alta >= 2);
    assert.equal(resumo.cnpjOficinaConfigurado, true);
  } finally { fechar(); }
});

test('download do XML guardado: só logado, devolve o XML original; nota digitada não tem XML', async () => {
  const { chamar, fechar, logar } = await subir();
  try {
    await logar();
    const xml = xmlNfe({ nNF: 61 });
    const id = (await chamar('POST', '/api/compras/notas/xml', { xml })).json.resultados[0].nota_id;
    const manual = (await chamar('POST', '/api/compras/notas', { fornecedorNome: 'AUTO PEÇAS X', numero: '7', dataEmissao: '2026-10-02', valorTotal: 50 })).json.nota_id;
    const d = await chamar('GET', `/api/compras/notas/${id}`);
    assert.equal(d.json.nota.tem_xml, true);
    assert.equal('xml_gz' in d.json.nota, false);
    const m = await chamar('GET', `/api/compras/notas/${manual}/xml`);
    assert.equal(m.status, 404);
  } finally { fechar(); }
});

test('leitura da linha digitável pela API devolve banco, valor, vencimento e erros', async () => {
  const { chamar, fechar, logar } = await subir();
  try {
    await logar();
    const linha = linhaDigitavel({ banco: '748', valor: 88.5, vencimento: '2026-11-10' });
    const r = (await chamar('POST', '/api/compras/boletos/ler', { linha })).json;
    assert.deepEqual([r.ok, r.valor, r.vencimento, r.bancoNome], [true, 88.5, '2026-11-10', 'Sicredi']);
    const errada = (await chamar('POST', '/api/compras/boletos/ler', { linha: linha.slice(0, 46) + '1' })).json;
    assert.equal(errada.ok, false);
  } finally { fechar(); }
});

test('rastro: do boleto até a OS, e da OS até o boleto', async () => {
  const { chamar, fechar, logar } = await subir();
  try {
    await logar();
    const venda = (await chamar('POST', '/api/vendas', { numero: '1500', data: '2026-10-05', placa: 'XYZ9K88', valorTotal: 800, valorMaoObra: 150 })).json.id;
    const imp = await chamar('POST', '/api/compras/notas/xml', { xml: xmlNfe({ nNF: 55, itens: [{ cProd: 'A', xProd: 'AMORTECEDOR', q: 1, vProd: 300 }, { cProd: 'B', xProd: 'COXIM', q: 1, vProd: 100 }], dups: [{ venc: '2026-10-28', valor: 400 }] }) });
    const nota = imp.json.resultados?.[0]?.nota_id ?? (await chamar('GET', '/api/compras/notas')).json[0].id;
    const det = (await chamar('GET', `/api/compras/notas/${nota}`)).json;
    await chamar('POST', `/api/compras/itens/${det.itens[0].id}/alocar`, { destino: 'os', vendaId: venda });
    const b = await chamar('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 400, vencimento: '2026-10-28' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    const d = (await chamar('GET', `/api/compras/boletos/${b.json.boleto_id}`)).json;
    assert.equal(d.rastro.length, 1);
    assert.equal(d.rastro[0].destinos[0].os, '1500');
    assert.equal(d.rastro[0].destinos[0].valor, 300);
    assert.equal(d.rastro[0].sem_destino, 100);                                          // o coxim ainda não tem destino
    const os = (await chamar('GET', `/api/vendas/${venda}`)).json;
    assert.equal(os.pecas_notas[0].situacao_boleto, 'boleto em aberto');
  } finally { fechar(); }
});

test('boletos em lote: cadastra os bons, aponta repetido, inválido e o que ficou sem nota', async () => {
  const { chamar, fechar, logar } = await subir();
  try {
    await logar();
    await chamar('POST', '/api/compras/notas/xml', { xml: xmlNfe({ nNF: 10, dups: [{ venc: '2026-10-25', valor: 150 }] }) });
    const l1 = linhaDigitavel({ valor: 150, vencimento: '2026-10-25' });
    const l2 = linhaDigitavel({ valor: 99.9, vencimento: '2026-10-26' });
    const r = await chamar('POST', '/api/compras/boletos/lote', { linhas: `${l1}\n${l2}\n${l1}\n123\n`, fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    assert.equal(r.status, 201);
    assert.deepEqual(r.json.resultados.map((x) => x.status), ['criado', 'criado', 'erro', 'erro']);
    assert.deepEqual(r.json.resultados.map((x) => x.ligado ?? null), [true, false, null, null]);
    assert.match(r.json.resultados[2].erro, /já está cadastrado/);
    assert.equal((await chamar('POST', '/api/compras/boletos/lote', { linhas: l1 })).status, 400);        // sem fornecedor
    assert.equal((await chamar('GET', '/api/compras/resumo')).json.totais.boletos, 2);
  } finally { fechar(); }
});
