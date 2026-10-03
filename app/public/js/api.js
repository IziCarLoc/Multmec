let aoPrecisarLogin = () => {};
export const definirLogin = (fn) => { aoPrecisarLogin = fn; };

export async function api(metodo, caminho, corpo) {
  const r = await fetch(`/api${caminho}`, {
    method: metodo,
    headers: corpo !== undefined ? { 'content-type': 'application/json' } : {},
    body: corpo !== undefined ? JSON.stringify(corpo) : undefined,
    credentials: 'same-origin',
  });
  if (r.status === 401 && caminho !== '/login') { aoPrecisarLogin(); throw new Error('Entre com a senha.'); }
  let json = null;
  try { json = await r.json(); } catch { /* sem corpo */ }
  if (!r.ok) throw new Error(json?.erro || `Erro ${r.status}`);
  return json;
}
export const GET = (c) => api('GET', c);
export const POST = (c, b = {}) => api('POST', c, b);
export const PUT = (c, b = {}) => api('PUT', c, b);
export const DEL = (c) => api('DELETE', c);
