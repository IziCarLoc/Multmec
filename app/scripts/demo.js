#!/usr/bin/env node
// Cria um banco de DEMONSTRAÇÃO com dados inventados (nada da oficina real).
//   DB_PATH=./data/demo.db node scripts/demo.js && DB_PATH=./data/demo.db npm start
import { abrirBanco } from '../src/db.js';
import { hoje, somarDias, somarMeses, mesDe } from '../src/util.js';
import { montarLinhaDigitavel } from '../src/boleto.js';
import { criarNotaManual, alocar } from '../src/compras.js';
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

// ---- conferência de compras: fornecedores, notas e boletos inventados, com alguns problemas de propósito
const CNPJ_DEMO = '11222333000181';
gravarConfig(db, { cnpjOficina: '45723174000110' });
const cfg = lerConfig(db);
const forn = Number(db.prepare("INSERT INTO fornecedores (nome, cnpj, principal) VALUES ('DISTRIBUIDORA EXEMPLO LTDA', ?, 1)").run(CNPJ_DEMO).lastInsertRowid);
Number(db.prepare("INSERT INTO fornecedores (nome) VALUES ('AUTO PEÇAS DO BAIRRO')").run().lastInsertRowid);
const nota = (numero, dias, valor, parcelas, extra = {}) => criarNotaManual(db, { fornecedor_id: forn, numero, data_emissao: somarDias(h, -dias), valor_total: valor,
  duplicatas: parcelas.map(([d, v]) => ({ vencimento: somarDias(h, d), valor: v })), ...extra }).nota_id;
const n1 = nota('18231', 12, 1480, [[3, 740], [18, 740]]);
const n2 = nota('18302', 6, 612.9, [[24, 612.9]]);
const n3 = nota('18377', 9, 345, []);
const linha = (valor, dias, banco = '341') => montarLinhaDigitavel({ banco, valor, vencimento: somarDias(h, dias), campoLivre: Math.floor(rnd() * 1e9) });
criarBoleto(db, { linha: linha(740, 3), fornecedor_id: forn, beneficiario_cnpj: CNPJ_DEMO }, h, cfg);               // bate com a 1ª parcela da nota 18231
criarBoleto(db, { linha: linha(612.9, 24), fornecedor_id: forn, beneficiario_cnpj: CNPJ_DEMO }, h, cfg);            // bate com a nota 18302
criarBoleto(db, { linha: linha(1290.75, 5), fornecedor_id: forn }, h, cfg);                                         // boleto sem nenhuma nota
criarBoleto(db, { linha: linha(345, 4), fornecedor_id: forn, beneficiario_cnpj: '27865757000102' }, h, cfg);       // valor bate, mas o beneficiário é outro
const algumasOs = db.prepare("SELECT id FROM vendas WHERE situacao = 'concluida' ORDER BY data DESC LIMIT 4").all().map((x) => x.id);
const item = db.prepare('SELECT id FROM nota_itens WHERE nota_id = ?').get(n1);
alocar(db, item.id, { destino: 'os', vendaId: algumasOs[0], quantidade: 1 });
console.log(`Compras de demonstração: notas ${n1}, ${n2}, ${n3}; 4 boletos (2 conferem, 1 sem nota, 1 com beneficiário trocado).`);
