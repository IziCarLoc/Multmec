#!/usr/bin/env node
// Cria um banco de DEMONSTRAÇÃO com dados inventados (nada da oficina real).
//   DB_PATH=./data/demo.db node scripts/demo.js && DB_PATH=./data/demo.db npm start
import { abrirBanco } from '../src/db.js';
import { hoje, somarDias, somarMeses, mesDe } from '../src/util.js';
import { montarLinhaDigitavel } from '../src/boleto.js';
import { importarNotaXml, alocar, criarNotaManual } from '../src/compras.js';
import { criarEmpresa, criarAdiantamentoManual } from '../src/grupo.js';
import { importarDda } from '../src/dda.js';
import { reatribuirEmpresas, pagarBoleto } from '../src/boletos.js';
import { cnabDda } from '../test/helpers/cnab.js';
import { interpretarBoleto } from '../src/boleto.js';
import { xmlNfe, chave as chaveNfe } from '../test/helpers/nfe.js';
import { criarBoleto } from '../src/boletos.js';
import { lerConfig, gravarConfig } from '../src/db.js';

const db = abrirBanco();
if (db.prepare('SELECT COUNT(*) AS n FROM vendas').get().n > 0) {
  console.error('Este banco já tem dados. Use outro DB_PATH para a demonstração.');
  process.exit(1);
}
let semente = 42;
const rnd = () => { semente = (semente * 1664525 + 1013904223) % 4294967296; return semente / 4294967296; };
const h = hoje();
const mecs = ['CARLOS', 'DANIEL'].map((n) => Number(db.prepare('INSERT INTO mecanicos (nome) VALUES (?)').run(n).lastInsertRowid));
const novoCli = (nome, tipo, prazo, limite) => Number(db.prepare('INSERT INTO clientes (nome, tipo, prazo_dias, limite_credito) VALUES (?, ?, ?, ?)').run(nome, tipo, prazo, limite).lastInsertRowid);
const locadora = novoCli('LOCADORA EXEMPLO', 'locadora', 7, 12000);
const frota = novoCli('EMPRESA EXEMPLO LTDA', 'frota', 15, 8000);
const avulsos = Array.from({ length: 40 }, (_, i) => novoCli(`CLIENTE ${String(i + 1).padStart(2, '0')}`, 'avulso', 0, 0));
const ins = db.prepare(`INSERT INTO vendas (numero, data, cliente_id, veiculo, placa, mecanico_id, situacao, valor_total, valor_mao_obra, custo_pecas, custo_frete, forma_pagamento, origem)
  VALUES (?, ?, ?, ?, ?, ?, 'concluida', ?, ?, ?, 15, ?, 'manual')`);
const rec = db.prepare('INSERT INTO recebimentos (venda_id, data, valor, forma) VALUES (?, ?, ?, ?)');
const carros = ['GOL', 'ONIX', 'KWID', 'MOBI', 'HB20', 'ARGO', 'UNO', 'PALIO'];
let n = 1000;
db.transaction(() => {
  for (let dias = 120; dias >= 0; dias--) {
    const d = somarDias(h, -dias);
    if (['0', '6'].includes(String(new Date(`${d}T12:00:00Z`).getUTCDay()))) continue;
    for (let k = 0; k < 3 + Math.floor(rnd() * 3); k++) {
      const total = Math.round(150 + rnd() ** 2 * 2800);
      const mo = Math.round(total * (0.15 + rnd() * 0.2));
      const custo = Math.round((total - mo) * (0.35 + rnd() * 0.2));
      const cli = rnd() < 0.18 ? locadora : rnd() < 0.08 ? frota : avulsos[Math.floor(rnd() * avulsos.length)];
      const id = Number(ins.run(String(++n), d, cli, carros[Math.floor(rnd() * carros.length)], `DEM${String(n).padStart(4, '0')}`, mecs[Math.floor(rnd() * 2)], total, mo, custo, 'pix').lastInsertRowid);
      const aPrazo = cli === locadora || cli === frota;
      if (!aPrazo || dias > 25) rec.run(id, d, total, 'pix');           // clientes a prazo recentes ficam em aberto
    }
  }
})();
const cat = (nome) => db.prepare('SELECT id FROM categorias WHERE nome LIKE ?').get(`${nome}%`).id;
const rcr = db.prepare('INSERT INTO recorrentes (descricao, categoria_id, valor, dia_vencimento) VALUES (?, ?, ?, ?)');
rcr.run('Salários (demo)', cat('Salários'), 18000, 5);
rcr.run('Aluguel (demo)', cat('Aluguel'), 4000, 10);
rcr.run('Energia e internet (demo)', cat('Energia'), 1500, 15);
rcr.run('Sistema e contador (demo)', cat('Sistema'), 900, 20);
rcr.run('Pró-labore (demo)', cat('Pró-labore'), 5000, 5);
db.prepare("UPDATE config SET valor = ? WHERE chave = 'saldo_caixa_inicial_data'").run(somarDias(h, -60));
db.prepare("UPDATE config SET valor = '8000' WHERE chave = 'saldo_caixa_inicial'").run();
console.log(`Demonstração criada (${mesDe(somarMeses(mesDe(h), -4))} a ${mesDe(h)}). Senha de desenvolvimento: multmec`);

// ---- conferência de compras: fornecedores, notas (XML de teste) e boletos inventados, com alguns problemas de propósito
const CNPJ_DEMO = '11222333000181';
const CNPJ_OFICINA_DEMO = '45723174000110';
gravarConfig(db, { cnpjOficina: CNPJ_OFICINA_DEMO });
const cfg = lerConfig(db);
db.prepare("INSERT INTO fornecedores (nome, cnpj, principal, confirmado_em) VALUES ('DISTRIBUIDORA EXEMPLO LTDA', ?, 1, ?)").run(CNPJ_DEMO, somarDias(h, -30));
db.prepare("INSERT INTO fornecedores (nome) VALUES ('AUTO PEÇAS DO BAIRRO')").run();
const algumasOs = db.prepare("SELECT id, numero FROM vendas WHERE situacao = 'concluida' AND numero IS NOT NULL ORDER BY data DESC LIMIT 4").all();
const xml = (nNF, dias, itens, dups) => xmlNfe({ nNF, emitNome: 'DISTRIBUIDORA EXEMPLO LTDA', emitCnpj: CNPJ_DEMO, destCnpj: CNPJ_OFICINA_DEMO, dhEmi: `${somarDias(h, -dias)}T10:00:00-03:00`,
  itens, dups: dups.map(([d, v]) => ({ venc: somarDias(h, d), valor: v })) });
const nota = (...args) => importarNotaXml(db, xml(...args)).nota_id;
const n1 = nota(18231, 12, [{ cProd: 'PF-0312', xProd: 'PASTILHA DE FREIO DIANTEIRA', q: 2, vProd: 900, xPed: `OS${algumasOs[0]?.numero ?? ''}`.slice(0, 15) }, { cProd: 'DF-0087', xProd: 'DISCO DE FREIO DIANTEIRO', q: 2, vProd: 580 }], [[3, 740], [18, 740]]);
const n2 = nota(18302, 6, [{ cProd: 'AM-2210', xProd: 'AMORTECEDOR DIANTEIRO', q: 2, vProd: 612.9 }], [[24, 612.9]]);
const n3 = nota(18377, 9, [{ cProd: 'FO-0101', xProd: 'FILTRO DE OLEO', q: 10, vProd: 345 }], []);
const linha = (valor, dias, banco = '341') => montarLinhaDigitavel({ banco, valor, vencimento: somarDias(h, dias), campoLivre: Math.floor(rnd() * 1e9) });
const comum = { fornecedor_id: 1, pagador_cnpj: CNPJ_OFICINA_DEMO };
criarBoleto(db, { ...comum, linha: linha(740, 3), beneficiario_cnpj: CNPJ_DEMO }, h, cfg);                 // bate com a 1ª parcela da nota 18231
criarBoleto(db, { ...comum, linha: linha(612.9, 24), beneficiario_cnpj: CNPJ_DEMO }, h, cfg);              // bate com a nota 18302
criarBoleto(db, { fornecedor_id: 1, linha: linha(1290.75, 5) }, h, cfg);                                    // boleto sem nenhuma nota, sem quem recebe
criarBoleto(db, { ...comum, linha: linha(345, 4), beneficiario_cnpj: '27865757000102' }, h, cfg);          // valor bate com a nota 18377, mas quem recebe é outro CNPJ
const item = db.prepare('SELECT id FROM nota_itens WHERE nota_id = ? ORDER BY n_item').get(n1);
if (algumasOs[0]) alocar(db, item.id, { destino: 'os', vendaId: algumasOs[0].id, quantidade: 1 });
console.log(`Compras de demonstração: notas ${n1}, ${n2}, ${n3}; 4 boletos (2 conferem, 1 sem nota e sem recebedor, 1 com recebedor trocado).`);

// ---- sócios e locadora: empresas do grupo, boleto no CNPJ da locadora pago pela oficina, nota sem XML e DDA do banco
const CNPJ_LOCADORA_DEMO = '45997418000153';
const CNPJ_MATEUS_DEMO = '33344455000183';
const CNPJ_BAIRRO_DEMO = '55667788000186';
const locadoraGrupo = criarEmpresa(db, { nome: 'IZICAR LOCADORA (demo)', cnpj: CNPJ_LOCADORA_DEMO, papel: 'locadora' }, cfg);
const mateus = criarEmpresa(db, { nome: 'OFICINA DO MATEUS (demo)', cnpj: CNPJ_MATEUS_DEMO, papel: 'socio' }, cfg);
// nota e boleto no CNPJ da locadora; a oficina pagou e fica a receber
const nLoc = importarNotaXml(db, xmlNfe({ nNF: 18410, emitNome: 'DISTRIBUIDORA EXEMPLO LTDA', emitCnpj: CNPJ_DEMO, destCnpj: CNPJ_LOCADORA_DEMO, dhEmi: `${somarDias(h, -50)}T10:00:00-03:00`,
  itens: [{ cProd: 'PN-0400', xProd: 'PNEU 185/65 R15', q: 4, vProd: 1480 }], dups: [{ venc: somarDias(h, -45), valor: 1480 }] })).nota_id;
const bLoc = criarBoleto(db, { fornecedor_id: 1, linha: montarLinhaDigitavel({ banco: '341', valor: 1480, vencimento: somarDias(h, -45), campoLivre: 71234567 }), beneficiario_cnpj: CNPJ_DEMO, pagador_cnpj: CNPJ_LOCADORA_DEMO }, h, lerConfig(db));
pagarBoleto(db, bLoc.boleto_id, { data: somarDias(h, -44), conferiuBanco: true, pagoPor: 'oficina' }, h, lerConfig(db));
criarAdiantamentoManual(db, { empresa_id: mateus.id, sentido: 'a_receber', valor: 650, data: somarDias(h, -12), descricao: 'Peças entregues para a oficina do Mateus (pastilhas)', caixa: false }, h);
reatribuirEmpresas(db);
// nota sem XML (fornecedor pequeno): chave da DANFE, boleto ligado, ainda sem consulta no portal
const chaveBairro = chaveNfe({ nNF: 5521, cnpj: CNPJ_BAIRRO_DEMO, aamm: somarDias(h, -4).slice(2, 4) + somarDias(h, -4).slice(5, 7), serie: 1 });
criarNotaManual(db, { fornecedor_nome: 'AUTO PEÇAS DO BAIRRO', cnpj_emitente: CNPJ_BAIRRO_DEMO, numero: '5521', serie: '1', data_emissao: somarDias(h, -4), valor_total: 268.4, chave: chaveBairro, cnpj_destinatario: CNPJ_OFICINA_DEMO, duplicatas: [{ vencimento: somarDias(h, 10), valor: 268.4 }] });
const bBairro = criarBoleto(db, { fornecedor_nome: 'AUTO PEÇAS DO BAIRRO', linha: montarLinhaDigitavel({ banco: '748', valor: 268.4, vencimento: somarDias(h, 10), campoLivre: 4455667 }), beneficiario_cnpj: CNPJ_BAIRRO_DEMO, pagador_cnpj: CNPJ_OFICINA_DEMO }, h, lerConfig(db));
// DDA do banco: dois boletos batem, um aparece e ninguém cadastrou; o boleto sem nota não aparece
const barras = (v, dias, campoLivre, banco = '341') => interpretarBoleto(montarLinhaDigitavel({ banco, valor: v, vencimento: somarDias(h, dias), campoLivre }), h).codigoBarras;
const bol = db.prepare('SELECT codigo_barras, valor, vencimento FROM boletos WHERE id IN (1, 2, 5) ORDER BY id').all();
const dd = (iso) => iso.split('-').reverse().join('');
importarDda(db, { arquivo: 'dda_demo.rem', conteudo: cnabDda({ cnpjEmpresa: CNPJ_OFICINA_DEMO, geracao: dd(h), titulos: [
  ...bol.filter((b) => b.codigo_barras).slice(0, 2).map((b) => ({ barras: b.codigo_barras, cnpjCedente: CNPJ_DEMO, nomeCedente: 'DISTRIBUIDORA EXEMPLO LTDA', vencimento: dd(b.vencimento), valor: b.valor, cnpjSacado: CNPJ_OFICINA_DEMO })),
  { barras: barras(999.9, 6, 33445566), cnpjCedente: '27865757000102', nomeCedente: 'PECAS DO FULANO ME', vencimento: dd(somarDias(h, 6)), valor: 999.9, documento: '000931', cnpjSacado: CNPJ_OFICINA_DEMO },
] }) }, h, lerConfig(db));
console.log(`Sócios/locadora de demonstração: ${locadoraGrupo.nome}, ${mateus.nome}; nota sem XML e DDA importado (boleto ${bBairro.boleto_id}, nota da locadora ${nLoc}).`);
