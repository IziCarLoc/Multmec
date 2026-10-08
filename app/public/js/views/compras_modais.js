// Janelas da área de Compras: boleto, nota, aplicar peça em OS, fornecedor, pagamento com trava.
import { h, brl, brl0, dataBR, selo, vazio, toast, modal, campo, entrada, selecao, lerForm, confirmar, montar, cnpjBR } from '../ui.js';
import { escolherItemLivre } from './vendas.js';
import { GET, POST, PUT, DEL } from '../api.js';

const hojeISO = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const num = (v) => (v === '' || v === undefined || v === null ? null : Number(String(v).replace(/\./g, '').replace(',', '.')));
const ROTULO_SEV = { alta: 'GRAVE', media: 'conferir', baixa: 'detalhe' };
const TIPO_SEV = { alta: 'critico', media: 'aviso', baixa: 'info' };
const DESTINOS = { estoque: 'Estoque', uso_interno: 'Uso interno', devolvido: 'Devolvida ao fornecedor', os: 'OS' };

export const chipSev = (sev) => selo(ROTULO_SEV[sev] ?? sev, TIPO_SEV[sev] ?? 'neutro');

export function listaOcorrencias(lista, aoMudar, { mostrarAcoes = true } = {}) {
  if (!lista.length) return h('p', { class: 'bom' }, 'Nada a apontar.');
  return h('div', { class: 'lista' }, lista.map((o) => h('div', { class: `ocorrencia ${o.severidade}` },
    h('div', { class: 'ocorrencia-topo' }, chipSev(o.severidade), h('b', null, o.titulo), o.valor ? h('span', { class: 'valor' }, brl(o.valor)) : null),
    h('p', null, o.detalhe),
    mostrarAcoes ? h('div', { class: 'botoes' },
      o.tipo === 'os_sem_nota' ? h('button', { class: 'pequeno primario', onclick: () => modalOsSemNota(aoMudar) }, 'Ver as OS') : null,
      o.tipo === 'custo_os_diverge' ? h('button', { class: 'pequeno', onclick: async () => { try { await POST(`/compras/os/${o.id}/usar-custo-das-notas`, {}); toast('Custo da OS agora vem das notas.'); aoMudar(); } catch (e) { toast(e.message, true); } } }, 'Usar o custo das notas') : null,
      h('button', { class: 'pequeno', onclick: () => modalAceitar(o, aoMudar) }, 'Conferi, está certo')) : null)));
}

export function modalAceitar(o, aoMudar) {
  modal('Conferi e está certo', (fechar) => {
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, `${o.titulo}. Fica registrado com o motivo para uma auditoria futura.`),
      campo('Por quê?', entrada('motivo', '', { required: true, minlength: 5, placeholder: 'ex.: pago no Pix dia 05/10, comprovante na pasta' })),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Registrar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await POST('/compras/ocorrencias/aceitar', { chave: o.chave, motivo: lerForm(f).motivo }); toast('Registrado.'); fechar(); aoMudar(); } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

/** Chama `executar(extra)`; se o servidor travar por ocorrência grave, pede o motivo para "pagar mesmo assim". */
export async function pagarComTrava(executar, aoFim) {
  try { await executar({}); toast('Pago.'); aoFim(); } catch (e) {
    if (e.status !== 409) { toast(e.message, true); return; }
    const ocs = e.dados?.ocorrencias ?? [];
    modal('Pagamento travado', (fechar) => {
      const f = h('form', { class: 'formulario' },
        h('p', { class: 'ruim' }, 'Antes de pagar, confira:'),
        listaOcorrencias(ocs, () => {}, { mostrarAcoes: false }),
        h('p', { class: 'dica' }, 'Só pague se tiver certeza de que o boleto é da oficina. Ao liberar, o motivo fica gravado.'),
        campo('Motivo para pagar mesmo assim', entrada('motivo', '', { required: true, minlength: 5, placeholder: 'ex.: liguei no fornecedor e confirmou o boleto' })),
        h('div', { class: 'botoes' }, h('button', { type: 'button', onclick: fechar }, 'Não pagar agora'), h('button', { type: 'submit', class: 'perigo' }, 'Pagar mesmo assim')));
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        try { await executar({ aprovar: true, motivo: lerForm(f).motivo }); toast('Pago, com ressalva registrada.'); fechar(); aoFim(); } catch (err) { toast(err.message, true); }
      });
      return f;
    });
  }
}

// ------------------------------------------------------------------ aplicar peça em OS

export function modalAplicarEmOs(item, aoMudar) {
  modal(`Aplicar: ${item.descricao}`, (fechar) => {
    const resultado = h('div', { class: 'lista' });
    const qtd = entrada('quantidade', item.restante ?? item.restante_qtd ?? item.quantidade, { inputmode: 'decimal' });
    const busca = h('input', { type: 'search', placeholder: 'Nº da OS, placa ou cliente…', autocomplete: 'off' });
    let t;
    const procurar = async () => {
      const q = busca.value.trim();
      if (q.length < 2) { montar(resultado, h('p', { class: 'dica' }, 'Digite pelo menos 2 caracteres.')); return; }
      const lista = await GET(`/compras/os-busca?q=${encodeURIComponent(q)}`);
      montar(resultado, lista.length ? lista.map((v) => h('button', { class: 'linha-os', onclick: async () => {
        try { await POST(`/compras/itens/${item.id}/alocar`, { destino: 'os', vendaId: v.id, quantidade: num(qtd.value) }); toast(`Aplicada na OS ${v.numero}.`); fechar(); aoMudar(); } catch (e) { toast(e.message, true); }
      } }, h('div', null, h('b', null, `OS ${v.numero ?? v.id} · ${v.cliente ?? 'sem cliente'}`)), h('div', { class: 'sub' }, `${dataBR(v.data)} · ${v.veiculo ?? ''} ${v.placa ?? ''}`))) : vazio('Nenhuma OS encontrada.'));
    };
    busca.addEventListener('input', () => { clearTimeout(t); t = setTimeout(procurar, 250); });
    return h('div', { class: 'formulario' },
      h('p', { class: 'dica' }, `Custo ainda sem destino: ${brl(item.restante_valor ?? item.valor ?? item.custo_total)}. Se a peça foi dividida entre carros, mude a quantidade.`),
      campo('Quantidade nesta OS', qtd), campo('Buscar a OS', busca), resultado);
  });
}

// ------------------------------------------------------------------ boleto

export async function modalBoleto(id, aoMudar) {
  const d = await GET(`/compras/boletos/${id}`);
  const b = d.boleto;
  modal(`Boleto ${b.fornecedor ?? ''}`.trim(), (fechar) => {
    const recarregar = () => { fechar(); modalBoleto(id, aoMudar); aoMudar(); };
    return h('div', null,
      h('dl', { class: 'detalhe' },
        h('dt', null, 'Valor'), h('dd', null, brl(b.valor)), h('dt', null, 'Vencimento'), h('dd', null, dataBR(b.vencimento)),
        h('dt', null, 'Situação'), h('dd', null, b.situacao), h('dt', null, 'Nº do documento'), h('dd', null, b.numero_documento ?? '—'),
        h('dt', null, 'Beneficiário (CNPJ)'), h('dd', null, b.beneficiario_cnpj ? cnpjBR(b.beneficiario_cnpj) : 'não informado'), h('dt', null, 'Banco'), h('dd', null, b.banco_nome ?? b.banco ?? '—')),
      b.linha_digitavel ? h('p', { class: 'dica mono' }, b.linha_digitavel) : null,
      b.aprovado_motivo ? h('p', { class: 'aviso-texto' }, `Pago com ressalva: ${b.aprovado_motivo}`) : null,
      d.rastro?.length ? [h('h3', null, 'Para onde foi o dinheiro deste boleto'),
        d.rastro.map((r) => h('div', { class: 'item-nota' },
          h('div', { class: 'item-nota-topo' }, h('b', null, `Nota ${r.nota}`), h('span', null, `este boleto paga ${brl(r.pago_por_este_boleto)} de ${brl(r.nota_total)}`)),
          r.destinos.length ? r.destinos.map((x) => h('div', { class: 'aloc' }, h('span', null, `${x.item} → ${x.destino === 'os' ? `OS ${x.os ?? ''} ${x.placa ?? ''}` : DESTINOS[x.destino]}`), h('b', null, brl(x.valor)))) : h('p', { class: 'dica' }, 'Nenhuma peça desta nota foi ligada a uma OS ainda.'),
          r.sem_destino > 0.04 ? h('p', { class: 'ruim' }, `Sem destino: ${brl(r.sem_destino)}`) : null))] : null,
      h('h3', null, 'Notas ligadas'),
      d.conciliacoes.length ? d.conciliacoes.map((c) => h('div', { class: 'linha-simples' },
        h('div', null, h('b', null, `Nota ${c.nota_numero} · ${c.fornecedor}`), h('small', null, `${dataBR(c.data_emissao)} · nota de ${brl(c.nota_total)} · cobre ${brl(c.valor)}`)),
        h('button', { class: 'pequeno', onclick: async () => { await DEL(`/compras/boletos/${id}/conciliacoes/${c.nota_id}`); recarregar(); } }, 'Desligar'))) : h('p', { class: 'ruim' }, 'Nenhuma nota ligada a este boleto.'),
      d.sugestoes.length ? [h('h3', null, 'Notas que podem ser deste boleto'), d.sugestoes.map((s) => h('div', { class: 'linha-simples' },
        h('div', null, h('b', null, s.itens.map((i) => `Nota ${i.numero}`).join(' + ')), h('small', null, `${s.motivo} · confiança ${s.score}%`)),
        h('button', { class: 'pequeno primario', onclick: async () => { try { await POST(`/compras/boletos/${id}/conciliar`, { itens: s.itens }); toast('Ligado.'); recarregar(); } catch (e) { toast(e.message, true); } } }, 'Ligar')))] : null,
      b.situacao === 'aberto' || b.situacao === 'contestado' ? h('div', { class: 'botoes' }, h('button', { onclick: () => modalEscolherNota(b, recarregar) }, 'Ligar a outra nota…')) : null,
      h('h3', null, 'Conferência'),
      listaOcorrencias(d.ocorrencias.filter((o) => !o.aceita), recarregar),
      h('div', { class: 'botoes' },
        b.situacao === 'aberto' ? h('button', { class: 'primario', onclick: () => pagarComTrava((extra) => POST(`/compras/boletos/${id}/pagar`, extra), recarregar) }, 'Marcar como pago') : null,
        b.situacao === 'aberto' ? h('button', { onclick: async () => { const m = window.prompt('Motivo da contestação (anotado no boleto):', 'Cobrança sem nota fiscal'); if (m === null) return; await POST(`/compras/boletos/${id}/cancelar`, { situacao: 'contestado', motivo: m }); toast('Boleto marcado como contestado. Não pague.'); recarregar(); } }, 'Contestar') : null,
        b.situacao !== 'pago' && b.situacao !== 'cancelado' ? h('button', { class: 'perigo', onclick: async () => { if (confirmar('Cancelar este boleto? A conta a pagar ligada também sai.')) { await POST(`/compras/boletos/${id}/cancelar`, { situacao: 'cancelado' }); fechar(); aoMudar(); } } }, 'Cancelar boleto') : null));
  });
}

async function modalEscolherNota(b, aoMudar) {
  const notas = await GET(`/compras/notas${b.fornecedor_id ? `?fornecedor_id=${b.fornecedor_id}` : ''}`);
  modal('Ligar a qual nota?', (fechar) => h('div', { class: 'lista' }, notas.filter((n) => n.situacao === 'ativa' && n.saldo > 0.04).slice(0, 40).map((n) => h('button', { class: 'linha-os', onclick: async () => {
    const v = window.prompt(`Quanto deste boleto (${brl(b.valor)}) paga a nota ${n.numero}?`, String(Math.min(b.valor, n.saldo)).replace('.', ','));
    if (v === null) return;
    try { await POST(`/compras/boletos/${b.id}/conciliar`, { itens: [{ nota_id: n.id, valor: num(v) }] }); toast('Ligado.'); fechar(); aoMudar(); } catch (e) { toast(e.message, true); }
  } }, h('div', null, h('b', null, `Nota ${n.numero} · ${n.fornecedor}`)), h('div', { class: 'sub' }, `${dataBR(n.data_emissao)} · ${brl(n.valor_total)} · sem boleto ${brl(n.saldo)}`))).concat(notas.length ? [] : [vazio('Nenhuma nota para escolher.')])));
}

export async function modalNovoBoleto(aoMudar) {
  const forn = await GET('/compras/fornecedores');
  const cfg = await GET('/config');
  modal('Novo boleto', (fechar) => {
    const leitura = h('div', { class: 'resumo-os' }, 'Cole a linha digitável (47 números), o código de barras (44) ou o texto inteiro do boleto copiado do PDF.');
    const linha = h('textarea', { name: 'linha', rows: 2, placeholder: '34191.79001 01043.510047 91020.150008 5 87560026000', inputmode: 'numeric', autocomplete: 'off' });
    const valor = entrada('valor', '', { inputmode: 'decimal' });
    const venc = entrada('vencimento', '', { type: 'date' });
    let t;
    const ler = async () => {
      const texto = linha.value.replace(/\D/g, '');
      if (texto.length < 44) { montar(leitura, 'Cole a linha digitável (47 números), o código de barras (44) ou o texto inteiro do boleto copiado do PDF.'); valor.readOnly = false; venc.readOnly = false; return; }
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
            h('small', { class: 'dica' }, ' Isso só mostra que a linha foi digitada certa. Não prova que o boleto é verdadeiro: a prova é a nota fiscal.')) : null,
          r.erros.map((m) => h('div', { class: 'ruim' }, m)), r.avisos.map((m) => h('div', { class: 'aviso-texto' }, m)));
        if (r.ok && r.valor > 0) { valor.value = String(r.valor).replace('.', ','); valor.readOnly = true; } else valor.readOnly = false;
        if (r.ok && r.vencimento) { venc.value = r.vencimento; venc.readOnly = true; } else venc.readOnly = false;
      } catch (e) { montar(leitura, h('div', { class: 'ruim' }, e.message)); }
    };
    linha.addEventListener('input', () => { clearTimeout(t); t = setTimeout(ler, 250); });
    const docCampo = entrada('numeroDocumento', '', { placeholder: 'ex.: 004321/01' });
    const benefCampo = entrada('beneficiarioCnpj', '', { inputmode: 'text', placeholder: '00.000.000/0000-00' });
    const pagCampo = entrada('pagadorCnpj', '', { inputmode: 'text', placeholder: 'o que está impresso como pagador' });
    const novoNome = entrada('fornecedorNome', '', { placeholder: 'nome do novo fornecedor' });
    const sel = selecao('fornecedorId', [['', 'Escolha o fornecedor'], ...forn.filter((f) => f.ativo).map((f) => [f.id, `${f.nome}${f.principal ? ' (principal)' : ''}`]), ['novo', '+ Outro fornecedor…']], '');
    const campoNovo = campo('Nome do novo fornecedor', novoNome);
    campoNovo.hidden = true;
    sel.addEventListener('change', () => { campoNovo.hidden = sel.value !== 'novo'; });
    const f = h('form', { class: 'formulario' },
      campo('Linha digitável, código de barras ou texto do boleto', linha), leitura,
      campo('Fornecedor', sel), campoNovo,
      h('div', { class: 'duas' }, campo('Valor', valor), campo('Vencimento', venc)),
      campo('Nº do documento (se estiver escrito no boleto)', docCampo, 'às vezes é o número da nota'),
      campo('CNPJ do beneficiário (opcional, recomendado)', benefCampo, 'É quem RECEBE o dinheiro. Confira no app do banco ANTES de pagar: o nome e o CNPJ de quem recebe.'),
      cfg.cnpjOficina ? campo('CNPJ do pagador (opcional)', pagCampo, 'deve ser o da oficina') : null,
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
    return f;
  });
}

function resultadoBoleto(r, aoMudar) {
  modal('Resultado da conferência', (fechar) => {
    const graves = r.ocorrencias.filter((o) => o.severidade === 'alta');
    return h('div', null,
      r.auto.ligado
        ? h('p', { class: 'bom' }, `Ligado automaticamente à ${r.auto.sugestao.itens.map((i) => `nota ${i.numero}`).join(' + ')} (${r.auto.sugestao.motivo}).`)
        : h('p', { class: 'ruim' }, r.auto.sugestoes?.length ? 'Nenhuma nota bate com segurança. Veja as sugestões no boleto.' : 'Não achei nenhuma nota que explique este boleto.'),
      graves.length ? h('p', { class: 'ruim' }, 'Não pague antes de resolver:') : null,
      listaOcorrencias(r.ocorrencias, () => {}, { mostrarAcoes: false }),
      h('div', { class: 'botoes' }, h('button', { class: 'primario', onclick: () => { fechar(); modalBoleto(r.boleto_id, aoMudar); } }, 'Abrir o boleto')));
  });
}

// ------------------------------------------------------------------ nota

export async function modalNota(id, aoMudar) {
  const d = await GET(`/compras/notas/${id}`);
  const n = d.nota;
  modal(`Nota ${n.numero} · ${n.fornecedor}`, (fechar) => {
    const recarregar = () => { fechar(); modalNota(id, aoMudar); aoMudar(); };
    return h('div', null,
      h('dl', { class: 'detalhe' },
        h('dt', null, 'Emissão'), h('dd', null, dataBR(n.data_emissao)), h('dt', null, 'Valor da nota'), h('dd', null, brl(n.valor_total)),
        h('dt', null, 'Ligado a boletos'), h('dd', null, brl(n.conciliado)), h('dt', null, 'Falta boleto'), h('dd', { class: n.saldo > 0.05 ? 'ruim' : '' }, brl(n.saldo)),
        h('dt', null, 'Destinatário'), h('dd', null, n.cnpj_destinatario ?? '—'), h('dt', null, 'Situação'), h('dd', null, n.situacao + (n.protocolo_status === '100' ? ' · autorizada' : '')),
        n.natureza ? [h('dt', null, 'Natureza'), h('dd', null, n.natureza)] : null),
      n.chave ? h('p', { class: 'dica mono' }, `Chave: ${n.chave}`) : h('p', { class: 'aviso-texto' }, 'Nota digitada sem chave de acesso.'),
      d.nota.tem_xml ? h('p', null, h('a', { class: 'botao', href: `/api/compras/notas/${id}/xml`, download: '' }, 'Baixar o XML guardado')) : null,
      n.info_compl ? h('p', { class: 'dica' }, `Informações da nota: ${n.info_compl}`) : null,
      d.duplicatas.length ? [h('h3', null, 'Parcelas'), h('div', { class: 'rolagem' }, h('table', { class: 'tabela' }, h('thead', null, h('tr', null, ['Vence', 'Valor', 'Sem boleto'].map((c) => h('th', null, c)))),
        h('tbody', null, d.duplicatas.map((p) => h('tr', null, h('td', null, dataBR(p.vencimento)), h('td', { class: 'num' }, brl(p.valor)), h('td', { class: `num ${p.saldo > 0.04 ? 'ruim' : 'bom'}` }, p.saldo > 0.04 ? brl(p.saldo) : 'ok')))))) ] : null,
      d.conciliacoes.length ? [h('h3', null, 'Boletos ligados'), d.conciliacoes.map((c) => h('button', { class: 'linha-simples', onclick: () => modalBoleto(c.boleto_id, aoMudar) },
        h('span', null, `Boleto de ${brl(c.boleto_valor)} · vence ${dataBR(c.vencimento)}`), h('small', null, c.situacao)))] : null,
      h('h3', null, 'Peças da nota'),
      d.sugestoes_os.length ? h('div', { class: 'resumo-os' },
        h('b', null, `${d.sugestoes_os.length} peça(s) com OS sugerida: `),
        d.sugestoes_os.map((s) => h('div', null, `${s.descricao} → OS ${s.os} (${s.motivo}${s.ambigua ? '; há mais de uma OS com essa placa' : ''})`)),
        h('div', { class: 'botoes' }, h('button', { class: 'pequeno primario', onclick: async () => { await POST(`/compras/notas/${id}/aplicar-sugestoes`, { itens: d.sugestoes_os.map((s) => ({ item_id: s.item_id, venda_id: s.venda_id })) }); toast('Sugestões aplicadas.'); recarregar(); } }, 'Aplicar sugestões'))) : null,
      d.itens.map((i) => h('div', { class: 'item-nota' },
        h('div', { class: 'item-nota-topo' }, h('b', null, i.descricao), h('span', null, `${i.quantidade} × · ${brl(i.custo_total)}`)),
        i.alocacoes.map((a) => h('div', { class: 'aloc' }, h('span', null, a.destino === 'os' ? `OS ${a.os ?? ''} ${a.placa ?? ''} · ${a.quantidade} · ${brl(a.valor)}` : `${DESTINOS[a.destino]} · ${a.quantidade} · ${brl(a.valor)}`),
          h('button', { class: 'icone pequeno', 'aria-label': 'Remover destino', onclick: async () => { await DEL(`/compras/alocacoes/${a.id}`); recarregar(); } }, '×'))),
        i.restante > 1e-6 ? h('div', { class: 'botoes' },
          h('span', { class: 'ruim' }, `Sem destino: ${i.restante} (${brl(i.restante_valor)})`),
          h('button', { class: 'pequeno primario', onclick: () => modalAplicarEmOs(i, recarregar) }, 'Aplicar em OS…'),
          ['estoque', 'uso_interno', 'devolvido'].map((dst) => h('button', { class: 'pequeno', onclick: async () => { await POST(`/compras/itens/${i.id}/alocar`, { destino: dst }); recarregar(); } }, DESTINOS[dst]))) : null)),
      d.itens.some((i) => i.restante > 1e-6) ? h('div', { class: 'botoes' }, h('button', { onclick: () => modalNotaTodaNaOs(id, recarregar) }, 'Toda a nota em uma OS…')) : null,
      h('h3', null, 'Conferência'),
      listaOcorrencias(d.ocorrencias.filter((o) => !o.aceita), recarregar),
      h('div', { class: 'botoes' },
        h('button', { class: 'pequeno', onclick: async () => { await PUT(`/compras/notas/${id}`, { situacao: n.situacao === 'ativa' ? 'cancelada' : 'ativa' }); recarregar(); } }, n.situacao === 'ativa' ? 'Marcar como cancelada' : 'Reativar nota'),
        h('button', { class: 'pequeno perigo', onclick: async () => { if (confirmar('Apagar esta nota?')) { try { await DEL(`/compras/notas/${id}`); fechar(); aoMudar(); } catch (e) { toast(e.message, true); } } } }, 'Apagar')));
  });
}

function modalNotaTodaNaOs(notaId, aoMudar) {
  modal('Toda a nota em uma OS', (fechar) => {
    const resultado = h('div', { class: 'lista' });
    const busca = h('input', { type: 'search', placeholder: 'Nº da OS, placa ou cliente…', autocomplete: 'off' });
    let t;
    busca.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        if (busca.value.trim().length < 2) return;
        const lista = await GET(`/compras/os-busca?q=${encodeURIComponent(busca.value.trim())}`);
        montar(resultado, lista.map((v) => h('button', { class: 'linha-os', onclick: async () => { await POST(`/compras/notas/${notaId}/alocar-os`, { vendaId: v.id }); toast(`Peças aplicadas na OS ${v.numero}.`); fechar(); aoMudar(); } },
          h('div', null, h('b', null, `OS ${v.numero ?? v.id} · ${v.cliente ?? ''}`)), h('div', { class: 'sub' }, `${dataBR(v.data)} · ${v.veiculo ?? ''} ${v.placa ?? ''}`))));
      }, 250);
    });
    return h('div', { class: 'formulario' }, h('p', { class: 'dica' }, 'Use quando a compra inteira foi para um carro só.'), campo('Buscar a OS', busca), resultado);
  });
}

export async function modalNotaManual(aoMudar) {
  const forn = await GET('/compras/fornecedores');
  modal('Nota lançada à mão', (fechar) => {
    const parcelas = h('div', { class: 'lista' });
    const addParcela = () => parcelas.append(h('div', { class: 'duas' }, entrada('pvenc', '', { type: 'date' }), entrada('pvalor', '', { inputmode: 'decimal', placeholder: 'valor da parcela' })));
    addParcela();
    const sel = selecao('fornecedorId', [['', 'Escolha o fornecedor'], ...forn.filter((x) => x.ativo).map((x) => [x.id, x.nome]), ['novo', '+ Outro fornecedor…']], '');
    const novoNome = entrada('fornecedorNome', '', { placeholder: 'nome do novo fornecedor' });
    const campoNovo = campo('Nome do novo fornecedor', novoNome);
    campoNovo.hidden = true;
    sel.addEventListener('change', () => { campoNovo.hidden = sel.value !== 'novo'; });
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Prefira importar o XML. Sem ele a nota não pode ser provada, e os itens não ficam separados.'),
      campo('Fornecedor', sel), campoNovo,
      h('div', { class: 'duas' }, campo('Nº da nota', entrada('numero', '', { required: true, inputmode: 'numeric' })), campo('Série', entrada('serie', '', { inputmode: 'numeric' }))),
      h('div', { class: 'duas' }, campo('Data de emissão', entrada('dataEmissao', hojeISO(), { type: 'date', required: true })), campo('Valor total', entrada('valorTotal', '', { inputmode: 'decimal', required: true }))),
      campo('Chave de acesso (44 números, opcional)', entrada('chave', '', { inputmode: 'numeric' })),
      h('h3', null, 'Parcelas (se houver)'), parcelas,
      h('div', { class: 'botoes' }, h('button', { type: 'button', class: 'pequeno', onclick: addParcela }, '+ parcela'), h('button', { type: 'submit', class: 'primario' }, 'Salvar nota')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const venc = [...f.querySelectorAll('[name=pvenc]')].map((x) => x.value);
      const vals = [...f.querySelectorAll('[name=pvalor]')].map((x) => x.value);
      const duplicatas = venc.map((v, i) => ({ vencimento: v, valor: num(vals[i]) })).filter((p) => p.vencimento && p.valor);
      try {
        await POST('/compras/notas', { fornecedorId: d.fornecedorId && d.fornecedorId !== 'novo' ? Number(d.fornecedorId) : null, fornecedorNome: d.fornecedorId === 'novo' ? d.fornecedorNome : null,
          numero: d.numero, serie: d.serie, dataEmissao: d.dataEmissao, valorTotal: num(d.valorTotal), chave: d.chave || null, duplicatas });
        toast('Nota salva.'); fechar(); aoMudar();
      } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

export function modalImportarXml(aoMudar) {
  modal('Importar XML de notas', (fechar) => {
    const arquivos = h('input', { type: 'file', accept: '.xml,text/xml,application/xml', multiple: true });
    const saida = h('div');
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Escolha um ou vários XML de NF-e (os que o fornecedor manda por e-mail). Também aceita o XML de cancelamento. Repetir um arquivo não duplica.'),
      campo('Arquivos XML', arquivos), saida,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Importar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const lista = [...arquivos.files];
      if (!lista.length) { toast('Escolha ao menos um arquivo.', true); return; }
      try {
        const xmls = await Promise.all(lista.map((a) => a.text()));
        const r = await POST('/compras/notas/xml', { xmls, nomes: lista.map((a) => a.name) });
        const ROT = { importada: 'importada', ja_existia: 'já estava', cancelada: 'cancelamento aplicado', erro: 'ERRO' };
        montar(saida, h('div', { class: 'rolagem' }, h('table', { class: 'tabela' }, h('thead', null, h('tr', null, ['Arquivo', 'Resultado'].map((c) => h('th', null, c)))),
          h('tbody', null, r.resultados.map((x) => h('tr', null, h('td', null, x.arquivo), h('td', { class: x.status === 'erro' ? 'ruim' : '' }, `${ROT[x.status] ?? x.status}${x.erro ? `: ${x.erro}` : ''}${x.avisos?.length ? ` · ${x.avisos.join(' ')}` : ''}`)))))));
        aoMudar();
      } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

// ------------------------------------------------------------------ fornecedor

export function modalFornecedor(f0, aoMudar) {
  const f = f0 ?? { nome: '', cnpj: '', principal: 0, ativo: 1 };
  modal(f0 ? 'Editar fornecedor' : 'Novo fornecedor', (fechar) => {
    const form = h('form', { class: 'formulario' },
      campo('Nome', entrada('nome', f.nome, { required: true })),
      campo('CNPJ', entrada('cnpj', f.cnpj ?? '', { inputmode: 'text', placeholder: '00.000.000/0000-00' }), 'Com o CNPJ o sistema confere se o beneficiário do boleto é mesmo este fornecedor.'),
      campo('Outros CNPJs que podem receber os boletos (opcional)', entrada('beneficiariosAutorizados', (f.beneficiarios_autorizados ?? '').split(',').filter(Boolean).map(cnpjBR).join(', '), { inputmode: 'text', placeholder: 'filial, banco ou factoring, separados por vírgula' }), 'Só cadastre depois de confirmar por telefone com o fornecedor. Sem isso, boleto em nome de outro CNPJ é tratado como grave.'),
      h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'principal', checked: !!f.principal }), ' Fornecedor principal'),
      f0 ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'ativo', checked: !!f.ativo }), ' Ativo') : null,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(form);
      const corpo = { nome: d.nome, cnpj: d.cnpj, principal: d.principal, beneficiariosAutorizados: d.beneficiariosAutorizados };
      if (f0) corpo.ativo = d.ativo;
      try { if (f0) await PUT(`/compras/fornecedores/${f.id}`, corpo); else await POST('/compras/fornecedores', corpo); toast('Fornecedor salvo.'); fechar(); aoMudar(); } catch (err) { toast(err.message, true); }
    });
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
    h('h3', null, 'Boletos'),
    d.boletos.length ? d.boletos.slice(0, 30).map((b) => h('button', { class: 'linha-simples', onclick: () => modalBoleto(b.id, aoMudar) },
      h('span', null, `${dataBR(b.vencimento)} · ${brl(b.valor)}`), h('small', { class: b.ligacoes ? '' : 'ruim' }, `${b.situacao}${b.ligacoes ? '' : ' · sem nota'}`))) : vazio('Nenhum boleto.'),
    h('h3', null, 'Notas'),
    d.notas.length ? d.notas.slice(0, 30).map((n) => h('button', { class: 'linha-simples', onclick: () => modalNota(n.id, aoMudar) },
      h('span', null, `Nota ${n.numero} · ${dataBR(n.data_emissao)} · ${brl(n.valor_total)}`), h('small', { class: n.saldo > 0.05 ? 'ruim' : '' }, n.saldo > 0.05 ? `sem boleto ${brl(n.saldo)}` : 'ok'))) : vazio('Nenhuma nota.')));
}

export async function modalOsSemNota(aoMudar) {
  const d = await GET('/compras/os-sem-nota');
  modal('OS com custo de peça sem nota', (fechar) => h('div', null,
    h('p', { class: 'dica' }, `Desde ${dataBR(d.desde)}. Toque na OS para ligar uma peça de uma nota que está esperando destino.`),
    h('div', { class: 'lista' }, d.os.length ? d.os.map((v) => h('button', { class: 'linha-os', onclick: () => escolherItemLivre({ id: v.id, numero: v.numero }, aoMudar) },
      h('div', null, h('b', null, `OS ${v.numero ?? v.id} · ${v.cliente ?? ''}`)), h('div', { class: 'sub' }, `${dataBR(v.data)} · ${v.veiculo ?? ''} ${v.placa ?? ''} · custo ${brl(v.custo_pecas)}`))) : [vazio('Todas as OS do período têm nota ligada.')])));
}

export async function modalBoletosEmLote(aoMudar) {
  const forn = await GET('/compras/fornecedores');
  modal('Colar vários boletos', (fechar) => {
    const saida = h('div');
    const sel = selecao('fornecedorId', [['', 'Escolha o fornecedor'], ...forn.filter((f) => f.ativo).map((f) => [f.id, f.nome]), ['novo', '+ Outro fornecedor…']], '');
    const novo = entrada('fornecedorNome', '', { placeholder: 'nome do novo fornecedor' });
    const campoNovo = campo('Nome do novo fornecedor', novo);
    campoNovo.hidden = true;
    sel.addEventListener('change', () => { campoNovo.hidden = sel.value !== 'novo'; });
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Todos os boletos de uma vez, do MESMO fornecedor. Uma linha digitável (ou código de barras) por linha. O sistema lê valor e vencimento, tenta achar a nota de cada um e mostra o que ficou sem nota.'),
      campo('Fornecedor', sel), campoNovo,
      campo('Linhas digitáveis (uma por linha)', h('textarea', { name: 'linhas', rows: 8, placeholder: '34191.79001 01043.510047 91020.150008 5 87560026000\n34191.79001 ...' })),
      saida,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Cadastrar todos')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      try {
        const r = await POST('/compras/boletos/lote', { linhas: d.linhas, fornecedorId: d.fornecedorId && d.fornecedorId !== 'novo' ? Number(d.fornecedorId) : null, fornecedorNome: d.fornecedorId === 'novo' ? d.fornecedorNome : null });
        const criados = r.resultados.filter((x) => x.status === 'criado');
        montar(saida,
          h('p', { class: criados.length ? 'bom' : 'ruim' }, `${criados.length} cadastrado(s), ${criados.filter((x) => x.ligado).length} ligado(s) a uma nota, ${criados.filter((x) => !x.ligado).length} SEM nota, ${r.resultados.length - criados.length} com erro.`),
          h('div', { class: 'rolagem' }, h('table', { class: 'tabela' }, h('thead', null, h('tr', null, ['Valor', 'Vence', 'Resultado'].map((c) => h('th', null, c)))),
            h('tbody', null, r.resultados.map((x) => h('tr', null, h('td', { class: 'num' }, x.valor ? brl(x.valor) : '—'), h('td', null, x.vencimento ? dataBR(x.vencimento) : '—'),
              h('td', { class: x.status === 'erro' ? 'ruim' : (x.ligado ? 'bom' : 'aviso-texto') }, x.status === 'erro' ? x.erro : (x.ligado ? 'ligado à nota' : 'SEM nota'))))))));
        aoMudar();
      } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}
