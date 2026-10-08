import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco } from '../src/db.js';
import { criarApp } from '../src/app.js';
import { lerTextoDanfe, acharChaveNoTexto } from '../src/danfe.js';
import { chave, CNPJ_OFICINA, CNPJ_FORNECEDOR } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';

const SENHA_DONO = 'senha-do-dono-123';
const SENHA_LANC = 'senha-lancamento-456';

const CHAVE = chave({ nNF: 4321, aamm: '2610' });
const DANFE = `DANFE
Documento Auxiliar da Nota Fiscal Eletrônica
Nº 000.004.321
SÉRIE 1
CHAVE DE ACESSO
${CHAVE.match(/.{1,4}/g).join(' ')}
Consulta de autenticidade no portal nacional da NF-e www.nfe.fazenda.gov.br/portal
VENDA DE MERCADORIA 143260000000001 - 01/10/2026 09:12:30
EMITENTE
DISTRIBUIDORA TESTE LTDA 11.222.333/0001-81
DESTINATÁRIO / REMETENTE
NOME / RAZÃO SOCIAL CNPJ / CPF DATA DA EMISSÃO
OFICINA TESTE LTDA 45.723.174/0001-10 01/10/2026
CÁLCULO DO IMPOSTO
BASE DE CÁLC. DO ICMS VALOR DO ICMS V. TOTAL PRODUTOS
0,00 0,00 1.000,00
VALOR DO FRETE VALOR DO SEGURO DESCONTO OUTRAS DESP. VALOR TOTAL DO IPI VALOR TOTAL DA NOTA
50,00 0,00 0,00 0,00 0,00 1.050,00
TRANSPORTADOR / VOLUMES TRANSPORTADOS
`;

test('leitor de DANFE: chave em blocos ou solta, número, série, mês, emitente, destinatário, data e valor', () => {
  const r = lerTextoDanfe(DANFE);
  assert.equal(r.chave.valida, true);
  assert.equal(r.chave.chave, CHAVE);
  assert.equal(r.chave.numero, '4321');
  assert.equal(r.chave.serie, '1');
  assert.equal(r.chave.cnpjEmitente, CNPJ_FORNECEDOR);
  assert.equal(r.destinatarioCnpj, CNPJ_OFICINA);
  assert.equal(r.dataEmissao, '2026-10-01');
  assert.equal(r.valorTotal, 1050);
  assert.deepEqual(r.valoresPossiveis, [1050, 1000, 50, 0]);
  assert.deepEqual(r.avisos, []);
  // chave solta, com pontos ou traços, no meio de uma frase
  assert.equal(acharChaveNoTexto(`chave: ${CHAVE}.`).chave, CHAVE);
  assert.equal(acharChaveNoTexto(CHAVE.match(/.{1,4}/g).join('-')).chave, CHAVE);
});

test('leitor de DANFE: não inventa nada com texto sem chave, chave adulterada ou data fora do mês da chave', () => {
  const nada = lerTextoDanfe('nota qualquer 1.234,56 sem nada que pareça chave');
  assert.equal(nada.chave, null);
  assert.ok(nada.avisos.some((a) => /chave de acesso/.test(a)));
  const errada = `${CHAVE.slice(0, 43)}${(Number(CHAVE[43]) + 1) % 10}`;
  const r = lerTextoDanfe(errada);
  assert.equal(r.chave.valida, false);
  assert.match(r.avisos[0], /dígito verificador/);
  // a data do texto precisa cair no mês da chave
  const outraData = lerTextoDanfe(DANFE.replace(/01\/10\/2026/g, '01/09/2026'));
  assert.equal(outraData.dataEmissao, null);
  assert.ok(outraData.avisos.some((a) => /data de emissão/.test(a)));
  // nota de consumidor (modelo 65) não entra
  const nfce = chave({ nNF: 9, mod: '65' });
  assert.ok(lerTextoDanfe(nfce).avisos.some((a) => /modelo 65/.test(a)));
});

async function subir() {
  const db = abrirBanco(':memory:');
  const app = criarApp(db, { senha: SENHA_DONO, senhaLancamento: SENHA_LANC, segredo: 's'.repeat(32), agora: () => new Date('2026-10-08T15:00:00Z') });
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

test('API ler-danfe: devolve o fornecedor da chave, avisa nota repetida e empresa do grupo', async () => {
  const { dono, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    const r = await dono('POST', '/api/compras/notas/ler-danfe', { texto: DANFE });
    assert.equal(r.status, 200);
    assert.equal(r.json.ok, true);
    assert.equal(r.json.numero, '4321');
    assert.equal(r.json.mesChave, '10/2026');
    assert.equal(r.json.fornecedor, null);
    assert.equal(r.json.notaExistente, null);
    // cadastra a nota e lê de novo: agora o fornecedor existe e a nota é repetida
    const nota = await dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4321', serie: '1', dataEmissao: '2026-10-01', valorTotal: 1050, chave: CHAVE, cnpjDestinatario: CNPJ_OFICINA });
    assert.equal(nota.status, 201);
    const de_novo = (await dono('POST', '/api/compras/notas/ler-danfe', { texto: CHAVE })).json;
    assert.equal(de_novo.fornecedor.cnpj, CNPJ_FORNECEDOR);
    assert.equal(de_novo.notaExistente.numero, '4321');
    const semChave = await dono('POST', '/api/compras/notas/ler-danfe', { texto: 'sem nada' });
    assert.equal(semChave.json.ok, false);
  } finally { fechar(); }
});

test('nota sem XML: só a consulta no portal feita pelo DONO e com o mesmo valor vira prova; a de quem lança fica pendente', async () => {
  const { db, dono, lanc, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    // quem lança digita a nota (com a chave da DANFE) e o boleto
    const nota = (await lanc('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4321', serie: '1', dataEmissao: '2026-10-01', valorTotal: 1050, chave: CHAVE, cnpjDestinatario: CNPJ_OFICINA, duplicatas: [{ vencimento: '2026-10-25', valor: 1050 }] })).json.nota_id;
    const forn = (await dono('GET', '/api/compras/fornecedores')).json[0];
    await dono('PUT', `/api/compras/fornecedores/${forn.id}`, { confirmado: true });
    const b = await lanc('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 1050, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', beneficiarioCnpj: CNPJ_FORNECEDOR, pagadorCnpj: CNPJ_OFICINA });
    assert.equal(b.json.auto.ligado, true);
    const semProva = () => b.json.ocorrencias.find((o) => o.tipo === 'boleto_nota_sem_prova');
    assert.ok(semProva());
    const ocorrenciasAgora = async () => (await dono('GET', `/api/compras/boletos/${b.json.boleto_id}`)).json.ocorrencias;

    // consulta de quem lança: registrada, mas ainda não prova
    assert.equal((await lanc('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 1050 })).json.confere, true);
    let oc = (await ocorrenciasAgora()).find((o) => o.tipo === 'boleto_nota_sem_prova');
    assert.ok(oc);
    assert.match(oc.detalhe, /consultada no portal por quem lança.*dono repetir/);
    const logs = db.prepare("SELECT perfil FROM auditoria_log WHERE acao = 'consulta_portal'").all();
    assert.deepEqual(logs.map((l) => l.perfil), ['lancamento']);

    // o dono repete, mas o portal mostrou outro valor: continua sem prova e a mensagem diz por quê
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 1500 })).json.confere, false);
    oc = (await ocorrenciasAgora()).find((o) => o.tipo === 'boleto_nota_sem_prova');
    assert.match(oc.detalhe, /portal mostrou R\$ 1500\.00.*digitada com R\$ 1050\.00/);

    // o dono consulta de novo e o valor bate: prova aceita, o alerta some
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 1050 })).json.confere, true);
    assert.ok(!(await ocorrenciasAgora()).some((o) => o.tipo === 'boleto_nota_sem_prova'));
    const det = (await dono('GET', `/api/compras/notas/${nota}`)).json;
    assert.equal(det.nota.consulta_por, 'dono');
    assert.equal(det.nota.consulta_em, '2026-10-08');
  } finally { fechar(); }
});

test('consulta no portal: chave não encontrada vira alerta; cancelada/denegada cancela a nota e trava o boleto', async () => {
  const { db, dono, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    const nota = (await dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4321', serie: '1', dataEmissao: '2026-10-01', valorTotal: 1050, chave: CHAVE, duplicatas: [{ vencimento: '2026-10-25', valor: 1050 }] })).json.nota_id;
    const b = await dono('POST', '/api/compras/boletos', { linha: linhaDigitavel({ valor: 1050, vencimento: '2026-10-25' }), fornecedorNome: 'DISTRIBUIDORA TESTE LTDA' });
    await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'nao_encontrada' });
    let oc = (await dono('GET', `/api/compras/boletos/${b.json.boleto_id}`)).json.ocorrencias.find((o) => o.tipo === 'boleto_nota_sem_prova');
    assert.match(oc.detalhe, /NÃO foi encontrada no portal/);

    await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'denegada' });
    assert.equal(db.prepare('SELECT situacao, cancelada_por, protocolo_status FROM notas_compra WHERE id = ?').get(nota).situacao, 'cancelada');
    oc = (await dono('GET', `/api/compras/boletos/${b.json.boleto_id}`)).json.ocorrencias.find((o) => o.tipo === 'nota_cancelada_com_boleto');
    assert.equal(oc.titulo, 'Boleto de nota DENEGADA');
    // validações
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'qualquer' })).status, 400);
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada' })).status, 400);     // faltou o valor
  } finally { fechar(); }
});

test('consulta exige chave; informar a chave depois confere fornecedor, número, mês e repetição', async () => {
  const { dono, fechar } = await subir();
  try {
    const nota = (await dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', cnpjEmitente: CNPJ_FORNECEDOR, numero: '4321', serie: '1', dataEmissao: '2026-10-01', valorTotal: 1050 })).json.nota_id;
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 1050 })).status, 400);
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/chave`, { chave: chave({ nNF: 9999, aamm: '2610' }) })).status, 400);                   // outro número
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/chave`, { chave: chave({ nNF: 4321, aamm: '2609' }) })).status, 400);                   // outro mês
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/chave`, { chave: chave({ nNF: 4321, aamm: '2610', cnpj: '27865757000102' }) })).status, 400); // outro CNPJ
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/chave`, { chave: `${CHAVE.slice(0, 43)}${(Number(CHAVE[43]) + 1) % 10}` })).status, 400);  // DV errado
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/chave`, { chave: CHAVE })).status, 200);
    assert.equal((await dono('POST', `/api/compras/notas/${nota}/consulta`, { situacao: 'autorizada', valor: 1050 })).status, 200);
    // a mesma chave não vale para outra nota
    const outra = (await dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '4322', serie: '1', dataEmissao: '2026-10-02', valorTotal: 10 })).json.nota_id;
    assert.equal((await dono('POST', `/api/compras/notas/${outra}/chave`, { chave: CHAVE })).status, 400);
  } finally { fechar(); }
});

test('nota digitada em nome da locadora (CNPJ do destinatário) é reconhecida como empresa do grupo', async () => {
  const { dono, fechar } = await subir();
  try {
    await dono('PUT', '/api/config', { cnpjOficina: CNPJ_OFICINA });
    const emp = (await dono('POST', '/api/compras/empresas', { nome: 'IZICAR LOCADORA', cnpj: '45997418000153', papel: 'locadora' })).json;
    const nota = (await dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', cnpjEmitente: CNPJ_FORNECEDOR, numero: '55', dataEmissao: '2026-10-01', valorTotal: 300, cnpjDestinatario: '45.997.418/0001-53' })).json.nota_id;
    const det = (await dono('GET', `/api/compras/notas/${nota}`)).json.nota;
    assert.equal(det.empresa_id, emp.id);
    assert.equal(det.empresa, 'IZICAR LOCADORA');
    assert.equal((await dono('POST', '/api/compras/notas', { fornecedorNome: 'DISTRIBUIDORA TESTE LTDA', numero: '56', dataEmissao: '2026-10-01', valorTotal: 300, cnpjDestinatario: '11222333000299' })).status, 400);
  } finally { fechar(); }
});
