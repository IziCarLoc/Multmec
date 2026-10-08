import { randomBytes } from 'node:crypto';
import { abrirBanco } from './db.js';
import { criarApp } from './app.js';

const producao = process.env.NODE_ENV === 'production';
let senha = process.env.APP_PASSWORD;
if (!senha) {
  if (producao) {
    console.error('Defina APP_PASSWORD (a senha de acesso) antes de subir em produção.');
    process.exit(1);
  }
  senha = 'multmec';
  console.warn('APP_PASSWORD não definida: usando a senha de desenvolvimento "multmec". Não use em produção.');
}
if (producao && senha.length < 10) {
  console.error('APP_PASSWORD precisa ter pelo menos 10 caracteres em produção.');
  process.exit(1);
}
// senha de quem só lança notas e boletos (opcional): sem ela, só o dono entra
const senhaLancamento = process.env.APP_PASSWORD_LANCAMENTO || null;
if (senhaLancamento) {
  if (senhaLancamento === senha) {
    console.error('APP_PASSWORD_LANCAMENTO precisa ser diferente de APP_PASSWORD (senão ninguém distingue quem é o dono).');
    process.exit(1);
  }
  if (producao && senhaLancamento.length < 10) {
    console.error('APP_PASSWORD_LANCAMENTO precisa ter pelo menos 10 caracteres em produção.');
    process.exit(1);
  }
}
let segredo = process.env.SESSION_SECRET;
if (!segredo) {
  if (producao) console.warn('SESSION_SECRET não definido: os logins serão derrubados a cada reinício. Defina um valor longo e aleatório.');
  segredo = randomBytes(32).toString('hex');
}

const db = abrirBanco();
const app = criarApp(db, { senha, senhaLancamento, segredo, confiarProxy: process.env.TRUST_PROXY === '1' });
const porta = Number(process.env.PORT || 3000);
app.listen(porta, process.env.HOST || '0.0.0.0', () => console.log(`Multmec financeiro em http://localhost:${porta}`));
