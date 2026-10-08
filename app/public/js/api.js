import { fecharTodasAsJanelas } from './ui.js';
let aoPrecisarLogin = () => {};
// quem está usando: "dono" faz tudo; "lancamento" só cadastra notas e boletos (o servidor é quem barra, a tela só esconde o que não vale)
export const sessao = { perfil: 'dono' };
export const ehDono = () => sessao.perfil === 'dono';
export const definirLogin = (fn) => { aoPrecisarLogin = fn; };

export async function api(metodo, caminho, corpo) {
  const r = await fetch(`/api${caminho}`, {
    method: metodo,
    headers: metodo === 'GET' ? {} : { 'content-type': 'application/json' },
    body: metodo === 'GET' ? undefined : JSON.stringify(corpo ?? {}),
    credentials: 'same-origin',
  });
  if (r.status === 401 && caminho !== '/login') { fecharTodasAsJanelas(); aoPrecisarLogin(); throw new Error('Entre com a senha.'); }
  let json = null;
  try { json = await r.json(); } catch { /* sem corpo */ }
  if (!r.ok) throw Object.assign(new Error(json?.erro || `Erro ${r.status}`), { status: r.status, dados: json });
  return json;
}
export const GET = (c) => api('GET', c);
export const POST = (c, b = {}) => api('POST', c, b);
export const PUT = (c, b = {}) => api('PUT', c, b);
export const DEL = (c) => api('DELETE', c);
