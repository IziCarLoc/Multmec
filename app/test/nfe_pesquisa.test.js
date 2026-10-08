// XML e chaves vindos da pesquisa (MOC 7.0, NT Conjunta 2025.001, XSD PL_010f): dados fictícios, estrutura conferida contra o XSD oficial.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { lerXmlNfe } from '../src/nfe.js';
import { dvChaveNfe, lerChaveNfe, cnpjValido } from '../src/documentos.js';
import { abrirBanco } from '../src/db.js';
import { importarNotaXml, sugerirAlocacoes } from '../src/compras.js';

const XML = readFileSync(new URL('./fixtures/nfe_pesquisa.xml', import.meta.url), 'utf8');

test('XML do fixture da pesquisa: chave, emitente, itens, parcelas e pedido', () => {
  const n = lerXmlNfe(XML);
  assert.equal(n.tipo, 'nota');
  assert.equal(n.chave, '43261012345678000195550010000482131076543215');
  assert.equal(n.numero, '48213');
  assert.equal(n.serie, '1');
  assert.equal(n.cnpj_emitente, '12345678000195');
  assert.equal(n.cnpj_destinatario, '11222333000181');
  assert.equal(n.data_emissao, '2026-10-05');
  assert.equal(n.valor_total, 277.3);
  assert.equal(n.situacao, 'ativa');
  assert.equal(n.protocolo_status, '100');
  assert.deepEqual(n.itens.map((i) => [i.codigo, i.quantidade, i.valor_total, i.x_ped]), [['PF-0312', 2, 179.8, 'OS1043'], ['FO-0087', 3, 97.5, 'OS1051']]);
  assert.deepEqual(n.duplicatas.map((d) => [d.numero, d.vencimento, d.valor]), [['001', '2026-11-04', 138.65], ['002', '2026-12-04', 138.65]]);
  assert.deepEqual(n.avisos, []);
});

test('pedido "OS1043" liga o item à OS 1043 (e só ela)', () => {
  const db = abrirBanco(':memory:');
  const v1 = db.prepare("INSERT INTO vendas (numero, data, placa, valor_total, situacao) VALUES ('1043', '2026-10-03', 'ABC1D23', 500, 'concluida')").run().lastInsertRowid;
  db.prepare("INSERT INTO vendas (numero, data, placa, valor_total, situacao) VALUES ('1051', '2026-10-04', 'XYZ9K88', 300, 'concluida')").run();
  db.prepare("INSERT INTO vendas (numero, data, placa, valor_total, situacao) VALUES ('2043', '2026-10-04', 'QQQ1A11', 300, 'concluida')").run();
  const r = importarNotaXml(db, XML);
  assert.equal(r.status, 'importada');
  const sug = sugerirAlocacoes(db, r.nota_id);
  assert.equal(sug.length, 2);
  assert.equal(sug[0].venda_id, v1);
  assert.equal(sug[0].os, '1043');
  assert.equal(sug[1].os, '1051');
  assert.ok(sug.every((s) => !s.ambigua));
});

test('dígito verificador da chave: exemplos oficiais do MOC, bordas e chaves de blog que NÃO fecham', () => {
  assert.equal(dvChaveNfe('5206043300991100250655012000000780026730161'), 5);
  assert.equal(dvChaveNfe('4118067839359200014655890000000604102819069'), 7);
  assert.equal(dvChaveNfe('3120101058820100010555001003842117183842217'), 8);
  assert.equal(dvChaveNfe('4326101234567800019555001000048213107654329'), 0);      // resto 0
  assert.equal(dvChaveNfe('4326101234567800019555001000048213107654324'), 0);      // resto 1
  assert.equal(dvChaveNfe('4326101234567800019555001000048213107654333'), 9);      // resto 2
  assert.equal(dvChaveNfe('4326101234567800019555001000048213107654323'), 1);      // resto 10
  assert.equal(lerChaveNfe('43261012345678000195550010000482131076543214').valida, false);
  for (const ruim of ['35220499999999999999550010020000001240556603', '43260812345678000199550010000123451123456789', '35200501442791000129550010000059172174173681']) {
    assert.equal(lerChaveNfe(ruim).valida, false, ruim);
  }
});

test('chave com CNPJ alfanumérico (ASCII - 48) é aceita e o CNPJ confere', () => {
  const ch = lerChaveNfe('43261012ABC34501DE35550010000482131076543215');
  assert.equal(ch.valida, true);
  assert.equal(ch.cnpjEmitente, '12ABC34501DE35');
  assert.equal(ch.dvIncerto, undefined);
  assert.equal(cnpjValido('12ABC34501DE35'), true);
  assert.equal(cnpjValido('12ABC34501DE36'), false);
  assert.equal(cnpjValido('11222333000181'), true);
  assert.equal(cnpjValido('33009911002506'), true);
  assert.equal(cnpjValido('78393592000146'), true);
});

test('protocolo: cancelada e denegada entram como canceladas; homologação e protocolo trocado são recusados', () => {
  const canc = lerXmlNfe(XML.replace('<cStat>100</cStat>', '<cStat>101</cStat>'));
  assert.equal(canc.situacao, 'cancelada');
  assert.match(canc.avisos.join(' '), /CANCELADA/);
  const den = lerXmlNfe(XML.replace('<cStat>100</cStat>', '<cStat>110</cStat>'));
  assert.equal(den.situacao, 'cancelada');
  assert.match(den.avisos.join(' '), /DENEGADO/);
  assert.throws(() => lerXmlNfe(XML.replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>')), /HOMOLOGA/);
  assert.throws(() => lerXmlNfe(XML.replace(/<chNFe>\d{44}<\/chNFe>/, '<chNFe>43261012345678000195550010000482131076543290</chNFe>')), /protocolo/);
});

test('valor com tributos por fora (vNFTot), parcelas que não somam e nota de ajuste geram avisos', () => {
  const comTot = lerXmlNfe(XML.replace('</ICMSTot>', '</ICMSTot><vNFTot>280.00</vNFTot>'));
  assert.equal(comTot.valor_com_tributos, 280);
  assert.match(comTot.avisos.join(' '), /por fora/);
  assert.ok(!/parcelas da nota somam/.test(comTot.avisos.join(' ')));      // parcelas = vNF: nada a avisar
  const semBate = lerXmlNfe(XML.replace('<vDup>138.65</vDup></dup><dup>', '<vDup>100.00</vDup></dup><dup>'));
  assert.match(semBate.avisos.join(' '), /parcelas da nota somam/);
  const ajuste = lerXmlNfe(XML.replace('<finNFe>1</finNFe>', '<finNFe>3</finNFe>'));
  assert.equal(ajuste.finalidade, 'ajuste');
  assert.match(ajuste.avisos.join(' '), /AJUSTE/);
});
