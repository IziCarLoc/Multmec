// Ocorrências de auditoria de compras. Cada regra produz itens {chave, tipo, severidade, titulo, detalhe, entidade, id, ...}.
// A ordem de importância: alta (pode ser prejuízo ou golpe) > media (conferir) > baixa (higiene) .
import { lerConfig } from './db.js';
import { r2, somarDias, diasEntre } from './util.js';
import { formatarCnpj } from './documentos.js';
import { ErroValidacao } from './validar.js';

const reais = (v) => Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const dataBR = (d) => (d ? d.split('-').reverse().join('/') : '');
const raiz = (c) => String(c ?? '').slice(0, 8);
const ORDEM = { alta: 0, media: 1, baixa: 2 };

export function ocorrencias(db, hojeStr, cfg = lerConfig(db)) {
  const tol = cfg.toleranciaValor ?? 0.05;
  const out = [];
  const add = (o) => out.push({ ...o, chave: `${o.tipo}:${o.entidade}:${o.id}`, boletos: o.boletos ?? [], notas: o.notas ?? [] });
  const aceites = new Map(db.prepare('SELECT * FROM auditoria_aceites').all().map((a) => [a.chave, a]));

  const boletos = db.prepare(`SELECT b.*, f.nome AS fornecedor, f.cnpj AS fornecedor_cnpj FROM boletos b
      LEFT JOIN fornecedores f ON f.id = b.fornecedor_id WHERE b.situacao <> 'cancelado'`).all();
  const concs = db.prepare(`SELECT c.*, n.numero AS nota_numero, n.cnpj_emitente, n.cnpj_destinatario, n.situacao AS nota_situacao, n.valor_total AS nota_total,
      d.valor AS dup_valor, d.vencimento AS dup_venc FROM conciliacoes c JOIN notas_compra n ON n.id = c.nota_id
      LEFT JOIN nota_duplicatas d ON d.id = c.duplicata_id`).all();
  const porBoleto = new Map();
  const porNota = new Map();
  for (const c of concs) {
    (porBoleto.get(c.boleto_id) ?? porBoleto.set(c.boleto_id, []).get(c.boleto_id)).push(c);
    (porNota.get(c.nota_id) ?? porNota.set(c.nota_id, []).get(c.nota_id)).push(c);
  }
  const nome = (b) => b.fornecedor ?? 'Fornecedor não informado';

  if (!cfg.cnpjOficina) {
    add({ tipo: 'config_cnpj_oficina', severidade: 'baixa', entidade: 'config', id: 0, titulo: 'CNPJ da oficina não informado',
      detalhe: 'Informe o CNPJ da oficina em Metas para o sistema conferir se a nota foi emitida para vocês e se o pagador do boleto é vocês.' });
  }

  // ---------------------------------------------------------------- boletos
  for (const b of boletos) {
    const cs = porBoleto.get(b.id) ?? [];
    const resumoB = `${nome(b)}: ${reais(b.valor)}, vence ${dataBR(b.vencimento)}`;
    if (!cs.length) {
      add({ tipo: 'boleto_sem_nota', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
        titulo: b.situacao === 'pago' ? 'Boleto PAGO sem nota fiscal ligada' : 'Boleto sem nota fiscal',
        detalhe: `${resumoB}. Nenhuma nota fiscal explica este boleto. Peça a nota ao fornecedor (ou confirme que o boleto é da oficina) antes de pagar.` });
    } else {
      const soma = cs.reduce((a, c) => a + c.valor, 0);
      const dif = r2(b.valor - soma);
      if (dif > tol) {
        add({ tipo: 'boleto_valor_diverge', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: cs.map((c) => c.nota_id), valor: dif, data: b.vencimento,
          titulo: 'Boleto cobra mais do que as notas explicam',
          detalhe: `${resumoB}. As notas ligadas explicam ${reais(soma)}; sobram ${reais(dif)}. Pode ser juros/multa, nota faltando ou cobrança indevida.` });
      } else if (dif < -tol) {
        add({ tipo: 'boleto_valor_diverge', severidade: 'baixa', entidade: 'boleto', id: b.id, boletos: [b.id], notas: cs.map((c) => c.nota_id), valor: -dif, data: b.vencimento,
          titulo: 'Valor ligado às notas é maior que o boleto', detalhe: `${resumoB}. Você ligou ${reais(soma)} de notas a um boleto de ${reais(b.valor)}: confira a ligação.` });
      }
      for (const c of cs) {
        if (c.dup_venc && c.dup_venc !== b.vencimento) {
          add({ tipo: 'boleto_venc_diverge', severidade: 'baixa', entidade: 'boleto', id: b.id, boletos: [b.id], notas: [c.nota_id], data: b.vencimento,
            titulo: 'Vencimento diferente da parcela da nota', detalhe: `${resumoB}, mas a parcela da nota ${c.nota_numero} vence em ${dataBR(c.dup_venc)}.` });
          break;
        }
      }
      for (const c of cs) {
        if (c.nota_situacao === 'cancelada') {
          add({ tipo: 'nota_cancelada_com_boleto', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: [c.nota_id], valor: b.valor, data: b.vencimento,
            titulo: 'Boleto de nota CANCELADA', detalhe: `${resumoB}. A nota ${c.nota_numero} foi cancelada: não deveria haver cobrança.` });
          break;
        }
      }
    }
    // beneficiário x emitente / fornecedor
    if (b.beneficiario_cnpj) {
      const esperados = [...new Set([b.fornecedor_cnpj, ...cs.map((c) => c.cnpj_emitente)].filter(Boolean))];
      const diverge = esperados.find((e) => e !== b.beneficiario_cnpj);
      if (diverge) {
        const mesmaRaiz = raiz(diverge) === raiz(b.beneficiario_cnpj);
        add({ tipo: 'boleto_beneficiario_diverge', severidade: mesmaRaiz ? 'media' : 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
          titulo: mesmaRaiz ? 'Boleto emitido por outra filial do fornecedor' : 'Beneficiário do boleto NÃO é o fornecedor',
          detalhe: `${resumoB}. O boleto é de ${formatarCnpj(b.beneficiario_cnpj)} e o fornecedor/nota é ${formatarCnpj(diverge)}.${mesmaRaiz ? ' Mesma raiz de CNPJ: confirme se é filial.' : ' Pode ser boleto trocado ou golpe: confirme por telefone com o fornecedor antes de pagar.'}` });
      }
    }
    if (b.pagador_cnpj && cfg.cnpjOficina && b.pagador_cnpj !== cfg.cnpjOficina) {
      const mesmaRaiz = raiz(b.pagador_cnpj) === raiz(cfg.cnpjOficina);
      add({ tipo: 'boleto_pagador_diverge', severidade: mesmaRaiz ? 'media' : 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
        titulo: 'Boleto emitido contra outro CNPJ', detalhe: `${resumoB}. O pagador do boleto é ${formatarCnpj(b.pagador_cnpj)}, não a oficina (${formatarCnpj(cfg.cnpjOficina)}). Este boleto pode não ser de vocês.` });
    }
    if (b.situacao === 'aberto' && b.vencimento < hojeStr) {
      add({ tipo: 'boleto_vencido', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
        titulo: 'Boleto vencido e não pago', detalhe: `${resumoB} (${diasEntre(b.vencimento, hojeStr)} dia(s) de atraso). Pergunte o valor atualizado com juros e multa.` });
    }
  }

  // duplicidade provável: mesmo fornecedor, valor e vencimento
  const grupos = new Map();
  for (const b of boletos) {
    if (!b.fornecedor_id) continue;
    const k = `${b.fornecedor_id}|${b.valor}|${b.vencimento}`;
    (grupos.get(k) ?? grupos.set(k, []).get(k)).push(b);
  }
  for (const lista of grupos.values()) {
    if (lista.length > 1) {
      const [primeiro] = lista;
      add({ tipo: 'boleto_duplicado', severidade: 'alta', entidade: 'boleto', id: primeiro.id, boletos: lista.map((x) => x.id), valor: primeiro.valor, data: primeiro.vencimento,
        titulo: 'Possível boleto em duplicidade', detalhe: `${lista.length} boletos de ${nome(primeiro)} com o mesmo valor (${reais(primeiro.valor)}) e vencimento (${dataBR(primeiro.vencimento)}). Pague só um, depois de confirmar com o fornecedor.` });
    }
  }

  // ---------------------------------------------------------------- notas
  const notas = db.prepare(`SELECT n.*, f.nome AS fornecedor FROM notas_compra n JOIN fornecedores f ON f.id = n.fornecedor_id`).all();
  for (const n of notas) {
    const cs = porNota.get(n.id) ?? [];
    const conciliado = cs.reduce((a, c) => a + c.valor, 0);
    if (n.cnpj_destinatario && cfg.cnpjOficina && n.cnpj_destinatario !== cfg.cnpjOficina) {
      add({ tipo: 'nota_destinatario_diverge', severidade: raiz(n.cnpj_destinatario) === raiz(cfg.cnpjOficina) ? 'media' : 'alta', entidade: 'nota', id: n.id, notas: [n.id], boletos: cs.map((c) => c.boleto_id), valor: n.valor_total, data: n.data_emissao,
        titulo: 'Nota emitida para outro CNPJ', detalhe: `Nota ${n.numero} de ${n.fornecedor} (${reais(n.valor_total)}) está em nome de ${formatarCnpj(n.cnpj_destinatario)}${n.nome_destinatario ? ` (${n.nome_destinatario})` : ''}, e não da oficina.` });
    }
    if (conciliado > n.valor_total + tol) {
      add({ tipo: 'nota_cobrada_a_mais', severidade: 'alta', entidade: 'nota', id: n.id, notas: [n.id], boletos: cs.map((c) => c.boleto_id), valor: r2(conciliado - n.valor_total), data: n.data_emissao,
        titulo: 'Boletos somam mais que a nota', detalhe: `Nota ${n.numero} de ${n.fornecedor}: ${reais(n.valor_total)}. Os boletos ligados somam ${reais(conciliado)}. Possível cobrança em duplicidade.` });
    }
    if (n.finalidade === 'devolucao') continue;       // devolução é crédito, não gera boleto
    if (n.situacao === 'cancelada') continue;
    const saldo = r2(n.valor_total - conciliado);
    if (saldo > tol) {
      const dups = db.prepare(`SELECT d.*, d.valor - COALESCE((SELECT SUM(c.valor) FROM conciliacoes c WHERE c.duplicata_id = d.id), 0) AS saldo
          FROM nota_duplicatas d WHERE d.nota_id = ? ORDER BY d.vencimento`).all(n.id).filter((d) => d.saldo > tol);
      if (dups.length) {
        const prox = dups[0];
        if (prox.vencimento <= somarDias(hojeStr, cfg.diasNotaSemBoleto ?? 5)) {
          add({ tipo: 'nota_sem_boleto', severidade: 'media', entidade: 'nota', id: n.id, notas: [n.id], valor: r2(dups.reduce((a, d) => a + d.saldo, 0)), data: prox.vencimento,
            titulo: prox.vencimento < hojeStr ? 'Parcela vencida sem boleto' : 'Parcela perto de vencer sem boleto',
            detalhe: `Nota ${n.numero} de ${n.fornecedor}: parcela de ${reais(prox.saldo)} ${prox.vencimento < hojeStr ? 'venceu' : 'vence'} em ${dataBR(prox.vencimento)} e nenhum boleto foi ligado. Se já foi paga de outro jeito (Pix, cartão, dinheiro), marque como conferida com o motivo.` });
        }
      } else if (diasEntre(n.data_emissao, hojeStr) >= 3) {
        add({ tipo: 'nota_sem_boleto', severidade: 'baixa', entidade: 'nota', id: n.id, notas: [n.id], valor: saldo, data: n.data_emissao,
          titulo: 'Nota sem boleto e sem parcelas', detalhe: `Nota ${n.numero} de ${n.fornecedor} (${reais(saldo)}) não tem boleto nem parcelas informadas. Se foi paga à vista (Pix, cartão, dinheiro), marque como conferida com o motivo.` });
      }
    }
    if (n.origem === 'manual' && !n.chave && n.valor_total >= 100) {
      add({ tipo: 'nota_sem_chave', severidade: 'baixa', entidade: 'nota', id: n.id, notas: [n.id], valor: n.valor_total, data: n.data_emissao,
        titulo: 'Nota digitada sem chave de acesso', detalhe: `Nota ${n.numero} de ${n.fornecedor} foi lançada à mão. Sem a chave de 44 dígitos não dá para provar que ela existe; peça o XML ou consulte o portal da NF-e.` });
    }
    // peças sem destino
    if (diasEntre(n.data_emissao, hojeStr) >= (cfg.diasNotaSemDestino ?? 7)) {
      const itens = db.prepare(`SELECT i.id, i.descricao, i.quantidade, i.custo_total,
          i.quantidade - COALESCE((SELECT SUM(a.quantidade) FROM alocacoes a WHERE a.item_id = i.id), 0) AS resta_qtd,
          i.custo_total - COALESCE((SELECT SUM(a.valor) FROM alocacoes a WHERE a.item_id = i.id), 0) AS resta_valor
          FROM nota_itens i WHERE i.nota_id = ?`).all(n.id).filter((i) => i.resta_qtd > 1e-6);
      if (itens.length) {
        const valor = r2(itens.reduce((a, i) => a + i.resta_valor, 0));
        add({ tipo: 'nota_sem_destino', severidade: valor >= 50 ? 'media' : 'baixa', entidade: 'nota', id: n.id, notas: [n.id], valor, data: n.data_emissao,
          titulo: 'Peças da nota sem destino', detalhe: `Nota ${n.numero} de ${n.fornecedor}: ${itens.length} item(ns), ${reais(valor)}, sem OS, estoque ou uso interno. Peça comprada e não aplicada é dinheiro parado ou perdido.` });
      }
    }
    // preço acima do histórico
    if (diasEntre(n.data_emissao, hojeStr) <= 90) {
      const itens = db.prepare('SELECT * FROM nota_itens WHERE nota_id = ? AND codigo IS NOT NULL AND valor_unitario > 0').all(n.id);
      for (const i of itens) {
        const ant = db.prepare(`SELECT i2.valor_unitario FROM nota_itens i2 JOIN notas_compra n2 ON n2.id = i2.nota_id
            WHERE n2.fornecedor_id = ? AND i2.codigo = ? AND (n2.data_emissao < ? OR (n2.data_emissao = ? AND n2.id < ?)) AND n2.finalidade <> 'devolucao' AND i2.valor_unitario > 0
            ORDER BY n2.data_emissao DESC, n2.id DESC LIMIT 5`).all(n.fornecedor_id, i.codigo, n.data_emissao, n.data_emissao, n.id).map((x) => x.valor_unitario).sort((a, b) => a - b);
        if (ant.length >= 2) {
          const mediana = ant[Math.floor(ant.length / 2)];
          if (i.valor_unitario > mediana * (1 + (cfg.variacaoPrecoPct ?? 0.15)) && i.valor_unitario - mediana >= 3) {
            add({ tipo: 'preco_acima', severidade: 'baixa', entidade: 'item', id: i.id, notas: [n.id], valor: r2(i.valor_unitario - mediana), data: n.data_emissao,
              titulo: 'Preço acima do que costuma ser', detalhe: `${i.descricao} (nota ${n.numero}, ${n.fornecedor}): ${reais(i.valor_unitario)} cada; nas últimas compras a mediana foi ${reais(mediana)} (+${Math.round((i.valor_unitario / mediana - 1) * 100)}%).` });
          }
        }
      }
    }
  }

  // ---------------------------------------------------------------- OS
  const desde = cfg.auditoriaDesde || `${hojeStr.slice(0, 7)}-01`;
  const vendas = db.prepare(`SELECT v.id, v.numero, v.placa, v.veiculo, v.data, v.custo_pecas, v.custo_pecas_auto,
      COALESCE((SELECT SUM(a.valor) FROM alocacoes a WHERE a.venda_id = v.id AND a.destino = 'os'), 0) AS nf_valor,
      (SELECT COUNT(*) FROM alocacoes a WHERE a.venda_id = v.id AND a.destino = 'os') AS nf_qtd
      FROM vendas v WHERE v.situacao IN ('concluida','aberta') AND v.custo_pecas IS NOT NULL`).all();
  const semNota = [];
  for (const v of vendas) {
    const rotulo = `OS ${v.numero ?? v.id} ${v.placa ?? ''}`.trim();
    if (v.nf_qtd > 0 && !v.custo_pecas_auto && Math.abs(v.custo_pecas - v.nf_valor) > Math.max(5, v.custo_pecas * 0.05)) {
      add({ tipo: 'custo_os_diverge', severidade: 'media', entidade: 'os', id: v.id, valor: r2(v.custo_pecas - v.nf_valor), data: v.data,
        titulo: 'Custo da OS diferente das notas', detalhe: `${rotulo}: custo digitado ${reais(v.custo_pecas)}, mas as notas ligadas somam ${reais(v.nf_valor)}. Um dos dois está errado.` });
    }
    if (v.nf_qtd === 0 && v.data >= desde && v.custo_pecas >= 30) semNota.push(v);
  }
  if (semNota.length) {
    const total = r2(semNota.reduce((a, v) => a + v.custo_pecas, 0));
    const maiores = [...semNota].sort((a, b) => b.custo_pecas - a.custo_pecas).slice(0, 3).map((v) => `OS ${v.numero ?? v.id} (${reais(v.custo_pecas)})`).join(', ');
    add({ tipo: 'os_sem_nota', severidade: 'baixa', entidade: 'os', id: 0, valor: total, data: desde,
      titulo: `${semNota.length} OS com custo de peça e nenhuma nota ligada`,
      detalhe: `Desde ${dataBR(desde)}: ${reais(total)} de custo de peças que não se prova com nota. Maiores: ${maiores}. Ligue as peças às OS em Compras > Notas para saber o custo real e achar compra sem destino.` });
  }

  // ---------------------------------------------------------------- fornecedores
  const semCnpj = db.prepare(`SELECT f.id, f.nome FROM fornecedores f WHERE f.cnpj IS NULL AND f.ativo = 1
      AND (EXISTS (SELECT 1 FROM notas_compra n WHERE n.fornecedor_id = f.id) OR EXISTS (SELECT 1 FROM boletos b WHERE b.fornecedor_id = f.id))`).all();
  for (const f of semCnpj) {
    add({ tipo: 'fornecedor_sem_cnpj', severidade: 'baixa', entidade: 'fornecedor', id: f.id,
      titulo: 'Fornecedor sem CNPJ cadastrado', detalhe: `${f.nome}: sem o CNPJ o sistema não consegue conferir se o beneficiário do boleto é mesmo o fornecedor.` });
  }

  return out
    .map((o) => ({ ...o, aceita: aceites.get(o.chave) ? { motivo: aceites.get(o.chave).motivo, em: aceites.get(o.chave).em } : null }))
    .sort((a, b) => ORDEM[a.severidade] - ORDEM[b.severidade] || (b.valor ?? 0) - (a.valor ?? 0));
}

export function ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg = lerConfig(db)) {
  return ocorrencias(db, hojeStr, cfg).filter((o) => o.boletos.includes(boletoId));
}

export function resumoAuditoria(lista) {
  const abertas = lista.filter((o) => !o.aceita);
  const conta = (s) => abertas.filter((o) => o.severidade === s).length;
  return {
    alta: conta('alta'), media: conta('media'), baixa: conta('baixa'), total: abertas.length,
    aceitas: lista.length - abertas.length,
    valorEmRisco: r2(abertas.filter((o) => o.severidade === 'alta' && ['boleto_sem_nota', 'boleto_valor_diverge', 'boleto_beneficiario_diverge', 'boleto_pagador_diverge', 'boleto_duplicado', 'nota_cancelada_com_boleto'].includes(o.tipo)).reduce((a, o) => a + (o.valor ?? 0), 0)),
  };
}

export function aceitarOcorrencia(db, chave, motivo) {
  const m = String(motivo ?? '').trim();
  if (m.length < 5) throw new ErroValidacao('Explique em poucas palavras por que está certo (mínimo 5 letras).');
  db.prepare('INSERT INTO auditoria_aceites (chave, motivo) VALUES (?, ?) ON CONFLICT(chave) DO UPDATE SET motivo = excluded.motivo, em = datetime(\'now\')').run(String(chave).slice(0, 80), m.slice(0, 300));
}

export function desfazerAceite(db, chave) {
  return db.prepare('DELETE FROM auditoria_aceites WHERE chave = ?').run(chave).changes > 0;
}
