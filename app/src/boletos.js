// Boletos de fornecedor: cadastro, conciliação com notas (automática e manual) e pagamento com trava.
import { interpretarBoleto } from './boleto.js';
import { normalizarCnpj, cnpjValido } from './documentos.js';
import { r2, somarDias, diasEntre } from './util.js';
import { ErroValidacao } from './validar.js';
import { garantirFornecedor } from './compras.js';
import { ocorrenciasDoBoleto } from './auditoria.js';
import { lerConfig } from './db.js';

export class ErroBloqueio extends Error {
  constructor(msg, ocorrencias) { super(msg); this.ocorrencias = ocorrencias; }
}

const tolerancia = (cfg) => cfg.toleranciaValor ?? 0.05;

// ------------------------------------------------------------------ saldos

export function duplicatasComSaldo(db, notaId) {
  return db.prepare(`SELECT d.*, d.valor - COALESCE((SELECT SUM(c.valor) FROM conciliacoes c WHERE c.duplicata_id = d.id), 0) AS saldo
      FROM nota_duplicatas d WHERE d.nota_id = ? ORDER BY d.vencimento`).all(notaId);
}

function notasCandidatas(db, boleto) {
  let filtro = 'n.finalidade NOT IN (\'devolucao\',\'ajuste\') AND n.situacao = \'ativa\'';
  const p = { venc: boleto.vencimento, de: somarDias(boleto.vencimento, -180), ate: somarDias(boleto.vencimento, 7) };
  let fornecedorId = boleto.fornecedor_id;
  if (!fornecedorId && boleto.beneficiario_cnpj) {
    fornecedorId = db.prepare('SELECT id FROM fornecedores WHERE cnpj = ?').get(normalizarCnpj(boleto.beneficiario_cnpj))?.id ?? null;
  }
  if (fornecedorId) { filtro += ' AND n.fornecedor_id = @forn'; p.forn = fornecedorId; }
  const linhas = db.prepare(`SELECT n.id, n.numero, n.data_emissao, n.valor_total, n.valor_com_tributos, n.fornecedor_id,
      COALESCE((SELECT SUM(c.valor) FROM conciliacoes c WHERE c.nota_id = n.id), 0) AS conciliado
      FROM notas_compra n WHERE ${filtro} AND n.data_emissao >= @de AND n.data_emissao <= @ate ORDER BY n.data_emissao DESC`).all(p);
  return linhas.map((n) => ({ ...n, aberto: r2(n.valor_total - n.conciliado), abertoAlt: n.valor_com_tributos ? r2(n.valor_com_tributos - n.conciliado) : null }))
    .filter((n) => n.aberto > 0.004).map((n) => ({ ...n, duplicatas: duplicatasComSaldo(db, n.id).filter((d) => d.saldo > 0.004) }));
}

const digitosDoc = (t) => [...String(t ?? '').matchAll(/\d+/g)].map((m) => String(Number(m[0])));

/** Combinações de 2 a 5 notas cujo saldo em aberto soma o valor do boleto (boleto agrupado/fatura). */
function combinacoes(notas, alvoCentavos, tolCentavos) {
  const base = notas.slice(0, 14).map((n) => ({ n, c: Math.round(n.aberto * 100) }));
  const achadas = [];
  const dfs = (inicio, escolhidas, soma) => {
    if (achadas.length >= 4) return;
    if (escolhidas.length >= 2 && Math.abs(soma - alvoCentavos) <= tolCentavos) { achadas.push(escolhidas.map((e) => e.n)); return; }
    if (escolhidas.length >= 5 || soma > alvoCentavos + tolCentavos) return;
    for (let i = inicio; i < base.length; i++) dfs(i + 1, [...escolhidas, base[i]], soma + base[i].c);
  };
  dfs(0, [], 0);
  return achadas;
}

/**
 * Candidatas de nota para um boleto, da mais para a menos provável.
 * 100: parcela (duplicata) com o mesmo valor e vencimento · 95: nº do documento do boleto bate com o da nota e o valor também
 * 85: mesmo valor de parcela, outro vencimento · 80: nota sem parcelas, saldo igual ao boleto · 75: nº bate, valor diferente · 60: soma de notas
 */
export function sugerirNotas(db, boleto, cfg = lerConfig(db)) {
  const tol = tolerancia(cfg);
  const cands = notasCandidatas(db, boleto);
  const docs = digitosDoc(boleto.numero_documento);
  const sug = new Map();
  const add = (notas, score, motivo, extra = {}) => {
    const chave = notas.map((n) => n.nota_id ?? n.id).sort().join('+');
    const atual = sug.get(chave);
    if (!atual || atual.score < score) sug.set(chave, { score, motivo, itens: notas.map((n) => ({ nota_id: n.nota_id ?? n.id, numero: n.numero, valor: n.valor ?? n.aberto, duplicata_id: n.duplicata_id ?? null })), ...extra });
  };
  for (const n of cands) {
    const numeroConfere = docs.includes(String(Number(n.numero))) && String(Number(n.numero)).length >= 3;
    for (const d of n.duplicatas) {
      const valorIgual = Math.abs(d.saldo - boleto.valor) <= tol;
      if (valorIgual && d.vencimento === boleto.vencimento) add([{ nota_id: n.id, numero: n.numero, valor: boleto.valor, duplicata_id: d.id }], numeroConfere ? 100 : 100, 'parcela da nota com o mesmo valor e vencimento');
      else if (valorIgual && numeroConfere) add([{ nota_id: n.id, numero: n.numero, valor: boleto.valor, duplicata_id: d.id }], 95, 'número do documento e valor batem; vencimento diferente da parcela');
      else if (valorIgual) add([{ nota_id: n.id, numero: n.numero, valor: boleto.valor, duplicata_id: d.id }], 85, 'valor igual ao de uma parcela, mas com outro vencimento');
    }
    if (n.duplicatas.length > 1 && Math.abs(n.aberto - boleto.valor) <= tol) {
      add([{ nota_id: n.id, numero: n.numero, valor: boleto.valor }], numeroConfere ? 90 : 70, 'o boleto cobre o saldo da nota inteira (todas as parcelas juntas)');
    }
    const valorComTributos = n.abertoAlt !== null && Math.abs(n.abertoAlt - boleto.valor) <= tol;
    if (!n.duplicatas.length && (Math.abs(n.aberto - boleto.valor) <= tol || valorComTributos)) {
      add([{ nota_id: n.id, numero: n.numero, valor: boleto.valor }], numeroConfere ? 95 : 80, numeroConfere ? 'número do documento e valor batem com a nota'
        : valorComTributos ? 'valor igual ao total da nota com tributos por fora (sem parcelas informadas)' : 'valor igual ao saldo da nota (sem parcelas informadas)');
    } else if (numeroConfere && !n.duplicatas.some((d) => Math.abs(d.saldo - boleto.valor) <= tol)) {
      const valor = Math.min(boleto.valor, n.aberto);
      add([{ nota_id: n.id, numero: n.numero, valor }], 75, 'o número do documento bate com a nota, mas o VALOR do boleto é diferente do esperado', { valorDiverge: true });
    }
  }
  if (!sug.size || [...sug.values()].every((s) => s.score < 90)) {
    for (const combo of combinacoes(cands, Math.round(boleto.valor * 100), Math.round(tol * 100))) {
      add(combo.map((n) => ({ nota_id: n.id, numero: n.numero, valor: n.aberto })), 60, `soma de ${combo.length} notas do mesmo fornecedor (boleto agrupado)`, { agrupado: true });
    }
  }
  return [...sug.values()].sort((a, b) => b.score - a.score);
}

// ------------------------------------------------------------------ conciliação

export function conciliar(db, boletoId, itens, origem = 'manual') {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (!itens?.length) throw new ErroValidacao('Escolha ao menos uma nota.');
  const ins = db.prepare(`INSERT INTO conciliacoes (boleto_id, nota_id, duplicata_id, valor, origem) VALUES (?, ?, ?, ?, ?)
      ON CONFLICT(boleto_id, nota_id) DO UPDATE SET valor = excluded.valor, duplicata_id = excluded.duplicata_id, origem = excluded.origem`);
  db.transaction(() => {
    for (const it of itens) {
      const nota = db.prepare('SELECT id, situacao FROM notas_compra WHERE id = ?').get(it.nota_id);
      if (!nota) throw new ErroValidacao('Nota não encontrada.');
      const valor = r2(Number(it.valor));
      if (!(valor > 0)) throw new ErroValidacao('O valor ligado a cada nota precisa ser maior que zero.');
      ins.run(boletoId, it.nota_id, it.duplicata_id ?? null, valor, origem);
    }
  })();
  return true;
}

export function desconciliar(db, boletoId, notaId) {
  return db.prepare('DELETE FROM conciliacoes WHERE boleto_id = ? AND nota_id = ?').run(boletoId, notaId).changes > 0;
}

/** Liga sozinho quando só existe uma explicação forte (nota 90+ e sem concorrente próximo). */
export function conciliarAutomatico(db, boletoId, cfg = lerConfig(db)) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  const sug = sugerirNotas(db, b, cfg);
  const melhor = sug[0];
  const segundo = sug[1];
  if (melhor && melhor.score >= 90 && (!segundo || segundo.score <= melhor.score - 10)) {
    conciliar(db, boletoId, melhor.itens, 'auto');
    return { ligado: true, sugestao: melhor, outras: sug.slice(1) };
  }
  return { ligado: false, sugestoes: sug };
}

// ------------------------------------------------------------------ cadastro de boleto

const CATEGORIA_FORNECEDORES = 'Peças e insumos (fornecedores)';

export function criarBoleto(db, d, hojeStr, cfg = lerConfig(db)) {
  let leitura = null;
  const textoLinha = String(d.linha ?? '').trim();
  if (textoLinha) {
    leitura = interpretarBoleto(textoLinha, hojeStr);
    if (!leitura.ok) throw new ErroValidacao(leitura.erros.join(' '));
  }
  const valorDigitado = d.valor === undefined || d.valor === null || d.valor === '' ? null : r2(Number(d.valor));
  let valor = leitura?.valor && leitura.valor > 0 ? leitura.valor : valorDigitado;
  if (leitura?.valor > 0 && valorDigitado !== null && Math.abs(valorDigitado - leitura.valor) > 0.004) {
    throw new ErroValidacao(`O valor digitado (${valorDigitado.toFixed(2)}) é diferente do valor que está na linha digitável (${leitura.valor.toFixed(2)}). Confira o que está escrito no boleto.`);
  }
  if (!(valor > 0)) throw new ErroValidacao('Informe o valor do boleto.');
  const vencDigitado = d.vencimento || null;
  let vencimento = leitura?.vencimento ?? vencDigitado;
  if (leitura?.vencimento && vencDigitado && leitura.vencimento !== vencDigitado) {
    throw new ErroValidacao(`O vencimento digitado (${vencDigitado}) é diferente do vencimento que está na linha digitável (${leitura.vencimento}). Confira o boleto.`);
  }
  if (!vencimento) throw new ErroValidacao('Informe o vencimento do boleto.');

  if (leitura?.codigoBarras) {
    const ja = db.prepare('SELECT id, situacao FROM boletos WHERE codigo_barras = ?').get(leitura.codigoBarras);
    if (ja) throw Object.assign(new ErroValidacao(`Este boleto já está cadastrado (nº ${ja.id}, ${ja.situacao}). Não pague duas vezes.`), { boleto_id: ja.id });
  }
  const benefCnpj = normalizarCnpj(d.beneficiario_cnpj) || null;
  if (benefCnpj && !cnpjValido(benefCnpj)) throw new ErroValidacao('O CNPJ do beneficiário não passa na validação (confira os números).');
  const pagadorCnpj = normalizarCnpj(d.pagador_cnpj) || null;
  if (pagadorCnpj && !cnpjValido(pagadorCnpj)) throw new ErroValidacao('O CNPJ do pagador não passa na validação (confira os números).');

  let fornecedor = d.fornecedor_id ? db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(d.fornecedor_id) : null;
  if (d.fornecedor_id && !fornecedor) throw new ErroValidacao('Fornecedor não encontrado.');
  if (!fornecedor && d.fornecedor_nome) {
    // o CNPJ digitado no boleto NUNCA muda um fornecedor que já existe (senão um boleto trocado "viraria" o fornecedor);
    // só serve de CNPJ quando o fornecedor é novo.
    const nomeUp = String(d.fornecedor_nome).replace(/\s+/g, ' ').trim().toUpperCase();
    fornecedor = db.prepare('SELECT * FROM fornecedores WHERE nome = ?').get(nomeUp) ?? garantirFornecedor(db, { nome: d.fornecedor_nome, cnpj: benefCnpj });
  }
  if (!fornecedor && benefCnpj) fornecedor = db.prepare('SELECT * FROM fornecedores WHERE cnpj = ?').get(benefCnpj) ?? null;

  let boletoId;
  db.transaction(() => {
    const cat = db.prepare('SELECT id FROM categorias WHERE nome = ?').get(CATEGORIA_FORNECEDORES);
    const saidaId = Number(db.prepare('INSERT INTO saidas (descricao, categoria_id, fornecedor, valor, vencimento, obs) VALUES (?, ?, ?, ?, ?, ?)').run(
      `Boleto ${fornecedor?.nome ?? 'sem fornecedor'}${d.numero_documento ? ` doc ${String(d.numero_documento).slice(0, 20)}` : ''}`,
      cat.id, fornecedor?.nome ?? null, valor, vencimento, 'criado pela tela de Compras').lastInsertRowid);
    boletoId = Number(db.prepare(`INSERT INTO boletos (fornecedor_id, codigo_barras, linha_digitavel, banco, valor, vencimento, numero_documento,
        beneficiario_nome, beneficiario_cnpj, pagador_cnpj, saida_id, obs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(
      fornecedor?.id ?? null, leitura?.codigoBarras ?? null, leitura?.linhaDigitavel ?? null, leitura?.banco ?? null, valor, vencimento,
      d.numero_documento ? String(d.numero_documento).trim().slice(0, 40) : null, d.beneficiario_nome ? String(d.beneficiario_nome).trim().slice(0, 80) : null,
      benefCnpj, pagadorCnpj, saidaId, d.obs ? String(d.obs).slice(0, 300) : null).lastInsertRowid);
  })();
  const auto = conciliarAutomatico(db, boletoId, cfg);
  return { boleto_id: boletoId, leitura, auto, ocorrencias: ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg) };
}

// ------------------------------------------------------------------ pagamento com trava

/** Marca o boleto (e a conta a pagar ligada) como pago. Ocorrência grave exige "pagar mesmo assim" com motivo. */
export function pagarBoleto(db, boletoId, { data, valor = null, aprovar = false, motivo = null }, hojeStr, cfg = lerConfig(db)) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (b.situacao === 'pago') throw new ErroValidacao('Este boleto já foi pago.');
  if (b.situacao === 'cancelado') throw new ErroValidacao('Este boleto está cancelado.');
  const graves = ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg).filter((o) => o.severidade === 'alta' && !o.aceita);
  if (graves.length && !(aprovar && String(motivo ?? '').trim().length >= 5)) {
    throw new ErroBloqueio('Há problemas sérios neste boleto. Resolva ou libere o pagamento informando o motivo.', graves);
  }
  const pagoEm = data || hojeStr;
  db.transaction(() => {
    db.prepare("UPDATE boletos SET situacao = 'pago', aprovado_motivo = ?, aprovado_em = ? WHERE id = ?")
      .run(graves.length ? String(motivo).trim().slice(0, 300) : null, graves.length ? hojeStr : null, boletoId);
    if (b.saida_id) db.prepare('UPDATE saidas SET pago_em = ?, valor_pago = ? WHERE id = ?').run(pagoEm, valor ?? b.valor, b.saida_id);
  })();
  return { ok: true, liberado_com_ressalva: graves.length > 0 };
}

export function cancelarBoleto(db, boletoId, situacao = 'cancelado', motivo = null) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (b.situacao === 'pago') throw new ErroValidacao('Boleto já pago: não dá para cancelar.');
  db.transaction(() => {
    db.prepare('UPDATE boletos SET situacao = ?, obs = COALESCE(?, obs) WHERE id = ?').run(situacao, motivo ? String(motivo).trim().slice(0, 300) : null, boletoId);
    if (b.saida_id) db.prepare('DELETE FROM saidas WHERE id = ? AND pago_em IS NULL').run(b.saida_id);
  })();
}

export { diasEntre };
