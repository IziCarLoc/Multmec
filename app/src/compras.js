// Compras de peças: fornecedor, nota fiscal (NF-e), itens, alocação de itens às OS e custo real por OS.
import { gzipSync } from 'node:zlib';
import { lerXmlNfe, ErroNfe } from './nfe.js';
import { cnpjValido, normalizarCnpj, lerChaveNfe } from './documentos.js';
import { r2, somarDias, normalizarPlaca } from './util.js';
import { ErroValidacao } from './validar.js';
import { registrar } from './trilha.js';

const EPS = 1e-6;

/** Destino que ainda vale: estoque/uso/devolução sempre; OS só enquanto a OS estiver aberta ou concluída (OS cancelada devolve a peça para "sem destino"). */
export const ALOC_VALIDA = "(a.destino <> 'os' OR EXISTS (SELECT 1 FROM vendas v WHERE v.id = a.venda_id AND v.situacao IN ('aberta','concluida')))";

/**
 * Parcelas da nota com o saldo ainda sem boleto. A ligação feita só com a nota (sem escolher parcela) abate as parcelas na ordem dos vencimentos.
 */
export function duplicatasComSaldo(db, notaId) {
  const dups = db.prepare('SELECT * FROM nota_duplicatas WHERE nota_id = ? ORDER BY vencimento, id').all(notaId);
  const por = new Map(db.prepare('SELECT duplicata_id AS d, SUM(valor) AS v FROM conciliacoes WHERE nota_id = ? GROUP BY duplicata_id').all(notaId).map((r) => [r.d, r.v]));
  let livre = por.get(null) ?? 0;
  return dups.map((d) => {
    let saldo = d.valor - (por.get(d.id) ?? 0);
    if (saldo > 0.004 && livre > 0.004) { const t = Math.min(saldo, livre); saldo -= t; livre -= t; }
    return { ...d, saldo: r2(saldo) };
  });
}

// ------------------------------------------------------------------ fornecedores

export function garantirFornecedor(db, { nome, cnpj }) {
  const c = normalizarCnpj(cnpj) || null;
  if (c) {
    const porCnpj = db.prepare('SELECT * FROM fornecedores WHERE cnpj = ?').get(c);
    if (porCnpj) return porCnpj;
  }
  let limpo = String(nome ?? '').replace(/\s+/g, ' ').trim().toUpperCase();
  if (!limpo) throw new ErroValidacao('Informe o nome do fornecedor.');
  const porNome = db.prepare('SELECT * FROM fornecedores WHERE nome = ?').get(limpo);
  if (porNome) {
    if (!porNome.cnpj) {
      if (c) db.prepare('UPDATE fornecedores SET cnpj = ? WHERE id = ?').run(c, porNome.id);
      return { ...porNome, cnpj: c };
    }
    if (!c || porNome.cnpj === c) return porNome;
    // mesmo nome, CNPJ diferente: outra empresa, não funde (filiais: usa o CNPJ completo)
    limpo = db.prepare('SELECT 1 FROM fornecedores WHERE nome = ?').get(`${limpo} (${c.slice(0, 8)})`) ? `${limpo} (${c})` : `${limpo} (${c.slice(0, 8)})`;
  }
  const id = Number(db.prepare('INSERT INTO fornecedores (nome, cnpj) VALUES (?, ?)').run(limpo, c).lastInsertRowid);
  return db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(id);
}

// ------------------------------------------------------------------ notas

/** Reparte o vNF entre os itens, proporcional ao valor líquido de cada um (soma exatamente vNF). */
export function ratearCusto(itens, valorNf) {
  // custo próprio de cada item (produto - desconto + frete + seguro + outras + IPI + ICMS-ST + FCP-ST), quando o XML traz por item;
  // o que sobrar do vNF (frete só no total, por exemplo) é repartido pelo valor dos produtos
  const base = itens.map((i) => (Number.isFinite(i.custo_base) ? i.custo_base : 0));
  const sobra = valorNf - base.reduce((a, b) => a + b, 0);
  const pesos = itens.map((i) => Math.max(0, (i.valor_total ?? 0) - (i.valor_desconto ?? 0)));
  const soma = pesos.reduce((a, b) => a + b, 0);
  const partes = itens.map((_, k) => base[k] + (soma > 0 ? sobra * (pesos[k] / soma) : sobra / itens.length));
  let acumulado = 0;
  return partes.map((p, k) => {
    if (k === partes.length - 1) return r2(valorNf - acumulado);
    const v = r2(p);
    acumulado = r2(acumulado + v);
    return v;
  });
}

function inserirNota(db, n) {
  const fornecedor = garantirFornecedor(db, { nome: n.nome_fantasia || n.nome_emitente, cnpj: n.cnpj_emitente });
  const dup = n.chave
    ? db.prepare('SELECT id FROM notas_compra WHERE chave = ?').get(n.chave)
    : null;
  if (dup) return { duplicada: true, nota_id: dup.id };
  const mesmaNumero = db.prepare('SELECT id FROM notas_compra WHERE fornecedor_id = ? AND numero = ? AND serie = ?').get(fornecedor.id, n.numero, n.serie ?? '');
  if (mesmaNumero) return { duplicada: true, nota_id: mesmaNumero.id };
  const custos = ratearCusto(n.itens, n.valor_total);
  let notaId;
  db.transaction(() => {
    notaId = Number(db.prepare(`INSERT INTO notas_compra (fornecedor_id, chave, numero, serie, data_emissao, valor_total, valor_produtos, valor_frete,
        valor_desconto, cnpj_emitente, nome_emitente, cnpj_destinatario, nome_destinatario, finalidade, protocolo_status, natureza, info_compl, origem, xml_hash, xml_gz,
        valor_com_tributos, situacao, cancelada_por, pago_no_ato, cnpj_receb)
        VALUES (@fornecedor_id, @chave, @numero, @serie, @data_emissao, @valor_total, @valor_produtos, @valor_frete, @valor_desconto, @cnpj_emitente,
        @nome_emitente, @cnpj_destinatario, @nome_destinatario, @finalidade, @protocolo_status, @natureza, @info_compl, @origem, @xml_hash, @xml_gz,
        @valor_com_tributos, @situacao, @cancelada_por, @pago_no_ato, @cnpj_receb)`).run({
      fornecedor_id: fornecedor.id, chave: n.chave ?? null, numero: n.numero, serie: n.serie ?? '', data_emissao: n.data_emissao,
      valor_com_tributos: n.valor_com_tributos ?? null, situacao: n.situacao ?? 'ativa', cancelada_por: n.situacao === 'cancelada' ? 'sefaz' : null, pago_no_ato: n.pago_no_ato ? 1 : 0, cnpj_receb: n.cnpj_receb ?? null,
      valor_total: n.valor_total, valor_produtos: n.valor_produtos ?? null, valor_frete: n.valor_frete ?? null, valor_desconto: n.valor_desconto ?? null,
      cnpj_emitente: n.cnpj_emitente ?? null, nome_emitente: n.nome_emitente ?? null, cnpj_destinatario: n.cnpj_destinatario ?? null,
      nome_destinatario: n.nome_destinatario ?? null, finalidade: n.finalidade ?? 'normal', protocolo_status: n.protocolo_status ?? null,
      natureza: n.natureza ?? null, info_compl: n.info_compl ?? null, origem: n.origem ?? 'xml', xml_hash: n.hash ?? null, xml_gz: n.xml_gz ?? null,
    }).lastInsertRowid);
    const insDup = db.prepare('INSERT INTO nota_duplicatas (nota_id, numero, vencimento, valor) VALUES (?, ?, ?, ?)');
    for (const d of n.duplicatas ?? []) insDup.run(notaId, d.numero ?? null, d.vencimento, r2(d.valor));
    const insItem = db.prepare(`INSERT INTO nota_itens (nota_id, n_item, codigo, descricao, ncm, cfop, unidade, quantidade, valor_unitario, valor_total, valor_desconto, custo_total, x_ped, info_adic)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
    n.itens.forEach((i, k) => insItem.run(notaId, i.n_item ?? k + 1, i.codigo ?? null, i.descricao, i.ncm ?? null, i.cfop ?? null, i.unidade ?? null,
      i.quantidade, i.valor_unitario, r2(i.valor_total), r2(i.valor_desconto ?? 0), custos[k], i.x_ped ?? null, i.info_adicional ?? null));
  })();
  return { duplicada: false, nota_id: notaId, fornecedor_id: fornecedor.id };
}

/**
 * Chegou o XML de uma nota que já tinha sido digitada à mão (sem chave): completa a nota existente em vez de duplicar.
 * Só troca parcelas e itens quando nada está ligado a eles; senão mantém o que existe e avisa.
 */
function completarNotaManual(db, existente, n) {
  const avisos = [];
  const diferenca = Math.abs(existente.valor_total - n.valor_total);
  if (diferenca > 0.05) {
    return { status: 'divergente_manual', nota_id: existente.id, avisos: [`Já existe uma nota digitada com este número, de ${existente.valor_total.toFixed(2)}, e o XML diz ${n.valor_total.toFixed(2)}. Confira e corrija ou apague a nota digitada antes de importar.`] };
  }
  const custos = ratearCusto(n.itens, n.valor_total);
  const conflito = db.prepare('SELECT id FROM notas_compra WHERE id <> ? AND (chave = ? OR (fornecedor_id = ? AND numero = ? AND serie = ?))').get(existente.id, n.chave, existente.fornecedor_id, existente.numero, n.serie ?? '');
  if (conflito) return { status: 'divergente_manual', nota_id: existente.id, avisos: ['Já existe outra nota com esta chave ou com este número e série. Confira as notas deste fornecedor antes de importar.'] };
  db.transaction(() => {
    db.prepare(`UPDATE notas_compra SET chave = ?, serie = ?, data_emissao = ?, valor_total = ?, valor_produtos = ?, valor_frete = ?, valor_desconto = ?,
        cnpj_emitente = ?, nome_emitente = ?, cnpj_destinatario = ?, nome_destinatario = ?, finalidade = ?, protocolo_status = ?, natureza = ?, info_compl = ?,
        origem = 'xml', xml_hash = ?, xml_gz = ?, valor_com_tributos = ?, situacao = ?, pago_no_ato = ?, cnpj_receb = ? WHERE id = ?`).run(n.chave, n.serie ?? '', n.data_emissao, n.valor_total, n.valor_produtos ?? null, n.valor_frete ?? null, n.valor_desconto ?? null,
      n.cnpj_emitente ?? null, n.nome_emitente ?? null, n.cnpj_destinatario ?? null, n.nome_destinatario ?? null, n.finalidade ?? 'normal', n.protocolo_status ?? null,
      n.natureza ?? null, n.info_compl ?? null, n.hash ?? null, n.xml_gz ?? null, n.valor_com_tributos ?? null, n.situacao === 'cancelada' ? 'cancelada' : existente.situacao, n.pago_no_ato ? 1 : 0, n.cnpj_receb ?? null, existente.id);
    if (n.situacao === 'cancelada') db.prepare("UPDATE notas_compra SET cancelada_por = 'sefaz' WHERE id = ?").run(existente.id);
    const parcelasLigadas = db.prepare('SELECT COUNT(*) AS c FROM conciliacoes WHERE nota_id = ? AND duplicata_id IS NOT NULL').get(existente.id).c;
    if (!parcelasLigadas) {
      db.prepare('DELETE FROM nota_duplicatas WHERE nota_id = ?').run(existente.id);
      const ins = db.prepare('INSERT INTO nota_duplicatas (nota_id, numero, vencimento, valor) VALUES (?, ?, ?, ?)');
      for (const d of n.duplicatas ?? []) ins.run(existente.id, d.numero ?? null, d.vencimento, r2(d.valor));
    } else avisos.push('As parcelas da nota digitada já estão ligadas a boletos e foram mantidas.');
    const itensLigados = db.prepare('SELECT COUNT(*) AS c FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id WHERE i.nota_id = ?').get(existente.id).c;
    if (!itensLigados) {
      db.prepare('DELETE FROM nota_itens WHERE nota_id = ?').run(existente.id);
      const ins = db.prepare(`INSERT INTO nota_itens (nota_id, n_item, codigo, descricao, ncm, cfop, unidade, quantidade, valor_unitario, valor_total, valor_desconto, custo_total, x_ped, info_adic)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
      n.itens.forEach((i, k) => ins.run(existente.id, i.n_item ?? k + 1, i.codigo ?? null, i.descricao, i.ncm ?? null, i.cfop ?? null, i.unidade ?? null, i.quantidade, i.valor_unitario, r2(i.valor_total), r2(i.valor_desconto ?? 0), custos[k], i.x_ped ?? null, i.info_adicional ?? null));
    } else avisos.push('Já há peças ligadas a OS na nota digitada: os itens do XML não substituíram os da nota; confira.');
  })();
  return { status: 'atualizada', nota_id: existente.id, avisos: [...n.avisos, ...avisos] };
}

/** Importa um XML de NF-e (ou de cancelamento). Nunca duplica: a chave de acesso é única. */
export function importarNotaXml(db, xml) {
  let n;
  try { n = lerXmlNfe(xml); n.xml_gz = n.tipo === 'nota' ? gzipSync(Buffer.from(String(xml), 'utf8')) : null; } catch (e) {
    if (e instanceof ErroNfe) throw new ErroValidacao(e.message);
    throw e;
  }
  if (n.tipo === 'cancelamento') {
    const nota = db.prepare('SELECT id FROM notas_compra WHERE chave = ?').get(n.chave);
    if (!nota) throw new ErroValidacao('Este é o cancelamento de uma nota que ainda não foi importada. Importe primeiro o XML da nota.');
    db.prepare("UPDATE notas_compra SET situacao = 'cancelada', cancelada_por = 'sefaz' WHERE id = ?").run(nota.id);
    registrar(db, 'nota_cancelada_xml', 'nota', nota.id, null);
    recalcularCustoDasOsDaNota(db, nota.id);
    return { status: 'cancelada', nota_id: nota.id, avisos: [] };
  }
  const fornecedor = garantirFornecedor(db, { nome: n.nome_fantasia || n.nome_emitente, cnpj: n.cnpj_emitente });
  const manual = db.prepare("SELECT * FROM notas_compra WHERE fornecedor_id = ? AND numero = ? AND chave IS NULL AND origem = 'manual'").get(fornecedor.id, n.numero);
  if (manual) return completarNotaManual(db, manual, n);
  const r = inserirNota(db, { ...n, origem: 'xml' });
  if (r.duplicada) {
    const atual = db.prepare('SELECT xml_hash FROM notas_compra WHERE id = ?').get(r.nota_id);
    const avisos = atual?.xml_hash && atual.xml_hash !== n.hash ? ['Já existia uma nota com esta chave, mas o conteúdo do XML é DIFERENTE do que foi importado antes. Desconfie.'] : [];
    return { status: 'ja_existia', nota_id: r.nota_id, avisos };
  }
  return { status: 'importada', nota_id: r.nota_id, fornecedor_id: r.fornecedor_id, avisos: n.avisos };
}

/** Nota lançada à mão (sem XML). Sem itens, cria um item único "Peças (total da nota)" para poder alocar à OS. */
export function criarNotaManual(db, d) {
  const numero = String(d.numero ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
  if (!numero) throw new ErroValidacao('Informe o número da nota.');
  if (!(d.valor_total > 0)) throw new ErroValidacao('Informe o valor total da nota.');
  let chave = null;
  let chaveLida = null;
  if (d.chave) {
    chaveLida = lerChaveNfe(d.chave);
    if (!chaveLida.valida) throw new ErroValidacao(chaveLida.motivo);
    chave = chaveLida.chave;
  }
  const cnpj = normalizarCnpj(d.cnpj_emitente);
  if (cnpj && !cnpjValido(cnpj)) throw new ErroValidacao('O CNPJ do fornecedor não passa na validação.');
  const fornecedor = d.fornecedor_id
    ? db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(d.fornecedor_id)
    : garantirFornecedor(db, { nome: d.fornecedor_nome, cnpj: cnpj || chaveLida?.cnpjEmitente });
  if (!fornecedor) throw new ErroValidacao('Fornecedor não encontrado.');
  // a chave é a prova da nota: precisa dizer o mesmo que foi digitado (fornecedor, número, série, mês e modelo)
  if (chaveLida) {
    if (chaveLida.modelo !== '55') throw new ErroValidacao(`A chave é de uma nota modelo ${chaveLida.modelo}; aqui só entra NF-e modelo 55.`);
    if (fornecedor.cnpj && fornecedor.cnpj !== chaveLida.cnpjEmitente) throw new ErroValidacao('O CNPJ que está dentro da chave de acesso não é o do fornecedor escolhido. A chave é de outra empresa.');
    if (!fornecedor.cnpj) {
      const dono = db.prepare('SELECT nome FROM fornecedores WHERE cnpj = ?').get(chaveLida.cnpjEmitente);
      if (dono) throw new ErroValidacao(`O CNPJ que está dentro da chave de acesso é de ${dono.nome}, não do fornecedor escolhido.`);
    }
    if (chaveLida.numero !== numero) throw new ErroValidacao(`O número dentro da chave (${chaveLida.numero}) não é o número digitado (${numero}).`);
    if (chaveLida.serie !== String(Number(d.serie || 0))) throw new ErroValidacao(`A série dentro da chave (${chaveLida.serie}) não é a digitada (${d.serie || '0'}).`);
    if (d.data_emissao && chaveLida.aamm !== `${d.data_emissao.slice(2, 4)}${d.data_emissao.slice(5, 7)}`) throw new ErroValidacao(`O mês dentro da chave (${chaveLida.aamm.slice(2)}/20${chaveLida.aamm.slice(0, 2)}) não é o mês da data de emissão digitada.`);
  }
  const mesmoNumero = db.prepare('SELECT serie FROM notas_compra WHERE fornecedor_id = ? AND numero = ?').all(fornecedor.id, numero);
  if (mesmoNumero.length && (!d.serie || mesmoNumero.some((x) => x.serie === '' || x.serie === String(d.serie)))) {
    throw new ErroValidacao('Já existe uma nota com este número deste fornecedor. Confira antes de lançar de novo.');
  }
  const soma = (d.duplicatas ?? []).reduce((a, x) => a + x.valor, 0);
  if ((d.duplicatas ?? []).length && Math.abs(soma - d.valor_total) > 0.05) {
    throw new ErroValidacao(`As parcelas somam ${soma.toFixed(2)} e a nota é de ${d.valor_total.toFixed(2)}. Confira.`);
  }
  const itens = d.itens?.length ? d.itens : [{ n_item: 1, codigo: null, descricao: 'Peças (total da nota)', quantidade: 1, valor_unitario: d.valor_total, valor_total: d.valor_total }];
  const r = inserirNota(db, {
    chave, numero, serie: d.serie ? String(d.serie) : '', data_emissao: d.data_emissao, valor_total: r2(d.valor_total),
    cnpj_emitente: fornecedor.cnpj ?? (cnpj || chaveLida?.cnpjEmitente || null), nome_emitente: fornecedor.nome, nome_fantasia: fornecedor.nome,
    finalidade: d.finalidade ?? 'normal', info_compl: d.info_compl ?? null, itens, duplicatas: d.duplicatas ?? [], origem: 'manual',
  });
  if (r.duplicada) throw new ErroValidacao('Esta nota já está cadastrada para este fornecedor.');
  return r;
}

export function notaComSaldo(db, notaId) {
  const n = db.prepare(`SELECT n.id, n.fornecedor_id, n.chave, n.numero, n.serie, n.data_emissao, n.valor_total, n.valor_produtos, n.valor_frete, n.valor_desconto,
      n.cnpj_emitente, n.nome_emitente, n.cnpj_destinatario, n.nome_destinatario, n.finalidade, n.situacao, n.protocolo_status, n.natureza, n.info_compl,
      n.valor_com_tributos, n.origem, n.xml_hash, n.obs, n.criado_em, f.nome AS fornecedor FROM notas_compra n JOIN fornecedores f ON f.id = n.fornecedor_id WHERE n.id = ?`).get(notaId);
  if (!n) return null;
  const conciliado = db.prepare('SELECT COALESCE(SUM(valor), 0) AS t FROM conciliacoes WHERE nota_id = ?').get(notaId).t;
  return { ...n, conciliado: r2(conciliado), saldo: r2(n.valor_total - conciliado) };
}

// ------------------------------------------------------------------ alocação de itens às OS

export function restanteItem(db, itemId) {
  const item = db.prepare('SELECT * FROM nota_itens WHERE id = ?').get(itemId);
  if (!item) return null;
  const a = db.prepare(`SELECT COALESCE(SUM(a.quantidade), 0) AS q, COALESCE(SUM(a.valor), 0) AS v FROM alocacoes a WHERE a.item_id = ? AND ${ALOC_VALIDA}`).get(itemId);
  return { item, quantidade: Math.max(0, item.quantidade - a.q), valor: r2(Math.max(0, item.custo_total - a.v)), alocadoQtd: a.q, alocadoValor: r2(a.v) };
}

export function recalcularCustoOs(db, vendaId) {
  const v = db.prepare('SELECT id, custo_pecas, custo_pecas_auto FROM vendas WHERE id = ?').get(vendaId);
  if (!v) return;
  const soma = db.prepare(`SELECT COALESCE(SUM(a.valor), 0) AS t FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id JOIN notas_compra n ON n.id = i.nota_id
      WHERE a.venda_id = ? AND a.destino = 'os' AND n.situacao = 'ativa' AND n.finalidade NOT IN ('devolucao','ajuste')`).get(vendaId).t;
  if (soma > 0) {
    if (v.custo_pecas === null || v.custo_pecas_auto === 1) {
      db.prepare('UPDATE vendas SET custo_pecas = ?, custo_pecas_auto = 1 WHERE id = ?').run(r2(soma), vendaId);
    }
  } else if (v.custo_pecas_auto === 1) {
    db.prepare('UPDATE vendas SET custo_pecas = NULL, custo_pecas_auto = 0 WHERE id = ?').run(vendaId);
  }
}

/** Dá destino a (parte de) um item. Sem `quantidade`, usa tudo o que ainda não tem destino. */
export function alocar(db, itemId, { destino = 'os', vendaId = null, quantidade = null, obs = null }) {
  if (!['os', 'estoque', 'uso_interno', 'devolvido'].includes(destino)) throw new ErroValidacao('Destino inválido.');
  const rest = restanteItem(db, itemId);
  if (!rest) throw new ErroValidacao('Item não encontrado.');
  const notaDoItem = db.prepare('SELECT numero, situacao, finalidade FROM notas_compra WHERE id = ?').get(rest.item.nota_id);
  if (notaDoItem.situacao !== 'ativa') throw new ErroValidacao(`A nota ${notaDoItem.numero} está cancelada: a peça não pode ser aplicada.`);
  if (notaDoItem.finalidade === 'devolucao' || notaDoItem.finalidade === 'ajuste') throw new ErroValidacao(`A nota ${notaDoItem.numero} é de ${notaDoItem.finalidade === 'devolucao' ? 'devolução' : 'ajuste'}: não tem peça para aplicar.`);
  if (rest.quantidade <= EPS) throw new ErroValidacao('Este item já tem destino para toda a quantidade.');
  const qtd = quantidade === null || quantidade === undefined || quantidade === '' ? rest.quantidade : Number(quantidade);
  if (!(qtd > 0)) throw new ErroValidacao('A quantidade precisa ser maior que zero.');
  if (qtd > rest.quantidade + EPS) throw new ErroValidacao(`Só restam ${rest.quantidade} para dar destino.`);
  if (destino === 'os') {
    const v = db.prepare('SELECT id, situacao FROM vendas WHERE id = ?').get(vendaId);
    if (!v) throw new ErroValidacao('Escolha a OS onde a peça foi aplicada.');
    if (v.situacao === 'orcamento' || v.situacao === 'cancelada' || v.situacao === 'saldo') throw new ErroValidacao('Só dá para aplicar peça em OS aberta ou concluída.');
  }
  const total = qtd >= rest.quantidade - EPS ? rest.valor : r2((rest.item.custo_total * qtd) / rest.item.quantidade);
  const id = Number(db.prepare('INSERT INTO alocacoes (item_id, destino, venda_id, quantidade, valor, obs) VALUES (?, ?, ?, ?, ?, ?)')
    .run(itemId, destino, destino === 'os' ? vendaId : null, qtd, total, obs).lastInsertRowid);
  if (destino === 'os') recalcularCustoOs(db, vendaId);
  return { id, valor: total };
}

export function removerAlocacao(db, id) {
  const a = db.prepare('SELECT * FROM alocacoes WHERE id = ?').get(id);
  if (!a) return false;
  db.prepare('DELETE FROM alocacoes WHERE id = ?').run(id);
  if (a.venda_id) recalcularCustoOs(db, a.venda_id);
  return true;
}

/** Toda a nota (o que ainda não tem destino) para uma OS: o caso comum de compra feita para um carro só. */
export function alocarNotaNaOs(db, notaId, vendaId) {
  const itens = db.prepare('SELECT id FROM nota_itens WHERE nota_id = ?').all(notaId);
  let n = 0;
  db.transaction(() => {
    for (const i of itens) {
      const r = restanteItem(db, i.id);
      if (r.quantidade > EPS) { alocar(db, i.id, { destino: 'os', vendaId }); n++; }
    }
  })();
  return n;
}

const REGEX_PLACA = /\b([A-Z]{3}-?[0-9][A-Z0-9][0-9]{2})\b/g;

/** Sugere a OS de cada item: pelo nº do pedido (xPed) ou pela placa escrita nas informações da nota. */
export function sugerirAlocacoes(db, notaId) {
  const nota = db.prepare('SELECT * FROM notas_compra WHERE id = ?').get(notaId);
  if (!nota) return [];
  const de = somarDias(nota.data_emissao, -45);
  const ate = somarDias(nota.data_emissao, 10);
  const vendas = db.prepare(`SELECT v.id, v.numero, v.placa, v.data, v.veiculo, c.nome AS cliente FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id
      WHERE v.situacao IN ('aberta','concluida') AND v.data >= ? AND v.data <= ? ORDER BY v.data DESC`).all(de, ate);
  const placas = [...String(nota.info_compl ?? '').toUpperCase().matchAll(REGEX_PLACA)].map((m) => normalizarPlaca(m[1]));
  const itens = db.prepare('SELECT * FROM nota_itens WHERE nota_id = ? ORDER BY n_item').all(notaId);
  const out = [];
  for (const it of itens) {
    const rest = restanteItem(db, it.id);
    if (rest.quantidade <= EPS) continue;
    // xPed costuma vir como "OS1043" ou "1043/2": testa cada grupo de números contra as OS e só aceita se exatamente uma bater
    const grupos = (String(it.x_ped ?? '').match(/\d+/g) ?? []).map((g) => g.replace(/^0+/, '')).filter((g) => g.length >= 3);
    let achado = null;
    if (grupos.length) {
      const bate = vendas.filter((x) => grupos.includes(String(x.numero ?? '').replace(/\D/g, '').replace(/^0+/, '')));
      if (bate.length === 1) achado = { venda: bate[0], motivo: `pedido ${it.x_ped} = OS ${bate[0].numero}` };
      else if (bate.length > 1) achado = { venda: bate[0], motivo: `pedido ${it.x_ped} bate com mais de uma OS`, ambigua: true };
    }
    const placasDoItem = [...placas, ...[...String(it.info_adic ?? '').toUpperCase().matchAll(REGEX_PLACA)].map((m) => normalizarPlaca(m[1]))];
    if (!achado && placasDoItem.length) {
      const cand = vendas.filter((x) => x.placa && placasDoItem.includes(x.placa));
      if (cand.length) {
        cand.sort((a, b) => Math.abs(Date.parse(a.data) - Date.parse(nota.data_emissao)) - Math.abs(Date.parse(b.data) - Date.parse(nota.data_emissao)));
        achado = { venda: cand[0], motivo: `placa ${cand[0].placa} citada na nota`, ambigua: cand.length > 1 };
      }
    }
    if (achado) out.push({ item_id: it.id, descricao: it.descricao, quantidade: rest.quantidade, valor: rest.valor, venda_id: achado.venda.id, os: achado.venda.numero, cliente: achado.venda.cliente, motivo: achado.motivo, ambigua: !!achado.ambigua });
  }
  return out;
}

/** Refaz o custo das OS que receberam peças desta nota (nota cancelada deixa de contar, nota reativada volta a contar). */
export function recalcularCustoDasOsDaNota(db, notaId) {
  const vendas = db.prepare(`SELECT DISTINCT a.venda_id FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id WHERE i.nota_id = ? AND a.venda_id IS NOT NULL`).all(notaId);
  for (const v of vendas) recalcularCustoOs(db, v.venda_id);
}

/** Marca a nota como cancelada ou ativa. Cancelamento que veio da SEFAZ (protocolo ou evento) não se desfaz aqui. */
export function definirSituacaoNota(db, notaId, situacao, motivo = null) {
  const n = db.prepare('SELECT id, numero, situacao, cancelada_por FROM notas_compra WHERE id = ?').get(notaId);
  if (!n) return null;
  if (situacao === n.situacao) return n;
  if (situacao === 'ativa' && n.cancelada_por === 'sefaz') {
    throw new ErroValidacao('Esta nota foi cancelada pela SEFAZ (consta no protocolo ou no XML de cancelamento). Não dá para reativar aqui: peça a nota correta ao fornecedor.');
  }
  const m = String(motivo ?? '').trim();
  if (m.length < 5) throw new ErroValidacao('Explique o motivo em poucas palavras (mínimo 5 letras).');
  db.transaction(() => {
    db.prepare('UPDATE notas_compra SET situacao = ?, cancelada_por = ?, obs = ? WHERE id = ?').run(situacao, situacao === 'cancelada' ? 'manual' : null, m.slice(0, 300), notaId);
    registrar(db, situacao === 'cancelada' ? 'nota_cancelada' : 'nota_reativada', 'nota', notaId, m);
    recalcularCustoDasOsDaNota(db, notaId);
  })();
  return { ...n, situacao };
}
