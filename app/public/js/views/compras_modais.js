// Janelas da área de Compras: boleto, nota, aplicar peça em OS, fornecedor, pagamento com trava.
import { h, brl, brl0, dataBR, selo, vazio, toast, modal, atualizarModal, acao, campo, entrada, selecao, lerForm, confirmar, montar, cnpjBR, numBR as num, paraCampo } from '../ui.js';
import { escolherItemLivre } from './vendas.js';
import { GET, POST, PUT, DEL, ehDono } from '../api.js';

const hojeISO = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const ROTULO_SEV = { alta: 'GRAVE', media: 'conferir', baixa: 'detalhe' };
const TIPO_SEV = { alta: 'critico', media: 'aviso', baixa: 'info' };
const DESTINOS = { estoque: 'Estoque', uso_interno: 'Uso interno', devolvido: 'Devolvida ao fornecedor', os: 'OS', outra_empresa: 'Outra empresa do grupo' };
const ROTULO_PERFIL = { dono: 'dono', lancamento: 'quem lança' };
const dataHoraBR = (s) => (s ? `${dataBR(s.slice(0, 10))} ${s.slice(11, 16)}` : '');
// Consulta resumida da NF-e no portal nacional (pede a chave e um captcha; mostra situação e valor)
const URL_PORTAL_NFE = 'https://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx?tipoConsulta=resumo&tipoConteudo=7PhJ+gAVw2g=';
const SIT_CONSULTA = [['autorizada', 'Autorizada (uso autorizado)'], ['cancelada', 'Cancelada'], ['denegada', 'Denegada'], ['nao_encontrada', 'NÃO encontrada']];
const MENSAGEM_PEDIR_XML = 'Olá! Aqui é da Multmec. A partir de agora, toda nota fiscal que vocês emitirem para nós precisa vir acompanhada do arquivo XML da NF-e, enviado pelo mesmo canal em que mandam o boleto. Também pedimos que, no pedido, conste o número da nossa OS ou a placa do veículo (no campo "Pedido" ou em "Informações adicionais" da nota), para conferirmos cada peça. Boleto que não bater com uma nota nossa será devolvido. Obrigado!';
const MENSAGEM_PADRAO = 'Cole a linha digitável (47 números), o código de barras (44) ou o texto inteiro do boleto copiado do PDF.';

export const chipSev = (sev) => selo(ROTULO_SEV[sev] ?? sev, TIPO_SEV[sev] ?? 'neutro');

/** Faixa grande com o veredito do boleto (não pague / falta conferir / conferido). */
export function faixaVeredito(v) {
  if (!v) return null;
  return h('div', { class: `veredito ${v.nivel}`, role: 'status' }, v.texto);
}

export function listaOcorrencias(lista, aoMudar, { mostrarAcoes = true } = {}) {
  if (!lista.length) return h('p', { class: 'bom' }, 'Nada a apontar.');
  return h('div', { class: 'lista' }, lista.map((o) => h('div', { class: `ocorrencia ${o.severidade}` },
    h('div', { class: 'ocorrencia-topo' }, chipSev(o.severidade), h('b', null, o.titulo), o.valor ? h('span', { class: 'valor' }, brl(o.valor)) : null),
    h('p', null, o.detalhe),
    o.aceite_vencido ? h('p', { class: 'aviso-texto' }, `Você já tinha aceito uma versão anterior (${o.aceite_vencido.motivo}), mas o fato mudou. Confira de novo.`) : null,
    mostrarAcoes ? h('div', { class: 'botoes' },
      o.tipo === 'os_sem_nota' ? h('button', { class: 'pequeno primario', onclick: acao(() => modalOsSemNota(aoMudar)) }, 'Ver as OS') : null,
      o.tipo === 'custo_os_diverge' ? h('button', { class: 'pequeno', onclick: acao(async () => { await POST(`/compras/os/${o.id}/usar-custo-das-notas`, {}); toast('Custo da OS agora vem das notas.'); aoMudar(); }) }, 'Usar o custo das notas') : null,
      (o.tipo === 'boleto_sem_beneficiario' || o.tipo === 'boleto_sem_pagador') && o.boletos?.[0] ? h('button', { class: 'pequeno primario', onclick: acao(() => modalBoleto(o.boletos[0], aoMudar, { informar: true })) }, 'Informar') : null,
      ehDono() ? h('button', { class: 'pequeno', onclick: () => modalAceitar(o, aoMudar) }, 'Conferi, está certo') : h('small', { class: 'dica' }, 'Só o dono aceita uma ocorrência.')) : null)));
}

export function modalAceitar(o, aoMudar) {
  modal('Conferi e está certo', (fechar) => {
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'ruim' }, o.titulo),
      h('p', null, o.detalhe),
      h('p', { class: 'dica' }, 'Fica registrado com o motivo e a data para uma auditoria futura. Se o fato mudar (outro valor, outro boleto), o aceite deixa de valer.'),
      campo('Por quê?', entrada('motivo', '', { required: true, minlength: 10, placeholder: 'ex.: pago no Pix dia 05/10, comprovante na pasta' })),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Registrar')));
    f.addEventListener('submit', acao(async (e) => {
      e.preventDefault();
      await POST('/compras/ocorrencias/aceitar', { chave: o.chave, motivo: lerForm(f).motivo });
      toast('Registrado.'); fechar(); aoMudar();
    }));
    return f;
  });
}

/**
 * Pagamento com trava. Chama `executar(extra)`; o servidor trava se (1) ninguém conferiu no app do banco quem recebe, ou
 * (2) há ocorrência grave. Aqui a pessoa confirma uma coisa ou outra, ou libera com motivo.
 */
export function pagarComTrava(executar, aoFim, opcoes = {}) {
  // boleto de outra empresa do grupo: primeiro se decide de quem sai o dinheiro
  if (opcoes.empresa) { perguntarQuemPaga(opcoes.empresa, (pagoPor) => tentarPagar(executar, aoFim, opcoes, { pagoPor })); return Promise.resolve(); }
  return tentarPagar(executar, aoFim, opcoes, {});
}

function perguntarQuemPaga(empresa, aoEscolher) {
  modal('Quem está pagando?', (fechar) => h('div', { class: 'formulario' },
    h('div', { class: 'veredito atencao' }, `Este boleto é da ${empresa}, não da oficina.`),
    h('button', { class: 'grande primario', onclick: () => { fechar(); aoEscolher('oficina'); } }, 'A oficina paga'),
    h('p', { class: 'dica' }, `O dinheiro sai da conta da oficina e o valor fica A RECEBER da ${empresa} (Compras > Grupo).`),
    h('button', { class: 'grande', onclick: () => { fechar(); aoEscolher('empresa'); } }, `A ${empresa} paga`),
    h('p', { class: 'dica' }, 'O dinheiro sai da conta dela. Aqui só fica registrado que foi pago; nada muda no caixa da oficina.')));
}

async function tentarPagar(executar, aoFim, { fornecedor = null }, base) {
  try { await executar(base); toast('Pago.'); aoFim(); } catch (e) {
    if (e.status !== 409) { toast(e.message, true); return; }
    const ocs = e.dados?.ocorrencias ?? [];
    const confira = ocs.find((o) => o.tipo === 'confira_recebedor');
    const graves = ocs.filter((o) => o.tipo !== 'confira_recebedor');
    modal(graves.length ? 'Pagamento travado' : 'Antes de pagar', (fechar) => {
      const conferi = h('input', { type: 'checkbox', name: 'conferiuBanco' });
      const motivo = entrada('motivo', '', { minlength: 10, placeholder: 'ex.: liguei no fornecedor e confirmou o boleto' });
      const botaoPagar = h('button', { type: 'submit', class: graves.length ? 'perigo' : 'primario' }, graves.length ? 'Pagar mesmo assim' : 'Pagar');
      const f = h('form', { class: 'formulario' },
        graves.length ? [h('p', { class: 'ruim' }, 'Há problemas sérios neste boleto:'), listaOcorrencias(graves, () => {}, { mostrarAcoes: false })] : null,
        confira ? h('div', { class: 'veredito atencao' }, confira.titulo, h('small', null, confira.detalhe)) : null,
        confira ? h('label', { class: 'marcar' }, conferi, ` Conferi no app do banco: quem recebe é ${fornecedor ?? 'o fornecedor'}`) : null,
        graves.length ? [h('p', { class: 'dica' }, 'Só pague se tiver certeza de que o boleto é da oficina. Ao liberar, o motivo fica gravado.'), campo('Motivo para pagar mesmo assim', motivo)] : null,
        h('div', { class: 'botoes' }, h('button', { type: 'button', onclick: fechar }, 'Não pagar agora'), botaoPagar));
      f.addEventListener('submit', acao(async (ev) => {
        ev.preventDefault();
        if (confira && !conferi.checked && !graves.length) { toast('Marque que conferiu o recebedor no app do banco.', true); return; }
        if (graves.length && String(motivo.value).trim().length < 10) { toast('Explique o motivo (pelo menos 10 letras).', true); return; }
        await executar({ ...base, conferiuBanco: conferi.checked, ...(graves.length ? { aprovar: true, motivo: motivo.value } : {}) });
        toast(graves.length ? 'Pago, com ressalva registrada.' : 'Pago.'); fechar(); aoFim();
      }));
      return f;
    });
  }
}

// ------------------------------------------------------------------ aplicar peça em OS

export function modalAplicarEmOs(item, aoMudar) {
  modal(`Aplicar: ${item.descricao}`, (fechar) => {
    const resultado = h('div', { class: 'lista' });
    const qtd = entrada('quantidade', paraCampo(item.restante ?? item.restante_qtd ?? item.quantidade), { inputmode: 'decimal' });
    const busca = h('input', { type: 'search', placeholder: 'Nº da OS, placa ou cliente…', autocomplete: 'off' });
    let t;
    const diasEntre = (a, b) => Math.abs(Math.round((Date.parse(a) - Date.parse(b)) / 86400000));
    const procurar = acao(async () => {
      const q = busca.value.trim();
      if (q.length < 2) { montar(resultado, h('p', { class: 'dica' }, 'Digite pelo menos 2 caracteres.')); return; }
      const lista = await GET(`/compras/os-busca?q=${encodeURIComponent(q)}`);
      montar(resultado, lista.length ? lista.map((v) => {
        const longe = item.dataNota && v.data ? diasEntre(item.dataNota, v.data) : 0;
        return h('button', { class: 'linha-os', onclick: acao(async () => {
          if (longe > 45 && !confirmar(`Esta OS é de ${dataBR(v.data)} e a nota é de ${dataBR(item.dataNota)} (${longe} dias de diferença). Aplicar mesmo assim?`)) return;
          const quantidade = num(qtd.value);
          if (quantidade !== null && Number.isNaN(quantidade)) { toast('Quantidade inválida.', true); return; }
          await POST(`/compras/itens/${item.id}/alocar`, { destino: 'os', vendaId: v.id, quantidade });
          toast(`Aplicada na OS ${v.numero}.`); fechar(); aoMudar();
        }) }, h('div', null, h('b', null, `OS ${v.numero ?? v.id} · ${v.cliente ?? 'sem cliente'}`)),
        h('div', { class: longe > 45 ? 'sub aviso-texto' : 'sub' }, `${dataBR(v.data)} · ${v.veiculo ?? ''} ${v.placa ?? ''}${longe > 45 ? ` · ${longe} dias da nota` : ''}`));
      }) : vazio('Nenhuma OS encontrada.'));
    });
    busca.addEventListener('input', () => { clearTimeout(t); t = setTimeout(procurar, 250); });
    return h('div', { class: 'formulario' },
      h('p', { class: 'dica' }, `Custo ainda sem destino: ${brl(item.restante_valor ?? item.valor ?? item.custo_total)}. Se a peça foi dividida entre carros, mude a quantidade.`),
      campo('Quantidade nesta OS', qtd), campo('Buscar a OS', busca), resultado);
  });
}

// ------------------------------------------------------------------ boleto

/** Formulário para informar quem recebe, quem paga e o documento impresso de um boleto em aberto. */
function formInformar(b, fornecedores, aoSalvar) {
  const sel = selecao('fornecedorId', fornecedores.filter((f) => f.ativo).map((f) => [f.id, f.nome]), b.fornecedor_id);
  const f = h('form', { class: 'formulario' },
    h('p', { class: 'dica' }, 'Digite como aparece no app do banco ao colar a linha digitável (ou no PDF). O sistema confere com o fornecedor e com a nota.'),
    campo('CNPJ de quem RECEBE (beneficiário)', entrada('beneficiarioCnpj', b.beneficiario_cnpj ? cnpjBR(b.beneficiario_cnpj) : '', { inputmode: 'text', placeholder: '00.000.000/0000-00' })),
    campo('CNPJ de quem PAGA (deve ser o da oficina)', entrada('pagadorCnpj', b.pagador_cnpj ? cnpjBR(b.pagador_cnpj) : '', { inputmode: 'text', placeholder: '00.000.000/0000-00' })),
    campo('Nº do documento impresso', entrada('numeroDocumento', b.numero_documento ?? '', { placeholder: 'ex.: 004321/01' })),
    b.fornecedor_id ? campo('Fornecedor', sel, 'Só dá para trocar se o boleto não estiver ligado a nenhuma nota.') : null,
    h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar e conferir de novo')));
  f.addEventListener('submit', acao(async (e) => {
    e.preventDefault();
    const d = lerForm(f);
    const corpo = { beneficiarioCnpj: d.beneficiarioCnpj, pagadorCnpj: d.pagadorCnpj, numeroDocumento: d.numeroDocumento };
    if (b.fornecedor_id && Number(d.fornecedorId) !== b.fornecedor_id) corpo.fornecedorId = Number(d.fornecedorId);
    await PUT(`/compras/boletos/${b.id}`, corpo);
    toast('Salvo.'); aoSalvar();
  }));
  return f;
}

export async function modalBoleto(id, aoMudar, { informar = false } = {}) {
  const fornecedores = await GET('/compras/fornecedores');
  let mostrarInformar = informar;
  const montarCorpo = async (fechar, corpo) => {
    const d = await GET(`/compras/boletos/${id}`);
    const b = d.boleto;
    const dono = ehDono();
    const atualizar = acao(async () => { await montarCorpo(fechar, corpo); aoMudar(); });
    const conteudo = h('div', null,
      faixaVeredito(d.veredito),
      h('dl', { class: 'detalhe' },
        h('dt', null, 'Fornecedor'), h('dd', null, b.fornecedor ?? '—'),
        h('dt', null, 'Valor'), h('dd', null, brl(b.valor)), h('dt', null, 'Vencimento'), h('dd', null, dataBR(b.vencimento)),
        h('dt', null, 'Situação'), h('dd', null, b.situacao), h('dt', null, 'Nº do documento'), h('dd', null, b.numero_documento ?? '—'),
        h('dt', null, 'Recebe (CNPJ)'), h('dd', null, b.beneficiario_cnpj ? cnpjBR(b.beneficiario_cnpj) : 'não informado'),
        h('dt', null, 'Paga (CNPJ)'), h('dd', null, b.pagador_cnpj ? cnpjBR(b.pagador_cnpj) : 'não informado'),
        b.empresa_nome ? [h('dt', null, 'Boleto de'), h('dd', null, `${b.empresa_nome} (fora das contas da oficina)`)] : null,
        d.acerto ? [h('dt', null, 'Acerto'), h('dd', { class: d.acerto.saldo > 0.04 ? 'aviso-texto' : '' }, d.acerto.saldo > 0.04 ? `a oficina pagou; ${d.acerto.empresa} ainda deve ${brl(d.acerto.saldo)}` : `a oficina pagou; ${d.acerto.empresa} já devolveu tudo`)] : null,
        b.criado_por ? [h('dt', null, 'Cadastrado por'), h('dd', null, ROTULO_PERFIL[b.criado_por] ?? b.criado_por)] : null,
        h('dt', null, 'Banco'), h('dd', null, b.banco_nome ?? b.banco ?? '—'),
        b.conferido_banco_em ? [h('dt', null, 'Recebedor conferido no banco'), h('dd', null, dataBR(b.conferido_banco_em))] : null),
      b.linha_digitavel ? h('p', { class: 'dica mono' }, b.linha_digitavel) : null,
      b.aprovado_motivo ? h('p', { class: 'aviso-texto' }, `Liberado com ressalva: ${b.aprovado_motivo}`) : null,
      b.situacao === 'aberto' ? h('div', { class: 'botoes' }, h('button', { class: 'pequeno', onclick: () => { mostrarInformar = !mostrarInformar; montarCorpo(fechar, corpo); } }, mostrarInformar ? 'Fechar' : 'Informar quem recebe / paga')) : null,
      mostrarInformar && b.situacao === 'aberto' ? formInformar(b, fornecedores, () => { mostrarInformar = false; atualizar({ currentTarget: null }); }) : null,
      d.rastro?.length ? [h('h3', null, 'Para onde foi o dinheiro deste boleto'),
        d.rastro.map((r) => h('div', { class: 'item-nota' },
          h('div', { class: 'item-nota-topo' }, h('b', null, `Nota ${r.nota}`), h('span', null, `este boleto paga ${brl(r.pago_por_este_boleto)} de ${brl(r.nota_total)}`)),
          r.destinos.length ? r.destinos.map((x) => h('div', { class: 'aloc' }, h('span', null, `${x.item} → ${x.destino === 'os' ? `OS ${x.os ?? ''} ${x.placa ?? ''}` : (x.destino === 'outra_empresa' ? `${x.empresa ?? 'outra empresa'}` : DESTINOS[x.destino])}`), h('b', null, brl(x.valor)))) : h('p', { class: 'dica' }, 'Nenhuma peça desta nota foi ligada a uma OS ainda.'),
          r.sem_destino > 0.04 ? h('p', { class: 'ruim' }, `Sem destino: ${brl(r.sem_destino)}`) : null))] : null,
      h('h3', null, 'Notas ligadas'),
      d.conciliacoes.length ? d.conciliacoes.map((c) => h('div', { class: 'linha-simples' },
        h('div', null, h('b', null, `Nota ${c.nota_numero} · ${c.fornecedor}`), h('small', null, `${dataBR(c.data_emissao)} · nota de ${brl(c.nota_total)} · cobre ${brl(c.valor)}`)),
        b.situacao !== 'pago' ? h('button', { class: 'pequeno', onclick: acao(async () => { await DEL(`/compras/boletos/${id}/conciliacoes/${c.nota_id}`); await atualizar({ currentTarget: null }); }) }, 'Desligar') : null)) : h('p', { class: 'ruim' }, 'Nenhuma nota ligada a este boleto.'),
      d.sugestoes.length ? [h('h3', null, 'Notas que podem ser deste boleto'), d.sugestoes.map((s) => h('div', { class: 'linha-simples' },
        h('div', null, h('b', null, s.itens.map((i) => `Nota ${i.numero}`).join(' + ')), h('small', null, `${s.motivo} · confiança ${s.score}%`)),
        h('button', { class: 'pequeno primario', onclick: acao(async () => { await POST(`/compras/boletos/${id}/conciliar`, { itens: s.itens }); toast('Ligado.'); await atualizar({ currentTarget: null }); }) }, 'Ligar')))] : null,
      b.situacao === 'aberto' || b.situacao === 'pago' ? h('div', { class: 'botoes' }, h('button', { onclick: acao(() => modalEscolherNota(b, () => atualizar({ currentTarget: null }))) }, 'Ligar a outra nota…')) : null,
      h('h3', null, 'Conferência'),
      listaOcorrencias(d.ocorrencias.filter((o) => !o.aceita), () => atualizar({ currentTarget: null })),
      h('div', { class: 'botoes' },
        dono && b.situacao === 'aberto' ? h('button', { class: 'primario', onclick: acao(() => pagarComTrava((extra) => POST(`/compras/boletos/${id}/pagar`, extra), () => atualizar({ currentTarget: null }), { fornecedor: b.fornecedor, empresa: b.empresa_nome })) }, 'Marcar como pago') : null,
        dono && b.situacao === 'aberto' ? h('button', { onclick: () => modalMotivo('Contestar o boleto', 'Por que está contestando? (fica anotado no boleto)', 'ex.: cobrança sem nota fiscal', async (motivo) => { await POST(`/compras/boletos/${id}/cancelar`, { situacao: 'contestado', motivo }); toast('Boleto marcado como contestado. Não pague.'); atualizar({ currentTarget: null }); }) }, 'Contestar') : null,
        dono && (b.situacao === 'contestado' || b.situacao === 'cancelado') ? h('button', { class: 'primario', onclick: acao(async () => { await POST(`/compras/boletos/${id}/reabrir`, {}); toast('Boleto reaberto.'); await atualizar({ currentTarget: null }); }) }, 'Reabrir boleto') : null,
        dono && b.situacao === 'pago' ? h('button', { onclick: acao(async () => { if (!confirmar('Desfazer o pagamento deste boleto? Ele volta a ficar em aberto.')) return; await POST(`/compras/boletos/${id}/desfazer-pagamento`, {}); toast('Pagamento desfeito.'); await atualizar({ currentTarget: null }); }) }, 'Desfazer pagamento') : null,
        b.situacao === 'aberto' && (dono || b.criado_por === 'lancamento') ? h('button', { class: 'perigo', onclick: acao(async () => { if (confirmar('Cancelar este boleto? A conta a pagar ligada também sai.')) { await POST(`/compras/boletos/${id}/cancelar`, { situacao: 'cancelado' }); toast('Boleto cancelado.'); fechar(); aoMudar(); } }) }, 'Cancelar boleto') : null),
      !dono && b.situacao === 'aberto' ? h('p', { class: 'dica' }, 'Quem paga é o dono. Cadastre a nota, informe quem recebe e quem paga, e avise o dono quando estiver conferido.') : null,
      d.historico?.length ? [h('h3', null, 'Histórico'), h('div', { class: 'historico' }, d.historico.map((x) => h('div', null, `${dataHoraBR(x.em)} · ${x.acao}${x.perfil ? ` (${ROTULO_PERFIL[x.perfil] ?? x.perfil})` : ''}${x.detalhe ? ` · ${x.detalhe.slice(0, 120)}` : ''}`)))] : null);
    atualizarModal(fechar, conteudo);
  };
  const fechar = modal('Boleto', () => h('p', { class: 'vazio' }, 'Carregando…'));
  try { await montarCorpo(fechar, null); } catch (e) { fechar(); throw e; }
}

/** Pergunta um motivo escrito (sem texto pré-preenchido) e executa. */
function modalMotivo(titulo, pergunta, exemplo, executar) {
  modal(titulo, (fechar) => {
    const f = h('form', { class: 'formulario' },
      campo(pergunta, entrada('motivo', '', { required: true, minlength: 5, placeholder: exemplo })),
      h('div', { class: 'botoes' }, h('button', { type: 'button', onclick: fechar }, 'Voltar'), h('button', { type: 'submit', class: 'primario' }, 'Confirmar')));
    f.addEventListener('submit', acao(async (e) => { e.preventDefault(); await executar(lerForm(f).motivo); fechar(); }));
    return f;
  });
}

async function modalEscolherNota(b, aoMudar) {
  const notas = await GET(`/compras/notas${b.fornecedor_id ? `?fornecedor_id=${b.fornecedor_id}` : ''}`);
  modal('Ligar a qual nota?', (fechar) => h('div', { class: 'lista' }, notas.filter((n) => n.situacao === 'ativa' && n.saldo > 0.04).slice(0, 40).map((n) => h('button', { class: 'linha-os', onclick: () => {
    const sugerido = Math.min(b.valor, n.saldo);
    modal(`Nota ${n.numero}`, (fechar2) => {
      const valor = entrada('valor', paraCampo(sugerido), { inputmode: 'decimal' });
      const f = h('form', { class: 'formulario' },
        h('p', null, `Boleto de ${brl(b.valor)} e nota de ${brl(n.valor_total)} (sem boleto: ${brl(n.saldo)}).`),
        campo('Quanto deste boleto paga esta nota?', valor),
        h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Ligar')));
      f.addEventListener('submit', acao(async (e) => {
        e.preventDefault();
        const v = num(valor.value);
        if (!(v > 0)) { toast('Informe um valor maior que zero.', true); return; }
        await POST(`/compras/boletos/${b.id}/conciliar`, { itens: [{ nota_id: n.id, valor: v }] });
        toast('Ligado.'); fechar2(); fechar(); aoMudar();
      }));
      return f;
    });
  } }, h('div', null, h('b', null, `Nota ${n.numero} · ${n.fornecedor}`)), h('div', { class: 'sub' }, `${dataBR(n.data_emissao)} · ${brl(n.valor_total)} · sem boleto ${brl(n.saldo)}`))).concat(notas.length ? [] : [vazio('Nenhuma nota para escolher.')])));
}

export async function modalNovoBoleto(aoMudar, inicial = {}) {
  const forn = await GET('/compras/fornecedores');
  const cfg = { cnpjOficina: (await GET('/compras/resumo')).cnpjOficinaConfigurado };
  modal('Novo boleto', (fechar) => {
    const leitura = h('div', { class: 'resumo-os' }, MENSAGEM_PADRAO);
    const linha = h('textarea', { name: 'linha', rows: 2, placeholder: '34191.79001 01043.510047 91020.150008 5 87560026000', inputmode: 'numeric', autocomplete: 'off' });
    const valor = entrada('valor', '', { inputmode: 'decimal' });
    const venc = entrada('vencimento', '', { type: 'date' });
    let t;
    const ler = async () => {
      const texto = linha.value.replace(/\D/g, '');
      if (texto.length < 44) { montar(leitura, MENSAGEM_PADRAO); valor.readOnly = false; venc.readOnly = false; return; }
      try {
        const r = await POST('/compras/boletos/ler', { linha: linha.value });
        if (r.texto && r.extras) {
          // texto colado do PDF: guarda só a linha limpa e preenche o que o texto trouxe (a pessoa confere)
          if (r.ok) { linha.value = r.linhaDigitavel || r.codigoBarras || linha.value; }
          if (r.extras.numeroDocumento && !docCampo.value) docCampo.value = r.extras.numeroDocumento;
          if (r.extras.beneficiarioCnpj && !benefCampo.value) benefCampo.value = cnpjBR(r.extras.beneficiarioCnpj);
          if (r.extras.pagadorCnpj && !pagCampo.value) pagCampo.value = cnpjBR(r.extras.pagadorCnpj);
          r.avisos = [...(r.avisos || []), ...(r.extras.avisos || []), ...(r.ok ? ['Li o texto colado. Confira o beneficiário e o pagador abaixo com o boleto em mãos antes de cadastrar.'] : [])];
        }
        montar(leitura,
          r.ok ? h('div', null, h('b', { class: 'bom' }, `Dígitos conferem · ${r.bancoNome ?? `banco ${r.banco ?? ''}`} · ${r.valor ? brl(r.valor) : 'sem valor'}${r.vencimento ? ` · vence ${dataBR(r.vencimento)}` : ''}`),
            h('small', { class: 'dica' }, ' Isso só mostra que a linha foi digitada certa. Não prova que o boleto é verdadeiro: a prova é a nota fiscal e o recebedor.')) : null,
          r.erros.map((m) => h('div', { class: 'ruim' }, m)), r.avisos.filter((m) => !r.erros.includes(m)).map((m) => h('div', { class: 'aviso-texto' }, m)));
        if (r.ok && r.valor > 0) { valor.value = paraCampo(r.valor); valor.readOnly = true; } else valor.readOnly = false;
        if (r.ok && r.vencimento) { venc.value = r.vencimento; venc.readOnly = true; } else venc.readOnly = false;
      } catch (e) { montar(leitura, h('div', { class: 'ruim' }, e.message)); }
    };
    linha.addEventListener('input', () => { clearTimeout(t); t = setTimeout(ler, 250); });
    const docCampo = entrada('numeroDocumento', '', { placeholder: 'ex.: 004321/01' });
    const benefCampo = entrada('beneficiarioCnpj', '', { inputmode: 'text', placeholder: '00.000.000/0000-00' });
    const pagCampo = entrada('pagadorCnpj', '', { inputmode: 'text', placeholder: 'o que está impresso como pagador' });
    const novoNome = entrada('fornecedorNome', '', { placeholder: 'nome do novo fornecedor' });
    const doCnpj = inicial.beneficiarioCnpj ? forn.find((f) => f.cnpj === inicial.beneficiarioCnpj) : null;
    const sel = selecao('fornecedorId', [['', 'Escolha o fornecedor'], ...forn.filter((f) => f.ativo).map((f) => [f.id, `${f.nome}${f.principal ? ' (principal)' : ''}`]), ['novo', '+ Outro fornecedor…']], doCnpj?.id ?? (inicial.beneficiarioNome && !doCnpj ? 'novo' : ''));
    if (inicial.beneficiarioNome && !doCnpj) novoNome.value = inicial.beneficiarioNome;
    const campoNovo = campo('Nome do novo fornecedor', novoNome);
    campoNovo.hidden = sel.value !== 'novo';
    sel.addEventListener('change', () => { campoNovo.hidden = sel.value !== 'novo'; });
    const f = h('form', { class: 'formulario' },
      inicial.linha ? h('div', { class: 'dica' }, 'Dados trazidos do DDA do banco. Escolha o fornecedor e cadastre; depois ligue à nota fiscal.') : null,
      campo('Linha digitável, código de barras ou texto do boleto', linha), leitura,
      campo('Fornecedor', sel), campoNovo,
      h('div', { class: 'duas' }, campo('Valor', valor), campo('Vencimento', venc)),
      campo('CNPJ do beneficiário (quem RECEBE) — recomendado', benefCampo, 'É o campo que pega o boleto "de outra pessoa". Leia o nome e o CNPJ de quem recebe no app do banco ou no PDF.'),
      cfg.cnpjOficina ? campo('CNPJ do pagador (quem PAGA) — recomendado', pagCampo, 'Deve ser o da oficina. Boleto de outro cliente do fornecedor tem outro CNPJ aqui.') : null,
      campo('Nº do documento (se estiver escrito no boleto)', docCampo, 'às vezes é o número da nota'),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Cadastrar e conferir')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const corpo = {
        linha: d.linha, valor: d.valor ? num(d.valor) : null, vencimento: d.vencimento || null, numeroDocumento: d.numeroDocumento,
        beneficiarioCnpj: d.beneficiarioCnpj, pagadorCnpj: d.pagadorCnpj,
        fornecedorId: d.fornecedorId && d.fornecedorId !== 'novo' ? Number(d.fornecedorId) : null, fornecedorNome: d.fornecedorId === 'novo' ? d.fornecedorNome : null,
      };
      try {
        const r = await POST('/compras/boletos', corpo);
        fechar(); aoMudar();
        resultadoBoleto(r, aoMudar);
      } catch (err) {
        if (err.dados?.boleto_id) { toast(err.message, true); fechar(); modalBoleto(err.dados.boleto_id, aoMudar); } else toast(err.message, true);
      }
    });
    if (inicial.linha) { linha.value = inicial.linha; ler(); }
    if (inicial.beneficiarioCnpj) benefCampo.value = cnpjBR(inicial.beneficiarioCnpj);
    if (inicial.pagadorCnpj) pagCampo.value = cnpjBR(inicial.pagadorCnpj);
    if (inicial.numeroDocumento) docCampo.value = inicial.numeroDocumento;
    return f;
  });
}

function resultadoBoleto(r, aoMudar) {
  modal('Resultado da conferência', (fechar) => {
    const graves = r.ocorrencias.filter((o) => o.severidade === 'alta');
    return h('div', null,
      r.conta_adotada ? h('p', { class: 'dica' }, 'Já havia uma conta com este valor e vencimento em Contas: ela foi ligada ao boleto (não ficou duplicada).') : null,
      r.auto.ligado
        ? h('p', { class: 'bom' }, `Ligado automaticamente à ${r.auto.sugestao.itens.map((i) => `nota ${i.numero}`).join(' + ')} (${r.auto.sugestao.motivo}).`)
        : h('p', { class: 'ruim' }, r.auto.recusado ? `Não liguei: ${r.auto.recusado}` : (r.auto.sugestoes?.length ? 'Nenhuma nota bate com segurança. Veja as sugestões no boleto.' : 'Não achei nenhuma nota que explique este boleto.')),
      graves.length ? h('p', { class: 'ruim' }, 'Não pague antes de resolver:') : null,
      listaOcorrencias(r.ocorrencias, () => {}, { mostrarAcoes: false }),
      h('div', { class: 'botoes' }, h('button', { class: 'primario', onclick: () => { fechar(); modalBoleto(r.boleto_id, aoMudar); } }, 'Abrir o boleto')));
  });
}

// ------------------------------------------------------------------ nota

export async function modalNota(id, aoMudar) {
  const montarCorpo = async (fechar) => {
    const d = await GET(`/compras/notas/${id}`);
    const n = d.nota;
    const recarregar = () => { montarCorpo(fechar).then(aoMudar).catch((e) => toast(e.message, true)); };
    const comProva = n.origem === 'xml' && ['100', '150'].includes(n.protocolo_status);
    const provaPorConsulta = !comProva && n.consulta_situacao === 'autorizada' && n.consulta_por === 'dono' && Math.abs((n.consulta_valor ?? 0) - n.valor_total) <= 0.05;
    atualizarModal(fechar, h('div', null,
      h('dl', { class: 'detalhe' },
        h('dt', null, 'Emissão'), h('dd', null, dataBR(n.data_emissao)), h('dt', null, 'Valor da nota'), h('dd', null, brl(n.valor_total)),
        n.valor_com_tributos ? [h('dt', null, 'Com tributos por fora'), h('dd', null, brl(n.valor_com_tributos))] : null,
        h('dt', null, 'Ligado a boletos'), h('dd', null, brl(n.conciliado)), h('dt', null, 'Falta boleto'), h('dd', { class: n.saldo > 0.05 ? 'ruim' : '' }, brl(n.saldo)),
        h('dt', null, 'Destinatário'), h('dd', null, n.cnpj_destinatario ? cnpjBR(n.cnpj_destinatario) : '—'),
        n.empresa ? [h('dt', null, 'Em nome de'), h('dd', null, `${n.empresa} (outra empresa do grupo)`)] : null,
        h('dt', null, 'Situação'), h('dd', null, n.situacao),
        h('dt', null, 'Prova'), h('dd', { class: comProva || provaPorConsulta ? '' : 'aviso-texto' }, comProva ? 'XML com protocolo de autorização (confirme no portal se for valor alto)' : (provaPorConsulta ? `consulta no portal feita pelo dono em ${dataBR(n.consulta_em)}: autorizada, ${brl(n.consulta_valor)}` : (n.origem === 'manual' ? 'digitada à mão: ainda não prova nada' : 'XML sem protocolo de autorização'))),
        n.natureza ? [h('dt', null, 'Natureza'), h('dd', null, n.natureza)] : null),
      n.chave ? h('p', { class: 'dica mono' }, `Chave: ${n.chave}`) : h('p', { class: 'aviso-texto' }, 'Nota digitada sem chave de acesso.'),
      comProva ? null : blocoConsulta(n, id, recarregar),
      d.nota.tem_xml ? h('p', null, h('a', { class: 'botao', href: `/api/compras/notas/${id}/xml`, download: '' }, 'Baixar o XML guardado')) : null,
      n.info_compl ? h('p', { class: 'dica' }, `Informações da nota: ${n.info_compl}`) : null,
      d.duplicatas.length ? [h('h3', null, 'Parcelas'), h('div', { class: 'rolagem' }, h('table', { class: 'tabela' }, h('thead', null, h('tr', null, ['Vence', 'Valor', 'Sem boleto'].map((c) => h('th', null, c)))),
        h('tbody', null, d.duplicatas.map((p) => h('tr', null, h('td', null, dataBR(p.vencimento)), h('td', { class: 'num' }, brl(p.valor)), h('td', { class: `num ${p.saldo > 0.04 ? 'ruim' : 'bom'}` }, p.saldo > 0.04 ? brl(p.saldo) : 'ok')))))) ] : null,
      d.conciliacoes.length ? [h('h3', null, 'Boletos ligados'), d.conciliacoes.map((c) => h('button', { class: 'linha-simples', onclick: acao(() => modalBoleto(c.boleto_id, aoMudar)) },
        h('span', null, `Boleto de ${brl(c.boleto_valor)} · vence ${dataBR(c.vencimento)}`), h('small', null, c.situacao)))] : null,
      h('h3', null, 'Peças da nota'),
      d.sugestoes_os.length ? h('div', { class: 'resumo-os' },
        h('b', null, `${d.sugestoes_os.length} peça(s) com OS sugerida: `),
        d.sugestoes_os.map((s) => h('div', null, `${s.descricao} → OS ${s.os} (${s.motivo}${s.ambigua ? '; mais de uma OS possível: escolha à mão' : ''})`)),
        d.sugestoes_os.some((s) => !s.ambigua) ? h('div', { class: 'botoes' }, h('button', { class: 'pequeno primario', onclick: acao(async () => { await POST(`/compras/notas/${id}/aplicar-sugestoes`, { itens: d.sugestoes_os.filter((s) => !s.ambigua).map((s) => ({ item_id: s.item_id, venda_id: s.venda_id })) }); toast('Sugestões aplicadas.'); recarregar(); }) }, 'Aplicar sugestões')) : null) : null,
      d.itens.map((i) => h('div', { class: 'item-nota' },
        h('div', { class: 'item-nota-topo' }, h('b', null, i.descricao), h('span', null, `${i.quantidade} × · ${brl(i.custo_total)}`)),
        i.alocacoes.map((a) => h('div', { class: 'aloc' }, h('span', null, a.destino === 'os' ? `OS ${a.os ?? ''} ${a.placa ?? ''} · ${a.quantidade} · ${brl(a.valor)}` : `${a.destino === 'outra_empresa' ? (a.empresa ?? 'Outra empresa') : DESTINOS[a.destino]} · ${a.quantidade} · ${brl(a.valor)}`),
          h('button', { class: 'icone pequeno', 'aria-label': 'Remover destino', onclick: acao(async () => { await DEL(`/compras/alocacoes/${a.id}`); toast('Destino removido.'); recarregar(); }) }, '×'))),
        i.restante > 1e-6 ? h('div', { class: 'botoes' },
          h('span', { class: 'ruim' }, `Sem destino: ${i.restante} (${brl(i.restante_valor)})`),
          h('button', { class: 'pequeno primario', onclick: () => modalAplicarEmOs({ ...i, dataNota: n.data_emissao }, recarregar) }, 'Aplicar em OS…'),
          ['estoque', 'uso_interno', 'devolvido'].map((dst) => h('button', { class: 'pequeno', onclick: acao(async () => { await POST(`/compras/itens/${i.id}/alocar`, { destino: dst }); toast(`Destino: ${DESTINOS[dst]} (${brl(i.restante_valor)}).`); recarregar(); }) }, DESTINOS[dst])),
          h('button', { class: 'pequeno', onclick: acao(() => escolherEmpresaDestino(i, n, recarregar)) }, 'Outra empresa…')) : null)),
      d.itens.some((i) => i.restante > 1e-6) ? h('div', { class: 'botoes' }, h('button', { onclick: () => modalNotaTodaNaOs(id, recarregar) }, 'Toda a nota em uma OS…')) : null,
      h('h3', null, 'Conferência'),
      listaOcorrencias(d.ocorrencias.filter((o) => !o.aceita), recarregar),
      ehDono() ? h('div', { class: 'botoes' },
        n.cancelada_por !== 'sefaz' ? h('button', { class: 'pequeno', onclick: () => modalMotivo(n.situacao === 'ativa' ? 'Marcar nota como cancelada' : 'Reativar nota', 'Por quê?', 'ex.: fornecedor avisou que cancelou', async (motivo) => { await PUT(`/compras/notas/${id}`, { situacao: n.situacao === 'ativa' ? 'cancelada' : 'ativa', obs: motivo }); toast('Registrado.'); recarregar(); }) }, n.situacao === 'ativa' ? 'Marcar como cancelada' : 'Reativar nota') : h('span', { class: 'dica' }, 'Cancelada pela SEFAZ'),
        h('button', { class: 'pequeno perigo', onclick: acao(async () => { if (confirmar('Apagar esta nota? Fica registrado no histórico.')) { await DEL(`/compras/notas/${id}`); toast('Nota apagada.'); fechar(); aoMudar(); } }) }, 'Apagar')) : null));
  };
  const fechar = modal('Nota fiscal', () => h('p', { class: 'vazio' }, 'Carregando…'));
  try { await montarCorpo(fechar); } catch (e) { fechar(); throw e; }
}

/**
 * Nota sem XML: a prova é consultar a chave no portal da NF-e e registrar o que ele mostrou.
 * Quem lança consulta e registra; só a consulta do DONO (o mesmo que paga) vale como prova.
 */
function blocoConsulta(n, id, recarregar) {
  if (!n.chave) return formChave(n, id, recarregar);
  const dono = ehDono();
  const sit = n.consulta_situacao;
  const confere = sit === 'autorizada' && Math.abs((n.consulta_valor ?? 0) - n.valor_total) <= 0.05;
  return h('div', { class: 'resumo-os' },
    h('b', null, 'Comprovar esta nota sem XML'),
    h('ol', { class: 'passos' },
      h('li', null, 'Abra o portal da NF-e, cole a chave e resolva o captcha.'),
      h('li', null, 'Leia a situação e o "Valor total da nota" que o portal mostra.'),
      h('li', null, 'Registre abaixo. Se for diferente do que foi digitado, a nota não vale.')),
    h('div', { class: 'botoes' },
      h('a', { class: 'botao', href: URL_PORTAL_NFE, target: '_blank', rel: 'noopener noreferrer' }, 'Abrir o portal da NF-e'),
      h('button', { type: 'button', onclick: acao(async () => { await navigator.clipboard.writeText(n.chave); toast('Chave copiada.'); }) }, 'Copiar a chave')),
    sit ? h('p', { class: confere && n.consulta_por === 'dono' ? 'bom' : 'aviso-texto' },
      `Última consulta: ${dataBR(n.consulta_em)}, por ${ROTULO_PERFIL[n.consulta_por] ?? n.consulta_por}: ${SIT_CONSULTA.find(([v]) => v === sit)?.[1] ?? sit}${n.consulta_valor ? `, ${brl(n.consulta_valor)}` : ''}.${sit === 'autorizada' && !confere ? ' O valor NÃO bate com a nota.' : ''}${confere && n.consulta_por !== 'dono' ? ' Falta o dono repetir a consulta.' : ''}`) : null,
    formConsulta(n, id, recarregar),
    dono ? null : h('p', { class: 'dica' }, 'A consulta de quem lança ajuda, mas só vale como prova depois que o dono repetir e registrar a dele.'));
}

function formConsulta(n, id, recarregar) {
  const f = h('form', { class: 'formulario' },
    h('div', { class: 'duas' }, campo('O que o portal mostrou', selecao('situacao', SIT_CONSULTA, n.consulta_situacao ?? 'autorizada')),
      campo('Valor total da nota no portal', entrada('valor', n.consulta_valor ? paraCampo(n.consulta_valor) : '', { inputmode: 'decimal', placeholder: 'ex.: 1.050,00' }))),
    h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Registrar a consulta')));
  f.addEventListener('submit', acao(async (e) => {
    e.preventDefault();
    const d = lerForm(f);
    const valor = num(d.valor);
    if (d.situacao === 'autorizada' && !(valor > 0)) { toast('Informe o valor total que o portal mostrou.', true); return; }
    const r = await POST(`/compras/notas/${id}/consulta`, { situacao: d.situacao, valor: d.situacao === 'autorizada' ? valor : null });
    toast(r.confere ? 'Consulta registrada: o valor bate com a nota.' : (d.situacao === 'autorizada' ? 'Registrado, mas o valor NÃO bate com a nota.' : 'Consulta registrada.'), d.situacao !== 'autorizada' || !r.confere);
    recarregar();
  }));
  return f;
}

function formChave(n, id, recarregar) {
  const f = h('form', { class: 'formulario' },
    h('p', { class: 'aviso-texto' }, 'Esta nota foi digitada sem a chave de acesso. Sem a chave não dá para consultar o portal. A chave tem 44 números e fica na DANFE, embaixo do código de barras.'),
    campo('Chave de acesso (44 números)', entrada('chave', '', { inputmode: 'numeric', required: true })),
    h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Informar a chave')));
  f.addEventListener('submit', acao(async (e) => { e.preventDefault(); await POST(`/compras/notas/${id}/chave`, { chave: lerForm(f).chave }); toast('Chave conferida e salva.'); recarregar(); }));
  void n;
  return f;
}

/** Peça que ficou com outra empresa do grupo (locadora, oficina do sócio): escolhe a empresa. Se a nota é da oficina, o custo fica a receber dela. */
async function escolherEmpresaDestino(item, nota, aoMudar) {
  const empresas = (await GET('/compras/empresas')).filter((e) => e.ativo);
  modal('Peça entregue a qual empresa?', (fechar) => h('div', { class: 'formulario' },
    empresas.length ? [
      h('p', { class: 'dica' }, nota.empresa ? `A nota já está em nome da ${nota.empresa}: não gera valor a receber.` : `Nota da oficina: o custo (${brl(item.restante_valor)}) fica A RECEBER da empresa escolhida, em Compras > Grupo.`),
      h('div', { class: 'lista' }, empresas.map((e) => h('button', { class: 'linha-os', onclick: acao(async () => { await POST(`/compras/itens/${item.id}/alocar`, { destino: 'outra_empresa', empresaId: e.id }); toast(`Peça entregue à ${e.nome}.`); fechar(); aoMudar(); }) },
        h('div', null, h('b', null, e.nome)), h('div', { class: 'sub' }, e.papel_rotulo))))]
      : vazio('Nenhuma empresa do grupo cadastrada. O dono cadastra em Compras > Grupo.')));
}

function modalNotaTodaNaOs(notaId, aoMudar) {
  modal('Toda a nota em uma OS', (fechar) => {
    const resultado = h('div', { class: 'lista' });
    const busca = h('input', { type: 'search', placeholder: 'Nº da OS, placa ou cliente…', autocomplete: 'off' });
    let t;
    busca.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(acao(async () => {
        if (busca.value.trim().length < 2) return;
        const lista = await GET(`/compras/os-busca?q=${encodeURIComponent(busca.value.trim())}`);
        montar(resultado, lista.map((v) => h('button', { class: 'linha-os', onclick: acao(async () => { await POST(`/compras/notas/${notaId}/alocar-os`, { vendaId: v.id }); toast(`Peças aplicadas na OS ${v.numero}.`); fechar(); aoMudar(); }) },
          h('div', null, h('b', null, `OS ${v.numero ?? v.id} · ${v.cliente ?? ''}`)), h('div', { class: 'sub' }, `${dataBR(v.data)} · ${v.veiculo ?? ''} ${v.placa ?? ''}`))));
      }), 250);
    });
    return h('div', { class: 'formulario' }, h('p', { class: 'dica' }, 'Use quando a compra inteira foi para um carro só.'), campo('Buscar a OS', busca), resultado);
  });
}

export async function modalNotaManual(aoMudar) {
  const forn = await GET('/compras/fornecedores');
  modal('Nota sem XML (tenho a DANFE)', (fechar) => {
    const parcelas = h('div', { class: 'lista' });
    const addParcela = () => parcelas.append(h('div', { class: 'duas' }, entrada('pvenc', '', { type: 'date' }), entrada('pvalor', '', { inputmode: 'decimal', placeholder: 'valor da parcela' })));
    addParcela();
    const sel = selecao('fornecedorId', [['', 'Escolha o fornecedor'], ...forn.filter((x) => x.ativo).map((x) => [x.id, x.nome]), ['novo', '+ Outro fornecedor…']], '');
    const novoNome = entrada('fornecedorNome', '', { placeholder: 'nome do novo fornecedor' });
    const campoNovo = campo('Nome do novo fornecedor', novoNome);
    campoNovo.hidden = true;
    sel.addEventListener('change', () => { campoNovo.hidden = sel.value !== 'novo'; });
    const numero = entrada('numero', '', { required: true, inputmode: 'numeric' });
    const serie = entrada('serie', '', { inputmode: 'numeric' });
    const dataEmissao = entrada('dataEmissao', hojeISO(), { type: 'date', required: true });
    const valorTotal = entrada('valorTotal', '', { inputmode: 'decimal', required: true });
    const chaveCampo = entrada('chave', '', { inputmode: 'numeric' });
    const destCampo = entrada('cnpjDestinatario', '', { inputmode: 'text', placeholder: '00.000.000/0000-00' });
    const leitura = h('div', { class: 'resumo-os' }, 'Cole abaixo o texto copiado do PDF da DANFE (ou só a chave de 44 números) e toque em "Ler". O sistema preenche o que conseguir; você confere com a nota na mão.');
    const texto = h('textarea', { name: 'texto', rows: 4, placeholder: 'Texto da DANFE ou chave de acesso…', autocomplete: 'off' });
    const ler = acao(async () => {
      const r = await POST('/compras/notas/ler-danfe', { texto: texto.value });
      const avisos = r.avisos ?? [];
      if (r.ok) {
        chaveCampo.value = r.chave;
        numero.value = r.numero; serie.value = r.serie === '0' ? '' : r.serie;
        if (r.dataEmissao) dataEmissao.value = r.dataEmissao;
        if (r.valorTotal) valorTotal.value = paraCampo(r.valorTotal);
        if (r.destinatarioCnpj) destCampo.value = cnpjBR(r.destinatarioCnpj);
        if (r.fornecedor) { sel.value = String(r.fornecedor.id); campoNovo.hidden = true; } else { sel.value = 'novo'; campoNovo.hidden = false; }
      }
      montar(leitura,
        r.ok ? h('b', { class: 'bom' }, `Chave válida · nota ${r.numero}, série ${r.serie}, emitida em ${r.mesChave} · CNPJ do fornecedor ${cnpjBR(r.cnpjEmitente)}`) : null,
        r.ok ? h('div', { class: r.fornecedor ? 'dica' : 'aviso-texto' }, r.fornecedor ? `Fornecedor: ${r.fornecedor.nome}.` : 'Fornecedor novo: digite o nome. O CNPJ vem da chave.') : null,
        r.empresaDestinatario ? h('div', { class: 'dica' }, `A nota está em nome da ${r.empresaDestinatario.nome} (empresa do grupo).`) : null,
        r.notaExistente ? h('div', { class: 'ruim' }, `Esta nota já está cadastrada (nº ${r.notaExistente.numero}).`) : null,
        r.valoresPossiveis?.length > 1 ? h('div', { class: 'dica' }, 'Valores que apareceram no bloco de totais (o total da nota é o maior; confira): ', r.valoresPossiveis.map((v) => h('button', { type: 'button', class: 'pequeno', onclick: () => { valorTotal.value = paraCampo(v); } }, brl(v)))) : null,
        avisos.map((a) => h('div', { class: 'aviso-texto' }, a)));
    });
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'aviso-texto' }, 'Prefira o XML (Notas > Importar XML). Nota sem XML só passa a provar alguma coisa depois que a chave é consultada no portal da NF-e e o dono registra a consulta.'),
      h('div', { class: 'dica' }, 'Dica: o contador recebe o XML de toda nota emitida contra o CNPJ da oficina. Peça a ele o pacote mensal e importe aqui: acaba a nota sem XML.'),
      campo('Texto da DANFE ou chave de acesso', texto), h('div', { class: 'botoes' }, h('button', { type: 'button', onclick: ler }, 'Ler')), leitura,
      campo('Fornecedor', sel), campoNovo,
      h('div', { class: 'duas' }, campo('Nº da nota', numero), campo('Série', serie)),
      h('div', { class: 'duas' }, campo('Data de emissão', dataEmissao), campo('Valor total', valorTotal)),
      campo('Chave de acesso (44 números)', chaveCampo, 'Se digitar, o sistema confere se bate com o fornecedor, o número, a série e o mês.'),
      campo('CNPJ de quem comprou (destinatário da nota)', destCampo, 'Fica na DANFE em "Destinatário". Serve para saber se a nota é da oficina, da locadora ou de outro CNPJ.'),
      h('h3', null, 'Parcelas (se houver)'), parcelas,
      h('div', { class: 'botoes' }, h('button', { type: 'button', class: 'pequeno', onclick: addParcela }, '+ parcela'), h('button', { type: 'submit', class: 'primario' }, 'Salvar nota')));
    f.addEventListener('submit', acao(async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const venc = [...f.querySelectorAll('[name=pvenc]')].map((x) => x.value);
      const vals = [...f.querySelectorAll('[name=pvalor]')].map((x) => x.value);
      const duplicatas = venc.map((v, i) => ({ vencimento: v, valor: num(vals[i]) })).filter((p) => p.vencimento && p.valor);
      const nota = await POST('/compras/notas', { fornecedorId: d.fornecedorId && d.fornecedorId !== 'novo' ? Number(d.fornecedorId) : null, fornecedorNome: d.fornecedorId === 'novo' ? d.fornecedorNome : null,
        numero: d.numero, serie: d.serie, dataEmissao: d.dataEmissao, valorTotal: num(d.valorTotal), chave: d.chave || null, cnpjDestinatario: d.cnpjDestinatario || null, duplicatas });
      toast('Nota salva. Agora consulte a chave no portal e registre.'); fechar(); aoMudar();
      if (nota.nota_id) modalNota(nota.nota_id, aoMudar);
    }));
    return f;
  });
}

export function modalImportarXml(aoMudar) {
  modal('Importar XML de notas', (fechar) => {
    const arquivos = h('input', { type: 'file', accept: '.xml,text/xml,application/xml', multiple: true });
    const saida = h('div', { class: 'lista' });
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Escolha um ou vários XML de NF-e (os que o fornecedor manda por e-mail). Também aceita o XML de cancelamento completo ("procEventoNFe"). Repetir um arquivo não duplica.'),
      campo('Arquivos XML', arquivos), saida,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Importar')));
    f.addEventListener('submit', acao(async (e) => {
      e.preventDefault();
      const lista = [...arquivos.files];
      if (!lista.length) { toast('Escolha ao menos um arquivo.', true); return; }
      const xmls = await Promise.all(lista.map((a) => a.text()));
      const r = await POST('/compras/notas/xml', { xmls, nomes: lista.map((a) => a.name) });
      const ROT = { importada: 'importada', ja_existia: 'já estava', cancelada: 'cancelamento aplicado', atualizada: 'completou a nota digitada', divergente_manual: 'CONFERIR', erro: 'ERRO' };
      montar(saida, r.resultados.map((x) => h('div', { class: 'resultado-cartao' },
        h('div', { class: 'topo' }, h('b', null, x.arquivo), selo(ROT[x.status] ?? x.status, x.status === 'erro' || x.status === 'divergente_manual' ? 'critico' : 'ok')),
        x.erro ? h('span', { class: 'ruim' }, x.erro) : null,
        (x.avisos ?? []).map((a) => h('span', { class: 'aviso-texto' }, a)))));
      aoMudar();
    }));
    return f;
  });
}

// ------------------------------------------------------------------ fornecedor

export function modalFornecedor(f0, aoMudar) {
  const f = f0 ?? { nome: '', cnpj: '', principal: 0, ativo: 1 };
  modal(f0 ? 'Editar fornecedor' : 'Novo fornecedor', (fechar) => {
    const form = h('form', { class: 'formulario' },
      campo('Nome', entrada('nome', f.nome, { required: true })),
      campo('CNPJ', entrada('cnpj', f.cnpj ? cnpjBR(f.cnpj) : '', { inputmode: 'text', placeholder: '00.000.000/0000-00' }), 'Com o CNPJ o sistema confere se o beneficiário do boleto é mesmo este fornecedor.'),
      !ehDono() ? h('p', { class: 'dica' }, 'Quem confirma o fornecedor (cartão CNPJ e telefone) e autoriza outros recebedores de boleto é o dono.') : null,
      ehDono() ? campo('Outros CNPJs que podem receber os boletos (opcional)', entrada('beneficiariosAutorizados', (f.beneficiarios_autorizados ?? '').split(',').filter(Boolean).map(cnpjBR).join(', '), { inputmode: 'text', placeholder: 'filial, banco ou factoring, separados por vírgula' }), 'Só cadastre depois de confirmar por telefone com o fornecedor. Sem isso, boleto em nome de outro CNPJ é tratado como grave.') : null,
      ehDono() ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'confirmado', checked: !!f.confirmado_em }), ' Conferi o CNPJ (cartão CNPJ) e o telefone deste fornecedor') : null,
      h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'principal', checked: !!f.principal }), ' Fornecedor principal'),
      f0 ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'ativo', checked: !!f.ativo }), ' Ativo') : null,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    form.addEventListener('submit', acao(async (e) => {
      e.preventDefault();
      const d = lerForm(form);
      const corpo = { nome: d.nome, cnpj: d.cnpj, principal: d.principal, ...(ehDono() ? { beneficiariosAutorizados: d.beneficiariosAutorizados, confirmado: d.confirmado } : {}) };
      if (f0) corpo.ativo = d.ativo;
      if (f0) await PUT(`/compras/fornecedores/${f.id}`, corpo); else await POST('/compras/fornecedores', corpo);
      toast('Fornecedor salvo.'); fechar(); aoMudar();
    }));
    return form;
  });
}

export async function modalExtratoFornecedor(id, aoMudar) {
  const d = await GET(`/compras/fornecedores/${id}/extrato`);
  const t = d.totais;
  modal(`Extrato · ${d.fornecedor.nome}`, () => h('div', null,
    h('div', { class: 'linhas' },
      h('div', null, h('span', null, 'Comprado (notas)'), h('b', null, brl0(t.comprado))),
      h('div', null, h('span', null, 'Notas sem boleto'), h('b', { class: t.notasSemBoleto > 0 ? 'ruim' : '' }, brl0(t.notasSemBoleto))),
      h('div', null, h('span', null, 'Boletos em aberto'), h('b', null, brl0(t.boletosAbertos))),
      h('div', null, h('span', null, 'Boletos pagos'), h('b', null, brl0(t.boletosPagos))),
      h('div', null, h('span', null, 'Boletos SEM nota'), h('b', { class: t.boletosSemNota > 0 ? 'ruim' : 'bom' }, brl0(t.boletosSemNota)))),
    h('div', { class: 'botoes' }, h('button', { class: 'pequeno', onclick: acao(async () => { await navigator.clipboard.writeText(MENSAGEM_PEDIR_XML); toast('Mensagem copiada. Cole no WhatsApp ou e-mail do fornecedor.'); }) }, 'Copiar mensagem pedindo o XML e o nº da OS')),
    h('h3', null, 'Boletos'),
    d.boletos.length ? d.boletos.slice(0, 30).map((b) => h('button', { class: 'linha-simples', onclick: acao(() => modalBoleto(b.id, aoMudar)) },
      h('span', null, `${dataBR(b.vencimento)} · ${brl(b.valor)}`), h('small', { class: b.ligacoes ? '' : 'ruim' }, `${b.situacao}${b.ligacoes ? '' : ' · sem nota'}`))) : vazio('Nenhum boleto.'),
    h('h3', null, 'Notas'),
    d.notas.length ? d.notas.slice(0, 30).map((n) => h('button', { class: 'linha-simples', onclick: acao(() => modalNota(n.id, aoMudar)) },
      h('span', null, `Nota ${n.numero} · ${dataBR(n.data_emissao)} · ${brl(n.valor_total)}`), h('small', { class: n.saldo > 0.05 ? 'ruim' : '' }, n.saldo > 0.05 ? `sem boleto ${brl(n.saldo)}` : 'ok'))) : vazio('Nenhuma nota.')));
}

export async function modalOsSemNota(aoMudar) {
  const d = await GET('/compras/os-sem-nota');
  modal('OS com custo de peça sem nota', (fechar) => h('div', null,
    h('p', { class: 'dica' }, `Desde ${dataBR(d.desde)}. Toque na OS para ligar uma peça de uma nota que está esperando destino.`),
    h('div', { class: 'lista' }, d.os.length ? d.os.map((v) => h('button', { class: 'linha-os', onclick: acao(() => escolherItemLivre({ id: v.id, numero: v.numero }, aoMudar)) },
      h('div', null, h('b', null, `OS ${v.numero ?? v.id} · ${v.cliente ?? ''}`)), h('div', { class: 'sub' }, `${dataBR(v.data)} · ${v.veiculo ?? ''} ${v.placa ?? ''} · custo ${brl(v.custo_pecas)}`))) : [vazio('Todas as OS do período têm nota ligada.')])));
}

export async function modalBoletosEmLote(aoMudar) {
  const forn = await GET('/compras/fornecedores');
  modal('Colar vários boletos', (fechar) => {
    const saida = h('div', { class: 'lista' });
    const sel = selecao('fornecedorId', [['', 'Escolha o fornecedor'], ...forn.filter((f) => f.ativo).map((f) => [f.id, f.nome]), ['novo', '+ Outro fornecedor…']], '');
    const novo = entrada('fornecedorNome', '', { placeholder: 'nome do novo fornecedor' });
    const campoNovo = campo('Nome do novo fornecedor', novo);
    campoNovo.hidden = true;
    sel.addEventListener('change', () => { campoNovo.hidden = sel.value !== 'novo'; });
    const area = h('textarea', { name: 'linhas', rows: 8, placeholder: '34191.79001 01043.510047 91020.150008 5 87560026000\n34191.79001 ...' });
    const botao = h('button', { type: 'submit', class: 'primario' }, 'Cadastrar todos');
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Todos os boletos de uma vez, do MESMO fornecedor. Uma linha digitável (ou código de barras) por linha. O sistema lê valor e vencimento e tenta achar a nota de cada um. Depois, abra cada boleto para informar quem recebe e quem paga.'),
      campo('Fornecedor', sel), campoNovo,
      campo('Linhas digitáveis (uma por linha)', area),
      saida,
      h('div', { class: 'botoes' }, botao));
    f.addEventListener('submit', acao(async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const r = await POST('/compras/boletos/lote', { linhas: d.linhas, fornecedorId: d.fornecedorId && d.fornecedorId !== 'novo' ? Number(d.fornecedorId) : null, fornecedorNome: d.fornecedorId === 'novo' ? d.fornecedorNome : null });
      const criados = r.resultados.filter((x) => x.status === 'criado');
      const falhas = r.resultados.filter((x) => x.status === 'erro');
      montar(saida,
        h('p', { class: criados.length ? 'bom' : 'ruim' }, `${criados.length} cadastrado(s), ${criados.filter((x) => x.ligado).length} ligado(s) a uma nota, ${criados.filter((x) => !x.ligado).length} SEM nota, ${falhas.length} com erro.`),
        r.resultados.map((x) => h('div', { class: 'resultado-cartao' },
          h('div', { class: 'topo' }, h('b', null, `…${String(x.linha).replace(/\D/g, '').slice(-8)}`), x.valor ? h('span', null, `${brl(x.valor)}${x.vencimento ? ` · vence ${dataBR(x.vencimento)}` : ''}`) : null),
          x.status === 'erro' ? h('span', { class: 'ruim' }, x.erro) : h('span', { class: x.ligado ? 'bom' : 'aviso-texto' }, x.ligado ? 'ligado à nota' : 'SEM nota'),
          x.boleto_id ? h('button', { type: 'button', class: 'pequeno', onclick: acao(() => modalBoleto(x.boleto_id, aoMudar)) }, 'Abrir') : null)));
      // o que deu certo não pode ser enviado de novo: a caixa passa a ter só as linhas que falharam
      area.value = falhas.map((x) => x.linha).join('\n');
      if (!falhas.length) botao.disabled = true;
      aoMudar();
    }));
    return f;
  });
}
