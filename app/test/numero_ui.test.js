// O leitor de números dos formulários (ui.js) é puro: roda no Node. Bug da revisão: "200.00" virava 20000.
import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.document = globalThis.document ?? {};
const { numBR, paraCampo } = await import('../public/js/ui.js');

test('numBR lê vírgula decimal, milhar com ponto e decimal com ponto sem multiplicar por 100', () => {
  const casos = [['1.234,56', 1234.56], ['1234,56', 1234.56], ['200.00', 200], ['1500.50', 1500.5], ['1500.5', 1500.5], ['2.5', 2.5], ['1.500', 1500], ['1.234.567', 1234567], ['0,5', 0.5], ['R$ 320,50', 320.5], ['  12 ', 12], [320.5, 320.5], [0, 0]];
  for (const [entrada, esperado] of casos) assert.equal(numBR(entrada), esperado, String(entrada));
  assert.equal(numBR(''), null);
  assert.equal(numBR(null), null);
  assert.equal(numBR(undefined), null);
  assert.ok(Number.isNaN(numBR('abc')));
});

test('paraCampo devolve vírgula decimal e o parser lê de volta o mesmo número', () => {
  for (const n of [2.5, 1500.5, 0.125, 320, 99999.99]) assert.equal(numBR(paraCampo(n)), n);
  assert.equal(paraCampo(null), '');
  assert.equal(paraCampo(''), '');
});
