import test from 'node:test';
import assert from 'node:assert/strict';
import { lerTextoDoBoleto, acharLinhaNoTexto, cnpjsDoTexto } from '../src/boleto_texto.js';
import { linhaDigitavel } from './helpers/boleto.js';
import { CNPJ_OFICINA } from './helpers/nfe.js';

const HOJE = '2026-10-08';
const FORN = '11.222.333/0001-81';

function formatada(l) { return `${l.slice(0, 5)}.${l.slice(5, 10)} ${l.slice(10, 15)}.${l.slice(15, 21)} ${l.slice(21, 26)}.${l.slice(26, 32)} ${l[32]} ${l.slice(33)}`; }

test('texto de boleto no layout comum: acha linha, beneficiário, pagador e número do documento', () => {
  const linha = linhaDigitavel({ valor: 1234.56, vencimento: '2026-10-30' });
  const texto = `Local de pagamento: Pagável em qualquer banco
Beneficiário
DISTRIBUIDORA TESTE LTDA - CNPJ ${FORN}
Agência/Código do beneficiário 0001 / 12345-6
Nº do documento: 004321/01
Vencimento 30/10/2026
Valor do documento 1.234,56
Pagador
OFICINA MULTMEC LTDA CPF/CNPJ ${CNPJ_OFICINA}
Sacador/Avalista
${formatada(linha)}`;
  const r = lerTextoDoBoleto(texto, HOJE);
  assert.ok(r.boleto?.ok);
  assert.equal(r.boleto.valor, 1234.56);
  assert.equal(r.boleto.vencimento, '2026-10-30');
  assert.equal(r.beneficiarioCnpj, '11222333000181');
  assert.equal(r.pagadorCnpj, CNPJ_OFICINA.replace(/\D/g, ''));
  assert.equal(r.numeroDocumento, '004321/01');
  assert.deepEqual(r.avisos, []);
});

test('linha com número solto antes (agência) ainda é encontrada; recibo e ficha repetidos não confundem', () => {
  const linha = linhaDigitavel({ valor: 80, vencimento: '2026-11-05' });
  const f = formatada(linha);
  const r = acharLinhaNoTexto(`Agência 0001 123456 ${f}\nRecibo do pagador\n${f}`, HOJE);
  assert.ok(r?.ok);
  assert.equal(r.valor, 80);
});

test('texto sem linha válida não inventa boleto', () => {
  const r = lerTextoDoBoleto('Boleto 12345 67890 11111 22222 33333 44444 5 66666666666666 valor 10,00', HOJE);
  assert.equal(r.boleto, null);
  assert.match(r.avisos[0], /linha digitável válida/);
});

test('CNPJ sem rótulo claro não vira beneficiário; CNPJ inválido é ignorado', () => {
  const texto = `Empresa ${FORN}\nOutro 11.222.333/0001-80\nPagador ${CNPJ_OFICINA}`;
  const cnpjs = cnpjsDoTexto(texto);
  assert.equal(cnpjs.length, 2);                      // o inválido (DV errado) foi descartado
  const r = lerTextoDoBoleto(texto, HOJE);
  assert.equal(r.beneficiarioCnpj, null);
  assert.equal(r.pagadorCnpj, CNPJ_OFICINA.replace(/\D/g, ''));
  assert.ok(r.avisos.some((a) => /beneficiário/.test(a)));
});

test('dois beneficiários diferentes no texto: não escolhe nenhum', () => {
  const texto = `Beneficiário A ${FORN}\nBeneficiário B ${CNPJ_OFICINA}`;
  const r = lerTextoDoBoleto(texto, HOJE);
  assert.equal(r.beneficiarioCnpj, null);
  assert.ok(r.avisos.some((a) => /mais de um CNPJ/.test(a)));
});

test('CNPJ do avalista não é tomado como beneficiário nem pagador', () => {
  const texto = `Pagador ${CNPJ_OFICINA}\nSacador/Avalista BANCO X ${FORN}`;
  const r = lerTextoDoBoleto(texto, HOJE);
  assert.equal(r.beneficiarioCnpj, null);
  assert.equal(r.pagadorCnpj, CNPJ_OFICINA.replace(/\D/g, ''));
});

test('número do documento: ignora data e valor sem dígito', () => {
  assert.equal(lerTextoDoBoleto('Nº do documento 12/09/2026', HOJE).numeroDocumento, null);
  assert.equal(lerTextoDoBoleto('Número do documento: NF-8890', HOJE).numeroDocumento, 'NF-8890');
});
