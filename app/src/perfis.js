// Perfis de acesso. "dono" faz tudo; "lancamento" (quem cadastra notas e boletos) não paga, não libera nada com problema,
// não confirma fornecedor e não vê Painel, metas, relatórios nem caixa. A senha é por perfil, não por pessoa.
export const PERFIS = ['dono', 'lancamento'];

export const ehDono = (req) => req.perfil === 'dono';

/** Middleware: só o dono passa. Sem perfil na sessão, trata como não-dono (fecha por padrão). */
export function soDono(req, res, next) {
  if (ehDono(req)) return next();
  res.status(403).json({ erro: 'Esta ação é só do dono. Peça para ele fazer ou liberar.' });
}

export class ErroProibido extends Error {}
