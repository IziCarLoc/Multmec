import test from 'node:test';
import assert from 'node:assert/strict';
import { cnpjValido, lerChaveNfe, normalizarCnpj, formatarCnpj } from '../src/documentos.js';
import { chave, CNPJ_FORNECEDOR } from './helpers/nfe.js';

test('CNPJ numérico e alfanumérico (em vigor desde 31/07/2026) são validados pelo dígito verificador', () => {
  assert.equal(cnpjValido('11.222.333/0001-81'), true);
  assert.equal(cnpjValido('11222333000182'), false);
  assert.equal(cnpjValido('00000000000000'), false);
  assert.equal(cnpjValido('00.000.000/E08G-12'), true);        // primeiro CNPJ alfanumérico divulgado pela Receita
  assert.equal(cnpjValido('12ABC34501DE35'), true);            // exemplo da nota técnica conjunta 2025.001
  assert.equal(cnpjValido('12ABC34501DE36'), false);
  assert.equal(cnpjValido('abc'), false);
  assert.equal(normalizarCnpj('11.222.333/0001-81'), '11222333000181');
  assert.equal(formatarCnpj('11222333000181'), '11.222.333/0001-81');
});

test('chave de acesso da NF-e: composição, dígito verificador e adulteração', () => {
  const c = chave({ nNF: 4321, serie: 2 });
  const r = lerChaveNfe(c);
  assert.equal(r.valida, true);
  assert.equal(r.cnpjEmitente, CNPJ_FORNECEDOR);
  assert.equal(r.numero, '4321');
  assert.equal(r.serie, '2');
  assert.equal(r.modelo, '55');
  assert.equal(r.aamm, '2610');
  const adulterada = c.slice(0, 30) + String((Number(c[30]) + 1) % 10) + c.slice(31);
  assert.equal(lerChaveNfe(adulterada).valida, false);
  assert.equal(lerChaveNfe(c.slice(1)).valida, false);        // 43 dígitos
  assert.equal(lerChaveNfe(`${c.slice(0, 4)} ${c.slice(4)}`).valida, true);   // aceita espaços
});
