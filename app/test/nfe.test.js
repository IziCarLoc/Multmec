import test from 'node:test';
import assert from 'node:assert/strict';
import { lerXmlNfe } from '../src/nfe.js';
import { abrirBanco } from '../src/db.js';
import { importarNotaXml, ratearCusto } from '../src/compras.js';
import { chave, xmlNfe, xmlCancelamento, CNPJ_FORNECEDOR, CNPJ_OFICINA } from './helpers/nfe.js';

const ITENS = [
  { cProd: 'P1', xProd: 'PASTILHA DE FREIO DIANTEIRA', q: 1, vProd: 120, xPed: '1148' },
  { cProd: 'D2', xProd: 'DISCO DE FREIO', q: 2, vProd: 180 },
];

test('lê itens, parcelas, emitente, destinatário e protocolo da NF-e', () => {
  const r = lerXmlNfe(xmlNfe({ nNF: 4321, itens: ITENS, dups: [{ venc: '2026-10-31', valor: 150 }, { venc: '2026-11-30', valor: 150 }], infCpl: 'PLACA ABC1D23' }));
  assert.equal(r.tipo, 'nota');
  assert.equal(r.numero, '4321');
  assert.equal(r.serie, '1');
  assert.equal(r.data_emissao, '2026-10-01');
  assert.equal(r.valor_total, 300);
  assert.equal(r.cnpj_emitente, CNPJ_FORNECEDOR);
  assert.equal(r.cnpj_destinatario, CNPJ_OFICINA);
  assert.equal(r.protocolo_status, '100');
  assert.deepEqual(r.duplicatas.map((d) => [d.vencimento, d.valor]), [['2026-10-31', 150], ['2026-11-30', 150]]);
  assert.equal(r.itens.length, 2);
  assert.equal(r.itens[0].x_ped, '1148');
  assert.equal(r.itens[1].quantidade, 2);
  assert.equal(r.info_compl, 'PLACA ABC1D23');
  assert.deepEqual(r.avisos, []);
});

test('NF-e sem duplicatas e sem protocolo é lida, com aviso de autenticidade', () => {
  const r = lerXmlNfe(xmlNfe({ proc: false }));
  assert.deepEqual(r.duplicatas, []);
  assert.match(r.avisos[0], /protocolo/);
});

test('recusa XML adulterado, de outro modelo, com DOCTYPE, vazio ou que não é NF-e', () => {
  const ok = xmlNfe({ nNF: 4321 });
  assert.throws(() => lerXmlNfe(ok.replace('<nNF>4321</nNF>', '<nNF>4322</nNF>')), /não batem com a chave/);
  assert.throws(() => lerXmlNfe(ok.replace(`<CNPJ>${CNPJ_FORNECEDOR}</CNPJ><xNome>DIST`, '<CNPJ>27865757000102</CNPJ><xNome>DIST')), /emitente não bate/);
  assert.throws(() => lerXmlNfe(xmlNfe({ mod: '65' })), /modelo 55/);
  assert.throws(() => lerXmlNfe('<!DOCTYPE a [<!ENTITY x "y">]><a/>'), /declarações/);
  assert.throws(() => lerXmlNfe(''), /vazio/);
  assert.throws(() => lerXmlNfe('<a><b>1</b></a>'), /Não encontrei uma NF-e/);
  assert.throws(() => lerXmlNfe('isto não é xml <<<'), /Não encontrei|Não consegui/);
  const chaveErrada = ok.replaceAll(/NFe(\d{43})\d/g, (_, p) => `NFe${p}0`).replace(/<chNFe>(\d{43})\d/, '<chNFe>$10');
  if (chaveErrada !== ok) assert.throws(() => lerXmlNfe(chaveErrada));
});

test('rateio do custo: a soma dos itens fecha exatamente com o valor da nota (frete e impostos incluídos)', () => {
  const partes = ratearCusto([{ valor_total: 100, valor_desconto: 0 }, { valor_total: 100, valor_desconto: 0 }, { valor_total: 100, valor_desconto: 0 }], 310);
  assert.equal(partes.reduce((a, b) => Math.round((a + b) * 100) / 100, 0), 310);
  const comDesconto = ratearCusto([{ valor_total: 200, valor_desconto: 50 }, { valor_total: 50, valor_desconto: 0 }], 200);
  assert.deepEqual(comDesconto, [150, 50]);
});

test('importar: cria fornecedor, nota, parcelas e itens; repetir não duplica; XML diferente com a mesma chave avisa', () => {
  const db = abrirBanco(':memory:');
  const xml = xmlNfe({ nNF: 77, itens: ITENS, dups: [{ venc: '2026-10-31', valor: 300 }], vFrete: 30 });
  const r1 = importarNotaXml(db, xml);
  assert.equal(r1.status, 'importada');
  const nota = db.prepare('SELECT * FROM notas_compra WHERE id = ?').get(r1.nota_id);
  assert.equal(nota.valor_total, 330);
  assert.equal(db.prepare('SELECT cnpj FROM fornecedores WHERE id = ?').get(nota.fornecedor_id).cnpj, CNPJ_FORNECEDOR);
  const custos = db.prepare('SELECT custo_total FROM nota_itens WHERE nota_id = ? ORDER BY n_item').all(r1.nota_id).map((i) => i.custo_total);
  assert.equal(Math.round(custos.reduce((a, b) => a + b, 0) * 100) / 100, 330);       // frete entra no custo das peças
  const r2 = importarNotaXml(db, xml);
  assert.equal(r2.status, 'ja_existia');
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM notas_compra').get().n, 1);
  const alterado = xml.replace('<xNome>OFICINA TESTE LTDA</xNome>', '<xNome>OUTRO NOME</xNome>');
  const r3 = importarNotaXml(db, alterado);
  assert.equal(r3.status, 'ja_existia');
  assert.match(r3.avisos[0], /DIFERENTE/);
});

test('XML de cancelamento marca a nota como cancelada', () => {
  const db = abrirBanco(':memory:');
  const chv = chave({ nNF: 88 });
  const r = importarNotaXml(db, xmlNfe({ nNF: 88, chv }));
  assert.throws(() => importarNotaXml(db, xmlCancelamento(chave({ nNF: 99 }))), /ainda não foi importada/);
  const c = importarNotaXml(db, xmlCancelamento(chv));
  assert.equal(c.status, 'cancelada');
  assert.equal(db.prepare('SELECT situacao FROM notas_compra WHERE id = ?').get(r.nota_id).situacao, 'cancelada');
});

test('o XML original fica guardado (comprimido) e pode ser baixado, igual ao que entrou', async () => {
  const { gunzipSync } = await import('node:zlib');
  const db = abrirBanco(':memory:');
  const xml = xmlNfe({ nNF: 31 });
  const r = importarNotaXml(db, xml);
  const guardado = gunzipSync(db.prepare('SELECT xml_gz FROM notas_compra WHERE id = ?').get(r.nota_id).xml_gz).toString('utf8');
  assert.equal(guardado, xml);
});
