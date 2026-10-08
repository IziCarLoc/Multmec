import express from 'express';
import { soDono, ehDono } from './perfis.js';
import { contexto } from './trilha.js';
import { lerConfig, gravarConfig } from './db.js';
import * as F from './finance.js';
import { importarServicos, lerPlanilhaServicos } from './importar.js';
import { hoje as hojeBR, mesDe, normalizarPlaca, r2, somarMeses, somarDias } from './util.js';
import { normalizarCnpj, cnpjValido } from './documentos.js';
import * as V from './validar.js';
import { criarApiCompras, ErroBloqueio } from './api_compras.js';
import { pagarBoleto, desfazerPagamentoBoleto, reatribuirEmpresas } from './boletos.js';
import { registrar } from './trilha.js';
import { ocorrencias as ocorrenciasCompras, resumoAuditoria, vereditoDoBoleto } from './auditoria.js';

const SITUACOES = ['orcamento', 'aberta', 'concluida', 'cancelada'];
const TIPOS_CLIENTE = ['avulso', 'frota', 'locadora', 'revenda'];
const FORMAS = ['pix', 'dinheiro', 'débito', 'cartão', 'boleto', 'transferência', 'histórico'];

const csvCelula = (v) => {
  let s = v === null || v === undefined ? '' : String(v);
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;            // evita fórmula ao abrir no Excel
  return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

export function criarApi(db, { agora = () => new Date() } = {}) {
  const api = express.Router();
  const hoje = () => hojeBR(agora());
  const wrap = (fn) => (req, res, next) => {
    try { fn(req, res, next); } catch (e) { next(e); }
  };
  // a trilha de auditoria anota o perfil de quem fez o pedido (o corpo do pedido já foi lido: o contexto cobre todo o tratamento)
  api.use((req, res, next) => contexto.run({ perfil: req.perfil ?? null }, next));
  // objeto com chave "toString"/"valueOf" no corpo faz o código de conversão de texto falhar (erro 500): pedido assim nunca é legítimo
  const PROIBIDAS = new Set(['toString', 'valueOf', 'toJSON', 'constructor', '__proto__', 'hasOwnProperty']);
  const suspeito = (v, nivel = 0) => nivel < 6 && v !== null && typeof v === 'object'
    && Object.keys(v).some((k) => PROIBIDAS.has(k) || suspeito(v[k], nivel + 1));
  api.use((req, res, next) => (suspeito(req.body) ? res.status(400).json({ erro: 'Pedido inválido.' }) : next()));

  // ------------------------------------------------------------ painel
  api.get('/painel', soDono, wrap((req, res) => {
    const cfg = lerConfig(db);
    const h = hoje();
    const ym = req.query.mes ? V.mes(req.query.mes) : mesDe(h);
    // contas fixas só são geradas para o mês atual e o próximo; meses passados não ganham dívida retroativa
    const teto = somarMeses(mesDe(h), 1);
    for (const alvo of [ym, somarMeses(ym, 1)]) if (alvo >= mesDe(h) && alvo <= teto) F.gerarRecorrentes(db, alvo);
    const tm = F.termometro(db, ym, h, cfg);
    const casc = F.cascata(db, ym, h, cfg);
    const eq = F.pontoEquilibrio(db, ym, cfg, cfg.retiradaSociosMeta);
    res.json({
      hoje: h, mes: ym, config: cfg,
      termometro: tm, cascata: casc, equilibrio: eq,
      caixa: F.caixa(db, h, cfg),
      agenda: F.agenda(db, h, 7),
      alertas: F.alertas(db, h, cfg),
      carteira: F.carteira(db, h, cfg).slice(0, 5),
      serie: F.serieMensal(db, ym, 6),
      compras: { ...resumoAuditoria(ocorrenciasCompras(db, h, cfg)), comecou: db.prepare('SELECT (SELECT COUNT(*) FROM notas_compra) + (SELECT COUNT(*) FROM boletos) AS n').get().n > 0 },
    });
  }));

  // ------------------------------------------------------------ configuração
  // quem só lança vê só o que precisa para trabalhar (CNPJ da oficina, prazos); meta, retirada, impostos e saldo são do dono
  api.get('/config', wrap((req, res) => {
    const c = lerConfig(db);
    if (ehDono(req)) return res.json(c);
    const { cnpjOficina, diasTrava, pctAvisoLimite, toleranciaValor, diasNotaSemDestino, diasNotaSemBoleto, diasDevolucaoAdiantamento, auditoriaDesde, feriados, sabadoConta } = c;
    res.json({ cnpjOficina, diasTrava, pctAvisoLimite, toleranciaValor, diasNotaSemDestino, diasNotaSemBoleto, diasDevolucaoAdiantamento, auditoriaDesde, feriados, sabadoConta });
  }));
  api.put('/config', soDono, wrap((req, res) => {
    const b = req.body || {};
    const novo = {};
    if ('metaFaturamento' in b) novo.metaFaturamento = V.dinheiro(b.metaFaturamento, { campo: 'a meta', obrigatorio: true });
    if ('retiradaSociosMeta' in b) novo.retiradaSociosMeta = V.dinheiro(b.retiradaSociosMeta, { campo: 'a retirada dos sócios' });
    for (const k of ['impostoPct', 'taxaCartaoPct', 'reservaPct', 'pctAvisoLimite']) {
      if (k in b) {
        const n = Number(b[k]);
        if (!(n >= 0 && n < 1)) throw new V.ErroValidacao(`${k} deve ficar entre 0 e 1 (ex.: 0.06 = 6%).`);
        novo[k] = n;
      }
    }
    if ('margemContribuicaoPct' in b) {
      if (b.margemContribuicaoPct === null || b.margemContribuicaoPct === '') novo.margemContribuicaoPct = null;
      else {
        const n = Number(b.margemContribuicaoPct);
        if (!(n > 0 && n < 1)) throw new V.ErroValidacao('A margem de contribuição deve ficar entre 0 e 1.');
        novo.margemContribuicaoPct = n;
      }
    }
    if ('sabadoConta' in b) {
      const n = Number(b.sabadoConta);
      if (![0, 0.5, 1].includes(n)) throw new V.ErroValidacao('Sábado conta 0, 0,5 ou 1 dia.');
      novo.sabadoConta = n;
    }
    if ('diasTrava' in b) novo.diasTrava = V.inteiro(b.diasTrava, { campo: 'dias de trava', min: 0, max: 365, padrao: 15 });
    if ('feriados' in b) {
      if (!Array.isArray(b.feriados)) throw new V.ErroValidacao('Feriados deve ser uma lista de datas.');
      novo.feriados = b.feriados.map((d) => V.data(d, { campo: 'Feriado' }));
    }
    if ('cnpjOficina' in b) {
      const c = normalizarCnpj(b.cnpjOficina);
      if (c && !cnpjValido(c)) throw new V.ErroValidacao('O CNPJ da oficina não passa na validação (confira os números).');
      if (c && db.prepare('SELECT 1 FROM empresas_grupo WHERE cnpj = ?').get(c)) throw new V.ErroValidacao('Este CNPJ já está cadastrado como empresa do grupo (Compras > Grupo). O CNPJ da oficina não pode ser o de outra empresa.');
      novo.cnpjOficina = c;
    }
    if ('auditoriaDesde' in b) novo.auditoriaDesde = V.data(b.auditoriaDesde, { campo: 'A data inicial da auditoria', obrigatorio: false }) || '';
    if ('toleranciaValor' in b) {
      const n = Number(b.toleranciaValor);
      if (!(n >= 0 && n <= 5)) throw new V.ErroValidacao('A tolerância de valor deve ficar entre 0 e 5 reais.');
      novo.toleranciaValor = n;
    }
    if ('variacaoPrecoPct' in b) {
      const n = Number(b.variacaoPrecoPct);
      if (!(n > 0 && n < 5)) throw new V.ErroValidacao('A variação de preço deve ficar entre 0 e 5 (ex.: 0.15 = 15%).');
      novo.variacaoPrecoPct = n;
    }
    if ('diasNotaSemDestino' in b) novo.diasNotaSemDestino = V.inteiro(b.diasNotaSemDestino, { campo: 'os dias para dar destino às peças', min: 0, max: 365, padrao: 7 });
    if ('diasNotaSemBoleto' in b) novo.diasNotaSemBoleto = V.inteiro(b.diasNotaSemBoleto, { campo: 'os dias de antecedência do boleto', min: 0, max: 60, padrao: 5 });
    if ('diasDevolucaoAdiantamento' in b) novo.diasDevolucaoAdiantamento = V.inteiro(b.diasDevolucaoAdiantamento, { campo: 'o prazo para a outra empresa devolver', min: 1, max: 365, padrao: 30 });
    if ('saldoCaixaInicial' in b) novo.saldoCaixaInicial = V.dinheiro(b.saldoCaixaInicial, { campo: 'o saldo inicial', minimo: -10_000_000 });
    if ('saldoCaixaInicialData' in b) novo.saldoCaixaInicialData = V.data(b.saldoCaixaInicialData, { campo: 'A data do saldo', obrigatorio: false }) || '';
    gravarConfig(db, novo);
    if ('cnpjOficina' in novo) reatribuirEmpresas(db);          // o CNPJ da oficina nunca é de empresa do grupo: refaz a empresa dos boletos
    res.json(lerConfig(db));
  }));

  // ------------------------------------------------------------ clientes
  const clienteDe = (b, atual = {}) => ({
    nome: V.texto(b.nome ?? atual.nome, { campo: 'o nome', obrigatorio: true, max: 80 }).toUpperCase(),
    tipo: V.opcao(b.tipo ?? atual.tipo, TIPOS_CLIENTE, { campo: 'o tipo', padrao: 'avulso' }),
    prazo_dias: V.inteiro(b.prazoDias ?? atual.prazo_dias, { campo: 'o prazo', min: 0, max: 180 }),
    limite_credito: V.dinheiro(b.limite ?? atual.limite_credito, { campo: 'o limite' }),
    ativo: b.ativo === undefined ? (atual.ativo ?? 1) : (b.ativo ? 1 : 0),
    obs: V.texto(b.obs ?? atual.obs, { campo: 'a observação', max: 500 }),
  });
  api.get('/clientes', wrap((req, res) => {
    const q = (req.query.q || '').toString().trim();
    const linhas = db.prepare(`SELECT * FROM clientes WHERE (@q = '' OR nome LIKE '%' || @q || '%') ORDER BY ativo DESC, nome LIMIT 500`).all({ q });
    res.json(linhas);
  }));
  api.post('/clientes', wrap((req, res) => {
    // cliente novo cadastrado por quem só lança nasce sem crédito: limite, prazo e tipo são política do dono
    const c = clienteDe(ehDono(req) ? (req.body || {}) : { nome: req.body?.nome, obs: req.body?.obs });
    try {
      const r = db.prepare('INSERT INTO clientes (nome, tipo, prazo_dias, limite_credito, ativo, obs) VALUES (@nome, @tipo, @prazo_dias, @limite_credito, @ativo, @obs)').run(c);
      res.status(201).json({ id: Number(r.lastInsertRowid), ...c });
    } catch (e) {
      if (String(e.message).includes('UNIQUE')) throw new V.ErroValidacao('Já existe um cliente com esse nome.');
      throw e;
    }
  }));
  api.put('/clientes/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const atual = db.prepare('SELECT * FROM clientes WHERE id = ?').get(id);
    if (!atual) return res.status(404).json({ erro: 'Cliente não encontrado.' });
    const c = clienteDe(req.body || {}, atual);
    // limite, prazo, tipo e "ativo" são a política de crédito: só o dono muda (quem lança pode corrigir nome e observação)
    if (!ehDono(req) && (c.limite_credito !== atual.limite_credito || c.prazo_dias !== atual.prazo_dias || c.tipo !== atual.tipo || c.ativo !== atual.ativo)) {
      return res.status(403).json({ erro: 'Limite, prazo, tipo e situação do cliente a prazo são só do dono.' });
    }
    if (c.limite_credito !== atual.limite_credito || c.prazo_dias !== atual.prazo_dias || c.tipo !== atual.tipo) registrar(db, 'cliente_credito', 'cliente', id, { antes: { limite: atual.limite_credito, prazo: atual.prazo_dias, tipo: atual.tipo }, depois: { limite: c.limite_credito, prazo: c.prazo_dias, tipo: c.tipo } });
    db.prepare('UPDATE clientes SET nome=@nome, tipo=@tipo, prazo_dias=@prazo_dias, limite_credito=@limite_credito, ativo=@ativo, obs=@obs WHERE id=@id').run({ ...c, id });
    res.json({ id, ...c });
  }));
  api.get('/carteira', wrap((req, res) => res.json(F.carteira(db, hoje()))));
  api.get('/clientes/:id/extrato', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const c = db.prepare('SELECT * FROM clientes WHERE id = ?').get(id);
    if (!c) return res.status(404).json({ erro: 'Cliente não encontrado.' });
    res.json({ situacao: F.situacaoCliente(db, c, hoje()), extrato: F.extratoCliente(db, id), abertas: F.emAberto(db, hoje(), id) });
  }));
  api.get('/clientes/:id/cobranca', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    if (!db.prepare('SELECT 1 FROM clientes WHERE id = ?').get(id)) return res.status(404).json({ erro: 'Cliente não encontrado.' });
    res.json({ texto: F.textoCobranca(db, id, hoje()) });
  }));
  api.post('/clientes/:id/pagamento', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const b = req.body || {};
    const r = F.receberDoCliente(db, id, {
      valor: V.dinheiro(b.valor, { campo: 'o valor', obrigatorio: true, minimo: 0.01 }),
      data: V.data(b.data || hoje(), { campo: 'A data' }),
      forma: V.opcao(b.forma, FORMAS, { campo: 'a forma', padrao: null }),
      obs: V.texto(b.obs, { campo: 'a observação', max: 200 }),
    });
    registrar(db, 'recebimento_cliente', 'cliente', id, { valor: V.dinheiro(b.valor, { campo: 'o valor', minimo: 0.01 }) });
    res.json(r);
  }));
  api.post('/clientes/:id/saldo-anterior', soDono, wrap((req, res) => {
    const id = V.idDe(req.params.id);
    if (!db.prepare('SELECT 1 FROM clientes WHERE id = ?').get(id)) return res.status(404).json({ erro: 'Cliente não encontrado.' });
    const valor = V.dinheiro(req.body?.valor, { campo: 'o saldo anterior', obrigatorio: true, minimo: 0.01 });
    const dt = V.data(req.body?.data || hoje(), { campo: 'A data' });
    db.transaction(() => {
      const ant = db.prepare("SELECT id FROM vendas WHERE cliente_id = ? AND situacao = 'saldo'").get(id);
      if (ant) {
        db.prepare('DELETE FROM recebimentos WHERE venda_id = ?').run(ant.id);
        db.prepare('UPDATE vendas SET valor_total = ?, data = ? WHERE id = ?').run(valor, dt, ant.id);
      } else {
        db.prepare("INSERT INTO vendas (numero, data, cliente_id, situacao, valor_total, origem, obs) VALUES ('SALDO', ?, ?, 'saldo', ?, 'manual', 'Saldo anterior ao sistema')").run(dt, id, valor);
      }
    })();
    res.json({ ok: true });
  }));

  // ------------------------------------------------------------ mecânicos
  api.get('/mecanicos', wrap((req, res) => res.json(db.prepare('SELECT * FROM mecanicos ORDER BY ativo DESC, nome').all())));
  api.post('/mecanicos', wrap((req, res) => {
    const nome = V.texto(req.body?.nome, { campo: 'o nome', obrigatorio: true, max: 60 }).toUpperCase();
    try {
      const r = db.prepare('INSERT INTO mecanicos (nome) VALUES (?)').run(nome);
      res.status(201).json({ id: Number(r.lastInsertRowid), nome, ativo: 1 });
    } catch (e) {
      if (String(e.message).includes('UNIQUE')) throw new V.ErroValidacao('Esse mecânico já existe.');
      throw e;
    }
  }));
  api.put('/mecanicos/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    db.prepare('UPDATE mecanicos SET ativo = ? WHERE id = ?').run(req.body?.ativo ? 1 : 0, id);
    res.json({ ok: true });
  }));

  // ------------------------------------------------------------ vendas (OS)
  const SELECT_VENDA = `
    SELECT v.*, c.nome AS cliente, c.tipo AS cliente_tipo, m.nome AS mecanico,
           COALESCE((SELECT SUM(valor) FROM recebimentos r WHERE r.venda_id = v.id), 0) AS recebido
      FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id LEFT JOIN mecanicos m ON m.id = v.mecanico_id`;
  const decorar = (v) => {
    const custo = (v.custo_pecas || 0) + v.custo_frete + v.custo_insumos;
    return {
      ...v, recebido: r2(v.recebido), aberto: ['concluida', 'saldo'].includes(v.situacao) ? r2(v.valor_total - v.recebido) : 0,
      lucro_bruto: r2(v.valor_total - custo),
      sem_custo: v.situacao === 'concluida' && v.custo_pecas === null && v.valor_total - v.valor_mao_obra > 50,
    };
  };
  api.get('/vendas', wrap((req, res) => {
    const where = [];
    const p = {};
    if (req.query.mes) { where.push("substr(v.data, 1, 7) = @mes"); p.mes = V.mes(req.query.mes); }
    if (req.query.situacao) { where.push('v.situacao = @sit'); p.sit = V.opcao(req.query.situacao, [...SITUACOES, 'saldo'], { campo: 'a situação' }); }
    if (req.query.cliente_id) { where.push('v.cliente_id = @cli'); p.cli = V.idDe(req.query.cliente_id); }
    if (req.query.q) { where.push("(v.placa LIKE '%' || @q || '%' OR v.numero LIKE '%' || @q || '%' OR c.nome LIKE '%' || @q || '%' OR v.veiculo LIKE '%' || @q || '%')"); p.q = String(req.query.q).trim().slice(0, 40); }
    if (req.query.aberto === '1') where.push("v.situacao IN ('concluida','saldo') AND v.valor_total - COALESCE((SELECT SUM(valor) FROM recebimentos r WHERE r.venda_id = v.id), 0) > 0.004");
    if (req.query.sem_custo === '1') where.push("v.situacao = 'concluida' AND v.custo_pecas IS NULL AND v.valor_total - v.valor_mao_obra > 50");
    const sql = `${SELECT_VENDA} ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY v.data DESC, v.id DESC LIMIT 400`;
    res.json(db.prepare(sql).all(p).map(decorar));
  }));
  api.get('/vendas/:id', wrap((req, res) => {
    const v = db.prepare(`${SELECT_VENDA} WHERE v.id = ?`).get(V.idDe(req.params.id));
    if (!v) return res.status(404).json({ erro: 'OS não encontrada.' });
    const pecas = db.prepare(`SELECT a.id, a.valor, a.quantidade, i.descricao, n.numero AS nota, n.id AS nota_id, n.valor_total AS nota_total, f.nome AS fornecedor,
        COALESCE((SELECT SUM(c.valor) FROM conciliacoes c WHERE c.nota_id = n.id), 0) AS ligado,
        (SELECT COUNT(*) FROM conciliacoes c JOIN boletos b ON b.id = c.boleto_id WHERE c.nota_id = n.id AND b.situacao = 'aberto') AS boletos_abertos,
        (SELECT COUNT(*) FROM conciliacoes c JOIN boletos b ON b.id = c.boleto_id WHERE c.nota_id = n.id AND b.situacao = 'pago') AS boletos_pagos
        FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id JOIN notas_compra n ON n.id = i.nota_id JOIN fornecedores f ON f.id = n.fornecedor_id
        WHERE a.venda_id = ? AND a.destino = 'os' ORDER BY a.id`).all(v.id)
      .map((p) => ({ ...p, situacao_boleto: p.ligado <= 0.04 ? 'sem boleto' : (p.boletos_abertos ? 'boleto em aberto' : (p.ligado >= p.nota_total - 0.05 ? 'boleto pago' : 'boleto parcial')) }));
    res.json({ ...decorar(v), recebimentos: db.prepare('SELECT * FROM recebimentos WHERE venda_id = ? ORDER BY data').all(v.id), pecas_notas: pecas, custo_notas: r2(pecas.reduce((a, p) => a + p.valor, 0)) });
  }));

  function vendaDe(b, atual = {}) {
    let clienteId = b.clienteId ?? atual.cliente_id ?? null;
    if (!clienteId && b.clienteNome) {                        // cria o cliente na hora, no balcão
      const nome = V.texto(b.clienteNome, { campo: 'o cliente', max: 80 }).toUpperCase();
      const ex = db.prepare('SELECT id FROM clientes WHERE nome = ?').get(nome);
      clienteId = ex ? ex.id : Number(db.prepare("INSERT INTO clientes (nome, tipo) VALUES (?, 'avulso')").run(nome).lastInsertRowid);
    }
    if (clienteId && !db.prepare('SELECT 1 FROM clientes WHERE id = ?').get(clienteId)) throw new V.ErroValidacao('Cliente não encontrado.');
    const total = V.dinheiro(b.valorTotal ?? atual.valor_total, { campo: 'o valor total', obrigatorio: true, minimo: 0 });
    const mo = V.dinheiro(b.valorMaoObra ?? atual.valor_mao_obra, { campo: 'a mão de obra' });
    if (mo > total) throw new V.ErroValidacao('A mão de obra não pode ser maior que o valor total.');
    const custoPecas = 'custoPecas' in b ? V.dinheiro(b.custoPecas, { campo: 'o custo das peças', nulo: true }) : (atual.custo_pecas ?? null);
    return {
      numero: V.texto(b.numero ?? atual.numero, { campo: 'o número da OS', max: 20 }),
      data: V.data(b.data ?? atual.data, { campo: 'A data' }),
      cliente_id: clienteId || null,
      veiculo: V.texto(b.veiculo ?? atual.veiculo, { campo: 'o veículo', max: 40 })?.toUpperCase() ?? null,
      placa: normalizarPlaca(b.placa ?? atual.placa) || null,
      mecanico_id: b.mecanicoId === undefined ? (atual.mecanico_id ?? null) : (b.mecanicoId || null),
      situacao: V.opcao(b.situacao ?? atual.situacao, SITUACOES, { campo: 'a situação', padrao: 'concluida' }),
      valor_total: total, valor_mao_obra: mo, custo_pecas: custoPecas,
      custo_frete: V.dinheiro(b.custoFrete ?? atual.custo_frete, { campo: 'o frete' }),
      custo_insumos: V.dinheiro(b.custoInsumos ?? atual.custo_insumos, { campo: 'os insumos' }),
      forma_pagamento: V.opcao(b.formaPagamento ?? atual.forma_pagamento, FORMAS, { campo: 'a forma de pagamento', padrao: null }),
      vencimento: V.data(b.vencimento ?? atual.vencimento, { campo: 'O vencimento', obrigatorio: false }),
      obs: V.texto(b.obs ?? atual.obs, { campo: 'a observação', max: 500 }),
    };
  }
  const COLS = 'numero, data, cliente_id, veiculo, placa, mecanico_id, situacao, valor_total, valor_mao_obra, custo_pecas, custo_frete, custo_insumos, forma_pagamento, vencimento, obs';
  api.post('/vendas', wrap((req, res) => {
    const v = vendaDe(req.body || {});
    const pago = req.body?.pagoAgora;     // { valor?, forma?, data? } -> já registra o recebimento
    let id;
    db.transaction(() => {
      id = Number(db.prepare(`INSERT INTO vendas (${COLS}) VALUES (@numero, @data, @cliente_id, @veiculo, @placa, @mecanico_id, @situacao, @valor_total, @valor_mao_obra, @custo_pecas, @custo_frete, @custo_insumos, @forma_pagamento, @vencimento, @obs)`).run(v).lastInsertRowid);
      if (pago && v.situacao === 'concluida') {
        const valor = pago.valor === undefined ? v.valor_total : V.dinheiro(pago.valor, { campo: 'o valor recebido', minimo: 0 });
        if (valor > 0) db.prepare('INSERT INTO recebimentos (venda_id, data, valor, forma) VALUES (?, ?, ?, ?)').run(id, V.data(pago.data || hoje(), { campo: 'A data do recebimento' }), valor, V.opcao(pago.forma, FORMAS, { campo: 'a forma', padrao: v.forma_pagamento }));
      }
    })();
    res.status(201).json({ id });
  }));
  api.put('/vendas/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const atual = db.prepare('SELECT * FROM vendas WHERE id = ?').get(id);
    if (!atual) return res.status(404).json({ erro: 'OS não encontrada.' });
    const v = vendaDe(req.body || {}, atual);
    if (v.valor_total !== atual.valor_total || v.situacao !== atual.situacao || (v.custo_pecas ?? null) !== (atual.custo_pecas ?? null)) {
      registrar(db, 'os_alterada', 'os', id, { antes: { total: atual.valor_total, situacao: atual.situacao, custo_pecas: atual.custo_pecas }, depois: { total: v.valor_total, situacao: v.situacao, custo_pecas: v.custo_pecas } });
    }
    db.prepare(`UPDATE vendas SET numero=@numero, data=@data, cliente_id=@cliente_id, veiculo=@veiculo, placa=@placa, mecanico_id=@mecanico_id, situacao=@situacao, valor_total=@valor_total, valor_mao_obra=@valor_mao_obra, custo_pecas=@custo_pecas, custo_frete=@custo_frete, custo_insumos=@custo_insumos, forma_pagamento=@forma_pagamento, vencimento=@vencimento, obs=@obs, data_estimada=0, custo_pecas_auto=@auto WHERE id=@id`).run({ ...v, id, auto: (v.custo_pecas ?? null) === (atual.custo_pecas ?? null) ? atual.custo_pecas_auto : 0 });
    res.json({ ok: true });
  }));
  api.delete('/vendas/:id', soDono, wrap((req, res) => {
    db.prepare('DELETE FROM vendas WHERE id = ?').run(V.idDe(req.params.id));
    res.json({ ok: true });
  }));
  api.post('/vendas/:id/receber', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const v = db.prepare(`${SELECT_VENDA} WHERE v.id = ?`).get(id);
    if (!v) return res.status(404).json({ erro: 'OS não encontrada.' });
    const aberto = r2(v.valor_total - v.recebido);
    const valor = req.body?.valor === undefined ? aberto : V.dinheiro(req.body.valor, { campo: 'o valor', obrigatorio: true, minimo: 0.01 });
    if (valor > aberto + 0.004) throw new V.ErroValidacao(`Só falta receber ${aberto.toFixed(2)} desta OS.`);
    db.prepare('INSERT INTO recebimentos (venda_id, data, valor, forma) VALUES (?, ?, ?, ?)').run(id, V.data(req.body?.data || hoje(), { campo: 'A data' }), valor, V.opcao(req.body?.forma, FORMAS, { campo: 'a forma', padrao: null }));
    registrar(db, 'recebimento', 'os', id, { valor });
    res.json({ ok: true });
  }));
  api.delete('/recebimentos/:id', soDono, wrap((req, res) => {
    db.prepare('DELETE FROM recebimentos WHERE id = ?').run(V.idDe(req.params.id));
    res.json({ ok: true });
  }));
  api.post('/vendas/:id/aprovar', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const r = db.prepare("UPDATE vendas SET situacao = 'concluida', data = ? WHERE id = ? AND situacao = 'orcamento'").run(V.data(req.body?.data || hoje(), { campo: 'A data' }), id);
    if (!r.changes) return res.status(404).json({ erro: 'Orçamento não encontrado.' });
    res.json({ ok: true });
  }));

  // ------------------------------------------------------------ saídas (contas a pagar)
  api.get('/categorias', wrap((req, res) => res.json(db.prepare('SELECT * FROM categorias ORDER BY ordem').all())));
  api.get('/saidas', wrap((req, res) => {
    const h = hoje();
    const ym = req.query.mes ? V.mes(req.query.mes) : mesDe(h);
    if (ym >= mesDe(h) && ym <= somarMeses(mesDe(h), 1)) F.gerarRecorrentes(db, ym);
    // conta que nasceu de boleto mostra o veredito da conferência na própria linha (o dono vê antes de tocar em "Paguei")
    const cfgCompras = lerConfig(db);
    const boletosDoMes = new Map(db.prepare(`SELECT b.*, f.nome AS fornecedor FROM boletos b LEFT JOIN fornecedores f ON f.id = b.fornecedor_id WHERE b.saida_id IS NOT NULL`).all().map((b) => [b.saida_id, b]));
    const todas = boletosDoMes.size ? ocorrenciasCompras(db, h, cfgCompras) : [];
    // quem só lança vê as contas de fornecedores (boletos); salário, aluguel, pró-labore e o resto são do dono
    const linhas = F.saidasDoMes(db, ym).filter((s) => ehDono(req) || s.grupo === 'pecas').map((s) => {
      const b = boletosDoMes.get(s.id);
      return {
        ...s, situacao: s.pago_em ? 'paga' : (s.vencimento < h ? 'atrasada' : 'a_pagar'),
        boleto_id: b?.id ?? null, veredito: b ? vereditoDoBoleto(b, todas.filter((o) => o.boletos.includes(b.id)), cfgCompras) : null,
      };
    });
    const soma = (f) => r2(linhas.filter(f).reduce((a, s) => a + s.valor, 0));
    // boletos de outras empresas do grupo não entram nestas contas (nem nos totais), mas alguém precisa pagá-los: aviso para não esquecer
    const g = db.prepare("SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS v, COALESCE(SUM(vencimento <= ?), 0) AS venc FROM boletos WHERE situacao = 'aberto' AND empresa_id IS NOT NULL").get(somarDias(h, 7));
    res.json({ mes: ym, linhas, totais: { total: soma(() => true), pagas: soma((s) => s.pago_em), aPagar: soma((s) => !s.pago_em), atrasadas: soma((s) => s.situacao === 'atrasada') },
      outrasEmpresas: { qtd: g.n, valor: r2(g.v), vencendo: g.venc } });
  }));
  const saidaDe = (b, atual = {}) => {
    const cat = b.categoriaId ?? atual.categoria_id;
    if (!db.prepare('SELECT 1 FROM categorias WHERE id = ?').get(cat)) throw new V.ErroValidacao('Escolha a categoria.');
    return {
      descricao: V.texto(b.descricao ?? atual.descricao, { campo: 'a descrição', obrigatorio: true, max: 120 }),
      categoria_id: cat,
      fornecedor: V.texto(b.fornecedor ?? atual.fornecedor, { campo: 'o fornecedor', max: 80 }),
      valor: V.dinheiro(b.valor ?? atual.valor, { campo: 'o valor', obrigatorio: true, minimo: 0.01 }),
      vencimento: V.data(b.vencimento ?? atual.vencimento, { campo: 'O vencimento' }),
      obs: V.texto(b.obs ?? atual.obs, { campo: 'a observação', max: 300 }),
    };
  };
  api.post('/saidas', wrap((req, res) => {
    // "já paguei" é marcar como paga: quem só lança não faz isso (a baixa é do dono)
    if (req.body?.pagarAgora && !ehDono(req)) return res.status(403).json({ erro: 'Marcar como paga é só do dono.' });
    const s = saidaDe(req.body || {});
    const id = Number(db.prepare('INSERT INTO saidas (descricao, categoria_id, fornecedor, valor, vencimento, obs) VALUES (@descricao, @categoria_id, @fornecedor, @valor, @vencimento, @obs)').run(s).lastInsertRowid);
    if (req.body?.pagarAgora) db.prepare('UPDATE saidas SET pago_em = ?, valor_pago = valor WHERE id = ?').run(hoje(), id);
    res.status(201).json({ id });
  }));
  api.put('/saidas/:id', wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const atual = db.prepare('SELECT * FROM saidas WHERE id = ?').get(id);
    if (!atual) return res.status(404).json({ erro: 'Conta não encontrada.' });
    const s = saidaDe(req.body || {}, atual);
    // conta já paga só o dono mexe (alterar valor ou categoria de conta paga muda a retirada e o resultado); toda mudança de valor/categoria/vencimento fica na trilha
    if (atual.pago_em && !ehDono(req)) return res.status(403).json({ erro: 'Conta já paga só o dono altera.' });
    if (s.valor !== atual.valor || s.categoria_id !== atual.categoria_id || s.vencimento !== atual.vencimento) {
      registrar(db, 'conta_alterada', 'saida', id, { antes: { valor: atual.valor, categoria: atual.categoria_id, vencimento: atual.vencimento, paga: !!atual.pago_em }, depois: { valor: s.valor, categoria: s.categoria_id, vencimento: s.vencimento } });
    }
    // valor e vencimento de uma conta nascida de boleto são do boleto: mudar aqui desligaria a conferência
    if (db.prepare('SELECT 1 FROM boletos WHERE saida_id = ?').get(id) && (s.valor !== atual.valor || s.vencimento !== atual.vencimento || s.categoria_id !== atual.categoria_id)) {
      throw new V.ErroValidacao('Valor, vencimento e categoria desta conta vêm do boleto e não podem ser mudados aqui. Se o boleto foi cadastrado errado, cancele-o em Compras e cadastre de novo.');
    }
    db.prepare('UPDATE saidas SET descricao=@descricao, categoria_id=@categoria_id, fornecedor=@fornecedor, valor=@valor, vencimento=@vencimento, obs=@obs WHERE id=@id').run({ ...s, id });
    res.json({ ok: true });
  }));
  api.delete('/saidas/:id', soDono, wrap((req, res) => {
    const id = V.idDe(req.params.id);
    if (db.prepare('SELECT 1 FROM boletos WHERE saida_id = ?').get(id)) throw new V.ErroValidacao('Esta conta veio de um boleto. Cancele o boleto em Compras.');
    db.prepare('DELETE FROM saidas WHERE id = ?').run(id);
    res.json({ ok: true });
  }));
  api.post('/saidas/:id/pagar', soDono, wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const s = db.prepare('SELECT * FROM saidas WHERE id = ?').get(id);
    if (!s) return res.status(404).json({ erro: 'Conta não encontrada.' });
    const desfazer = req.body?.desfazer === true;
    const boleto = db.prepare('SELECT id, situacao FROM boletos WHERE saida_id = ?').get(id);
    if (boleto && !desfazer) {
      // conta que nasceu de um boleto passa pela conferência de compras (trava de pagamento)
      res.json(pagarBoleto(db, boleto.id, {
        data: req.body?.data ? V.data(req.body.data, { campo: 'A data' }) : null,
        valor: req.body?.valor ? V.dinheiro(req.body.valor, { campo: 'o valor pago', minimo: 0.01 }) : null,
        aprovar: req.body?.aprovar === true, motivo: req.body?.motivo, conferiuBanco: req.body?.conferiuBanco === true,
      }, hoje()));
      return;
    }
    if (desfazer) {
      if (boleto) desfazerPagamentoBoleto(db, boleto.id);
      else db.prepare('UPDATE saidas SET pago_em = NULL, valor_pago = NULL WHERE id = ?').run(id);
    } else {
      db.prepare('UPDATE saidas SET pago_em = ?, valor_pago = ? WHERE id = ?').run(V.data(req.body?.data || hoje(), { campo: 'A data' }), V.dinheiro(req.body?.valor ?? s.valor, { campo: 'o valor pago', minimo: 0.01 }), id);
    }
    res.json({ ok: true });
  }));

  api.get('/recorrentes', soDono, wrap((req, res) => res.json(db.prepare(`SELECT r.*, c.nome AS categoria, c.grupo FROM recorrentes r JOIN categorias c ON c.id = r.categoria_id ORDER BY r.ativo DESC, c.ordem, r.descricao`).all())));
  api.post('/recorrentes', soDono, wrap((req, res) => {
    const b = req.body || {};
    const cat = b.categoriaId;
    if (!db.prepare('SELECT 1 FROM categorias WHERE id = ?').get(cat)) throw new V.ErroValidacao('Escolha a categoria.');
    const r = db.prepare('INSERT INTO recorrentes (descricao, categoria_id, valor, dia_vencimento) VALUES (?, ?, ?, ?)').run(
      V.texto(b.descricao, { campo: 'a descrição', obrigatorio: true, max: 120 }), cat,
      V.dinheiro(b.valor, { campo: 'o valor', obrigatorio: true, minimo: 0.01 }), V.inteiro(b.diaVencimento, { campo: 'o dia', min: 1, max: 31, padrao: 5 }));
    res.status(201).json({ id: Number(r.lastInsertRowid) });
  }));
  api.put('/recorrentes/:id', soDono, wrap((req, res) => {
    const id = V.idDe(req.params.id);
    const a = db.prepare('SELECT * FROM recorrentes WHERE id = ?').get(id);
    if (!a) return res.status(404).json({ erro: 'Modelo não encontrado.' });
    const b = req.body || {};
    db.prepare('UPDATE recorrentes SET descricao=?, valor=?, dia_vencimento=?, ativo=? WHERE id=?').run(
      V.texto(b.descricao ?? a.descricao, { campo: 'a descrição', obrigatorio: true, max: 120 }),
      V.dinheiro(b.valor ?? a.valor, { campo: 'o valor', obrigatorio: true, minimo: 0.01 }),
      V.inteiro(b.diaVencimento ?? a.dia_vencimento, { campo: 'o dia', min: 1, max: 31 }),
      b.ativo === undefined ? a.ativo : (b.ativo ? 1 : 0), id);
    // contas futuras ainda não pagas acompanham o novo valor
    db.prepare("UPDATE saidas SET valor = ? WHERE recorrente_id = ? AND pago_em IS NULL AND competencia >= ?").run(V.dinheiro(b.valor ?? a.valor, { campo: 'o valor', minimo: 0.01 }), id, mesDe(hoje()));
    res.json({ ok: true });
  }));

  // ------------------------------------------------------------ relatórios e simulador
  api.get('/relatorios', soDono, wrap((req, res) => {
    const h = hoje();
    const de = V.data(req.query.de || `${somarMeses(mesDe(h), -5)}-01`, { campo: 'A data inicial' });
    const ate = V.data(req.query.ate || h, { campo: 'A data final' });
    res.json({
      de, ate, analise: F.analiseOs(db, de, ate), serie: F.serieMensal(db, mesDe(h), 13),
      orcamentos: F.orcamentosAbertos(db),
    });
  }));
  api.post('/simulador', soDono, wrap((req, res) => {
    const b = req.body || {};
    const cfg = lerConfig(db);
    const h = hoje();
    const eq = F.pontoEquilibrio(db, mesDe(h), cfg, 0);
    res.json(F.simulador({
      os: Number(b.os) || 0, ticket: Number(b.ticket) || 0, meta: cfg.metaFaturamento,
      margemContribuicaoPct: b.margemContribuicaoPct ?? eq.margemContribuicaoPct, custosFixos: b.custosFixos ?? eq.custosFixos,
    }));
  }));

  // ------------------------------------------------------------ importar / exportar
  api.post('/importar/previa', soDono, wrap((req, res) => {
    const csv = V.texto(req.body?.csv, { campo: 'o arquivo', obrigatorio: true, max: 5_000_000 });
    const { linhas, avisos } = lerPlanilhaServicos(csv);
    const porMes = {};
    for (const l of linhas) if (l.situacao === 'concluida') { porMes[l.data.slice(0, 7)] = (porMes[l.data.slice(0, 7)] || 0) + l.valorTotal; }
    res.json({ linhas: linhas.length, orcamentos: linhas.filter((l) => l.situacao === 'orcamento').length, datasEstimadas: linhas.filter((l) => l.dataEstimada).length, avisos, faturamentoPorMes: porMes });
  }));
  api.post('/importar', soDono, wrap((req, res) => {
    const csv = V.texto(req.body?.csv, { campo: 'o arquivo', obrigatorio: true, max: 5_000_000 });
    const corte = V.data(req.body?.quitadasAte, { campo: 'A data de corte', obrigatorio: false });
    res.json(importarServicos(db, csv, { quitadasAte: corte, atualizar: req.body?.atualizar === true }));
  }));
  api.get('/exportar/vendas.csv', soDono, wrap((req, res) => {
    const cab = ['OS', 'data', 'cliente', 'placa', 'veiculo', 'mecanico', 'situacao', 'total', 'mao_de_obra', 'custo_pecas', 'custo_frete', 'custo_insumos', 'lucro_bruto', 'recebido', 'aberto'];
    const linhas = db.prepare(`${SELECT_VENDA} ${req.query.mes ? "WHERE substr(v.data,1,7) = ?" : ''} ORDER BY v.data, v.id`).all(...(req.query.mes ? [V.mes(req.query.mes)] : [])).map(decorar);
    const corpo = linhas.map((v) => [v.numero, v.data, v.cliente, v.placa, v.veiculo, v.mecanico, v.situacao, v.valor_total, v.valor_mao_obra, v.custo_pecas, v.custo_frete, v.custo_insumos, v.lucro_bruto, v.recebido, v.aberto].map(csvCelula).join(','));
    res.type('text/csv; charset=utf-8').set('Content-Disposition', 'attachment; filename="vendas.csv"').send('﻿' + [cab.join(','), ...corpo].join('\n'));
  }));

  // ------------------------------------------------------------ compras (notas, boletos, auditoria)
  api.use('/compras', criarApiCompras(db, { agora }));

  // ------------------------------------------------------------ erros
  api.use((req, res) => res.status(404).json({ erro: 'Rota não encontrada.' }));
  // eslint-disable-next-line no-unused-vars
  api.use((err, req, res, next) => {
    if (err instanceof ErroBloqueio) return res.status(409).json({ erro: err.message, ocorrencias: err.ocorrencias });
    if (err instanceof V.ErroValidacao) return res.status(400).json({ erro: err.message, ...(err.boleto_id ? { boleto_id: err.boleto_id } : {}) });
    if (err instanceof Error && /Valor do pagamento|Cliente não encontrado/.test(err.message)) return res.status(400).json({ erro: err.message });
    console.error(err);
    res.status(500).json({ erro: 'Erro interno. Tente de novo.' });
  });
  return api;
}
