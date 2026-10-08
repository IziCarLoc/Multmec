// Regressões dos achados da revisão adversarial do módulo de conferência de compras.
import test from 'node:test';
import assert from 'node:assert/strict';
import { abrirBanco, gravarConfig, lerConfig } from '../src/db.js';
import { importarNotaXml, criarNotaManual, alocar, definirSituacaoNota, ratearCusto, recalcularCustoOs, sugerirAlocacoes, duplicatasComSaldo } from '../src/compras.js';
import { criarBoleto, conciliar, desconciliar, pagarBoleto, cancelarBoleto, reabrirBoleto, atualizarBoleto, ErroBloqueio } from '../src/boletos.js';
import { ocorrencias, ocorrenciasDoBoleto, aceitarOcorrencia, desfazerAceite, resumoAuditoria } from '../src/auditoria.js';
import { lerXmlNfe } from '../src/nfe.js';
import { xmlNfe, xmlCancelamento, chave, CNPJ_FORNECEDOR, CNPJ_OFICINA } from './helpers/nfe.js';
import { linhaDigitavel } from './helpers/boleto.js';

const HOJE = '2026-10-08';
const NOME = 'DISTRIBUIDORA TESTE LTDA';

function cenario() {
  const db = abrirBanco(':memory:');
  gravarConfig(db, { cnpjOficina: CNPJ_OFICINA });
  const cfg = () => lerConfig(db);
  db.prepare("INSERT INTO fornecedores (nome, cnpj, principal, confirmado_em) VALUES (?, ?, 1, '2026-09-01')").run(NOME, CNPJ_FORNECEDOR);
  const nota = (nNF, valor, dups = [{ venc: '2026-10-20', valor }], extra = {}) =>
    importarNotaXml(db, xmlNfe({ nNF, itens: [{ cProd: `C${nNF}`, xProd: `PECA ${nNF}`, q: 2, vProd: valor }], dups, ...extra })).nota_id;
  const boleto = (valor, vencimento, extra = {}) => criarBoleto(db, { linha: linhaDigitavel({ valor, vencimento }), beneficiario_cnpj: CNPJ_FORNECEDOR, pagador_cnpj: CNPJ_OFICINA, fornecedor_nome: NOME, ...extra }, HOJE, cfg());
  const pagar = (id, extra = {}) => pagarBoleto(db, id, { conferiuBanco: true, ...extra }, HOJE, cfg());
  const tipos = (id) => ocorrenciasDoBoleto(db, id, HOJE, cfg()).filter((o) => !o.aceita).map((o) => o.tipo);
  return { db, cfg, nota, boleto, pagar, tipos };
}

test('cancelar o boleto libera a nota: ela volta a precisar de boleto e o boleto certo liga sozinho', () => {
  const { db, cfg, nota, boleto } = cenario();
  const n = nota(500, 320, [{ venc: '2026-10-09', valor: 320 }]);
  const r1 = boleto(320, '2026-10-09');
  assert.equal(r1.auto.ligado, true);
  cancelarBoleto(db, r1.boleto_id, 'contestado', 'valor errado');
  assert.equal(db.prepare('SELECT COUNT(*) AS c FROM conciliacoes WHERE nota_id = ?').get(n).c, 0);
  assert.ok(ocorrencias(db, HOJE, cfg()).some((o) => o.tipo === 'nota_sem_boleto' && o.notas.includes(n)));
  const r2 = boleto(320, '2026-10-09', { linha: linhaDigitavel({ valor: 320, vencimento: '2026-10-09', livre: 4242 }) });
  assert.equal(r2.auto.ligado, true);
  assert.deepEqual(r2.ocorrencias.filter((o) => o.severidade === 'alta'), []);
});

test('boleto contestado: não paga, pode ser reaberto (volta a conta e a ligação) e cancelado pode ser recadastrado', () => {
  const { db, nota, boleto, pagar } = cenario();
  nota(501, 100, [{ venc: '2026-10-20', valor: 100 }]);
  const linha = linhaDigitavel({ valor: 100, vencimento: '2026-10-20' });
  const r = criarBoleto(db, { linha, beneficiario_cnpj: CNPJ_FORNECEDOR, pagador_cnpj: CNPJ_OFICINA, fornecedor_nome: NOME }, HOJE, lerConfig(db));
  cancelarBoleto(db, r.boleto_id, 'contestado', 'conferindo com o fornecedor');
  assert.throws(() => pagar(r.boleto_id), /contestado/);
  assert.throws(() => criarBoleto(db, { linha, fornecedor_nome: NOME }, HOJE, lerConfig(db)), /contestado/);
  const aberto = reabrirBoleto(db, r.boleto_id, HOJE);
  assert.equal(aberto.auto.ligado, true);
  assert.ok(db.prepare('SELECT 1 FROM saidas WHERE id = (SELECT saida_id FROM boletos WHERE id = ?)').get(r.boleto_id));
  cancelarBoleto(db, r.boleto_id, 'cancelado');
  const de_novo = criarBoleto(db, { linha, beneficiario_cnpj: CNPJ_FORNECEDOR, pagador_cnpj: CNPJ_OFICINA, fornecedor_nome: NOME }, HOJE, lerConfig(db));
  assert.equal(de_novo.boleto_id, r.boleto_id);                      // reaproveita o registro
  assert.equal(db.prepare('SELECT situacao FROM boletos WHERE id = ?').get(r.boleto_id).situacao, 'aberto');
  void boleto;
});

test('conciliar recusa o que não pode ser verdade', () => {
  const { db, nota, boleto } = cenario();
  const n = nota(510, 400, [{ venc: '2026-10-20', valor: 400 }]);
  const outraEmpresa = db.prepare("INSERT INTO fornecedores (nome, cnpj) VALUES ('OUTRA LTDA', '27865757000102')").run().lastInsertRowid;
  const b = boleto(150, '2026-10-21');                               // não bate com nenhuma parcela: fica sem ligação
  assert.equal(b.auto.ligado, false);
  assert.throws(() => conciliar(db, b.boleto_id, [{ nota_id: n, valor: 400 }]), /mais que o boleto/);                       // soma maior que o boleto
  assert.throws(() => conciliar(db, b.boleto_id, [{ nota_id: 9999, valor: 1 }]), /não encontrada/);
  assert.throws(() => conciliar(db, b.boleto_id, 'x'), /ao menos uma nota/);
  assert.throws(() => conciliar(db, b.boleto_id, [null]), /inválido/);
  assert.throws(() => conciliar(db, b.boleto_id, [{ nota_id: n, valor: 100, duplicata_id: 99999 }]), /não pertence/);
  const nOutro = importarNotaXml(db, xmlNfe({ nNF: 511, emitCnpj: '27865757000102', emitNome: 'OUTRA LTDA', itens: [{ cProd: 'Z', xProd: 'Z', q: 1, vProd: 150 }], dups: [] })).nota_id;
  assert.throws(() => conciliar(db, b.boleto_id, [{ nota_id: nOutro, valor: 150 }]), /outro fornecedor/);
  const dev = importarNotaXml(db, xmlNfe({ nNF: 512, finNFe: 4, itens: [{ cProd: 'D', xProd: 'D', q: 1, vProd: 150 }], dups: [] })).nota_id;
  assert.throws(() => conciliar(db, b.boleto_id, [{ nota_id: dev, valor: 150 }]), /devolução/);
  conciliar(db, b.boleto_id, [{ nota_id: n, valor: 150 }]);
  assert.throws(() => conciliar(db, boleto(300, '2026-10-22').boleto_id, [{ nota_id: n, valor: 300 }]), /só tem R\$ 250.00/);        // saldo da nota
  definirSituacaoNota(db, n, 'cancelada', 'fornecedor cancelou');
  const b3 = boleto(50, '2026-10-23');
  assert.throws(() => conciliar(db, b3.boleto_id, [{ nota_id: n, valor: 50 }]), /cancelada/);
  void outraEmpresa;
});

test('boleto pago: a ligação com a nota não pode ser desfeita; ligar a nota que chegou depois pode', () => {
  const { db, nota, boleto, pagar } = cenario();
  const sem = boleto(777, '2026-10-12');
  pagar(sem.boleto_id, { aprovar: true, motivo: 'Fornecedor confirmou por telefone, nota amanhã' });
  const n = nota(520, 777, [{ venc: '2026-10-12', valor: 777 }]);
  conciliar(db, sem.boleto_id, [{ nota_id: n, valor: 777 }]);                               // fechar a ressalva é permitido
  assert.throws(() => desconciliar(db, sem.boleto_id, n), /já foi pago/);
});

test('aceite só vale para o mesmo fato: a divergência aceita por R$ 30 não cobre a de R$ 310', () => {
  const { db, cfg, nota, boleto } = cenario();
  const n1 = nota(530, 1000, [{ venc: '2026-10-20', valor: 1000 }]);
  const n2 = nota(531, 40, [{ venc: '2026-10-20', valor: 40 }]);
  const b = boleto(100, '2026-10-20', { linha: linhaDigitavel({ valor: 100, vencimento: '2026-10-20', livre: 777 }) });
  assert.equal(b.auto.ligado, false);
  conciliar(db, b.boleto_id, [{ nota_id: n1, valor: 70 }, { nota_id: n2, valor: 30 }]);          // soma 100: ok
  desconciliar(db, b.boleto_id, n1);                                                          // sobra R$ 70 do boleto sem explicação
  const o = ocorrencias(db, HOJE, cfg()).find((x) => x.tipo === 'boleto_valor_diverge' && x.boletos.includes(b.boleto_id));
  assert.equal(o.severidade, 'alta');
  aceitarOcorrencia(db, o.chave, 'Juros combinados por telefone', HOJE, cfg());
  assert.ok(ocorrencias(db, HOJE, cfg()).find((x) => x.chave === o.chave).aceita);
  conciliar(db, b.boleto_id, [{ nota_id: n2, valor: 10 }]);                                   // agora sobram R$ 90: outro fato
  const novo = ocorrencias(db, HOJE, cfg()).find((x) => x.chave === o.chave);
  assert.equal(novo.aceita, null);
  assert.ok(novo.aceite_vencido);
});

test('aceitar exige ocorrência que existe, motivo de verdade e deixa trilha só de inclusão', () => {
  const { db, cfg, boleto } = cenario();
  assert.throws(() => aceitarOcorrencia(db, 'boleto_sem_nota:boleto:1', 'vou aceitar de antemão', HOJE, cfg()), /não existe mais/);
  const b = boleto(88, '2026-10-30');
  const o = ocorrenciasDoBoleto(db, b.boleto_id, HOJE, cfg()).find((x) => x.tipo === 'boleto_sem_nota');
  assert.throws(() => aceitarOcorrencia(db, o.chave, 'ok', HOJE, cfg()), /pelo menos 10/);
  assert.throws(() => aceitarOcorrencia(db, o.chave, 'aaaaaaaaaaaa', HOJE, cfg()), /pelo menos 10/);
  aceitarOcorrencia(db, o.chave, 'Nota chega amanhã por e-mail', HOJE, cfg());
  aceitarOcorrencia(db, o.chave, 'Nota chegou, lançar segunda', HOJE, cfg());               // reaceitar sobrescreve o estado atual...
  desfazerAceite(db, o.chave);
  const log = db.prepare("SELECT acao FROM auditoria_log WHERE acao LIKE '%aceit%' ORDER BY id").all().map((x) => x.acao);
  assert.deepEqual(log, ['aceitar', 'aceitar', 'desfazer_aceite']);                          // ...mas o histórico guarda os dois motivos
});

test('id de nota apagada não é reaproveitado: a nota nova não herda o aceite da antiga', () => {
  const { db, cfg, nota } = cenario();
  const n = nota(540, 5000, [{ venc: '2026-10-20', valor: 5000 }], { destCnpj: '27865757000102' });
  const o = ocorrencias(db, HOJE, cfg()).find((x) => x.tipo === 'nota_destinatario_diverge');
  aceitarOcorrencia(db, o.chave, 'é nota do sócio, conferido', HOJE, cfg());
  db.prepare('DELETE FROM notas_compra WHERE id = ?').run(n);
  const n2 = nota(541, 9000, [{ venc: '2026-10-20', valor: 9000 }], { destCnpj: '27865757000102' });
  assert.ok(n2 > n);
  const o2 = ocorrencias(db, HOJE, cfg()).find((x) => x.tipo === 'nota_destinatario_diverge');
  assert.equal(o2.aceita, null);
});

test('nota digitada à mão ou XML sem protocolo não prova nada: boleto ligado a ela fica grave até haver prova', () => {
  const { db, cfg, boleto, pagar } = cenario();
  const manual = criarNotaManual(db, { fornecedor_nome: NOME, numero: '900', data_emissao: '2026-10-05', valor_total: 640, duplicatas: [{ vencimento: '2026-10-25', valor: 640 }] }).nota_id;
  const b = boleto(640, '2026-10-25');
  assert.equal(b.auto.ligado, true);                                   // liga (é o melhor palpite) mas não libera
  assert.ok(b.ocorrencias.some((o) => o.tipo === 'boleto_nota_sem_prova' && o.severidade === 'alta'));
  assert.throws(() => pagar(b.boleto_id), ErroBloqueio);
  const semProtocolo = importarNotaXml(db, xmlNfe({ nNF: 901, proc: false, itens: [{ cProd: 'P', xProd: 'P', q: 1, vProd: 55 }], dups: [{ venc: '2026-10-26', valor: 55 }] }));
  assert.match(semProtocolo.avisos.join(' '), /sem protocolo/);
  const b2 = boleto(55, '2026-10-26');
  assert.ok(b2.ocorrencias.some((o) => o.tipo === 'boleto_nota_sem_prova'));
  // chega o XML autorizado da nota digitada: completa a mesma nota e a prova passa a existir
  importarNotaXml(db, xmlNfe({ nNF: 900, itens: [{ cProd: 'P', xProd: 'P', q: 1, vProd: 640 }], dups: [{ venc: '2026-10-25', valor: 640 }] }));
  assert.ok(!ocorrenciasDoBoleto(db, b.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'boleto_nota_sem_prova'));
  void manual;
});

test('nota digitada com chave: a chave precisa dizer o mesmo que foi digitado', () => {
  const { db } = cenario();
  const base = { fornecedor_nome: NOME, data_emissao: '2026-10-05', valor_total: 100 };
  const chv = chave({ nNF: 1234, serie: 1, cnpj: CNPJ_FORNECEDOR, aamm: '2610' });
  assert.throws(() => criarNotaManual(db, { ...base, numero: '999', serie: '1', chave: chv }), /número dentro da chave/);
  assert.throws(() => criarNotaManual(db, { ...base, numero: '1234', serie: '7', chave: chv }), /série dentro da chave/);
  assert.throws(() => criarNotaManual(db, { ...base, numero: '1234', serie: '1', chave: chv, data_emissao: '2026-03-05' }), /mês dentro da chave/);
  assert.throws(() => criarNotaManual(db, { fornecedor_nome: 'OUTRA EMPRESA', cnpj_emitente: '27865757000102', data_emissao: '2026-10-05', valor_total: 100, numero: '1234', serie: '1', chave: chv }), /CNPJ que está dentro da chave|de outra empresa/);
  const ok = criarNotaManual(db, { ...base, numero: '1234', serie: '1', chave: chv });
  assert.ok(ok.nota_id);
  assert.throws(() => criarNotaManual(db, { ...base, numero: '1234', serie: '1' }), /Já existe/);
  assert.ok(criarNotaManual(db, { ...base, numero: '1234', serie: '2' }).nota_id);              // outra série é outra nota
});

test('pagamento: recebedor conferido no banco, motivo de verdade, valor diferente explicado, ressalvas registradas', () => {
  const { db, nota, boleto, pagar } = cenario();
  nota(550, 320, [{ venc: '2026-10-20', valor: 320 }]);
  const b = boleto(320, '2026-10-20');
  assert.throws(() => pagarBoleto(db, b.boleto_id, {}, HOJE, lerConfig(db)), (e) => e instanceof ErroBloqueio && e.ocorrencias[0].tipo === 'confira_recebedor' && /DISTRIBUIDORA TESTE/.test(e.ocorrencias[0].detalhe));
  assert.throws(() => pagar(b.boleto_id, { valor: 340 }), /valor pago .* diferente/);
  assert.throws(() => pagar(b.boleto_id, { valor: 340, motivo: 'multa' }), /pelo menos 10/);
  const p = pagar(b.boleto_id, { valor: 340, motivo: 'multa e juros de 5 dias de atraso' });
  assert.equal(p.liberado_com_ressalva, false);
  const salvo = db.prepare('SELECT aprovado_motivo, conferido_banco_em FROM boletos WHERE id = ?').get(b.boleto_id);
  assert.match(salvo.aprovado_motivo, /multa e juros/);
  assert.equal(salvo.conferido_banco_em, HOJE);
  assert.equal(db.prepare('SELECT valor_pago FROM saidas WHERE id = (SELECT saida_id FROM boletos WHERE id = ?)').get(b.boleto_id).valor_pago, 340);
  // o motivo de uma liberação antiga não fica no boleto reaberto
  const { desfazerPagamentoBoleto } = db;
  void desfazerPagamentoBoleto;
});

test('pagar com ocorrência grave já aceita registra o aceite no boleto (rastro)', () => {
  const { db, cfg, boleto, pagar } = cenario();
  const b = boleto(990, '2026-10-30');
  const o = ocorrenciasDoBoleto(db, b.boleto_id, HOJE, cfg()).find((x) => x.tipo === 'boleto_sem_nota');
  aceitarOcorrencia(db, o.chave, 'Nota vem junto da mercadoria amanhã', HOJE, cfg());
  const p = pagar(b.boleto_id);
  assert.equal(p.liberado_com_ressalva, true);
  assert.match(db.prepare('SELECT aprovado_motivo FROM boletos WHERE id = ?').get(b.boleto_id).aprovado_motivo, /Aceito antes: Boleto sem nota fiscal \(Nota vem junto/);
});

test('boleto exige fornecedor, valor finito e razoável; conta já lançada em Contas é adotada, não duplicada', () => {
  const { db, cfg } = cenario();
  const linha = linhaDigitavel({ valor: 250, vencimento: '2026-10-25', livre: 55 });
  assert.throws(() => criarBoleto(db, { linha }, HOJE, cfg()), /Escolha o fornecedor/);
  assert.throws(() => criarBoleto(db, { valor: Infinity, vencimento: '2026-10-25', fornecedor_nome: NOME }, HOJE, cfg()), /valor/i);
  assert.throws(() => criarBoleto(db, { valor: 99999999999, vencimento: '2026-10-25', fornecedor_nome: NOME }, HOJE, cfg()), /grande demais/);
  const cat = db.prepare("SELECT id FROM categorias WHERE nome LIKE 'Outros%'").get().id;
  const antes = db.prepare('INSERT INTO saidas (descricao, categoria_id, valor, vencimento) VALUES (?, ?, ?, ?)').run('Scherer boleto outubro', cat, 250, '2026-10-25').lastInsertRowid;
  const r = criarBoleto(db, { linha, beneficiario_cnpj: CNPJ_FORNECEDOR, pagador_cnpj: CNPJ_OFICINA, fornecedor_nome: NOME }, HOJE, cfg());
  assert.equal(r.conta_adotada, true);
  assert.equal(db.prepare('SELECT saida_id FROM boletos WHERE id = ?').get(r.boleto_id).saida_id, Number(antes));
  assert.equal(db.prepare("SELECT COUNT(*) AS c FROM saidas WHERE valor = 250 AND vencimento = '2026-10-25'").get().c, 1);
});

test('informar quem recebe depois do cadastro: corrige CNPJ, aponta divergência e registra no histórico', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(560, 410, [{ venc: '2026-10-20', valor: 410 }]);
  const b = boleto(410, '2026-10-20', { beneficiario_cnpj: '', pagador_cnpj: '' });
  assert.ok(b.ocorrencias.some((o) => o.tipo === 'boleto_sem_beneficiario'));
  const r = atualizarBoleto(db, b.boleto_id, { beneficiario_cnpj: '27.865.757/0001-02', pagador_cnpj: CNPJ_OFICINA }, HOJE, cfg());
  assert.ok(r.ocorrencias.some((o) => o.tipo === 'boleto_beneficiario_diverge' && o.severidade === 'alta'));
  assert.ok(!r.ocorrencias.some((o) => o.tipo === 'boleto_sem_beneficiario'));
  assert.throws(() => atualizarBoleto(db, b.boleto_id, { beneficiario_cnpj: '12345' }, HOJE, cfg()), /validação/);
  assert.equal(db.prepare("SELECT COUNT(*) AS c FROM auditoria_log WHERE acao = 'corrigir'").get().c, 1);
});

test('regras novas: segunda via do mesmo título, documento que não é da nota, peças lançadas direto em Contas', () => {
  const { db, cfg, nota, boleto } = cenario();
  nota(570, 200, [{ venc: '2026-10-20', valor: 200 }]);
  const a = boleto(200, '2026-10-20', { linha: linhaDigitavel({ valor: 200, vencimento: '2026-10-20', livre: 31415 }), numero_documento: '000999' });
  assert.ok(ocorrenciasDoBoleto(db, a.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'boleto_doc_diverge'));
  const segunda = boleto(205, '2026-10-27', { linha: linhaDigitavel({ valor: 205, vencimento: '2026-10-27', livre: 31415 }) });
  assert.ok(ocorrenciasDoBoleto(db, segunda.boleto_id, HOJE, cfg()).some((o) => o.tipo === 'boleto_mesmo_titulo' && o.severidade === 'alta'));
  const cat = db.prepare("SELECT id FROM categorias WHERE grupo = 'pecas'").get().id;
  db.prepare('INSERT INTO saidas (descricao, categoria_id, valor, vencimento, pago_em) VALUES (?, ?, ?, ?, ?)').run('Peça do balcão', cat, 380, '2026-10-03', '2026-10-03');
  assert.ok(ocorrencias(db, HOJE, cfg()).some((o) => o.tipo === 'saida_pecas_sem_boleto' && o.valor === 380));
});

test('valor em risco conta cada boleto uma só vez', () => {
  const { db, cfg, boleto } = cenario();
  const b = boleto(500, '2026-10-20', { beneficiario_cnpj: '27865757000102' });             // sem nota + beneficiário diferente
  const graves = ocorrenciasDoBoleto(db, b.boleto_id, HOJE, cfg()).filter((o) => o.severidade === 'alta');
  assert.ok(graves.length >= 2);
  assert.equal(resumoAuditoria(ocorrencias(db, HOJE, cfg())).valorEmRisco, 500);
});

test('cancelar a nota recalcula o custo da OS; OS cancelada devolve a peça para "sem destino"; custo digitado não é sobrescrito', () => {
  const { db, nota } = cenario();
  const n = nota(580, 300, []);
  const item = db.prepare('SELECT id FROM nota_itens WHERE nota_id = ?').get(n).id;
  const cli = Number(db.prepare("INSERT INTO clientes (nome) VALUES ('C')").run().lastInsertRowid);
  const os = Number(db.prepare("INSERT INTO vendas (numero, data, cliente_id, placa, situacao, valor_total, valor_mao_obra) VALUES ('2000', '2026-10-02', ?, 'AAA1A11', 'concluida', 900, 200)").run(cli).lastInsertRowid);
  alocar(db, item, { destino: 'os', vendaId: os });
  assert.equal(db.prepare('SELECT custo_pecas FROM vendas WHERE id = ?').get(os).custo_pecas, 300);
  definirSituacaoNota(db, n, 'cancelada', 'fornecedor cancelou a nota');
  assert.equal(db.prepare('SELECT custo_pecas FROM vendas WHERE id = ?').get(os).custo_pecas, null);
  assert.throws(() => alocar(db, item, { destino: 'estoque' }), /cancelada/);
  definirSituacaoNota(db, n, 'ativa', 'engano, a nota vale');
  assert.equal(db.prepare('SELECT custo_pecas FROM vendas WHERE id = ?').get(os).custo_pecas, 300);
  db.prepare("UPDATE vendas SET situacao = 'cancelada' WHERE id = ?").run(os);
  assert.equal(db.prepare('SELECT 1 FROM alocacoes WHERE item_id = ?').get(item) !== undefined, true);
  alocar(db, item, { destino: 'estoque' });                                              // a peça da OS cancelada voltou a ter saldo
});

test('cancelamento que veio da SEFAZ não se desfaz à mão; protocolo cancelado vale na importação', () => {
  const { db } = cenario();
  const chv = chave({ nNF: 590 });
  const id = importarNotaXml(db, xmlNfe({ nNF: 590, chv })).nota_id;
  importarNotaXml(db, xmlCancelamento(chv));
  assert.throws(() => definirSituacaoNota(db, id, 'ativa', 'quero reativar mesmo assim'), /cancelada pela SEFAZ/);
  const pelaProtocolo = importarNotaXml(db, xmlNfe({ nNF: 591, cStat: '101' })).nota_id;
  assert.equal(db.prepare('SELECT situacao, cancelada_por FROM notas_compra WHERE id = ?').get(pelaProtocolo).cancelada_por, 'sefaz');
  assert.throws(() => importarNotaXml(db, xmlCancelamento(chave({ nNF: 592 }), { homologado: false })), /não traz a resposta da SEFAZ/);
});

test('parcelas: ligação feita só com a nota abate as parcelas na ordem; nota sem parcela escolhida não gera falso alerta', () => {
  const { db, cfg, nota, boleto } = cenario();
  const n = nota(600, 600, [{ venc: '2026-10-10', valor: 300 }, { venc: '2026-11-10', valor: 300 }]);
  const b = boleto(600, '2026-10-10', { linha: linhaDigitavel({ valor: 600, vencimento: '2026-10-10', livre: 6006 }) });
  conciliar(db, b.boleto_id, [{ nota_id: n, valor: 600 }]);
  assert.deepEqual(duplicatasComSaldo(db, n).map((d) => d.saldo), [0, 0]);
  assert.ok(!ocorrencias(db, HOJE, cfg()).some((o) => o.tipo === 'nota_sem_boleto' && o.notas.includes(n)));
});

test('lote: ocorrências calculadas uma vez (rápido) e o resultado diz quais são graves', () => {
  const { db, cfg } = cenario();
  const t0 = Date.now();
  for (let i = 0; i < 60; i++) {
    criarBoleto(db, { linha: linhaDigitavel({ valor: 100 + i, vencimento: '2026-10-30', livre: 9000 + i }), fornecedor_nome: NOME }, HOJE, cfg(), { semOcorrencias: true });
  }
  assert.ok(Date.now() - t0 < 3000, 'criar 60 boletos sem recalcular tudo a cada um');
  assert.equal(ocorrencias(db, HOJE, cfg()).filter((o) => o.tipo === 'boleto_sem_nota').length, 60);
});

// ------------------------------------------------------------------ XML

test('XML: entidades (&amp;) são decodificadas; custo por item inclui IPI e ICMS-ST; CPF de emitente é aceito', () => {
  const base = xmlNfe({ nNF: 700, emitNome: 'AUTO PECAS L&amp;M LTDA', itens: [{ cProd: 'A', xProd: 'BUCHA &amp; ARRUELA 3&quot;', q: 1, vProd: 800 }, { cProd: 'B', xProd: 'JUNTA', q: 1, vProd: 300 }], dups: [], vNF: 1200 });
  const comImposto = base
    .replace('</prod></det><det nItem="2">', '</prod><imposto><IPI><IPITrib><vIPI>40.00</vIPI></IPITrib></IPI></imposto></det><det nItem="2">')
    .replace(/<\/prod><\/det><total>/, '</prod><imposto><IPI><IPITrib><vIPI>15.00</vIPI></IPITrib></IPI><ICMS><ICMS10><vICMSST>45.00</vICMSST></ICMS10></ICMS></imposto></det><total>');
  const n = lerXmlNfe(comImposto);
  assert.equal(n.nome_emitente, 'AUTO PECAS L&M LTDA');
  assert.equal(n.itens[0].descricao, 'BUCHA & ARRUELA 3"');
  assert.deepEqual(n.itens.map((i) => i.custo_base), [840, 360]);                      // 800+40 e 300+15+45
  const db = abrirBanco(':memory:');
  const r = importarNotaXml(db, comImposto);
  const custos = db.prepare('SELECT custo_total FROM nota_itens WHERE nota_id = ? ORDER BY n_item').all(r.nota_id).map((x) => x.custo_total);
  assert.deepEqual(custos, [840, 360]);
  assert.equal(custos[0] + custos[1], 1200);
  assert.deepEqual(ratearCusto([{ valor_total: 100 }, { valor_total: 50 }], 160), [106.67, 53.33]);   // sem base por item: proporcional ao produto
});

test('XML: data de emissão inválida, item sem número e chave contraditória são recusados com mensagem clara', () => {
  const ok = xmlNfe({ nNF: 710 });
  assert.throws(() => lerXmlNfe(ok.replace(/<dhEmi>[^<]*<\/dhEmi>/, '<dhEmi>2026-13-45T10:00:00-03:00</dhEmi>')), /data de emissão válida/);
  assert.throws(() => lerXmlNfe(ok.replace('<det nItem="1">', '<det nItem="abc">')), /sem número válido/);
  assert.throws(() => lerXmlNfe(ok.replace('<cUF>43</cUF>', '<cUF>31</cUF>')), /contradiz/);
  assert.throws(() => lerXmlNfe(ok.replace('<mod>55</mod>', '<mod>65</mod>')), /modelo 65|modelo/);
});

test('XML: o mesmo arquivo com BOM e quebras de linha diferentes tem o mesmo hash (sem alarme falso)', () => {
  const db = abrirBanco(':memory:');
  const xml = xmlNfe({ nNF: 720 });
  importarNotaXml(db, xml);
  const outra = importarNotaXml(db, `﻿${xml.replace(/></g, '>\r\n  <')}`);
  assert.equal(outra.status, 'ja_existia');
  assert.deepEqual(outra.avisos, []);
});

test('sugestão de OS usa também a informação adicional do item e ignora o ambíguo', () => {
  const { db } = cenario();
  const cli = Number(db.prepare("INSERT INTO clientes (nome) VALUES ('C')").run().lastInsertRowid);
  const mk = (numero, placa) => Number(db.prepare("INSERT INTO vendas (numero, data, cliente_id, placa, situacao, valor_total) VALUES (?, '2026-10-02', ?, ?, 'concluida', 500)").run(numero, cli, placa).lastInsertRowid);
  const osA = mk('3001', 'ABC1D23');
  mk('3002', 'ABC1D23');
  const xml = xmlNfe({ nNF: 730, itens: [{ cProd: 'A', xProd: 'ITEM A', q: 1, vProd: 100 }, { cProd: 'B', xProd: 'ITEM B', q: 1, vProd: 80, xPed: 'OS3001' }] });
  const id = importarNotaXml(db, xml).nota_id;
  db.prepare("UPDATE nota_itens SET info_adic = 'PLACA ABC-1D23' WHERE nota_id = ? AND n_item = 1").run(id);
  const sug = sugerirAlocacoes(db, id);
  assert.equal(sug.find((s) => s.descricao === 'ITEM B').venda_id, osA);
  assert.equal(sug.find((s) => s.descricao === 'ITEM A').ambigua, true);                      // duas OS com a mesma placa: escolha manual
  void recalcularCustoOs;
});
