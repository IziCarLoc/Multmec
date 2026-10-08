// Vetores da pesquisa sobre FEBRABAN/BCB (manuais do Banco do Brasil, Santander, Banpará e Inter; cálculo conferido à parte).
import test from 'node:test';
import assert from 'node:assert/strict';
import { interpretarBoleto, fatorParaData, dataParaFator, dvModulo10, dvModulo11Convenio } from '../src/boleto.js';

const HOJE = '2026-10-08';
const ler = (t) => interpretarBoleto(t, HOJE);

test('fator -> data pela janela de 3.000 dias para trás e 5.500 para frente (hoje = 08/10/2026)', () => {
  const casos = [['1593', '2026-10-08'], ['1000', '2025-02-22'], ['3737', '2032-08-21'], ['7093', '2041-10-29'], ['7593', '2018-07-22'], ['7592', '2018-07-21'], ['9999', '2025-02-21']];       // 7592 = tolerância de 3.001 dias do manual
  for (const [f, d] of casos) assert.equal(fatorParaData(f, HOJE), d, `fator ${f}`);
  for (const f of ['7094', '7552', '7591', '0999', '0000', '0001', '10000']) assert.equal(fatorParaData(f, HOJE), null, `fator ${f}`);
});

test('janela do exemplo do manual do Inter (hoje = 23/03/2014)', () => {
  assert.equal(fatorParaData('6011', '2014-03-23'), '2014-03-23');
  assert.equal(fatorParaData('3011', '2014-03-23'), '2006-01-04');
  assert.equal(fatorParaData('3010', '2014-03-23'), '2006-01-03');          // tolerância de 3.001 dias
  assert.equal(fatorParaData('2511', '2014-03-23'), '2029-04-13');
  assert.equal(fatorParaData('2512', '2014-03-23'), null);
});

test('data -> fator: base antiga e reinício de 22/02/2025', () => {
  const antigos = [['2000-07-03', '1000'], ['2000-07-04', '1001'], ['2002-05-01', '1667'], ['2007-12-31', '3737'], ['2010-11-17', '4789'], ['2012-04-27', '5316'], ['2025-02-20', '9998'], ['2025-02-21', '9999']];
  for (const [d, f] of antigos) assert.equal(dataParaFator(d), f, d);
  const novos = [['2025-02-22', '1000'], ['2025-02-23', '1001'], ['2026-10-08', '1593'], ['2026-10-15', '1600'], ['2026-12-21', '1667'], ['2026-12-31', '1677'], ['2035-07-09', '4789'], ['2049-10-13', '9999'], ['2049-10-14', '1000']];
  for (const [d, f] of novos) assert.equal(dataParaFator(d), f, d);
});

test('exemplo oficial do Banco do Brasil: código de barras e linha digitável dão o mesmo boleto', () => {
  const barras = ler('00193373700000001000500940144816060680935031');
  const linha = ler('00190.50095 40144.816069 06809.350314 3 37370000000100');
  for (const r of [barras, linha]) {
    assert.equal(r.ok, true);
    assert.equal(r.banco, '001');
    assert.equal(r.valor, 1);
    assert.equal(r.vencimento, '2032-08-21');                // 3737 lido hoje (não 31/12/2007)
    assert.equal(r.codigoBarras, '00193373700000001000500940144816060680935031');
  }
  assert.equal(barras.linhaDigitavel, '00190500954014481606906809350314337370000000100');
});

test('bordas do DV geral: resto 0, 1 e 10 viram 1; resto 2 vira 9', () => {
  for (const b of ['00191373700000001000500940144816060680935038', '00191373700000001000500940144816060680935033', '00191373700000001000500940144816060680935032']) {
    assert.equal(ler(b).ok, true, b);
  }
  assert.equal(ler('00199373700000001000500940144816060680935039').ok, true);
  assert.equal(ler('00191373700000001000500940144816060680935038').linhaDigitavel, '00190500954014481606906809350389137370000000100');
});

test('linhas de bancos reais de manuais: valor e vencimento pela janela', () => {
  const casos = [
    ['23790.44809 56168.623793 36011.058009 7 40430000124020', 1240.2, '2033-06-23'],
    ['03399.02827 03356.661243 57800.201014 8 20460000027371', 273.71, '2028-01-04'],
    ['03790.00094 99100.650003 00000.004028 4 81900000019990', 199.9, '2020-03-10'],
    ['23790.00009 00012.345674 89012.345677 5 16000000015075', 150.75, '2026-10-15'],
    ['00190.50095 40144.816069 06809.350314 8 16000000123456', 1234.56, '2026-10-15'],
    ['34196790600001000002220000005566385101214000', 1000, '2019-05-31'],
  ];
  for (const [t, valor, venc] of casos) {
    const r = ler(t);
    assert.equal(r.ok, true, t);
    assert.equal(r.valor, valor, t);
    assert.equal(r.vencimento, venc, t);
  }
});

test('fator na faixa de segurança: o boleto é recusado com explicação', () => {
  const r = ler('23793.38128 60007.827136 95000.063305 9 75520000370000');     // fator 7552
  assert.equal(r.ok, false);
  assert.match(r.erros.join(' '), /fora da faixa/);
});

test('fator 0000 só avisa; valor zero só avisa; fator abaixo de 1000 é erro', () => {
  const sem = ler('00190.50095 40144.816069 06809.350314 8 00000000000100');
  assert.equal(sem.ok, true);
  assert.equal(sem.vencimento, null);
  assert.match(sem.avisos.join(' '), /fator 0000/);
  const zero = ler('00190.50095 40144.816069 06809.350314 8 37370000000000');
  assert.equal(zero.ok, true);
  assert.equal(zero.valor, 0);
  assert.match(zero.avisos.join(' '), /sem valor definido/);
});

test('adulterações do exemplo do BB são pegas e explicadas', () => {
  const campo1 = ler('00190.10095 40144.816069 06809.350314 3 37370000000100');
  assert.equal(campo1.ok, false);
  assert.ok(campo1.erros.some((e) => /1º campo/.test(e)) && campo1.erros.some((e) => /geral/.test(e)));
  const campo2 = ler('00190.50095 40144.816068 06809.350314 3 37370000000100');
  assert.equal(campo2.erros.length, 1);
  assert.match(campo2.erros[0], /2º campo/);
  const valor = ler('00190.50095 40144.816069 06809.350314 3 37370000000101');
  assert.equal(valor.erros.length, 1);
  assert.match(valor.erros[0], /geral/);
  assert.equal(ler('00190.50095 40144.816069 06809.350314 4 37370000000100').ok, false);
  assert.equal(ler('00193373700000001000500940144896060680935031').ok, false);
  assert.match(ler('0019337370000000100050094014481606068093503').erros[0], /43/);
});

test('arrecadação/convênio: vetores FEBRABAN v8 e bibliotecas', () => {
  assert.equal(dvModulo10('01230067896'), 3);
  assert.equal(dvModulo11Convenio('01230067896'), 0);
  const energia = ler('836200000005 667800481000 180975657313 001589636081');
  assert.equal(energia.ok, true);
  assert.equal(energia.tipo, 'convenio');
  assert.equal(energia.valor, 66.78);
  assert.equal(energia.codigoBarras, '83620000000667800481001809756573100158963608');
  const gov = ler('85890000460-9 52460179160-5 60759305086-5 83148300001-0');
  assert.equal(gov.ok, true);
  assert.equal(gov.valor, 46052.46);
  assert.equal(ler('858000001239061603852620610716262474385997310225').valor, 12306.16);
  assert.equal(ler('836200000005667800481000180975657313001589636082').ok, false);     // DV do bloco 4 alterado
  assert.equal(ler('836200000005677800481000180975657313001589636081').ok, false);     // dado do bloco 2 alterado
  assert.equal(ler('536200000005667800481000180975657313001589636081').ok, false);     // 48 dígitos que não começam com 8
});
