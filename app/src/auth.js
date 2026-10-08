// Autenticação mínima: uma senha para o dono e, se quiser, outra para quem só lança notas e boletos (perfil "lancamento"),
// cookie assinado (HMAC) com sessão guardada no banco (o "sair" revoga de verdade) e limite de tentativas.
// O perfil mora na sessão do banco, não no cookie: quem mexer no cookie não vira dono.
import { createHmac, randomBytes, timingSafeEqual, createHash } from 'node:crypto';

const DURACAO_MS = 1000 * 60 * 60 * 24 * 14;     // 14 dias
const COOKIE = 'multmec_sid';

const igual = (a, b) => {
  const ha = createHash('sha256').update(String(a)).digest();
  const hb = createHash('sha256').update(String(b)).digest();
  return timingSafeEqual(ha, hb);
};

export function criarAuth({ senha, senhaLancamento = null, segredo = randomBytes(32).toString('hex'), agora = () => Date.now(), db = null }) {
  const assinar = (payload) => createHmac('sha256', segredo).update(payload).digest('base64url');
  const tentativas = new Map();                    // ip -> { n, ate }
  const memoria = new Map();                       // sem banco (testes): sid -> { exp, perfil }
  if (db) {
    db.exec("CREATE TABLE IF NOT EXISTS sessoes (sid TEXT PRIMARY KEY, exp INTEGER NOT NULL, perfil TEXT NOT NULL DEFAULT 'dono')");
    // sessões de antes dos perfis (uma senha só) eram do dono
    if (!db.prepare('PRAGMA table_info(sessoes)').all().some((c) => c.name === 'perfil')) db.exec("ALTER TABLE sessoes ADD COLUMN perfil TEXT NOT NULL DEFAULT 'dono'");
  }

  const guardar = (sid, exp, perfil) => {
    if (db) {
      db.prepare('DELETE FROM sessoes WHERE exp < ?').run(agora());
      db.prepare('INSERT INTO sessoes (sid, exp, perfil) VALUES (?, ?, ?)').run(sid, exp, perfil);
    } else memoria.set(sid, { exp, perfil });
  };
  const buscar = (sid) => {
    const s = db ? db.prepare('SELECT exp, perfil FROM sessoes WHERE sid = ?').get(sid) : memoria.get(sid);
    return s && s.exp > agora() ? s : null;
  };
  const apagar = (sid) => { if (db) db.prepare('DELETE FROM sessoes WHERE sid = ?').run(sid); else memoria.delete(sid); };

  function emitir(perfil) {
    const sid = randomBytes(18).toString('base64url');
    const exp = agora() + DURACAO_MS;
    guardar(sid, exp, perfil);
    const payload = Buffer.from(JSON.stringify({ exp, sid })).toString('base64url');
    return `${payload}.${assinar(payload)}`;
  }
  function sessaoDe(token) {
    try {
      if (!token || !token.includes('.')) return null;
      const [payload, assinatura] = token.split('.');
      const esperado = Buffer.from(assinar(payload));
      const recebido = Buffer.from(assinatura);
      if (recebido.length !== esperado.length || !timingSafeEqual(recebido, esperado)) return null;
      const dados = JSON.parse(Buffer.from(payload, 'base64url').toString());
      if (!(dados.exp > agora()) || typeof dados.sid !== 'string') return null;
      const s = buscar(dados.sid);
      return s ? { sid: dados.sid, perfil: s.perfil === 'lancamento' ? 'lancamento' : 'dono' } : null;
    } catch { return null; }
  }
  const lerCookie = (req) => {
    const c = (req.headers.cookie || '').split(';').map((s) => s.trim()).find((s) => s.startsWith(`${COOKIE}=`));
    if (!c) return null;
    try { return decodeURIComponent(c.slice(COOKIE.length + 1)); } catch { return null; }
  };
  const seguro = (req) => req.secure || req.headers['x-forwarded-proto'] === 'https';

  return {
    exigir(req, res, next) {
      const s = sessaoDe(lerCookie(req));
      if (!s) return res.status(401).json({ erro: 'Entre com a senha para continuar.' });
      req.perfil = s.perfil;
      next();
    },
    estaLogado: (req) => !!sessaoDe(lerCookie(req)),
    perfilDe: (req) => sessaoDe(lerCookie(req))?.perfil ?? null,
    login(req, res) {
      const ip = req.ip || 'x';
      for (const [chave, t] of tentativas) if (agora() >= t.ate) tentativas.delete(chave);      // não deixa a lista crescer
      const t = tentativas.get(ip) || { n: 0, ate: 0 };
      if (t.n >= 5 && agora() < t.ate) return res.status(429).json({ erro: 'Muitas tentativas. Espere alguns minutos.' });
      // só tentativa de senha de verdade conta: corpo lixo não tranca o dono para fora
      if (typeof req.body?.senha !== 'string') return res.status(400).json({ erro: 'Informe a senha.' });
      // as duas senhas são sempre comparadas (mesmo tempo para qualquer resposta); se forem iguais, vale o dono
      const ehDono = igual(req.body.senha, senha);
      const ehLancamento = !!senhaLancamento && igual(req.body.senha, senhaLancamento);
      if (!ehDono && !ehLancamento) {
        t.n += 1; t.ate = agora() + 10 * 60 * 1000; tentativas.set(ip, t);
        return res.status(401).json({ erro: 'Senha incorreta.' });
      }
      tentativas.delete(ip);
      const perfil = ehDono ? 'dono' : 'lancamento';
      const flags = `HttpOnly; SameSite=Lax; Path=/; Max-Age=${DURACAO_MS / 1000}${seguro(req) ? '; Secure' : ''}`;
      res.setHeader('Set-Cookie', `${COOKIE}=${encodeURIComponent(emitir(perfil))}; ${flags}`);
      res.json({ ok: true, perfil });
    },
    logout(req, res) {
      try {
        const token = lerCookie(req);
        const s = sessaoDe(token);
        if (s) apagar(s.sid);
      } catch { /* cookie ilegível: só limpa */ }
      res.setHeader('Set-Cookie', `${COOKIE}=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0`);
      res.json({ ok: true });
    },
  };
}
