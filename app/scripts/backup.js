#!/usr/bin/env node
// Cópia consistente do banco (mesmo com o sistema rodando) e limpeza de cópias antigas.
//   node scripts/backup.js            -> grava em $BACKUP_DIR (padrão: ./data/backups)
// Agende todo dia (cron) e leve a pasta para FORA do servidor (Google Drive, outro disco...).
import { mkdirSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import Database from 'better-sqlite3';

const origem = process.env.DB_PATH || join(process.cwd(), 'data', 'multmec.db');
const pasta = process.env.BACKUP_DIR || join(process.cwd(), 'data', 'backups');
const manterDias = Number(process.env.BACKUP_DIAS || 30);
mkdirSync(pasta, { recursive: true });

const carimbo = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 16);
const destino = join(pasta, `multmec-${carimbo}.db`);
const db = new Database(origem, { readonly: true, fileMustExist: true });
await db.backup(destino);
db.close();
console.log(`Backup criado: ${destino}`);

const limite = Date.now() - manterDias * 86400000;
for (const nome of readdirSync(pasta)) {
  if (!/^multmec-.*\.db$/.test(nome)) continue;
  const caminho = join(pasta, nome);
  if (statSync(caminho).mtimeMs < limite) { unlinkSync(caminho); console.log(`Removido (antigo): ${nome}`); }
}
