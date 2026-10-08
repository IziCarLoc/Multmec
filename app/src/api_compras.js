import express from 'express';
import { gunzipSync } from 'node:zlib';
import { lerConfig } from './db.js';
import * as V from './validar.js';
import { hoje as hojeBR, mesDe, r2, somarDias } from './util.js';
import { normalizarCnpj, cnpjValido, formatarCnpj } from './documentos.js';
import { interpretarBoleto, nomeBanco } from './boleto.js';
import { lerTextoDoBoleto } from './boleto_texto.js';
import {
  garantirFornecedor, importarNotaXml, criarNotaManual, notaComSaldo, restanteItem, alocar, removerAlocacao, alocarNotaNaOs, sugerirAlocacoes, recalcularCustoOs,
} from './compras.js';
import {
  criarBoleto, sugerirNotas, conciliar, desconciliar, pagarBoleto, cancelarBoleto, duplicatasComSaldo, ErroBloqueio,
} from './boletos.js';
import { ocorrencias, ocorrenciasDoBoleto, resumoAuditoria, aceitarOcorrencia, desfazerAceite } from './auditoria.js';

export function criarApiCompras(db, { agora = () => new Date() } = {}) {
  const r = express.Router();
  const hoje = () => hojeBR(agora());
  const wrap = (fn) => (req, res, next) => { try { fn(req, res, next); } catch (e) { next(e); } };

  // ------------------------------------------------------------ painel de compras
  r.get('/resumo', wrap((req, res) => {
    const h = hoje();
    const cfg = lerConfig(db);
    const lista = ocorrencias(db, h, cfg);
    const bol = db.prepare("SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS v FROM boletos WHERE situacao = 'aberto'").get();
    const prox = db.prepare("SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS v FROM boletos WHERE situacao = 'aberto' AND vencimento <= ?").get(somarDias(h, 7));
    const mes = db.prepare("SELECT COUNT(*) AS n, COALESCE(SUM(valor_total), 0) AS v FROM notas_compra WHERE substr(data_emissao, 1, 7) = ? AND finalidade NOT IN ('devolucao','ajuste') AND situacao = 'ativa'").get(mesDe(h));
    const cob = db.prepare(`SELECT COALESCE(SUM(a.valor), 0) AS nf FROM alocacoes a WHERE a.destino = 'os'`).get();
    res.json({
      hoje: h, auditoria: resumoAuditoria(lista),
      boletosAbertos: { qtd: bol.n, valor: r2(bol.v) }, boletosSemana: { qtd: prox.n, valor: r2(prox.v) },
      notasMes: { qtd: mes.n, valor: r2(mes.v) }, custoLigadoAOs: r2(cob.nf),
      cnpjOficinaConfigurado: !!cfg.cnpjOficina,
      totais: {
        notas: db.prepare('SELECT COUNT(*) AS n FROM notas_compra').get().n,
        boletos: db.prepare('SELECT COUNT(*) AS n FROM boletos').get().n,
        fornecedores: db.prepare('SELECT COUNT(*) AS n FROM fornecedores').get().n,
      },
    });
  }));

  r.get('/ocorrencias', wrap((req, res) => {
    const lista = ocorrencias(db, hoje(), lerConfig(db));
    const filtro = req.query.tipo ? lista.filter((o) => o.tipo === String(req.query.tipo)) : lista;
    res.json({ resumo: resumoAuditoria(lista), ocorrencias: req.query.aceitas === '1' ? filtro : filtro.filter((o) => !o.aceita), aceitas: filtro.filter((o) => o.aceita) });
  }));
  r.post('/ocorrencias/aceitar', wrap((req, res) => {
    const chave = V.texto(req.body?.chave, { campo: 'a ocorrência', obrigatorio: true, max: 80 });
    if (!/^[a-z_]+:[a-z]+:\d+$/.test(chave)) throw new V.ErroValidacao('Ocorrência inválida.');
    aceitarOcorrencia(db, chave, req.body?.motivo);
    res.json({ ok: true });
  }));
  r.delete('/ocorrencias/aceite', wrap((req, res) => {
    res.json({ ok: desfazerAceite(db, String(req.query.chave ?? '')) });
  }));

  // ------------------------------------------------------------ fornecedores
  const fornecedorDe = (b, atual = {}) => {
    const cnpj = normalizarCnpj(b.cnpj ?? atual.cnpj) || null;
    if (cnpj && !cnpjValido(cnpj)) throw new V.ErroValidacao('O CNPJ não passa na validação (confira os números).');
    return {
      nome: V.texto(b.nome ?? atual.nome, { campo: 'o nome', obrigatorio: true, max: 80 }).toUpperCase(),
      cnpj,
      principal: b.principal === undefined ? (atual.principal ?? 0) : (b.principal ? 1 : 0),
      ativo: b.ativo === undefined ? (atual.ativo ?? 1) : (b.ativo ? 1 : 0),
      obs: V.texto(b.obs ?? atual.obs, { campo: 'a observação', max: 300 }),
    };
  };
  r.get('/fornecedores', wrap((req, res) => {
    const linhas = db.prepare(`SELECT f.*,
        (SELECT COALESCE(SUM(n.valor_total), 0) FROM notas_compra n WHERE n.fornecedor_id = f.id AND n.situacao = 'ativa' AND n.finalidade NOT IN ('devolucao','ajuste')) AS comprado,
        (SELECT COUNT(*) FROM notas_compra n WHERE n.fornecedor_id = f.id) AS notas,
        (SELECT COALESCE(SUM(b.valor), 0) FROM boletos b WHERE b.fornecedor_id = f.id AND b.situacao = 'aberto') AS boletos_abertos,
        (SELECT COUNT(*) FROM boletos b WHERE b.fornecedor_id = f.id AND b.situacao IN ('aberto','pago') AND NOT EXISTS (SELECT 1 FROM conciliacoes c WHERE c.boleto_id = b.id)) AS boletos_sem_nota
        FROM fornecedores f ORDER BY f.ativo DESC, f.principal DESC, f.nome`).all();
    res.json(linhas.map((f) => ({ ...f, comprado: r2(f.comprado), boletos_abertos: r2(f.boletos_abertos), cnpj_formatado: f.cnpj ? formatarCnpj(f.cnpj) : null })));
  }));
  r.post('/fornecedores', wrap((req, res) => {
    const f = fornecedorDe(req.body || {});
    try {
      const id = Number(db.prepare('INSERT INTO fornecedores (nome, cnpj, principal, ativo, obs) VALUES (@nome, @cnpj, @principal, @ativo, @obs)').run(f).lastInsertRowid);
      res.status(201).json({ id, ...f });
    } catch (e) {
      if (String(e.message).includes('UNIQUE')) throw new V.ErroValidacao('Já existe fornecedor com esse nome ou CNPJ.');
      throw e;
    }
  }));
  r.put('/fornecedores/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const atual = db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(id);
    if (!atual) return res.status(404).json({ erro: 'Fornecedor não encontrado.' });
    const f = fornecedorDe(req.body || {}, atual);
    try { db.prepare('UPDATE fornecedores SET nome=@nome, cnpj=@cnpj, principal=@principal, ativo=@ativo, obs=@obs WHERE id=@id').run({ ...f, id }); } catch (e) {
      if (String(e.message).includes('UNIQUE')) throw new V.ErroValidacao('Já existe fornecedor com esse nome ou CNPJ.');
      throw e;
    }
    res.json({ id, ...f });
  }));
  r.get('/fornecedores/:id/extrato', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const f = db.prepare('SELECT * FROM fornecedores WHERE id = ?').get(id);
    if (!f) return res.status(404).json({ erro: 'Fornecedor não encontrado.' });
    const notas = db.prepare('SELECT id FROM notas_compra WHERE fornecedor_id = ? ORDER BY data_emissao DESC LIMIT 200').all(id).map((n) => notaComSaldo(db, n.id));
    const boletos = db.prepare('SELECT b.*, (SELECT COUNT(*) FROM conciliacoes c WHERE c.boleto_id = b.id) AS ligacoes FROM boletos b WHERE b.fornecedor_id = ? ORDER BY b.vencimento DESC LIMIT 200').all(id);
    const t = (fn) => r2(fn.reduce((a, x) => a + x, 0));
    res.json({
      fornecedor: f,
      totais: {
        comprado: t(notas.filter((n) => n.situacao === 'ativa' && !['devolucao', 'ajuste'].includes(n.finalidade)).map((n) => n.valor_total)),
        devolvido: t(notas.filter((n) => n.finalidade === 'devolucao').map((n) => n.valor_total)),
        notasSemBoleto: t(notas.filter((n) => n.situacao === 'ativa' && !['devolucao', 'ajuste'].includes(n.finalidade) && n.saldo > 0.05).map((n) => n.saldo)),
        boletosAbertos: t(boletos.filter((b) => b.situacao === 'aberto').map((b) => b.valor)),
        boletosPagos: t(boletos.filter((b) => b.situacao === 'pago').map((b) => b.valor)),
        boletosSemNota: t(boletos.filter((b) => ['aberto', 'pago'].includes(b.situacao) && !b.ligacoes).map((b) => b.valor)),
      },
      notas, boletos,
    });
  }));

  // ------------------------------------------------------------ notas
  r.get('/notas', wrap((req, res) => {
    const where = ['1=1'];
    const p = {};
    if (req.query.fornecedor_id) { where.push('n.fornecedor_id = @f'); p.f = V.idDe(req.query.fornecedor_id); }
    if (req.query.mes) { where.push('substr(n.data_emissao, 1, 7) = @m'); p.m = V.mes(req.query.mes); }
    if (req.query.q) { where.push("(n.numero LIKE '%' || @q || '%' OR f.nome LIKE '%' || @q || '%' OR n.chave LIKE '%' || @q || '%')"); p.q = String(req.query.q).trim().slice(0, 44); }
    const linhas = db.prepare(`SELECT n.id, n.fornecedor_id, n.chave, n.numero, n.serie, n.data_emissao, n.valor_total, n.finalidade, n.situacao, n.origem, f.nome AS fornecedor,
        n.valor_total - COALESCE((SELECT SUM(c.valor) FROM conciliacoes c WHERE c.nota_id = n.id), 0) AS saldo,
        (SELECT COALESCE(SUM(i.quantidade - COALESCE((SELECT SUM(a.quantidade) FROM alocacoes a WHERE a.item_id = i.id), 0)), 0) FROM nota_itens i WHERE i.nota_id = n.id) AS qtd_sem_destino
        FROM notas_compra n JOIN fornecedores f ON f.id = n.fornecedor_id WHERE ${where.join(' AND ')}
        ORDER BY n.data_emissao DESC, n.id DESC LIMIT 300`).all(p);
    res.json(linhas.map((n) => ({ ...n, saldo: r2(n.saldo), sem_destino: n.qtd_sem_destino > 1e-6 })));
  }));

  r.get('/notas/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const nota = notaComSaldo(db, id);
    if (!nota) return res.status(404).json({ erro: 'Nota não encontrada.' });
    nota.tem_xml = !!db.prepare('SELECT xml_gz IS NOT NULL AS t FROM notas_compra WHERE id = ?').get(id).t;
    delete nota.xml_gz;
    const itens = db.prepare('SELECT * FROM nota_itens WHERE nota_id = ? ORDER BY n_item').all(id).map((i) => {
      const rest = restanteItem(db, i.id);
      const aloc = db.prepare(`SELECT a.*, v.numero AS os, v.placa, v.veiculo FROM alocacoes a LEFT JOIN vendas v ON v.id = a.venda_id WHERE a.item_id = ? ORDER BY a.id`).all(i.id);
      return { ...i, restante: rest.quantidade, restante_valor: rest.valor, alocacoes: aloc };
    });
    const conc = db.prepare(`SELECT c.*, b.vencimento, b.valor AS boleto_valor, b.situacao, b.numero_documento FROM conciliacoes c JOIN boletos b ON b.id = c.boleto_id WHERE c.nota_id = ?`).all(id);
    res.json({
      nota, itens, duplicatas: duplicatasComSaldo(db, id), conciliacoes: conc, sugestoes_os: sugerirAlocacoes(db, id),
      ocorrencias: ocorrencias(db, hoje(), lerConfig(db)).filter((o) => o.notas.includes(id) || (o.entidade === 'item' && itens.some((i) => i.id === o.id))),
    });
  }));

  // o XML original (prova da nota) para guardar/enviar ao contador
  r.get('/notas/:id/xml', wrap((req, res) => {
    const n = db.prepare('SELECT chave, numero, xml_gz FROM notas_compra WHERE id = ?').get(V.idDe(req.params.id));
    if (!n?.xml_gz) return res.status(404).json({ erro: 'Esta nota não tem XML guardado.' });
    res.type('application/xml; charset=utf-8').set('Content-Disposition', `attachment; filename="NFe${(n.chave ?? n.numero).replace(/[^0-9A-Za-z]/g, '')}.xml"`).send(gunzipSync(n.xml_gz));
  }));

  r.post('/notas/xml', wrap((req, res) => {
    const lista = Array.isArray(req.body?.xmls) ? req.body.xmls : (req.body?.xml ? [req.body.xml] : []);
    if (!lista.length) throw new V.ErroValidacao('Envie ao menos um XML.');
    if (lista.length > 100) throw new V.ErroValidacao('No máximo 100 arquivos por vez.');
    const resultados = lista.map((xml, i) => {
      try { return { arquivo: req.body?.nomes?.[i] ?? `arquivo ${i + 1}`, ...importarNotaXml(db, String(xml)) }; } catch (e) {
        if (e instanceof V.ErroValidacao) return { arquivo: req.body?.nomes?.[i] ?? `arquivo ${i + 1}`, status: 'erro', erro: e.message };
        throw e;
      }
    });
    res.status(resultados.some((x) => x.status === 'importada' || x.status === 'cancelada') ? 201 : 200).json({ resultados });
  }));

  r.post('/notas', wrap((req, res) => {
    const b = req.body || {};
    const duplicatas = (b.duplicatas ?? []).map((d) => ({ vencimento: V.data(d.vencimento, { campo: 'O vencimento da parcela' }), valor: V.dinheiro(d.valor, { campo: 'o valor da parcela', obrigatorio: true, minimo: 0.01 }) }));
    const nota = criarNotaManual(db, {
      fornecedor_id: b.fornecedorId ? V.idDe(b.fornecedorId) : null, fornecedor_nome: b.fornecedorNome, cnpj_emitente: b.cnpjEmitente,
      numero: V.texto(b.numero, { campo: 'o número da nota', obrigatorio: true, max: 12 }), serie: V.texto(b.serie, { campo: 'a série', max: 4 }),
      data_emissao: V.data(b.dataEmissao, { campo: 'A data de emissão' }), valor_total: V.dinheiro(b.valorTotal, { campo: 'o valor total', obrigatorio: true, minimo: 0.01 }),
      chave: b.chave ? String(b.chave) : null, duplicatas, info_compl: V.texto(b.infoCompl, { campo: 'as informações', max: 300 }),
    });
    res.status(201).json(nota);
  }));

  r.put('/notas/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const n = db.prepare('SELECT * FROM notas_compra WHERE id = ?').get(id);
    if (!n) return res.status(404).json({ erro: 'Nota não encontrada.' });
    const situacao = V.opcao(req.body?.situacao, ['ativa', 'cancelada'], { campo: 'a situação' });
    db.prepare('UPDATE notas_compra SET situacao = ?, obs = COALESCE(?, obs) WHERE id = ?').run(situacao, V.texto(req.body?.obs, { campo: 'a observação', max: 300 }), id);
    res.json({ ok: true });
  }));

  r.delete('/notas/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const ligada = db.prepare('SELECT (SELECT COUNT(*) FROM conciliacoes WHERE nota_id = ?) AS c, (SELECT COUNT(*) FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id WHERE i.nota_id = ?) AS a').get(id, id);
    if (ligada.c || ligada.a) throw new V.ErroValidacao('Esta nota já tem boleto ou peça ligada. Desfaça as ligações antes de apagar (ou marque como cancelada).');
    db.prepare('DELETE FROM notas_compra WHERE id = ?').run(id);
    res.json({ ok: true });
  }));

  // ------------------------------------------------------------ alocação de peças
  r.post('/itens/:id/alocar', wrap((req, res) => {
    const b = req.body || {};
    const out = alocar(db, V.idDe(req.params.id), {
      destino: V.opcao(b.destino, ['os', 'estoque', 'uso_interno', 'devolvido'], { campo: 'o destino', padrao: 'os' }),
      vendaId: b.vendaId ? V.idDe(b.vendaId) : null, quantidade: b.quantidade ?? null, obs: V.texto(b.obs, { campo: 'a observação', max: 200 }),
    });
    res.status(201).json(out);
  }));
  r.delete('/alocacoes/:id', wrap((req, res) => { res.json({ ok: removerAlocacao(db, V.idDe(req.params.id)) }); }));
  r.post('/notas/:id/alocar-os', wrap((req, res) => {
    const n = alocarNotaNaOs(db, V.idDe(req.params.id), V.idDe(req.body?.vendaId));
    res.json({ itens_alocados: n });
  }));
  r.post('/notas/:id/aplicar-sugestoes', wrap((req, res) => {
    const notaId = V.idDe(req.params.id);
    const validas = new Map(sugerirAlocacoes(db, notaId).map((s) => [s.item_id, s]));
    let n = 0;
    db.transaction(() => {
      for (const s of req.body?.itens ?? []) {
        const sug = validas.get(Number(s.item_id));
        if (!sug) continue;                                  // só aplica o que o sistema realmente sugeriu
        alocar(db, sug.item_id, { destino: 'os', vendaId: Number(s.venda_id ?? sug.venda_id) });
        n++;
      }
    })();
    res.json({ aplicadas: n });
  }));
  r.post('/os/:vendaId/usar-custo-das-notas', wrap((req, res) => {
    const id = V.idDe(req.params.vendaId);
    const soma = db.prepare("SELECT COALESCE(SUM(valor), 0) AS t FROM alocacoes WHERE venda_id = ? AND destino = 'os'").get(id).t;
    if (!(soma > 0)) throw new V.ErroValidacao('Esta OS ainda não tem peças ligadas a notas.');
    db.prepare('UPDATE vendas SET custo_pecas = ?, custo_pecas_auto = 1 WHERE id = ?').run(r2(soma), id);
    res.json({ custo_pecas: r2(soma) });
  }));
  r.get('/os/:vendaId', wrap((req, res) => {
    const id = V.idDe(req.params.vendaId);
    const pecas = db.prepare(`SELECT a.id, a.valor, a.quantidade, i.descricao, i.codigo, n.numero AS nota, n.id AS nota_id, f.nome AS fornecedor
        FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id JOIN notas_compra n ON n.id = i.nota_id JOIN fornecedores f ON f.id = n.fornecedor_id
        WHERE a.venda_id = ? AND a.destino = 'os' ORDER BY a.id`).all(id);
    res.json({ pecas, custo_notas: r2(pecas.reduce((a, p) => a + p.valor, 0)) });
  }));
  // itens de nota que ainda não têm destino (para escolher a partir da tela da OS)
  r.get('/itens-livres', wrap((req, res) => {
    const q = String(req.query.q ?? '').trim().slice(0, 40);
    const linhas = db.prepare(`SELECT i.id, i.descricao, i.codigo, i.quantidade, i.custo_total, n.id AS nota_id, n.numero AS nota, n.data_emissao, f.nome AS fornecedor,
        i.quantidade - COALESCE((SELECT SUM(a.quantidade) FROM alocacoes a WHERE a.item_id = i.id), 0) AS restante_qtd,
        i.custo_total - COALESCE((SELECT SUM(a.valor) FROM alocacoes a WHERE a.item_id = i.id), 0) AS restante_valor
        FROM nota_itens i JOIN notas_compra n ON n.id = i.nota_id JOIN fornecedores f ON f.id = n.fornecedor_id
        WHERE n.situacao = 'ativa' AND n.finalidade NOT IN ('devolucao','ajuste') AND n.data_emissao >= @de
          AND (@q = '' OR i.descricao LIKE '%' || @q || '%' OR i.codigo LIKE '%' || @q || '%' OR n.numero LIKE '%' || @q || '%' OR f.nome LIKE '%' || @q || '%')
        ORDER BY n.data_emissao DESC, i.n_item LIMIT 80`).all({ q, de: somarDias(hoje(), -120) });
    res.json(linhas.filter((l) => l.restante_qtd > 1e-6).map((l) => ({ ...l, restante_valor: r2(l.restante_valor) })));
  }));

  r.get('/os-sem-nota', wrap((req, res) => {
    const cfg = lerConfig(db);
    const desde = cfg.auditoriaDesde || `${hoje().slice(0, 7)}-01`;
    const linhas = db.prepare(`SELECT v.id, v.numero, v.placa, v.veiculo, v.data, v.custo_pecas, c.nome AS cliente FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.situacao IN ('concluida','aberta') AND v.custo_pecas >= 30 AND v.data >= ? AND NOT EXISTS (SELECT 1 FROM alocacoes a WHERE a.venda_id = v.id AND a.destino = 'os')
        ORDER BY v.custo_pecas DESC LIMIT 200`).all(desde);
    res.json({ desde, os: linhas });
  }));

  // OS candidatas na hora de aplicar uma peça (por número, placa ou cliente)
  r.get('/os-busca', wrap((req, res) => {
    const q = String(req.query.q ?? '').trim().slice(0, 30);
    if (q.length < 2) return res.json([]);
    res.json(db.prepare(`SELECT v.id, v.numero, v.placa, v.veiculo, v.data, v.situacao, c.nome AS cliente FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.situacao IN ('aberta','concluida') AND (v.numero LIKE '%' || @q || '%' OR v.placa LIKE '%' || upper(replace(@q, '-', '')) || '%' OR c.nome LIKE '%' || @q || '%')
        ORDER BY v.data DESC, v.id DESC LIMIT 12`).all({ q }));
  }));

  // ------------------------------------------------------------ boletos
  r.post('/boletos/ler', wrap((req, res) => {
    const entrada = String(req.body?.linha ?? '').slice(0, 20000);
    // texto inteiro do boleto (copiado do PDF): separa linha digitável, CNPJs e número do documento
    if (/[A-Za-z\u00c0-\u00ff]/.test(entrada) || entrada.replace(/\D/g, '').length > 60) {
      const t = lerTextoDoBoleto(entrada, hoje());
      const extras = { beneficiarioCnpj: t.beneficiarioCnpj, pagadorCnpj: t.pagadorCnpj, numeroDocumento: t.numeroDocumento, avisos: t.avisos };
      return res.json(t.boleto ? { ...t.boleto, texto: true, extras } : { ok: false, texto: true, erros: [t.avisos[0] ?? 'Não encontrei uma linha digitável no texto.'], avisos: [], extras });
    }
    res.json(interpretarBoleto(entrada, hoje()));
  }));

  r.get('/boletos', wrap((req, res) => {
    const where = ['1=1'];
    const p = {};
    if (req.query.situacao) { where.push('b.situacao = @s'); p.s = V.opcao(req.query.situacao, ['aberto', 'pago', 'contestado', 'cancelado'], { campo: 'a situação' }); }
    if (req.query.fornecedor_id) { where.push('b.fornecedor_id = @f'); p.f = V.idDe(req.query.fornecedor_id); }
    if (req.query.sem_nota === '1') where.push("b.situacao IN ('aberto','pago') AND NOT EXISTS (SELECT 1 FROM conciliacoes c WHERE c.boleto_id = b.id)");
    const linhas = db.prepare(`SELECT b.*, f.nome AS fornecedor,
        (SELECT COUNT(*) FROM conciliacoes c WHERE c.boleto_id = b.id) AS ligacoes FROM boletos b LEFT JOIN fornecedores f ON f.id = b.fornecedor_id
        WHERE ${where.join(' AND ')} ORDER BY (b.situacao = 'aberto') DESC, b.vencimento ASC LIMIT 300`).all(p);
    const ocs = ocorrencias(db, hoje(), lerConfig(db)).filter((o) => !o.aceita);
    res.json(linhas.map((b) => {
      const mine = ocs.filter((o) => o.boletos.includes(b.id));
      return { ...b, ocorrencias: mine.length, pior: mine.length ? mine[0].severidade : null };
    }));
  }));

  r.get('/boletos/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const b = db.prepare('SELECT b.*, f.nome AS fornecedor FROM boletos b LEFT JOIN fornecedores f ON f.id = b.fornecedor_id WHERE b.id = ?').get(id);
    if (!b) return res.status(404).json({ erro: 'Boleto não encontrado.' });
    const cfg = lerConfig(db);
    const conc = db.prepare(`SELECT c.*, n.numero AS nota_numero, n.data_emissao, n.valor_total AS nota_total, n.chave, f.nome AS fornecedor FROM conciliacoes c
        JOIN notas_compra n ON n.id = c.nota_id JOIN fornecedores f ON f.id = n.fornecedor_id WHERE c.boleto_id = ?`).all(id);
    const rastro = conc.map((c) => {
      const itens = db.prepare('SELECT * FROM nota_itens WHERE nota_id = ? ORDER BY n_item').all(c.nota_id);
      const destinos = [];
      let semDestino = 0;
      for (const i of itens) {
        const rest = restanteItem(db, i.id);
        semDestino += rest.valor;
        for (const a of db.prepare(`SELECT a.destino, a.valor, a.quantidade, v.id AS venda_id, v.numero AS os, v.placa, v.veiculo FROM alocacoes a LEFT JOIN vendas v ON v.id = a.venda_id WHERE a.item_id = ?`).all(i.id)) {
          destinos.push({ item: i.descricao, destino: a.destino, valor: a.valor, quantidade: a.quantidade, venda_id: a.venda_id, os: a.os, placa: a.placa, veiculo: a.veiculo });
        }
      }
      return { nota_id: c.nota_id, nota: c.nota_numero, fornecedor: c.fornecedor, nota_total: c.nota_total, pago_por_este_boleto: c.valor, destinos, sem_destino: r2(semDestino) };
    });
    res.json({ boleto: { ...b, banco_nome: nomeBanco(b.banco) }, conciliacoes: conc, rastro, sugestoes: conc.length ? [] : sugerirNotas(db, b, cfg), ocorrencias: ocorrenciasDoBoleto(db, id, hoje(), cfg) });
  }));

  r.post('/boletos', wrap((req, res) => {
    const b = req.body || {};
    const out = criarBoleto(db, {
      linha: b.linha, valor: b.valor === '' ? null : b.valor, vencimento: b.vencimento ? V.data(b.vencimento, { campo: 'O vencimento' }) : null,
      numero_documento: b.numeroDocumento, fornecedor_id: b.fornecedorId ? V.idDe(b.fornecedorId) : null, fornecedor_nome: V.texto(b.fornecedorNome, { campo: 'o fornecedor', max: 80 }),
      beneficiario_cnpj: b.beneficiarioCnpj, beneficiario_nome: b.beneficiarioNome, pagador_cnpj: b.pagadorCnpj, obs: b.obs,
    }, hoje());
    res.status(201).json(out);
  }));

  // vários boletos de uma vez (uma linha digitável por linha), do mesmo fornecedor
  r.post('/boletos/lote', wrap((req, res) => {
    const linhas = String(req.body?.linhas ?? '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    if (!linhas.length) throw new V.ErroValidacao('Cole ao menos uma linha digitável.');
    if (linhas.length > 60) throw new V.ErroValidacao('No máximo 60 boletos por vez.');
    const fornecedorId = req.body?.fornecedorId ? V.idDe(req.body.fornecedorId) : null;
    const fornecedorNome = V.texto(req.body?.fornecedorNome, { campo: 'o fornecedor', max: 80 });
    if (!fornecedorId && !fornecedorNome) throw new V.ErroValidacao('Escolha o fornecedor.');
    const resultados = linhas.map((linha) => {
      try {
        const r1 = criarBoleto(db, { linha, fornecedor_id: fornecedorId, fornecedor_nome: fornecedorNome }, hoje());
        const graves = r1.ocorrencias.filter((o) => o.severidade === 'alta').length;
        return { linha, status: 'criado', boleto_id: r1.boleto_id, ligado: r1.auto.ligado, graves, valor: r1.leitura?.valor ?? null, vencimento: r1.leitura?.vencimento ?? null };
      } catch (e) {
        if (e instanceof V.ErroValidacao) return { linha, status: 'erro', erro: e.message, boleto_id: e.boleto_id ?? null };
        throw e;
      }
    });
    res.status(201).json({ resultados });
  }));

  r.post('/boletos/:id/conciliar', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const itens = (req.body?.itens ?? []).map((i) => ({ nota_id: V.idDe(i.nota_id), valor: V.dinheiro(i.valor, { campo: 'o valor ligado', obrigatorio: true, minimo: 0.01 }), duplicata_id: i.duplicata_id ? V.idDe(i.duplicata_id) : null }));
    conciliar(db, id, itens, 'manual');
    res.json({ ocorrencias: ocorrenciasDoBoleto(db, id, hoje()) });
  }));
  r.delete('/boletos/:id/conciliacoes/:notaId', wrap((req, res) => {
    res.json({ ok: desconciliar(db, V.idDe(req.params.id), V.idDe(req.params.notaId)) });
  }));
  r.post('/boletos/:id/pagar', wrap((req, res) => {
    const b = req.body || {};
    res.json(pagarBoleto(db, V.idDe(req.params.id), {
      data: b.data ? V.data(b.data, { campo: 'A data' }) : null, valor: b.valor ? V.dinheiro(b.valor, { campo: 'o valor pago', minimo: 0.01 }) : null,
      aprovar: b.aprovar === true, motivo: b.motivo,
    }, hoje()));
  }));
  r.post('/boletos/:id/cancelar', wrap((req, res) => {
    cancelarBoleto(db, V.idDe(req.params.id), V.opcao(req.body?.situacao, ['cancelado', 'contestado'], { campo: 'a situação', padrao: 'cancelado' }), V.texto(req.body?.motivo, { campo: 'o motivo', max: 300 }));
    res.json({ ok: true });
  }));

  return r;
}

export { ErroBloqueio, recalcularCustoOs };
