// Boletos de fornecedor: cadastro, conciliação com notas (automática e manual) e pagamento com trava.
import { interpretarBoleto } from './boleto.js';
import { normalizarCnpj, cnpjValido } from './documentos.js';
import { r2, somarDias, diasEntre } from './util.js';
import { ErroValidacao, dinheiro, motivoValido, MOTIVO_MINIMO } from './validar.js';
import { garantirFornecedor, duplicatasComSaldo } from './compras.js';
import { ocorrenciasDoBoleto, ocorrencias } from './auditoria.js';
import { lerConfig } from './db.js';
import { registrar, perfilAtual } from './trilha.js';
import { confirmadoNoDda } from './dda.js';
import { empresaDoCnpj, adiantamentoDeBoleto, removerAdiantamentoDeOrigem } from './grupo.js';

export class ErroBloqueio extends Error {
  constructor(msg, ocorrencias) { super(msg); this.ocorrencias = ocorrencias; }
}

const tolerancia = (cfg) => cfg.toleranciaValor ?? 0.05;

// ------------------------------------------------------------------ saldos

export { duplicatasComSaldo };

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

/**
 * Liga o boleto a notas (ou parcelas). Recusa o que não pode ser verdade: nota de outro fornecedor, nota cancelada,
 * devolução/ajuste, parcela de outra nota, valor maior que o boleto ou que o saldo da nota.
 */
export function conciliar(db, boletoId, itens, origem = 'manual') {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (b.situacao === 'cancelado' || b.situacao === 'contestado') throw new ErroValidacao(`Este boleto está ${b.situacao}. Reabra-o antes de ligar a uma nota.`);
  if (!Array.isArray(itens) || !itens.length) throw new ErroValidacao('Escolha ao menos uma nota.');
  if (itens.some((i) => !i || typeof i !== 'object')) throw new ErroValidacao('Item de ligação inválido.');
  const tol = 0.05;
  const notasDosItens = new Set(itens.map((i) => Number(i.nota_id)));
  const outras = db.prepare('SELECT nota_id, SUM(valor) AS v FROM conciliacoes WHERE boleto_id = ? GROUP BY nota_id').all(boletoId)
    .filter((o) => !notasDosItens.has(o.nota_id)).reduce((a, o) => a + o.v, 0);
  const novo = itens.reduce((a, i) => a + r2(Number(i?.valor)), 0);
  if (!Number.isFinite(novo)) throw new ErroValidacao('O valor ligado a cada nota precisa ser um número.');
  if (outras + novo > b.valor + tol) {
    throw new ErroValidacao(`As notas ligadas somariam R$ ${r2(outras + novo).toFixed(2)}, mais que o boleto (R$ ${b.valor.toFixed(2)}). Confira os valores.`);
  }
  const ins = db.prepare(`INSERT INTO conciliacoes (boleto_id, nota_id, duplicata_id, valor, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?)
      ON CONFLICT(boleto_id, nota_id) DO UPDATE SET valor = excluded.valor, duplicata_id = excluded.duplicata_id, origem = excluded.origem, criado_por = excluded.criado_por`);
  db.transaction(() => {
    let fornecedorDaNota = null;
    for (const it of itens) {
      if (!it || typeof it !== 'object') throw new ErroValidacao('Item de ligação inválido.');
      const nota = db.prepare('SELECT id, numero, fornecedor_id, situacao, finalidade, valor_total, valor_com_tributos FROM notas_compra WHERE id = ?').get(it.nota_id);
      if (!nota) throw new ErroValidacao('Nota não encontrada.');
      if (nota.situacao !== 'ativa') throw new ErroValidacao(`A nota ${nota.numero} está cancelada: não dá para ligar boleto a ela.`);
      if (nota.finalidade === 'devolucao' || nota.finalidade === 'ajuste') throw new ErroValidacao(`A nota ${nota.numero} é de ${nota.finalidade === 'devolucao' ? 'devolução/crédito' : 'ajuste'}: não gera cobrança e não pode ser ligada a boleto.`);
      if (b.fornecedor_id && nota.fornecedor_id !== b.fornecedor_id) throw new ErroValidacao(`A nota ${nota.numero} é de outro fornecedor. Boleto e nota precisam ser do mesmo fornecedor.`);
      const valor = r2(Number(it.valor));
      if (!(valor > 0) || !Number.isFinite(valor)) throw new ErroValidacao('O valor ligado a cada nota precisa ser maior que zero.');
      if (it.duplicata_id) {
        const d = db.prepare('SELECT id FROM nota_duplicatas WHERE id = ? AND nota_id = ?').get(it.duplicata_id, nota.id);
        if (!d) throw new ErroValidacao(`A parcela escolhida não pertence à nota ${nota.numero}.`);
      }
      const jaLigado = db.prepare('SELECT COALESCE(SUM(valor), 0) AS v FROM conciliacoes WHERE nota_id = ? AND boleto_id <> ?').get(nota.id, boletoId).v;
      const limite = Math.max(nota.valor_total, nota.valor_com_tributos ?? 0);
      if (jaLigado + valor > limite + tol) throw new ErroValidacao(`A nota ${nota.numero} só tem R$ ${r2(Math.max(0, limite - jaLigado)).toFixed(2)} sem boleto. Confira o valor ligado.`);
      ins.run(boletoId, nota.id, it.duplicata_id ?? null, valor, origem, perfilAtual());
      fornecedorDaNota ??= nota.fornecedor_id;
    }
    if (!b.fornecedor_id && fornecedorDaNota) db.prepare('UPDATE boletos SET fornecedor_id = ? WHERE id = ?').run(fornecedorDaNota, boletoId);
    registrar(db, 'conciliar', 'boleto', boletoId, { origem, notas: itens.map((i) => i.nota_id), valor: r2(novo) });
  })();
  aplicarEmpresaDoBoleto(db, boletoId);              // sem o pagador impresso, a nota diz de quem é o boleto
  return true;
}

export function desconciliar(db, boletoId, notaId) {
  const b = db.prepare('SELECT situacao FROM boletos WHERE id = ?').get(boletoId);
  if (b?.situacao === 'pago') throw new ErroValidacao('Este boleto já foi pago: a ligação com a nota não pode ser desfeita (ela é a prova do que foi pago).');
  const ok = db.prepare('DELETE FROM conciliacoes WHERE boleto_id = ? AND nota_id = ?').run(boletoId, notaId).changes > 0;
  if (ok) { registrar(db, 'desconciliar', 'boleto', boletoId, { nota: notaId }); aplicarEmpresaDoBoleto(db, boletoId); }
  return ok;
}

/** Liga sozinho quando só existe uma explicação forte (nota 90+ e sem concorrente próximo). */
export function conciliarAutomatico(db, boletoId, cfg = lerConfig(db)) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  const sug = sugerirNotas(db, b, cfg);
  const melhor = sug[0];
  const segundo = sug[1];
  if (melhor && melhor.score >= 90 && (!segundo || segundo.score <= melhor.score - 10)) {
    try {
      conciliar(db, boletoId, melhor.itens, 'auto');
      return { ligado: true, sugestao: melhor, outras: sug.slice(1) };
    } catch (e) {
      if (!(e instanceof ErroValidacao)) throw e;
      return { ligado: false, sugestoes: sug, recusado: e.message };           // a nota não comporta este boleto: fica para ligação manual
    }
  }
  return { ligado: false, sugestoes: sug };
}

// ------------------------------------------------------------------ cadastro de boleto

const CATEGORIA_FORNECEDORES = 'Peças e insumos (fornecedores)';

/** Cria (ou adota) a conta a pagar do boleto. Se já existe uma conta aberta com o mesmo valor e vencimento, usa a mesma: não duplica a dívida. */
function contaDoBoleto(db, { fornecedor, valor, vencimento, numeroDocumento }) {
  const cat = db.prepare('SELECT id FROM categorias WHERE nome = ?').get(CATEGORIA_FORNECEDORES);
  const descricao = `Boleto ${fornecedor?.nome ?? 'sem fornecedor'}${numeroDocumento ? ` doc ${String(numeroDocumento).slice(0, 20)}` : ''}`;
  const iguais = db.prepare(`SELECT s.id FROM saidas s WHERE s.valor = ? AND s.vencimento = ? AND s.pago_em IS NULL AND s.recorrente_id IS NULL
      AND NOT EXISTS (SELECT 1 FROM boletos b WHERE b.saida_id = s.id)`).all(valor, vencimento);
  if (iguais.length === 1) {
    const antes = db.prepare('SELECT descricao, categoria_id, fornecedor, obs FROM saidas WHERE id = ?').get(iguais[0].id);
    db.prepare('UPDATE saidas SET descricao = ?, categoria_id = ?, fornecedor = COALESCE(?, fornecedor), obs = ? WHERE id = ?')
      .run(descricao, cat.id, fornecedor?.nome ?? null, 'conta já lançada, ligada ao boleto pela tela de Compras', iguais[0].id);
    return { id: iguais[0].id, adotada: true, antes: JSON.stringify(antes) };
  }
  const id = Number(db.prepare('INSERT INTO saidas (descricao, categoria_id, fornecedor, valor, vencimento, obs) VALUES (?, ?, ?, ?, ?, ?)')
    .run(descricao, cat.id, fornecedor?.nome ?? null, valor, vencimento, 'criado pela tela de Compras').lastInsertRowid);
  return { id, adotada: false, antes: null };
}

/**
 * O boleto deixa de ser conta a pagar da oficina (cancelado, ou virou boleto de outra empresa).
 * A conta que o próprio boleto criou é apagada; a que já existia (lançada à mão e adotada) volta a ser o que era, nunca é apagada.
 */
function soltarContaDoBoleto(db, b) {
  if (!b.saida_id) return;
  const conta = db.prepare('SELECT id, pago_em FROM saidas WHERE id = ?').get(b.saida_id);
  if (!conta || conta.pago_em) return;
  if (b.saida_adotada) {
    const o = JSON.parse(b.saida_adotada);
    db.prepare('UPDATE saidas SET descricao = ?, categoria_id = ?, fornecedor = ?, obs = ? WHERE id = ?').run(o.descricao, o.categoria_id, o.fornecedor, o.obs, conta.id);
    registrar(db, 'conta_devolvida', 'boleto', b.id, { saida: conta.id, descricao: o.descricao });
  } else {
    db.prepare('DELETE FROM saidas WHERE id = ?').run(conta.id);
    registrar(db, 'conta_removida', 'boleto', b.id, { saida: conta.id });
  }
}

export function criarBoleto(db, d, hojeStr, cfg = lerConfig(db), { semOcorrencias = false } = {}) {
  let leitura = null;
  const textoLinha = String(d.linha ?? '').trim();
  if (textoLinha) {
    leitura = interpretarBoleto(textoLinha, hojeStr);
    if (!leitura.ok) throw new ErroValidacao(leitura.erros.join(' '));
  }
  const valorDigitado = dinheiro(d.valor, { campo: 'O valor', nulo: true, minimo: 0.01 });
  const valor = leitura?.valor && leitura.valor > 0 ? leitura.valor : valorDigitado;
  if (leitura?.valor > 0 && valorDigitado !== null && Math.abs(valorDigitado - leitura.valor) > 0.004) {
    throw new ErroValidacao(`O valor digitado (${valorDigitado.toFixed(2)}) é diferente do valor que está na linha digitável (${leitura.valor.toFixed(2)}). Confira o que está escrito no boleto.`);
  }
  if (!(valor > 0)) throw new ErroValidacao('Informe o valor do boleto.');
  if (valor > 10_000_000) throw new ErroValidacao('Valor grande demais para um boleto de fornecedor: confira os números.');
  const vencDigitado = d.vencimento || null;
  const vencimento = leitura?.vencimento ?? vencDigitado;
  if (leitura?.vencimento && vencDigitado && leitura.vencimento !== vencDigitado) {
    throw new ErroValidacao(`O vencimento digitado (${vencDigitado}) é diferente do vencimento que está na linha digitável (${leitura.vencimento}). Confira o boleto.`);
  }
  if (!vencimento) throw new ErroValidacao('Informe o vencimento do boleto.');

  let reativarId = null;
  if (leitura?.codigoBarras) {
    const ja = db.prepare('SELECT id, situacao, cancelado_por FROM boletos WHERE codigo_barras = ?').get(leitura.codigoBarras);
    if (ja?.situacao === 'cancelado') {
      // quem lança não reabre, só por cadastrar de novo, o boleto que o dono cancelou
      if (ja.cancelado_por === 'dono' && perfilAtual() === 'lancamento') throw Object.assign(new ErroValidacao(`Este boleto foi cancelado pelo dono (nº ${ja.id}). Só o dono reabre.`), { boleto_id: ja.id });
      reativarId = ja.id;                                                   // boleto cancelado e cadastrado de novo: reaproveita o registro
    }
    else if (ja?.situacao === 'contestado') throw Object.assign(new ErroValidacao(`Este boleto está contestado (nº ${ja.id}). Reabra-o em Compras > Boletos se o fornecedor confirmou a cobrança.`), { boleto_id: ja.id });
    else if (ja) throw Object.assign(new ErroValidacao(`Este boleto já está cadastrado (nº ${ja.id}, ${ja.situacao}). Não pague duas vezes.`), { boleto_id: ja.id });
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
  if (!fornecedor) throw new ErroValidacao('Escolha o fornecedor do boleto (ou informe o nome de um novo). Sem fornecedor o sistema não consegue conferir nada.');

  let boletoId;
  let contaAdotada = false;
  // boleto contra o CNPJ de outra empresa do grupo não é conta a pagar da oficina: não cria saída
  const empresaPagadora = pagadorCnpj ? empresaDoCnpj(db, pagadorCnpj) : null;
  db.transaction(() => {
    const conta = empresaPagadora ? { id: null, adotada: false } : contaDoBoleto(db, { fornecedor, valor, vencimento, numeroDocumento: d.numero_documento });
    contaAdotada = conta.adotada;
    const campos = [fornecedor.id, leitura?.codigoBarras ?? null, leitura?.linhaDigitavel ?? null, leitura?.banco ?? null, valor, vencimento,
      d.numero_documento ? String(d.numero_documento).trim().slice(0, 40) : null, d.beneficiario_nome ? String(d.beneficiario_nome).trim().slice(0, 80) : null,
      benefCnpj, pagadorCnpj, conta.id, empresaPagadora?.id ?? null, perfilAtual(), conta.antes ?? null, d.obs ? String(d.obs).slice(0, 300) : null];
    if (reativarId) {
      db.prepare(`UPDATE boletos SET fornecedor_id = ?, codigo_barras = ?, linha_digitavel = ?, banco = ?, valor = ?, vencimento = ?, numero_documento = ?,
          beneficiario_nome = ?, beneficiario_cnpj = ?, pagador_cnpj = ?, saida_id = ?, empresa_id = ?, criado_por = ?, saida_adotada = ?, obs = ?, situacao = 'aberto', cancelado_por = NULL, aprovado_motivo = NULL, aprovado_em = NULL, conferido_banco_em = NULL WHERE id = ?`).run(...campos, reativarId);
      boletoId = reativarId;
      registrar(db, 'reabrir', 'boleto', boletoId, 'cadastrado de novo');
    } else {
      boletoId = Number(db.prepare(`INSERT INTO boletos (fornecedor_id, codigo_barras, linha_digitavel, banco, valor, vencimento, numero_documento,
          beneficiario_nome, beneficiario_cnpj, pagador_cnpj, saida_id, empresa_id, criado_por, saida_adotada, obs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(...campos).lastInsertRowid);
      registrar(db, 'cadastrar', 'boleto', boletoId, { valor, vencimento });
    }
  })();
  const auto = conciliarAutomatico(db, boletoId, cfg);
  return {
    boleto_id: boletoId, leitura, auto, conta_adotada: contaAdotada,
    ocorrencias: semOcorrencias ? [] : ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg),
  };
}

/** Informar ou corrigir quem recebe, quem paga, o documento impresso e o fornecedor de um boleto ainda em aberto. */
export function atualizarBoleto(db, boletoId, campos, hojeStr, cfg = lerConfig(db)) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (b.situacao !== 'aberto') throw new ErroValidacao('Só dá para corrigir boleto em aberto.');
  const novo = {};
  if (campos.beneficiario_cnpj !== undefined) {
    const c = normalizarCnpj(campos.beneficiario_cnpj) || null;
    if (c && !cnpjValido(c)) throw new ErroValidacao('O CNPJ do beneficiário não passa na validação (confira os números).');
    novo.beneficiario_cnpj = c;
  }
  if (campos.pagador_cnpj !== undefined) {
    const c = normalizarCnpj(campos.pagador_cnpj) || null;
    if (c && !cnpjValido(c)) throw new ErroValidacao('O CNPJ do pagador não passa na validação (confira os números).');
    novo.pagador_cnpj = c;
  }
  if (campos.beneficiario_nome !== undefined) novo.beneficiario_nome = campos.beneficiario_nome ? String(campos.beneficiario_nome).trim().slice(0, 80) : null;
  if (campos.numero_documento !== undefined) novo.numero_documento = campos.numero_documento ? String(campos.numero_documento).trim().slice(0, 40) : null;
  if (campos.fornecedor_id !== undefined && campos.fornecedor_id !== b.fornecedor_id) {
    if (!db.prepare('SELECT 1 FROM fornecedores WHERE id = ?').get(campos.fornecedor_id)) throw new ErroValidacao('Fornecedor não encontrado.');
    if (db.prepare('SELECT 1 FROM conciliacoes WHERE boleto_id = ?').get(boletoId)) throw new ErroValidacao('Desfaça a ligação com as notas antes de trocar o fornecedor do boleto.');
    novo.fornecedor_id = campos.fornecedor_id;
  }
  const chaves = Object.keys(novo);
  if (!chaves.length) return { alterado: false };
  db.transaction(() => {
    db.prepare(`UPDATE boletos SET ${chaves.map((k) => `${k} = @${k}`).join(', ')} WHERE id = @id`).run({ ...novo, id: boletoId });
    registrar(db, 'corrigir', 'boleto', boletoId, novo);
  })();
  aplicarEmpresaDoBoleto(db, boletoId);
  const auto = db.prepare('SELECT 1 FROM conciliacoes WHERE boleto_id = ?').get(boletoId) ? null : conciliarAutomatico(db, boletoId, cfg);
  return { alterado: true, auto, ocorrencias: ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg) };
}

/** Boleto contestado que o fornecedor confirmou, ou cancelado por engano: volta a ser cobrança em aberto (recria a conta a pagar). */
export function reabrirBoleto(db, boletoId, hojeStr, cfg = lerConfig(db)) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (b.situacao !== 'contestado' && b.situacao !== 'cancelado') throw new ErroValidacao('Só boleto contestado ou cancelado pode ser reaberto.');
  const fornecedor = b.fornecedor_id ? db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(b.fornecedor_id) : null;
  db.transaction(() => {
    const conta = b.empresa_id ? { id: null, antes: null } : contaDoBoleto(db, { fornecedor, valor: b.valor, vencimento: b.vencimento, numeroDocumento: b.numero_documento });
    db.prepare("UPDATE boletos SET situacao = 'aberto', saida_id = ?, saida_adotada = ?, cancelado_por = NULL, conferido_banco_em = NULL WHERE id = ?").run(conta.id, conta.antes ?? null, boletoId);
    registrar(db, 'reabrir', 'boleto', boletoId, `estava ${b.situacao}`);
  })();
  aplicarEmpresaDoBoleto(db, boletoId);              // a empresa pode ter sido cadastrada (ou desativada) enquanto o boleto estava fora
  const auto = conciliarAutomatico(db, boletoId, cfg);
  return { auto, ocorrencias: ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg) };
}

// ------------------------------------------------------------------ boleto de outra empresa do grupo

/**
 * Decide de quem é o boleto em aberto: o CNPJ do pagador impresso manda; sem ele, vale a empresa para a qual as notas ligadas foram emitidas.
 * Boleto de empresa do grupo sai das contas a pagar da oficina (a conta some); se voltar a ser da oficina, a conta é recriada.
 */
export function aplicarEmpresaDoBoleto(db, boletoId) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b || b.situacao !== 'aberto') return null;
  let alvo = null;
  if (b.pagador_cnpj) alvo = empresaDoCnpj(db, b.pagador_cnpj)?.id ?? null;
  else {
    const donos = db.prepare('SELECT DISTINCT n.cnpj_destinatario AS c FROM conciliacoes c JOIN notas_compra n ON n.id = c.nota_id WHERE c.boleto_id = ?').all(boletoId)
      .map((x) => (x.c ? empresaDoCnpj(db, x.c)?.id ?? null : null));
    if (donos.length && donos.every((x) => x !== null && x === donos[0])) alvo = donos[0];
  }
  if (alvo === (b.empresa_id ?? null)) return alvo;
  const fornecedor = b.fornecedor_id ? db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(b.fornecedor_id) : null;
  db.transaction(() => {
    if (alvo !== null) {
      soltarContaDoBoleto(db, b);
      db.prepare('UPDATE boletos SET empresa_id = ?, saida_id = NULL, saida_adotada = NULL WHERE id = ?').run(alvo, boletoId);
    } else {
      const conta = b.saida_id ? { id: b.saida_id, antes: b.saida_adotada } : contaDoBoleto(db, { fornecedor, valor: b.valor, vencimento: b.vencimento, numeroDocumento: b.numero_documento });
      db.prepare('UPDATE boletos SET empresa_id = NULL, saida_id = ?, saida_adotada = ? WHERE id = ?').run(conta.id, conta.antes ?? null, boletoId);
    }
    registrar(db, 'empresa_do_boleto', 'boleto', boletoId, { de: b.empresa_id ?? null, para: alvo });
  })();
  return alvo;
}

/** Depois de cadastrar, ativar ou desativar uma empresa do grupo: refaz a empresa das notas e dos boletos em aberto. */
export function reatribuirEmpresas(db) {
  db.prepare('UPDATE notas_compra SET empresa_id = (SELECT e.id FROM empresas_grupo e WHERE e.cnpj = notas_compra.cnpj_destinatario AND e.ativo = 1)').run();
  for (const { id } of db.prepare("SELECT id FROM boletos WHERE situacao = 'aberto'").all()) aplicarEmpresaDoBoleto(db, id);
}

// ------------------------------------------------------------------ pagamento com trava

/**
 * Marca o boleto (e a conta a pagar ligada) como pago.
 * 1) Antes do primeiro pagamento alguém precisa ter conferido no app do banco quem recebe (nome e CNPJ): `conferiuBanco`.
 * 2) Ocorrência grave exige "pagar mesmo assim" com motivo (`aprovar` + `motivo`).
 * 3) Valor pago diferente do valor do boleto exige motivo (juros, multa, desconto).
 */
export function pagarBoleto(db, boletoId, { data, valor = null, aprovar = false, motivo = null, conferiuBanco = false, pagoPor = null }, hojeStr, cfg = lerConfig(db)) {
  aplicarEmpresaDoBoleto(db, boletoId);              // o pagador (ou a nota) pode ter passado a ser de uma empresa do grupo depois do cadastro
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (data && data > hojeStr) throw new ErroValidacao('A data do pagamento não pode ser futura: registre no dia em que o dinheiro sair.');
  if (b.situacao === 'pago') throw new ErroValidacao('Este boleto já foi pago.');
  if (b.situacao === 'cancelado') throw new ErroValidacao('Este boleto está cancelado.');
  if (b.situacao === 'contestado') throw new ErroValidacao('Este boleto está contestado. Reabra-o antes de pagar.');
  // boleto de outra empresa do grupo: é preciso dizer de quem sai o dinheiro (a conta da oficina cobre o que é dos outros? então fica a receber)
  const empresa = b.empresa_id ? db.prepare('SELECT * FROM empresas_grupo WHERE id = ?').get(b.empresa_id) : null;
  if (empresa && pagoPor !== 'oficina' && pagoPor !== 'empresa') {
    throw new ErroValidacao(`Este boleto é da ${empresa.nome}, não da oficina. Diga quem está pagando: a oficina (o valor fica a receber da ${empresa.nome}) ou a própria ${empresa.nome}.`);
  }
  const todas = ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg).filter((o) => o.severidade === 'alta');
  const graves = todas.filter((o) => !o.aceita);
  const aceitasAltas = todas.filter((o) => o.aceita);
  const forn = b.fornecedor_id ? db.prepare('SELECT nome, cnpj FROM fornecedores WHERE id = ?').get(b.fornecedor_id) : null;
  // o DDA importado do banco já mostra quem recebe (CNPJ), o valor e o vencimento: dispensa conferir de novo no app do banco
  const semConferirBanco = !b.conferido_banco_em && !conferiuBanco && !confirmadoNoDda(db, b, hojeStr, cfg);
  const liberacao = aprovar && motivoValido(motivo);
  const bloqueios = [...graves];
  if (semConferirBanco) {
    bloqueios.unshift({
      chave: `confira_recebedor:boleto:${b.id}`, tipo: 'confira_recebedor', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], aceita: null,
      titulo: 'Confira quem recebe no app do banco',
      detalhe: `Cole a linha digitável no app do banco e leia o nome e o CNPJ de quem recebe. Deve ser ${forn?.nome ?? 'o fornecedor'}${forn?.cnpj ? ` (CNPJ ${forn.cnpj.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5')})` : ''}. Se for outro nome ou outro CNPJ, não pague e ligue para o fornecedor.`,
    });
  }
  if (graves.length && !liberacao) throw new ErroBloqueio(aprovar ? MOTIVO_MINIMO : 'Há problemas sérios neste boleto. Resolva ou libere o pagamento informando o motivo.', bloqueios);
  if (semConferirBanco && !liberacao) throw new ErroBloqueio('Antes de pagar, confira no app do banco quem recebe este boleto.', bloqueios);
  const dif = valor !== null && Math.abs(valor - b.valor) > tolerancia(cfg);
  if (dif && !motivoValido(motivo)) throw new ErroValidacao(`O valor pago (R$ ${Number(valor).toFixed(2)}) é diferente do boleto (R$ ${b.valor.toFixed(2)}). Explique o motivo (juros, multa, desconto). ${MOTIVO_MINIMO}`);

  const partes = [];
  if (liberacao || dif) partes.push(String(motivo).trim());
  for (const o of aceitasAltas) partes.push(`Aceito antes: ${o.titulo} (${o.aceita.motivo})`);
  const registro = partes.join(' | ').slice(0, 600) || null;
  const pagoEm = data || hojeStr;
  db.transaction(() => {
    db.prepare("UPDATE boletos SET situacao = 'pago', aprovado_motivo = ?, aprovado_em = ?, conferido_banco_em = COALESCE(conferido_banco_em, ?) WHERE id = ?")
      .run(registro, registro ? hojeStr : null, conferiuBanco ? hojeStr : null, boletoId);
    if (b.saida_id) db.prepare('UPDATE saidas SET pago_em = ?, valor_pago = ? WHERE id = ?').run(pagoEm, valor ?? b.valor, b.saida_id);
    if (empresa && pagoPor === 'oficina') {
      // se as peças destas notas já foram entregues à empresa (a receber pelo custo delas), o mesmo gasto não pode virar a receber duas vezes
      const jaPorPeca = db.prepare(`SELECT 1 FROM adiantamentos a JOIN alocacoes al ON al.id = a.alocacao_id JOIN nota_itens i ON i.id = al.item_id
          JOIN conciliacoes c ON c.nota_id = i.nota_id WHERE a.origem = 'peca' AND c.boleto_id = ? LIMIT 1`).get(boletoId);
      if (jaPorPeca) throw new ErroValidacao('As peças das notas deste boleto já estão como "a receber" da empresa (entrega de peças). Desfaça o destino das peças ou escolha "a empresa paga", senão o mesmo gasto contaria duas vezes.');
      adiantamentoDeBoleto(db, { empresa, boleto: b, valor: valor ?? b.valor, data: pagoEm });
    }
    registrar(db, graves.length || aceitasAltas.length ? 'pagar_liberado' : 'pagar', 'boleto', boletoId, { valor: valor ?? b.valor, conferiu_banco: !!conferiuBanco, motivo: registro, ...(empresa ? { empresa: empresa.nome, pago_por: pagoPor } : {}) });
  })();
  return { ok: true, liberado_com_ressalva: graves.length > 0 || aceitasAltas.length > 0 };
}

export function desfazerPagamentoBoleto(db, boletoId) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b || b.situacao !== 'pago') return false;
  db.transaction(() => {
    removerAdiantamentoDeOrigem(db, { boletoId });                      // recusa se a outra empresa já devolveu parte
    db.prepare("UPDATE boletos SET situacao = 'aberto', aprovado_motivo = NULL, aprovado_em = NULL WHERE id = ?").run(boletoId);
    if (b.saida_id) db.prepare('UPDATE saidas SET pago_em = NULL, valor_pago = NULL WHERE id = ?').run(b.saida_id);
    registrar(db, 'desfazer_pagamento', 'boleto', boletoId, { motivo_anterior: b.aprovado_motivo });
  })();
  aplicarEmpresaDoBoleto(db, boletoId);              // voltou a ser boleto em aberto: de quem é, agora?
  return true;
}

/** Cancela ou contesta. As ligações com notas somem junto: a nota volta a ficar sem boleto (e aparece como pendente). */
export function cancelarBoleto(db, boletoId, situacao = 'cancelado', motivo = null) {
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(boletoId);
  if (!b) throw new ErroValidacao('Boleto não encontrado.');
  if (b.situacao === 'pago') throw new ErroValidacao('Boleto já pago: não dá para cancelar.');
  if (b.situacao === 'cancelado' || b.situacao === 'contestado') throw new ErroValidacao(`Este boleto já está ${b.situacao}.`);
  db.transaction(() => {
    db.prepare('UPDATE boletos SET situacao = ?, cancelado_por = ?, obs = COALESCE(?, obs) WHERE id = ?').run(situacao, perfilAtual(), motivo ? String(motivo).trim().slice(0, 300) : null, boletoId);
    db.prepare('DELETE FROM conciliacoes WHERE boleto_id = ?').run(boletoId);
    soltarContaDoBoleto(db, b);
    db.prepare('UPDATE boletos SET saida_id = NULL, saida_adotada = NULL WHERE id = ?').run(boletoId);
    registrar(db, situacao === 'contestado' ? 'contestar' : 'cancelar', 'boleto', boletoId, { motivo });
  })();
}

export { diasEntre, ocorrencias };
