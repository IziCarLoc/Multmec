#!/usr/bin/env node
// Importa a planilha CONTROLE SERVIÇOS (CSV) direto no banco, sem subir o servidor.
//   node scripts/importar.js caminho/servicos.csv --corte 2026-10-01 [--atualizar]
import { readFileSync } from 'node:fs';
import { abrirBanco } from '../src/db.js';
import { importarServicos } from '../src/importar.js';

const args = process.argv.slice(2);
const arquivo = args.find((a) => !a.startsWith('--'));
const i = args.indexOf('--corte');
const corte = i >= 0 ? args[i + 1] : null;
if (!arquivo) {
  console.error('Uso: node scripts/importar.js servicos.csv [--corte AAAA-MM-DD] [--atualizar]');
  process.exit(1);
}
const db = abrirBanco();
const r = importarServicos(db, readFileSync(arquivo, 'utf8'), { quitadasAte: corte, atualizar: args.includes('--atualizar') });
console.log(JSON.stringify(r, null, 2));
