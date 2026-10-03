import test from 'node:test';
import assert from 'node:assert/strict';
import { lerPlanilhaServicos, importarServicos } from '../src/importar.js';
import { abrirBanco } from '../src/db.js';

const CAB = 'O.S,DATA,VEÍCULO,PLACA,OPERADOR,CUSTO PEÇAS,M DE OBRA,INSUMOS,FRETE,VL SERVIÇO,LUCRO PÇ,PROPRIETA,DESC,LUCRO BRUTO,OBSERVAÇÃO,OBSERVAÇÃO,LOCADORA';
const q = (v) => (v ? `"${v}"` : '');          // valores da planilha têm vírgula, vêm entre aspas
const linha = (os, data, placa, cli, total, { custo = '', mo = '', status = '', obs = '' } = {}) =>
  `${os},${data},GOL,${placa},ROBSON,${q(custo)},${q(mo)},,,${q(total)},,${cli},,,${obs},${status},`;

function planilha() {
  const l = [CAB];
  for (let i = 0; i < 12; i++) l.push(linha(100 + i, `${String(3 + i).padStart(2, '0')}/10/2025`, `AAA${1000 + i}`, 'JOAO', 'R$ 500,00', { custo: 'R$ 200,00', mo: 'R$ 100,00' }));
  l.push(linha(112, '19/12', 'BBB2222', 'IZI', 'R$ 1.000,00', { custo: 'R$ 300,00' }));             // digitada errado no meio de outubro
  l.push(linha(113, 'ORÇAMENTO', 'CCC3333', 'ANA', 'R$ 750,00'));
  l.push(linha(114, '15/10/2025', 'DDD4444', 'IZICAR', 'R$ 300,00', { status: 'RECEBIDO' }));
  l.push('OUTUBRO,,,,,,,,,"R$ 9.999,00",,,,,,,');                                                    // linha de total do mês
  l.push(linha(115, '16/10/2025', 'EEE5555', 'ANA', '', {}));                                         // sem valor
  l.push(linha(116, '17/10/2025', 'FFF6666', 'ANA', 'R$ 410,00', { status: 'C.C 3X' }));
  return l.join('\n');
}

test('lê a planilha: ignora totais e linhas vazias, corrige data absurda, separa orçamento', () => {
  const { linhas } = lerPlanilhaServicos(planilha());
  assert.equal(linhas.length, 12 + 1 + 1 + 1 + 1);               // 12 + 112 + orçamento + 114 + 116
  const os112 = linhas.find((l) => l.numero === '112');
  assert.ok(os112.data.startsWith('2025-10'), `esperava outubro, veio ${os112.data}`);
  assert.equal(os112.dataEstimada, 1);
  assert.equal(linhas.find((l) => l.numero === '113').situacao, 'orcamento');
  assert.equal(linhas.find((l) => l.numero === '114').cliente, 'IZICAR');
  assert.equal(linhas.find((l) => l.numero === '116').forma, 'cartão');
  assert.equal(linhas.find((l) => l.numero === '100').custoPecas, 200);
  assert.equal(linhas.find((l) => l.numero === '112').valorMaoObra, 0);
});

test('importa uma vez só, aplica corte de quitação e cria a locadora com prazo', () => {
  const db = abrirBanco(':memory:');
  const r = importarServicos(db, planilha(), { quitadasAte: '2025-10-10' });
  assert.equal(r.novas, 16);
  assert.equal(r.orcamentos, 1);
  assert.ok(r.quitadasPorCorte >= 6);
  const loc = db.prepare("SELECT * FROM clientes WHERE nome = 'IZICAR'").get();
  assert.equal(loc.tipo, 'locadora');
  assert.equal(loc.prazo_dias, 7);
  const de = db.prepare("SELECT valor_total - COALESCE((SELECT SUM(valor) FROM recebimentos WHERE venda_id = v.id), 0) AS aberto FROM vendas v WHERE numero = '114'").get();
  assert.equal(de.aberto, 0);                                     // RECEBIDO na planilha
  const de2 = importarServicos(db, planilha(), { quitadasAte: '2025-10-10' });
  assert.equal(de2.novas, 0);
  assert.equal(de2.jaExistiam, 16);
});
