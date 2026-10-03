// Autenticação mínima: uma senha compartilhada pela diretoria, cookie assinado (HMAC), limite de tentativas.
import { createHmac, randomBytes, timingSafeEqual, createHash } from 'node:crypto';

const DURACAO_MS = 1000 * 60 * 60 * 24 * 14;     // 14 dias
const COOKIE = 'multmec_sid';

const igual = (a, b) => {
  const ha = createHash('sha256').update(String(a)).digest();
  const hb = createHash('sha256').update(String(b)).digest();
  return timingSafeEqual(ha, hb);
};

export function criarAuth({ senha, segredo = randomBytes(32).toString('hex'), agora = () => Date.now() }) {
  const assinar = (payload) => createHmac('sha256', segredo).update(payload).digest('base64url');
  const tentativas = new Map();                    // ip -> { n, ate }

  function emitir() {
    const payload = Buffer.from(JSON.stringify({ exp: agora() + DURACAO_MS })).toString('base64url');
    return `${payload}.${assinar(payload)}`;
  }
  function valido(token) {
    if (!token || !token.includes('.')) return false;
    const [payload, assinatura] = token.split('.');
    const esperado = assinar(payload);
    if (assinatura.length !== esperado.length || !timingSafeEqual(Buffer.from(assinatura), Buffer.from(esperado))) return false;
    try { return JSON.parse(Buffer.from(payload, 'base64url').toString()).exp > agora(); } catch { return false; }
  }
  const lerCookie = (req) => {
    const c = (req.headers.cookie || '').split(';').map((s) => s.trim()).find((s) => s.startsWith(`${COOKIE}=`));
    return c ? decodeURIComponent(c.slice(COOKIE.length + 1)) : null;
  };
  const seguro = (req) => req.secure || req.headers['x-forwarded-proto'] === 'https';

  return {
    exigir(req, res, next) {
      if (valido(lerCookie(req))) return next();
      res.status(401).json({ erro: 'Entre com a senha para continuar.' });
    },
    estaLogado: (req) => valido(lerCookie(req)),
    login(req, res) {
      const ip = req.ip || 'x';
      const t = tentativas.get(ip) || { n: 0, ate: 0 };
      if (t.n >= 5 && agora() < t.ate) return res.status(429).json({ erro: 'Muitas tentativas. Espere alguns minutos.' });
      if (agora() >= t.ate) { t.n = 0; }
      if (!igual(req.body?.senha ?? '', senha)) {
        t.n += 1; t.ate = agora() + 10 * 60 * 1000; tentativas.set(ip, t);
        return res.status(401).json({ erro: 'Senha incorreta.' });
      }
      tentativas.delete(ip);
      const flags = `HttpOnly; SameSite=Lax; Path=/; Max-Age=${DURACAO_MS / 1000}${seguro(req) ? '; Secure' : ''}`;
      res.setHeader('Set-Cookie', `${COOKIE}=${encodeURIComponent(emitir())}; ${flags}`);
      res.json({ ok: true });
    },
    logout(req, res) {
      res.setHeader('Set-Cookie', `${COOKIE}=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0`);
      res.json({ ok: true });
    },
  };
}
