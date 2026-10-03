// Importa a aba SERVIÇOS da planilha CONTROLE SERVIÇOS (exportada como CSV).
// Regras de limpeza espelham analise/servicos.py, mas em passada única na ordem da planilha:
// a planilha é cronológica por blocos, então a data de cada linha é conferida contra as vizinhas.
import { lerCsv, lerValor, normalizarPlaca, r2, dataValida } from './util.js';

const LOCADORA = new Set(['IZI', 'IZICAR', 'IZICR', 'IZI CAR']);
const IDX = { os: 0, data: 1, veiculo: 2, placa: 3, operador: 4, custoPecas: 5, maoObra: 6, insumos: 7, frete: 8, total: 9, cliente: 11, obs: 14, status: 15, locadora: 16 };

function lerData(txt) {
  let s = String(txt || '').trim();
  s = s.replace(/^0(\d{2}\/)/, '$1').replace('/001/', '/01/').replace(/^(\d{1,2})\/(\d{2})(\d{4})$/, '$1/$2/$3');
  let m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (m) {
    let [, d, mo, y] = m.map(Number);
    if (y === 2028) y = 2025;                              // 27/08/2028 digitado errado
    if (y < 2025 || y > 2027) return { sem: true };
    const iso = `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    return dataValida(iso) ? { iso } : { sem: true };
  }
  m = s.match(/^(\d{1,2})\/(\d{1,2})$/);
  if (m) return { dia: Number(m[1]), mes: Number(m[2]) };   // sem ano
  return { sem: true };
}

const ord = (iso) => Math.round(Date.parse(`${iso}T12:00:00Z`) / 86400000);
const deOrd = (n) => new Date(n * 86400000).toISOString().slice(0, 10);

function mediana(xs) {
  const o = [...xs].sort((a, b) => a - b);
  const m = o.length >> 1;
  return o.length % 2 ? o[m] : (o[m - 1] + o[m]) / 2;
}
/** Mediana móvel centrada (como pandas rolling(janela, center=True, min_periods)). */
function medianaMovel(ys, janela, minimo) {
  const meio = janela >> 1;
  return ys.map((_, i) => {
    const w = ys.slice(Math.max(0, i - meio), i + meio + 1);
    return w.length >= minimo ? mediana(w) : null;
  });
}
function interpolar(xs, ys, x) {
  if (x <= xs[0]) return ys[0];
  if (x >= xs[xs.length - 1]) return ys[ys.length - 1];
  let j = 1;
  while (xs[j] < x) j++;
  const t = (x - xs[j - 1]) / (xs[j] - xs[j - 1]);
  return ys[j - 1] + t * (ys[j] - ys[j - 1]);
}

/**
 * A planilha é cronológica por blocos. As datas digitadas servem de âncora; a mediana das
 * vizinhas descarta digitação absurda (ex.: 19/12 no meio de novembro) e as linhas sem data
 * (orçamentos) herdam a posição por interpolação. Linhas corrigidas ficam marcadas como estimadas.
 */
function resolverDatas(candidatas) {
  const idx = candidatas.map((c, i) => (c ? i : -1)).filter((i) => i >= 0);
  const out = candidatas.map(() => ({ iso: null, estimada: 1 }));
  if (idx.length < 5) {
    candidatas.forEach((c, i) => { if (c) out[i] = { iso: c, estimada: 0 }; });
    return out;
  }
  const y = idx.map((i) => ord(candidatas[i]));
  const med = medianaMovel(y, 15, 5);
  const boas = idx.map((_, k) => med[k] !== null && Math.abs(y[k] - med[k]) <= 25);
  const xg = idx.filter((_, k) => boas[k]);
  const yg0 = y.filter((_, k) => boas[k]);
  let yg = medianaMovel(yg0, 5, 1);
  for (let k = 1; k < yg.length; k++) yg[k] = Math.max(yg[k], yg[k - 1]);
  const boaSet = new Set(xg);
  candidatas.forEach((c, i) => {
    const interp = interpolar(xg, yg, i);
    const proprio = c ? ord(c) : null;
    if (proprio !== null && boaSet.has(i) && Math.abs(proprio - interp) <= 25) out[i] = { iso: c, estimada: 0 };
    else out[i] = { iso: deOrd(Math.round(interp)), estimada: 1 };
  });
  return out;
}

function nomeCliente(txt) {
  const s = String(txt || '').replace(/\s+/g, ' ').trim().toUpperCase();
  if (!s) return '(SEM NOME)';
  return LOCADORA.has(s) ? 'IZICAR' : s;
}

function formaDe(texto) {
  const t = texto.toUpperCase();
  if (/C\.?C\b|CART|CRED/.test(t)) return 'cartão';
  if (/PIX/.test(t)) return 'pix';
  if (/BOLETO/.test(t)) return 'boleto';
  if (/DINHEIRO/.test(t)) return 'dinheiro';
  return null;
}

/** Converte o CSV em linhas normalizadas (sem tocar no banco). */
export function lerPlanilhaServicos(texto) {
  const linhas = lerCsv(texto);
  const brutas = [];
  const avisos = [];
  let ultimaCompleta = ord('2025-08-25');
  for (let i = 1; i < linhas.length; i++) {
    const c = linhas[i];
    const numero = (c[IDX.os] || '').trim();
    if (!/^\d{1,4}$/.test(numero)) continue;       // linhas de mês, totais e em branco
    const colData = (c[IDX.data] || '').trim();
    const marcador = colData.toUpperCase();
    const total = lerValor(c[IDX.total]);
    const eOrcamento = /OR[CÇ]AM/.test(marcador);
    if (/PE[CÇ]AS/.test(marcador)) { avisos.push(`OS ${numero}: compra de peças lançada como OS, ignorada.`); continue; }
    if (!eOrcamento && !(total > 0)) continue;     // sem valor: nada a lançar

    const d = lerData(colData);
    let candidata = null;
    if (d.iso) { candidata = d.iso; ultimaCompleta = ord(d.iso); }
    else if (d.dia) {                               // dd/mm sem ano: escolhe o ano mais perto da última data completa
      const cands = [2025, 2026].map((y) => `${y}-${String(d.mes).padStart(2, '0')}-${String(d.dia).padStart(2, '0')}`).filter(dataValida);
      cands.sort((a, b) => Math.abs(ord(a) - ultimaCompleta) - Math.abs(ord(b) - ultimaCompleta));
      candidata = cands[0] || null;
    }
    const obs = (c[IDX.obs] || '').trim();
    const status = (c[IDX.status] || '').trim();
    brutas.push({
      candidata,
      linha: {
        numero,
        situacao: eOrcamento ? 'orcamento' : 'concluida',
        veiculo: (c[IDX.veiculo] || '').trim().toUpperCase() || null,
        placa: normalizarPlaca(c[IDX.placa]) || null,
        mecanico: (c[IDX.operador] || '').trim().toUpperCase().replace(/^\(?SEM\)?$/, '') || null,
        cliente: nomeCliente(c[IDX.cliente]),
        valorTotal: total ?? 0,
        valorMaoObra: lerValor(c[IDX.maoObra]) ?? 0,
        custoPecas: lerValor(c[IDX.custoPecas]),
        custoFrete: lerValor(c[IDX.frete]) ?? 0,
        custoInsumos: lerValor(c[IDX.insumos]) ?? 0,
        forma: formaDe(`${status} ${obs}`),
        recebidoNaPlanilha: /^RECEBIDO$/i.test(status),
        obs: [obs, status && !/^RECEBIDO$/i.test(status) ? status : ''].filter(Boolean).join(' | ') || null,
      },
    });
  }
  const datas = resolverDatas(brutas.map((b) => b.candidata));
  const out = brutas.map((b, i) => ({ ...b.linha, data: datas[i].iso, dataEstimada: datas[i].estimada }));
  return { linhas: out, avisos };
}

/**
 * Grava no banco. `quitadasAte` (YYYY-MM-DD): OS dessa data para trás entram como já recebidas,
 * para o controle começar "zerado" e só valer daqui para frente.
 */
export function importarServicos(db, texto, { quitadasAte = null, atualizar = false } = {}) {
  const { linhas, avisos } = lerPlanilhaServicos(texto);
  const resumo = { lidas: linhas.length, novas: 0, jaExistiam: 0, atualizadas: 0, quitadasPorCorte: 0, orcamentos: 0, avisos, clientesNovos: 0, mecanicosNovos: 0, datasEstimadas: 0 };
  const getCliente = db.prepare('SELECT id FROM clientes WHERE nome = ?');
  const insCliente = db.prepare('INSERT INTO clientes (nome, tipo, prazo_dias, limite_credito) VALUES (?, ?, ?, ?)');
  const getMec = db.prepare('SELECT id FROM mecanicos WHERE nome = ?');
  const insMec = db.prepare('INSERT INTO mecanicos (nome) VALUES (?)');
  const getVenda = db.prepare('SELECT id FROM vendas WHERE chave_import = ?');
  const insVenda = db.prepare(`INSERT INTO vendas (numero, data, cliente_id, veiculo, placa, mecanico_id, situacao, valor_total,
      valor_mao_obra, custo_pecas, custo_frete, custo_insumos, forma_pagamento, data_estimada, origem, chave_import, obs)
      VALUES (@numero, @data, @cliente_id, @veiculo, @placa, @mecanico_id, @situacao, @valor_total, @valor_mao_obra,
      @custo_pecas, @custo_frete, @custo_insumos, @forma_pagamento, @data_estimada, 'planilha', @chave, @obs)`);
  const updVenda = db.prepare(`UPDATE vendas SET data=@data, cliente_id=@cliente_id, veiculo=@veiculo, placa=@placa, mecanico_id=@mecanico_id,
      situacao=@situacao, valor_total=@valor_total, valor_mao_obra=@valor_mao_obra, custo_pecas=@custo_pecas, custo_frete=@custo_frete,
      custo_insumos=@custo_insumos, forma_pagamento=@forma_pagamento, data_estimada=@data_estimada, obs=@obs WHERE id=@id`);
  const insReceb = db.prepare('INSERT INTO recebimentos (venda_id, data, valor, forma, obs) VALUES (?, ?, ?, ?, ?)');
  const chavesVistas = new Map();

  db.transaction(() => {
    for (const l of linhas) {
      let cliente = getCliente.get(l.cliente);
      if (!cliente) {
        const locadora = l.cliente === 'IZICAR';
        const r = insCliente.run(l.cliente, locadora ? 'locadora' : 'avulso', locadora ? 7 : 0, 0);
        cliente = { id: Number(r.lastInsertRowid) }; resumo.clientesNovos++;
      }
      let mecId = null;
      if (l.mecanico) {
        let m = getMec.get(l.mecanico);
        if (!m) { m = { id: Number(insMec.run(l.mecanico).lastInsertRowid) }; resumo.mecanicosNovos++; }
        mecId = m.id;
      }
      let chave = `${l.numero}|${l.placa || ''}|${l.data}`;
      const n = (chavesVistas.get(chave) || 0) + 1; chavesVistas.set(chave, n);
      if (n > 1) chave += `#${n}`;
      const params = {
        numero: l.numero, data: l.data, cliente_id: cliente.id, veiculo: l.veiculo, placa: l.placa, mecanico_id: mecId,
        situacao: l.situacao, valor_total: l.valorTotal, valor_mao_obra: l.valorMaoObra, custo_pecas: l.custoPecas,
        custo_frete: l.custoFrete, custo_insumos: l.custoInsumos, forma_pagamento: l.forma, data_estimada: l.dataEstimada, chave, obs: l.obs,
      };
      const existente = getVenda.get(chave);
      if (existente) {
        if (atualizar) { updVenda.run({ ...params, id: existente.id }); resumo.atualizadas++; } else resumo.jaExistiam++;
        continue;
      }
      const vendaId = Number(insVenda.run(params).lastInsertRowid);
      resumo.novas++;
      if (l.situacao === 'orcamento') { resumo.orcamentos++; continue; }
      if (l.dataEstimada) resumo.datasEstimadas++;
      const quitadaPorCorte = quitadasAte && l.data <= quitadasAte;
      if (l.recebidoNaPlanilha || quitadaPorCorte) {
        insReceb.run(vendaId, l.data, r2(l.valorTotal), 'histórico', quitadaPorCorte && !l.recebidoNaPlanilha ? 'quitada pelo corte da importação' : 'RECEBIDO na planilha');
        if (quitadaPorCorte && !l.recebidoNaPlanilha) resumo.quitadasPorCorte++;
      }
    }
  })();
  return resumo;
}

