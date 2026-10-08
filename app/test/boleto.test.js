import test from 'node:test';
import assert from 'node:assert/strict';
import { interpretarBoleto, fatorParaData, dataParaFator } from '../src/boleto.js';
import { VETORES_BANCO, VETORES_CONVENIO } from './helpers/vetores_boleto.js';
import { linhaDigitavel } from './helpers/boleto.js';

const HOJE = '2026-10-08';

test('linha digitável e código de barras de banco: leitura bate com vetores independentes', () => {
  for (const v of VETORES_BANCO) {
    const a = interpretarBoleto(v.linha, HOJE);
    const b = interpretarBoleto(v.barras, HOJE);
    assert.equal(a.ok, true, a.erros.join());
    assert.equal(b.ok, true, b.erros.join());
    assert.equal(a.codigoBarras, v.barras);
    assert.equal(b.linhaDigitavel, v.linha);
    assert.equal(a.valor, v.valor);
    assert.equal(a.fator, v.fator);
  }
});

test('aceita a linha com pontos e espaços, como vem impressa', () => {
  const v = VETORES_BANCO[0].linha;
  const bonita = `${v.slice(0, 5)}.${v.slice(5, 10)} ${v.slice(10, 15)}.${v.slice(15, 21)} ${v.slice(21, 26)}.${v.slice(26, 32)} ${v[32]} ${v.slice(33)}`;
  assert.equal(interpretarBoleto(bonita, HOJE).ok, true);
});

test('digitação errada de um dígito: campos 1 a 3 sempre detectam; o resto detecta quase sempre (e isso NÃO prova que o boleto é verdadeiro)', () => {
  let testadas = 0;
  let detectadas = 0;
  for (const v of VETORES_BANCO) {
    for (let i = 0; i < v.linha.length; i++) {
      const l = v.linha.split('');
      l[i] = String((Number(l[i]) + 1) % 10);
      const ok = interpretarBoleto(l.join(''), HOJE).ok;
      if (i < 32) assert.equal(ok, false, `posição ${i} (campos 1 a 3) passou sem erro`);   // módulo 10 pega todo erro de 1 dígito
      testadas++;
      if (!ok) detectadas++;
    }
  }
  assert.ok(detectadas / testadas >= 0.97, `detectou só ${detectadas} de ${testadas}`);
});

test('tamanho inválido é recusado com mensagem clara', () => {
  const r = interpretarBoleto('1234', HOJE);
  assert.equal(r.ok, false);
  assert.match(r.erros[0], /dígitos/);
});

test('boleto de convênio/arrecadação (48 dígitos): dígitos verificadores e valor', () => {
  for (const v of VETORES_CONVENIO) {
    const a = interpretarBoleto(v.linha, HOJE);
    const b = interpretarBoleto(v.barras, HOJE);
    assert.equal(a.tipo, 'convenio');
    assert.equal(a.ok, true, a.erros.join());
    assert.equal(b.ok, true, b.erros.join());
    assert.equal(a.codigoBarras, v.barras);
    assert.equal(a.valor, v.valor);
  }
  const l = VETORES_CONVENIO[0].linha.split('');
  l[5] = String((Number(l[5]) + 1) % 10);
  assert.equal(interpretarBoleto(l.join(''), HOJE).ok, false);
});

test('fator de vencimento: ciclo antigo (07/10/1997) e novo (1000 = 22/02/2025)', () => {
  assert.equal(fatorParaData('1000', HOJE), '2025-02-22');
  assert.equal(fatorParaData('9999', '2025-02-10'), '2025-02-21');
  assert.equal(fatorParaData('1000', '2000-07-01'), '2000-07-03');
  assert.equal(dataParaFator('2025-02-22'), '1000');
  assert.equal(dataParaFator('2025-02-21'), '9999');
  assert.equal(fatorParaData(dataParaFator('2026-10-30'), HOJE), '2026-10-30');
  assert.equal(fatorParaData(dataParaFator('2027-03-15'), HOJE), '2027-03-15');
});

test('linha montada pelos testes é lida de volta com valor e vencimento certos', () => {
  const linha = linhaDigitavel({ banco: '756', valor: 1234.56, vencimento: '2026-10-30' });
  const r = interpretarBoleto(linha, HOJE);
  assert.equal(r.ok, true, r.erros.join());
  assert.equal(r.valor, 1234.56);
  assert.equal(r.vencimento, '2026-10-30');
  assert.equal(r.banco, '756');
  assert.equal(r.bancoNome, 'Sicoob');
});
