// Empresas do mesmo grupo (locadora do dono, oficina do sócio...) e o acerto de contas entre elas e a oficina.
// Nota ou boleto no CNPJ de uma empresa do grupo não é golpe, mas também não é despesa da oficina: fica fora das contas a pagar
// da oficina e, se a oficina pagar por ela, vira "a receber" nesta tabela (adiantamentos), com baixa quando o dinheiro volta.
import { normalizarCnpj, cnpjValido, formatarCnpj } from './documentos.js';
import { ErroValidacao, dinheiro, data as dataValida, texto } from './validar.js';
import { r2, diasEntre } from './util.js';
import { registrar, perfilAtual } from './trilha.js';
import { lerConfig } from './db.js';

export const PAPEIS = ['locadora', 'socio', 'outra'];
export const ROTULO_PAPEL = { locadora: 'Locadora', socio: 'Oficina do sócio', outra: 'Outra empresa' };

const TOL = 0.005;

// ------------------------------------------------------------------ cadastro

export function listarEmpresas(db) {
  return db.prepare('SELECT * FROM empresas_grupo ORDER BY ativo DESC, nome').all()
    .map((e) => ({ ...e, cnpj_formatado: formatarCnpj(e.cnpj), papel_rotulo: ROTULO_PAPEL[e.papel] }));
}

/** Empresa ativa do grupo dona deste CNPJ (ou null). A própria oficina nunca é "do grupo". */
export function empresaDoCnpj(db, cnpj) {
  const c = normalizarCnpj(cnpj);
  if (!c) return null;
  if (c === lerConfig(db).cnpjOficina) return null;
  return db.prepare('SELECT * FROM empresas_grupo WHERE cnpj = ? AND ativo = 1').get(c) ?? null;
}

export function criarEmpresa(db, d, cfg = lerConfig(db)) {
  const nome = texto(d.nome, { campo: 'o nome da empresa', obrigatorio: true, max: 80 });
  const cnpj = normalizarCnpj(d.cnpj);
  if (!cnpj || !cnpjValido(cnpj)) throw new ErroValidacao('O CNPJ da empresa não passa na validação (confira os números).');
  if (cfg.cnpjOficina && cnpj === cfg.cnpjOficina) throw new ErroValidacao('Este é o CNPJ da própria oficina (Metas). Cadastre aqui só as OUTRAS empresas.');
  const papel = PAPEIS.includes(d.papel) ? d.papel : 'outra';
  if (db.prepare('SELECT 1 FROM fornecedores WHERE cnpj = ?').get(cnpj)) {
    throw new ErroValidacao('Este CNPJ já é de um fornecedor. Uma empresa do grupo não pode ser também fornecedor de peças.');
  }
  try {
    const id = Number(db.prepare('INSERT INTO empresas_grupo (nome, cnpj, papel) VALUES (?, ?, ?)').run(nome, cnpj, papel).lastInsertRowid);
    registrar(db, 'empresa_criada', 'empresa', id, { nome, cnpj, papel });
    return db.prepare('SELECT * FROM empresas_grupo WHERE id = ?').get(id);
  } catch (e) {
    if (String(e.message).includes('UNIQUE')) throw new ErroValidacao('Esta empresa já está cadastrada.');
    throw e;
  }
}

export function atualizarEmpresa(db, id, d) {
  const atual = db.prepare('SELECT * FROM empresas_grupo WHERE id = ?').get(id);
  if (!atual) throw new ErroValidacao('Empresa não encontrada.');
  const nome = d.nome === undefined ? atual.nome : texto(d.nome, { campo: 'o nome da empresa', obrigatorio: true, max: 80 });
  const papel = d.papel === undefined ? atual.papel : (PAPEIS.includes(d.papel) ? d.papel : atual.papel);
  const ativo = d.ativo === undefined ? atual.ativo : (d.ativo ? 1 : 0);
  if (!ativo && atual.ativo && saldoDaEmpresa(db, id).emAberto > TOL) {
    throw new ErroValidacao('Esta empresa ainda tem acerto em aberto com a oficina. Dê baixa nos valores antes de desativá-la.');
  }
  db.prepare('UPDATE empresas_grupo SET nome = ?, papel = ?, ativo = ? WHERE id = ?').run(nome, papel, ativo, id);
  registrar(db, 'empresa_alterada', 'empresa', id, { antes: { nome: atual.nome, papel: atual.papel, ativo: atual.ativo }, depois: { nome, papel, ativo } });
  return db.prepare('SELECT * FROM empresas_grupo WHERE id = ?').get(id);
}

// ------------------------------------------------------------------ acerto entre empresas

const SQL_SALDO = `a.valor - COALESCE((SELECT SUM(x.valor) FROM adiantamento_baixas x WHERE x.adiantamento_id = a.id), 0)`;

export function saldoDoAdiantamento(db, id) {
  return r2(db.prepare(`SELECT ${SQL_SALDO} AS s FROM adiantamentos a WHERE a.id = ?`).get(id)?.s ?? 0);
}

/** A oficina pagou um boleto de outra empresa do grupo: fica a receber. O dinheiro saiu da conta da oficina nesse dia. */
export function adiantamentoDeBoleto(db, { empresa, boleto, valor, data }) {
  const id = Number(db.prepare(`INSERT INTO adiantamentos (empresa_id, sentido, origem, boleto_id, descricao, valor, data, caixa, criado_por)
      VALUES (?, 'a_receber', 'boleto', ?, ?, ?, ?, 1, ?)`).run(empresa.id, boleto.id,
    `Boleto pago pela oficina${boleto.numero_documento ? ` (doc ${String(boleto.numero_documento).slice(0, 20)})` : ''}, vencimento ${boleto.vencimento.split('-').reverse().join('/')}`, r2(valor), data, perfilAtual()).lastInsertRowid);
  registrar(db, 'adiantamento_criado', 'empresa', empresa.id, { adiantamento: id, boleto: boleto.id, valor: r2(valor) });
  return id;
}

/** Peças compradas no CNPJ da oficina e entregues a outra empresa do grupo: a oficina tem a receber o custo delas. Não saiu dinheiro agora. */
export function adiantamentoDePeca(db, { empresa, alocacaoId, valor, data, descricao }) {
  const id = Number(db.prepare(`INSERT INTO adiantamentos (empresa_id, sentido, origem, alocacao_id, descricao, valor, data, caixa, criado_por)
      VALUES (?, 'a_receber', 'peca', ?, ?, ?, ?, 0, ?)`).run(empresa.id, alocacaoId, String(descricao).slice(0, 160), r2(valor), data, perfilAtual()).lastInsertRowid);
  registrar(db, 'adiantamento_criado', 'empresa', empresa.id, { adiantamento: id, alocacao: alocacaoId, valor: r2(valor) });
  return id;
}

/** Lançamento à mão (acerto que não veio de boleto nem de peça). `caixa` = saiu/entrou dinheiro da conta da oficina nesse dia. */
export function criarAdiantamentoManual(db, d, hojeStr) {
  const empresa = db.prepare('SELECT * FROM empresas_grupo WHERE id = ?').get(d.empresa_id);
  if (!empresa) throw new ErroValidacao('Escolha a empresa.');
  const sentido = d.sentido === 'a_pagar' ? 'a_pagar' : 'a_receber';
  const valor = dinheiro(d.valor, { campo: 'o valor', obrigatorio: true, minimo: 0.01 });
  const data = dataValida(d.data || hojeStr, { campo: 'A data' });
  if (data > hojeStr) throw new ErroValidacao('A data não pode ser futura: registre o acerto no dia em que acontecer.');
  const descricao = texto(d.descricao, { campo: 'a descrição', obrigatorio: true, max: 160 });
  // só A RECEBER mexe no caixa da oficina quando o dinheiro saiu da conta dela; A PAGAR ainda não saiu dinheiro (sai na baixa)
  const caixa = sentido === 'a_receber' && d.caixa === true ? 1 : 0;
  const id = Number(db.prepare(`INSERT INTO adiantamentos (empresa_id, sentido, origem, descricao, valor, data, caixa, obs, criado_por)
      VALUES (?, ?, 'manual', ?, ?, ?, ?, ?, ?)`).run(empresa.id, sentido, descricao, valor, data, caixa, d.obs ? String(d.obs).slice(0, 300) : null, perfilAtual()).lastInsertRowid);
  registrar(db, 'adiantamento_criado', 'empresa', empresa.id, { adiantamento: id, sentido, valor, manual: true });
  return id;
}

export function apagarAdiantamento(db, id) {
  const a = db.prepare('SELECT * FROM adiantamentos WHERE id = ?').get(id);
  if (!a) return false;
  if (a.origem !== 'manual') throw new ErroValidacao('Este valor nasceu de um boleto ou de uma peça e se desfaz lá (desfazendo o pagamento ou o destino da peça).');
  if (db.prepare('SELECT 1 FROM adiantamento_baixas WHERE adiantamento_id = ?').get(id)) throw new ErroValidacao('Já houve devolução neste valor. Desfaça as devoluções antes de apagar.');
  db.prepare('DELETE FROM adiantamentos WHERE id = ?').run(id);
  registrar(db, 'adiantamento_apagado', 'empresa', a.empresa_id, { adiantamento: id, valor: a.valor, descricao: a.descricao });
  return true;
}

/** Remove o acerto que nasceu de um boleto ou de uma peça (quando o pagamento ou o destino é desfeito). Se já houve devolução, recusa. */
export function removerAdiantamentoDeOrigem(db, { boletoId = null, alocacaoId = null }) {
  const alvo = boletoId !== null
    ? db.prepare("SELECT * FROM adiantamentos WHERE boleto_id = ? AND origem = 'boleto'").all(boletoId)
    : db.prepare("SELECT * FROM adiantamentos WHERE alocacao_id = ? AND origem = 'peca'").all(alocacaoId);
  for (const a of alvo) {
    if (db.prepare('SELECT 1 FROM adiantamento_baixas WHERE adiantamento_id = ?').get(a.id)) {
      throw new ErroValidacao('A empresa já devolveu parte deste valor. Desfaça a devolução em Compras > Entre empresas antes.');
    }
  }
  for (const a of alvo) {
    db.prepare('DELETE FROM adiantamentos WHERE id = ?').run(a.id);
    registrar(db, 'adiantamento_desfeito', 'empresa', a.empresa_id, { adiantamento: a.id, valor: a.valor });
  }
  return alvo.length;
}

/** Dinheiro que voltou (ou, no sentido a pagar, que a oficina devolveu). Parcial ou total; nunca passa do que falta. */
export function baixarAdiantamento(db, id, d, hojeStr) {
  const a = db.prepare('SELECT * FROM adiantamentos WHERE id = ?').get(id);
  if (!a) throw new ErroValidacao('Acerto não encontrado.');
  const falta = saldoDoAdiantamento(db, id);
  if (falta <= TOL) throw new ErroValidacao('Este valor já foi acertado por inteiro.');
  const valor = d.valor === undefined || d.valor === null || d.valor === '' ? falta : dinheiro(d.valor, { campo: 'o valor devolvido', obrigatorio: true, minimo: 0.01 });
  if (valor > falta + 0.05) throw new ErroValidacao(`Só faltam R$ ${falta.toFixed(2)} neste acerto.`);
  const data = dataValida(d.data || hojeStr, { campo: 'A data' });
  if (data > hojeStr) throw new ErroValidacao('A data não pode ser futura: registre a devolução no dia em que o dinheiro voltar.');
  if (data < a.data) throw new ErroValidacao('A devolução não pode ser anterior ao dia em que o valor nasceu.');
  const bid = Number(db.prepare('INSERT INTO adiantamento_baixas (adiantamento_id, data, valor, obs) VALUES (?, ?, ?, ?)').run(id, data, Math.min(valor, falta), d.obs ? String(d.obs).slice(0, 200) : null).lastInsertRowid);
  registrar(db, 'adiantamento_baixa', 'empresa', a.empresa_id, { adiantamento: id, valor, data });
  return bid;
}

export function desfazerBaixa(db, baixaId) {
  const b = db.prepare('SELECT x.*, a.empresa_id FROM adiantamento_baixas x JOIN adiantamentos a ON a.id = x.adiantamento_id WHERE x.id = ?').get(baixaId);
  if (!b) return false;
  db.prepare('DELETE FROM adiantamento_baixas WHERE id = ?').run(baixaId);
  registrar(db, 'adiantamento_baixa_desfeita', 'empresa', b.empresa_id, { adiantamento: b.adiantamento_id, valor: b.valor, data: b.data });
  return true;
}

export function saldoDaEmpresa(db, empresaId) {
  const linhas = db.prepare(`SELECT a.sentido, ${SQL_SALDO} AS saldo FROM adiantamentos a WHERE a.empresa_id = ?`).all(empresaId);
  const aReceber = r2(linhas.filter((l) => l.sentido === 'a_receber').reduce((s, l) => s + l.saldo, 0));
  const aPagar = r2(linhas.filter((l) => l.sentido === 'a_pagar').reduce((s, l) => s + l.saldo, 0));
  return { aReceber, aPagar, saldo: r2(aReceber - aPagar), emAberto: r2(aReceber + aPagar) };
}

/** Tudo o que está em aberto entre a oficina e cada empresa, mais o histórico recente de devoluções. */
export function acertoEntreEmpresas(db, hojeStr, cfg) {
  const prazo = cfg?.diasDevolucaoAdiantamento ?? 30;
  const linhas = db.prepare(`SELECT a.*, ${SQL_SALDO} AS saldo, e.nome AS empresa, e.papel,
      bo.numero_documento AS boleto_doc, bo.vencimento AS boleto_venc
      FROM adiantamentos a JOIN empresas_grupo e ON e.id = a.empresa_id LEFT JOIN boletos bo ON bo.id = a.boleto_id
      ORDER BY a.data DESC, a.id DESC`).all();
  const baixas = db.prepare('SELECT * FROM adiantamento_baixas ORDER BY data, id').all();
  const porAdiant = new Map();
  for (const b of baixas) (porAdiant.get(b.adiantamento_id) ?? porAdiant.set(b.adiantamento_id, []).get(b.adiantamento_id)).push(b);
  const itens = linhas.map((l) => ({
    ...l, saldo: r2(l.saldo), baixas: porAdiant.get(l.id) ?? [],
    dias: diasEntre(l.data, hojeStr), atrasado: l.saldo > TOL && diasEntre(l.data, hojeStr) > prazo,
  }));
  const empresas = new Map();
  for (const e of listarEmpresas(db)) empresas.set(e.id, { ...e, aReceber: 0, aPagar: 0, itens: [] });
  for (const i of itens) {
    const e = empresas.get(i.empresa_id);
    e.itens.push(i);
    if (i.sentido === 'a_receber') e.aReceber = r2(e.aReceber + i.saldo); else e.aPagar = r2(e.aPagar + i.saldo);
  }
  const lista = [...empresas.values()].map((e) => ({ ...e, saldo: r2(e.aReceber - e.aPagar) }));
  return {
    prazoDias: prazo, empresas: lista,
    aReceber: r2(lista.reduce((s, e) => s + e.aReceber, 0)), aPagar: r2(lista.reduce((s, e) => s + e.aPagar, 0)),
    atrasado: r2(itens.filter((i) => i.atrasado && i.sentido === 'a_receber').reduce((s, i) => s + i.saldo, 0)),
  };
}

/** Dinheiro que saiu/entrou da conta da oficina por causa desses acertos num período (desde < data <= ate), para o saldo de caixa. */
export function movimentoDeCaixa(db, desde, ate) {
  const saiuAdiant = db.prepare('SELECT COALESCE(SUM(valor), 0) AS t FROM adiantamentos WHERE caixa = 1 AND data > ? AND data <= ?').get(desde, ate).t;
  const baixas = db.prepare(`SELECT a.sentido, COALESCE(SUM(x.valor), 0) AS t FROM adiantamento_baixas x JOIN adiantamentos a ON a.id = x.adiantamento_id
      WHERE x.data > ? AND x.data <= ? GROUP BY a.sentido`).all(desde, ate);
  const entrou = baixas.find((b) => b.sentido === 'a_receber')?.t ?? 0;
  const pagou = baixas.find((b) => b.sentido === 'a_pagar')?.t ?? 0;
  return { entrou: r2(entrou), saiu: r2(saiuAdiant + pagou) };
}

