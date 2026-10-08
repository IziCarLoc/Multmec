import express from 'express';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { criarAuth } from './auth.js';
import { criarApi } from './api.js';

const aqui = dirname(fileURLToPath(import.meta.url));

export function criarApp(db, { senha, segredo, agora, confiarProxy = false } = {}) {
  const app = express();
  app.disable('x-powered-by');
  if (confiarProxy) app.set('trust proxy', 1);
  const auth = criarAuth({ senha, segredo, db, agora: agora ? () => agora().getTime() : undefined });

  app.use((req, res, next) => {
    if (req.secure || (confiarProxy && req.headers['x-forwarded-proto'] === 'https')) res.set('Strict-Transport-Security', 'max-age=15552000');
    res.set({
      'X-Content-Type-Options': 'nosniff',
      'X-Frame-Options': 'DENY',
      'Referrer-Policy': 'same-origin',
      'Cache-Control': 'no-store',
      'Content-Security-Policy': "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
    });
    next();
  });
  app.get('/saude', (req, res) => res.json({ ok: true }));
  app.use(express.static(join(aqui, '..', 'public'), { index: 'index.html', maxAge: 0 }));

  // Pedidos que alteram dados só valem com Content-Type: application/json (mesmo sem corpo):
  // um formulário de outro site não consegue enviar esse cabeçalho.
  app.use('/api', (req, res, next) => {
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(req.method) && !String(req.headers['content-type'] || '').toLowerCase().startsWith('application/json')) {
      return res.status(415).json({ erro: 'Use application/json.' });
    }
    next();
  });
  // corpo pequeno antes de saber quem é: um anônimo não pode obrigar o servidor a processar 8 MB
  app.post('/api/login', express.json({ limit: '2kb' }), (req, res) => auth.login(req, res));
  app.post('/api/logout', (req, res) => auth.logout(req, res));
  app.get('/api/sessao', (req, res) => res.json({ logado: auth.estaLogado(req) }));
  app.use('/api', auth.exigir, express.json({ limit: '8mb' }), criarApi(db, { agora }));
  app.use((err, req, res, next) => {          // JSON malformado etc.
    if (err.type === 'entity.parse.failed' || err.type === 'encoding.unsupported' || err.type === 'charset.unsupported') return res.status(400).json({ erro: 'JSON inválido.' });
    if (err.type === 'entity.too.large') return res.status(413).json({ erro: 'Arquivo grande demais.' });
    next(err);
  });
  return app;
}
