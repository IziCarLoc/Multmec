import test from 'node:test';
import assert from 'node:assert/strict';
import { lerValor, lerCsv, diasUteis, somarMeses, hoje, diasEntre } from '../src/util.js';

test('lerValor entende formatos brasileiros e da planilha', () => {
  assert.equal(lerValor('R$ 1.234,56'), 1234.56);
  assert.equal(lerValor('R$ 68,30'), 68.3);
  assert.equal(lerValor('-R$ 28,00'), -28);
  assert.equal(lerValor('1234.5'), 1234.5);
  assert.equal(lerValor('1.200'), 1.2);        // sem vírgula: ponto é decimal (entrada da API é número)
  assert.equal(lerValor(''), null);
  assert.equal(lerValor('\\-'), null);
  assert.equal(lerValor(12.345), 12.35);
});

test('lerCsv respeita aspas, vírgulas e quebras de linha dentro do campo', () => {
  const l = lerCsv('a,b,c\n"x, y","linha1\nlinha2","diz ""oi"""\n');
  assert.deepEqual(l[1], ['x, y', 'linha1\nlinha2', 'diz "oi"']);
});

test('diasUteis descarta fim de semana e feriados', () => {
  const dias = diasUteis('2026-10', ['2026-10-12']);
  assert.equal(dias.length, 21);               // outubro/2026 tem 22 dias úteis; menos o feriado de 12/10
  assert.ok(!dias.some((d) => d.data === '2026-10-12'));
  assert.equal(diasUteis('2026-10', [], 0.5).length, 22 + 5);
});

test('datas em Brasília e aritmética de meses', () => {
  assert.equal(hoje(new Date('2026-10-04T02:30:00Z')), '2026-10-03');   // ainda é dia 3 em Brasília
  assert.equal(somarMeses('2026-01', -1), '2025-12');
  assert.equal(diasEntre('2026-10-01', '2026-10-15'), 14);
});
