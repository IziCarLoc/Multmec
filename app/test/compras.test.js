import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco, gravarConfig, lerConfig } from '../src/db.js';
import { importarNotaXml, criarNotaManual, alocar, removerAlocacao, alocarNotaNaOs, sugerirAlocacoes, restanteItem } from '../src/compras.js';
import { criarBoleto, sugerirNotas, conciliar, pagarBoleto, ErroBloqueio, cancelarBoleto } from '../src/boletos.js';
import { ocorrencias, ocorrenciasDoBoleto, resumoAuditoria, aceitarOcorrencia } from '../src/auditoria.js';
import { xmlNfe, CNPJ_FORNECEDOR, CNPJ_OFICINA, CNPJ_OUTRO } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';

const HOJE = '2026-10-08';

function cenario() {
  const db = abrirBanco(':memory:');
  gravarConfig(db, { cnpjOficina: CNPJ_OFICINA });
  const cfg = () => lerConfig(db);
  // fornecedor principal já cadastrado e conferido por uma pessoa (sem isso a tela pede a conferência)
  db.prepare("INSERT INTO fornecedores (nome, cnpj, principal, confirmado_em) VALUES ('DISTRIBUIDORA TESTE LTDA', ?, 1, '2026-09-01')").run(CNPJ_FORNECEDOR);
  const nota = (nNF, valor, dups = [{ venc: '2026-10-20', valor }], extra = {}) =>
    importarNotaXml(db, xmlNfe({ nNF, itens: [{ cProd: `C${nNF}`, xProd: `PECA ${nNF}`, q: 2, vProd: valor }], dups, ...extra })).nota_id;
  const boleto = (valor, vencimento, extra = {}) => criarBoleto(db, { linha: linhaDigitavel({ valor, vencimento }), beneficiario_cnpj: CNPJ_FORNECEDOR, pagador_cnpj: CNPJ_OFICINA, ...extra }, HOJE, cfg());
  const cliente = Number(db.prepare("INSERT INTO clientes (nome) VALUES ('CLIENTE TESTE')").run().lastInsertRowid);
  const os = (numero, placa, extra = {}) => Number(db.prepare(`INSERT INTO vendas (numero, data, cliente_id, placa, situacao, valor_total, valor_mao_obra, custo_pecas)
      VALUES (?, ?, ?, ?, 'concluida', 900, 200, ?)`).run(numero, extra.data ?? '2026-10-02', cliente, placa, extra.custo ?? null).lastInsertRowid);
  const tipos = (lista) => lista.filter((o) => !o.aceita).map((o) => o.tipo);
  return { db, cfg, nota, boleto, os, tipos };
}

test('boleto com o mesmo valor e vencimento de uma parcela é ligado sozinho à nota', () => {
  const { db, nota, boleto, tipos } = cenario();
  const n = nota(500, 320);
  const r = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.equal(r.auto.ligado, true);
  assert.equal(r.auto.sugestao.score, 100);
  const c = db.prepare('SELECT * FROM conciliacoes WHERE boleto_id = ?').get(r.boleto_id);
  assert.equal(c.nota_id, n);
  assert.ok(c.duplicata_id);
  assert.deepEqual(tipos(r.ocorrencias), []);
});

test('boleto SEM nota correspondente vira ocorrência ALTA e o pagamento é travado até liberar com motivo', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(500, 320);
  const r = boleto(777.77, '2026-10-25', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.equal(r.auto.ligado, false);
  assert.deepEqual(r.ocorrencias.map((o) => [o.tipo, o.severidade]), [['boleto_sem_nota', 'alta']]);
  assert.throws(() => pagarBoleto(db, r.boleto_id, { conferiuBanco: true }, HOJE, cfg()), (e) => e instanceof ErroBloqueio && e.ocorrencias.length === 1 && e.ocorrencias[0].tipo === 'boleto_sem_nota');
  assert.throws(() => pagarBoleto(db, r.boleto_id, { aprovar: true, motivo: 'ok' }, HOJE, cfg()), ErroBloqueio);     // motivo curto demais
  const pago = pagarBoleto(db, r.boleto_id, { aprovar: true, motivo: 'Fornecedor confirmou por telefone' }, HOJE, cfg());
  assert.equal(pago.liberado_com_ressalva, true);
  const b = db.prepare('SELECT * FROM boletos WHERE id = ?').get(r.boleto_id);
  assert.equal(b.situacao, 'pago');
  assert.equal(b.aprovado_motivo, 'Fornecedor confirmou por telefone');
  assert.equal(db.prepare('SELECT pago_em FROM saidas WHERE id = ?').get(b.saida_id).pago_em, HOJE);
  assert.throws(() => pagarBoleto(db, r.boleto_id, {}, HOJE, cfg()), /já foi pago/);
});

test('boleto conciliado e sem problemas é pago normalmente', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(500, 320);
  const r = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.throws(() => pagarBoleto(db, r.boleto_id, {}, HOJE, cfg()), (e) => e instanceof ErroBloqueio && e.ocorrencias[0].tipo === 'confira_recebedor');     // sem conferir o recebedor no banco não paga
  const p = pagarBoleto(db, r.boleto_id, { conferiuBanco: true }, HOJE, cfg());
  assert.equal(p.liberado_com_ressalva, false);
  assert.ok(db.prepare('SELECT conferido_banco_em FROM boletos WHERE id = ?').get(r.boleto_id).conferido_banco_em);
});

const FILIAL_FORNECEDOR = '11222333000262';          // mesma raiz do CNPJ do fornecedor de teste, outra filial (DV válido)

test('beneficiário do boleto diferente do emitente da nota é alta; mesma raiz de CNPJ (filial) é média', () => {
  const { nota, boleto } = cenario();
  nota(500, 320);
  const golpe = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: CNPJ_OUTRO });
  assert.deepEqual(golpe.ocorrencias.map((o) => [o.tipo, o.severidade]), [['boleto_beneficiario_diverge', 'alta']]);
  const outro = cenario();
  outro.nota(501, 410);
  const filial = outro.boleto(410, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: FILIAL_FORNECEDOR });
  assert.deepEqual(filial.ocorrencias.map((o) => [o.tipo, o.severidade]), [['boleto_beneficiario_diverge', 'media']]);
  const igual = cenario();
  igual.nota(502, 410);
  const certo = igual.boleto(410, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: CNPJ_FORNECEDOR });
  assert.deepEqual(certo.ocorrencias, []);
});

test('CNPJ digitado errado no beneficiário é recusado', () => {
  const { boleto, nota } = cenario();
  nota(500, 320);
  assert.throws(() => boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: '11222333000263' }), /CNPJ do beneficiário/);
});

test('pagador diferente da oficina e nota emitida para outro CNPJ são ocorrências altas', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(500, 320, [{ venc: '2026-10-20', valor: 320 }], { destCnpj: CNPJ_OUTRO });
  const r = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', pagador_cnpj: CNPJ_OUTRO });
  const t = r.ocorrencias.map((o) => o.tipo).sort();
  assert.deepEqual(t, ['boleto_pagador_diverge', 'nota_destinatario_diverge']);
  assert.ok(ocorrencias(db, HOJE, cfg()).every((o) => o.severidade === 'alta' || o.tipo !== 'boleto_pagador_diverge'));
});

test('boleto maior que a nota: valor divergente (alta) e valor menor que a nota continua sem boleto', () => {
  const { db, cfg, nota, boleto, tipos } = cenario();
  const n = nota(500, 320, []);                        // nota sem parcelas
  const r = boleto(350, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', numero_documento: '000500' });
  assert.equal(r.auto.ligado, false);                 // 75: o número bate mas o valor não
  conciliar(db, r.boleto_id, [{ nota_id: n, valor: 320 }], 'manual');
  const lista = ocorrenciasDoBoleto(db, r.boleto_id, HOJE, cfg());
  const div = lista.find((o) => o.tipo === 'boleto_valor_diverge');
  assert.equal(div.severidade, 'alta');
  assert.equal(div.valor, 30);
  assert.match(div.detalhe, /sobram/);
  assert.ok(tipos(lista).includes('boleto_valor_diverge'));
});

test('boleto agrupado (soma de duas notas) é sugerido, mas não ligado sozinho', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(600, 200, []);
  nota(601, 150, []);
  nota(602, 999, []);
  const r = boleto(350, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.equal(r.auto.ligado, false);
  const sug = sugerirNotas(db, db.prepare('SELECT * FROM boletos WHERE id = ?').get(r.boleto_id), cfg());
  assert.equal(sug[0].agrupado, true);
  assert.equal(sug[0].itens.length, 2);
  assert.equal(sug[0].score, 60);
});

test('boleto em duplicidade (mesmo fornecedor, valor e vencimento) e cadastro repetido da mesma linha', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(500, 320);
  const linha = linhaDigitavel({ valor: 320, vencimento: '2026-10-20' });
  const a = criarBoleto(db, { linha, fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' }, HOJE, cfg());
  assert.throws(() => criarBoleto(db, { linha, fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' }, HOJE, cfg()), /já está cadastrado/);
  // segunda via com outra linha digitável, mesmo valor e vencimento
  const b = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  const dup = ocorrencias(db, HOJE, cfg()).find((o) => o.tipo === 'boleto_duplicado');
  assert.deepEqual(dup.boletos.sort(), [a.boleto_id, b.boleto_id].sort());
  assert.equal(dup.severidade, 'alta');
});

test('valor ou vencimento digitados que não batem com a linha digitável são recusados', () => {
  const { db, cfg } = cenario();
  const linha = linhaDigitavel({ valor: 320, vencimento: '2026-10-20' });
  assert.throws(() => criarBoleto(db, { linha, valor: 32, fornecedor_nome: 'F' }, HOJE, cfg()), /valor digitado/);
  assert.throws(() => criarBoleto(db, { linha, vencimento: '2026-10-21', fornecedor_nome: 'F' }, HOJE, cfg()), /vencimento digitado/);
  assert.throws(() => criarBoleto(db, { linha: linha.slice(0, 40) + '9999999', fornecedor_nome: 'F' }, HOJE, cfg()), /confere|verificador/);
});

test('boleto manual (sem linha) exige valor e vencimento', () => {
  const { db, cfg } = cenario();
  assert.throws(() => criarBoleto(db, { fornecedor_nome: 'F' }, HOJE, cfg()), /valor/);
  assert.throws(() => criarBoleto(db, { valor: 100, fornecedor_nome: 'F' }, HOJE, cfg()), /vencimento/);
  const r = criarBoleto(db, { valor: 100, vencimento: '2026-10-30', fornecedor_nome: 'F' }, HOJE, cfg());
  assert.ok(r.boleto_id);
});

test('nota com parcela vencida e sem boleto aparece como média; marcar como conferida (com motivo) tira da lista', () => {
  const { db, cfg, nota } = cenario();
  nota(500, 320, [{ venc: '2026-10-05', valor: 320 }]);
  let lista = ocorrencias(db, HOJE, cfg());
  const o = lista.find((x) => x.tipo === 'nota_sem_boleto');
  assert.equal(o.severidade, 'media');
  const antes = resumoAuditoria(lista).media;
  assert.throws(() => aceitarOcorrencia(db, o.chave, 'ok', HOJE, cfg()), /Explique/);
  aceitarOcorrencia(db, o.chave, 'Pago no Pix em 05/10', HOJE, cfg());
  lista = ocorrencias(db, HOJE, cfg());
  assert.ok(lista.find((x) => x.chave === o.chave).aceita);
  assert.equal(resumoAuditoria(lista).media, antes - 1);
});

test('nota cancelada com boleto ligado é alta; nota cobrada duas vezes é alta', () => {
  const { db, cfg, nota, boleto } = cenario();
  const n = nota(500, 320);
  const r1 = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  const r2 = boleto(320, '2026-10-27', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.throws(() => conciliar(db, r2.boleto_id, [{ nota_id: n, valor: 320 }], 'manual'), /só tem R\$ 0.00 sem boleto/);          // a ligação em excesso é recusada
  db.prepare("INSERT INTO conciliacoes (boleto_id, nota_id, valor, origem) VALUES (?, ?, 320, 'manual')").run(r2.boleto_id, n);        // mas um dado antigo assim ainda é apontado
  assert.ok(ocorrencias(db, HOJE, cfg()).some((o) => o.tipo === 'nota_cobrada_a_mais' && o.severidade === 'alta'));
  db.prepare("UPDATE notas_compra SET situacao = 'cancelada' WHERE id = ?").run(n);
  assert.ok(ocorrenciasDoBoleto(db, r1.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'nota_cancelada_com_boleto'));
});

test('alocar peças em OS: parcial, resto, custo da OS vem das notas e a soma fecha com a nota', () => {
  const { db, nota, os } = cenario();
  const n = nota(700, 301);                                                 // 2 unidades por R$ 301
  const item = db.prepare('SELECT id, custo_total, quantidade FROM nota_itens WHERE nota_id = ?').get(n);
  const osA = os('1200', 'ABC1D23');
  const osB = os('1201', 'XYZ9K88');
  alocar(db, item.id, { destino: 'os', vendaId: osA, quantidade: 1 });
  assert.equal(restanteItem(db, item.id).quantidade, 1);
  assert.equal(db.prepare('SELECT custo_pecas, custo_pecas_auto FROM vendas WHERE id = ?').get(osA).custo_pecas, 150.5);
  alocar(db, item.id, { destino: 'os', vendaId: osB });                    // o que sobra
  const soma = db.prepare('SELECT ROUND(SUM(valor), 2) AS t FROM alocacoes WHERE item_id = ?').get(item.id).t;
  assert.equal(soma, 301);
  assert.equal(restanteItem(db, item.id).quantidade, 0);
  assert.throws(() => alocar(db, item.id, { destino: 'os', vendaId: osA }), /já tem destino/);
  const a = db.prepare('SELECT id FROM alocacoes WHERE venda_id = ?').get(osA);
  removerAlocacao(db, a.id);
  assert.equal(db.prepare('SELECT custo_pecas FROM vendas WHERE id = ?').get(osA).custo_pecas, null);   // voltou a "não informado"
});

test('custo digitado à mão não é sobrescrito; divergência com as notas é apontada', () => {
  const { db, cfg, nota, os } = cenario();
  const n = nota(701, 300, []);
  const manual = os('1300', 'AAA1A11', { custo: 120 });                  // alguém digitou 120, mas a nota diz 300
  alocarNotaNaOs(db, n, manual);
  assert.equal(db.prepare('SELECT custo_pecas, custo_pecas_auto FROM vendas WHERE id = ?').get(manual).custo_pecas, 120);
  const o = ocorrencias(db, HOJE, cfg()).find((x) => x.tipo === 'custo_os_diverge');
  assert.ok(o);
  assert.equal(o.valor, -180);
});

test('peças sem destino depois de 7 dias viram ocorrência; estoque e uso interno dão destino', () => {
  const { db, cfg, nota, tipos } = cenario();
  const n = nota(702, 300, [], { dhEmi: '2026-09-20T10:00:00-03:00' });
  assert.ok(tipos(ocorrencias(db, HOJE, cfg())).includes('nota_sem_destino'));
  const item = db.prepare('SELECT id FROM nota_itens WHERE nota_id = ?').get(n);
  alocar(db, item.id, { destino: 'estoque' });
  assert.ok(!tipos(ocorrencias(db, HOJE, cfg())).includes('nota_sem_destino'));
  assert.throws(() => alocar(db, item.id, { destino: 'os', vendaId: 999 }), /já tem destino/);
});

test('sugestão de OS pelo pedido (xPed) e pela placa escrita na nota', () => {
  const { db, os } = cenario();
  const osA = os('1148', 'ABC1D23');
  const osB = os('1149', 'QWE4R56');
  const r = importarNotaXml(db, xmlNfe({
    nNF: 800, infCpl: 'Veiculo placa QWE-4R56',
    itens: [{ cProd: 'A', xProd: 'AMORTECEDOR', q: 1, vProd: 200, xPed: '1148' }, { cProd: 'B', xProd: 'COXIM', q: 1, vProd: 80 }],
  }));
  const sug = sugerirAlocacoes(db, r.nota_id);
  assert.equal(sug.length, 2);
  assert.equal(sug.find((s) => s.descricao === 'AMORTECEDOR').venda_id, osA);
  assert.match(sug.find((s) => s.descricao === 'AMORTECEDOR').motivo, /pedido/);
  assert.equal(sug.find((s) => s.descricao === 'COXIM').venda_id, osB);
  assert.match(sug.find((s) => s.descricao === 'COXIM').motivo, /placa/);
});

test('nota digitada à mão: sem itens vira "Peças (total)", parcelas precisam fechar com o total, chave é validada', () => {
  const { db } = cenario();
  const r = criarNotaManual(db, { fornecedor_nome: 'Auto Peças do Zé', numero: '000321', data_emissao: '2026-10-05', valor_total: 450, duplicatas: [{ vencimento: '2026-10-30', valor: 450 }] });
  const itens = db.prepare('SELECT * FROM nota_itens WHERE nota_id = ?').all(r.nota_id);
  assert.equal(itens.length, 1);
  assert.equal(itens[0].custo_total, 450);
  assert.equal(db.prepare('SELECT numero FROM notas_compra WHERE id = ?').get(r.nota_id).numero, '321');
  assert.throws(() => criarNotaManual(db, { fornecedor_nome: 'Auto Peças do Zé', numero: '322', data_emissao: '2026-10-05', valor_total: 450, duplicatas: [{ vencimento: '2026-10-30', valor: 400 }] }), /parcelas somam/);
  assert.throws(() => criarNotaManual(db, { fornecedor_nome: 'Auto Peças do Zé', numero: '321', data_emissao: '2026-10-05', valor_total: 450 }), /já (está cadastrada|existe)/i);
  assert.throws(() => criarNotaManual(db, { fornecedor_nome: 'X', numero: '5', data_emissao: '2026-10-05', valor_total: 10, chave: '123' }), /44 caracteres/);
});

test('cancelar o boleto remove a conta a pagar ligada e libera a nota', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(500, 320);
  const r = boleto(320, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  const saida = db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(r.boleto_id).saida_id;
  assert.ok(db.prepare('SELECT 1 FROM saidas WHERE id = ?').get(saida));
  cancelarBoleto(db, r.boleto_id);
  assert.equal(db.prepare('SELECT 1 FROM saidas WHERE id = ?').get(saida), undefined);
  assert.ok(ocorrencias(db, HOJE, cfg()).every((o) => o.tipo !== 'boleto_sem_nota'));
});

test('preço unitário bem acima do histórico do mesmo item é apontado', () => {
  const { db, cfg, tipos } = cenario();
  const mk = (n, preco, data) => importarNotaXml(db, xmlNfe({ nNF: n, dhEmi: `${data}T10:00:00-03:00`, itens: [{ cProd: 'FILTRO', xProd: 'FILTRO DE OLEO', q: 1, vProd: preco }], dups: [] })).nota_id;
  mk(900, 30, '2026-08-10'); mk(901, 31, '2026-08-25'); mk(902, 30, '2026-09-10');
  const caro = mk(903, 45, '2026-10-01');
  const o = ocorrencias(db, HOJE, cfg()).find((x) => x.tipo === 'preco_acima');
  assert.ok(o);
  assert.deepEqual(o.notas, [caro]);
  assert.match(o.detalhe, /\+5\d%/);
  assert.ok(tipos([o]).length === 1);
});

test('OS com custo de peça sem nota vira UMA ocorrência resumo (não uma por OS) e some quando a peça é ligada', () => {
  const { db, cfg, nota, os } = cenario();
  const a = os('2001', 'AAA1A11', { custo: 300, data: '2026-10-02' });
  os('2002', 'BBB2B22', { custo: 120, data: '2026-10-03' });
  os('2003', 'CCC3C33', { custo: 10, data: '2026-10-03' });                       // abaixo de R$ 30: ignorada
  os('1999', 'DDD4D44', { custo: 500, data: '2026-09-15' });                      // antes do início da auditoria (1º dia do mês)
  let lista = ocorrencias(db, HOJE, cfg()).filter((o) => o.tipo === 'os_sem_nota');
  assert.equal(lista.length, 1);
  assert.equal(lista[0].valor, 420);
  assert.match(lista[0].titulo, /^2 OS/);
  const n = nota(703, 300, []);
  alocarNotaNaOs(db, n, a);
  lista = ocorrencias(db, HOJE, cfg()).filter((o) => o.tipo === 'os_sem_nota');
  assert.equal(lista[0].valor, 120);
});

test('XML que chega depois da nota digitada completa a mesma nota (não duplica), com chave e itens reais', () => {
  const { db } = cenario();
  criarNotaManual(db, { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', cnpj_emitente: '11222333000181', numero: '4321', data_emissao: '2026-10-01', valor_total: 300, duplicatas: [{ vencimento: '2026-10-31', valor: 300 }] });
  const r = importarNotaXml(db, xmlNfe({ nNF: 4321, itens: [{ cProd: 'A', xProd: 'PASTILHA', q: 1, vProd: 120 }, { cProd: 'B', xProd: 'DISCO', q: 2, vProd: 180 }], dups: [{ venc: '2026-10-31', valor: 300 }] }));
  assert.equal(r.status, 'atualizada');
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM notas_compra').get().n, 1);
  const n = db.prepare('SELECT * FROM notas_compra WHERE id = ?').get(r.nota_id);
  assert.equal(n.origem, 'xml');
  assert.equal(n.chave.length, 44);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM nota_itens WHERE nota_id = ?').get(r.nota_id).n, 2);
  // valor diferente: não mexe, avisa
  criarNotaManual(db, { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', numero: '555', data_emissao: '2026-10-01', valor_total: 100 });
  const d = importarNotaXml(db, xmlNfe({ nNF: 555, itens: [{ cProd: 'A', xProd: 'X', q: 1, vProd: 250 }] }));
  assert.equal(d.status, 'divergente_manual');
  assert.equal(db.prepare('SELECT valor_total FROM notas_compra WHERE numero = ?').get('555').valor_total, 100);
});

test('mesmo nome de fornecedor com CNPJ diferente não funde duas empresas', () => {
  const { db } = cenario();
  importarNotaXml(db, xmlNfe({ nNF: 1, emitNome: 'AUTO PECAS CENTRAL', emitCnpj: '12345678000195' }));
  importarNotaXml(db, xmlNfe({ nNF: 2, emitNome: 'AUTO PECAS CENTRAL', emitCnpj: '27865757000102' }));
  const f = db.prepare("SELECT nome, cnpj FROM fornecedores WHERE nome LIKE 'AUTO PECAS CENTRAL%' ORDER BY id").all();
  assert.equal(f.length, 2);
  assert.notEqual(f[0].cnpj, f[1].cnpj);
  assert.match(f[1].nome, /AUTO PECAS CENTRAL \(27865757\)/);
});

test('boleto único que cobre todas as parcelas da nota é sugerido, e fornecedor inexistente é recusado', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(900, 600, [{ venc: '2026-10-10', valor: 300 }, { venc: '2026-11-10', valor: 300 }]);
  const r = boleto(600, '2026-10-10', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.equal(r.auto.ligado, false);                               // 70: sugere, não liga sozinho
  assert.equal(r.auto.sugestoes[0].score, 70);
  assert.throws(() => criarBoleto(db, { valor: 10, vencimento: '2026-10-30', fornecedor_id: 999 }, HOJE, cfg()), /Fornecedor não encontrado/);
});

test('boleto aberto sem o CNPJ do beneficiário vira lembrete (baixa) e sobe para média perto do vencimento', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(500, 320, [{ venc: '2026-11-20', valor: 320 }]);
  const longe = boleto(320, '2026-11-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: '' });
  assert.deepEqual(longe.ocorrencias.map((o) => [o.tipo, o.severidade]), [['boleto_sem_beneficiario', 'baixa']]);
  nota(501, 90, [{ venc: '2026-10-10', valor: 90 }]);
  const perto = boleto(90, '2026-10-10', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: '' });
  assert.deepEqual(perto.ocorrencias.map((o) => [o.tipo, o.severidade]), [['boleto_sem_beneficiario', 'media']]);
  const com = boleto(777.77, '2026-11-25', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' });
  assert.ok(!com.ocorrencias.some((o) => o.tipo === 'boleto_sem_beneficiario'));
  // lembrete não trava o pagamento
  assert.doesNotThrow(() => pagarBoleto(db, perto.boleto_id, { conferiuBanco: true }, HOJE, cfg()));
});

test('fornecedor que sempre cobrou por um banco e aparece com outro: alerta médio (boleto_banco_novo)', () => {
  const { db, cfg, nota, boleto } = cenario();
  const b = (valor, venc, banco) => criarBoleto(db, { linha: linhaDigitavel({ banco, valor, vencimento: venc }), beneficiario_cnpj: CNPJ_FORNECEDOR, fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' }, HOJE, cfg());
  nota(600, 100, [{ venc: '2026-10-20', valor: 100 }]); nota(601, 110, [{ venc: '2026-10-21', valor: 110 }]); nota(602, 120, [{ venc: '2026-10-22', valor: 120 }]);
  const r1 = b(100, '2026-10-20', '341');
  const r2 = b(110, '2026-10-21', '341');
  assert.ok(!r1.ocorrencias.some((o) => o.tipo === 'boleto_banco_novo'));
  assert.ok(!r2.ocorrencias.some((o) => o.tipo === 'boleto_banco_novo'));     // só 1 anterior: ainda sem histórico
  const r3 = b(120, '2026-10-22', '237');
  assert.ok(r3.ocorrencias.some((o) => o.tipo === 'boleto_banco_novo' && o.severidade === 'media'));
  const r4 = criarBoleto(db, { linha: linhaDigitavel({ banco: '341', valor: 55, vencimento: '2026-10-23' }), beneficiario_cnpj: CNPJ_FORNECEDOR, fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA' }, HOJE, cfg());
  assert.ok(!r4.ocorrencias.some((o) => o.tipo === 'boleto_banco_novo'));     // voltou a um banco já usado
});

test('nota paga no ato (Pix/dinheiro) sem parcelas não cobra "nota sem boleto"; nota a prazo sem parcelas cobra', () => {
  const { db, cfg, nota } = cenario();
  nota(700, 200, [], { pag: '<detPag><tPag>17</tPag><vPag>200.00</vPag></detPag>', dhEmi: '2026-09-20T10:00:00-03:00' });
  nota(701, 150, [], { dhEmi: '2026-09-20T10:00:00-03:00' });
  const sem = ocorrencias(db, HOJE, cfg()).filter((o) => o.tipo === 'nota_sem_boleto');
  assert.equal(sem.length, 1);
  assert.match(sem[0].detalhe, /701/);
  assert.equal(db.prepare("SELECT pago_no_ato FROM notas_compra WHERE numero = '700'").get().pago_no_ato, 1);
});

test('remessa em consignação (CFOP 5917) não espera boleto; protocolo cancelado entra como nota cancelada', () => {
  const { db } = cenario();
  const r = importarNotaXml(db, xmlNfe({ nNF: 710, cfop: '5917', itens: [{ cProd: 'K1', xProd: 'KIT', q: 1, vProd: 300 }] }));
  assert.match(r.avisos.join(' '), /CONSIGNAÇÃO/);
  assert.equal(db.prepare('SELECT finalidade FROM notas_compra WHERE id = ?').get(r.nota_id).finalidade, 'ajuste');
  const c = importarNotaXml(db, xmlNfe({ nNF: 711, cStat: '101' }));
  assert.equal(db.prepare('SELECT situacao FROM notas_compra WHERE id = ?').get(c.nota_id).situacao, 'cancelada');
});

test('boleto emitido por CNPJ autorizado no cadastro do fornecedor ou informado pela nota (CNPJReceb) não é grave; outro CNPJ continua grave', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(720, 400, [{ venc: '2026-10-20', valor: 400 }]);
  const estranho = boleto(400, '2026-10-20', { fornecedor_nome: 'DISTRIBUIDORA TESTE LTDA', beneficiario_cnpj: CNPJ_OUTRO });
  assert.ok(estranho.ocorrencias.some((o) => o.tipo === 'boleto_beneficiario_diverge' && o.severidade === 'alta'));
  db.prepare("UPDATE fornecedores SET beneficiarios_autorizados = ?").run(CNPJ_OUTRO);
  assert.ok(!ocorrenciasDoBoleto(db, estranho.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'boleto_beneficiario_diverge'));
  db.prepare("UPDATE fornecedores SET beneficiarios_autorizados = NULL").run();
  db.prepare("UPDATE notas_compra SET cnpj_receb = ?").run(CNPJ_OUTRO);
  assert.ok(!ocorrenciasDoBoleto(db, estranho.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'boleto_beneficiario_diverge'));
  db.prepare("UPDATE notas_compra SET cnpj_receb = NULL").run();
  assert.ok(ocorrenciasDoBoleto(db, estranho.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'boleto_beneficiario_diverge'));
});
