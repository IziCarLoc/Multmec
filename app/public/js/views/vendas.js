import { h, brl, brl0, pct, dataBR, nomeMes, somarMes, selo, vazio, carregando, toast, modal, campo, entrada, selecao, lerForm, confirmar, montar } from '../ui.js';
import { GET, POST, PUT, DEL } from '../api.js';

const FORMAS = [['', '—'], ['pix', 'Pix'], ['dinheiro', 'Dinheiro'], ['débito', 'Débito'], ['cartão', 'Cartão de crédito'], ['boleto', 'Boleto'], ['transferência', 'Transferência']];
const hojeISO = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const num = (v) => (v === '' || v === undefined || v === null ? null : Number(String(v).replace(/\./g, '').replace(',', '.')) );
const numOuZero = (v) => num(v) ?? 0;

function chip(v) {
  if (v.situacao === 'orcamento') return selo('orçamento', 'info');
  if (v.situacao === 'saldo') return selo(v.aberto > 0 ? 'saldo anterior' : 'saldo quitado', v.aberto > 0 ? 'aviso' : 'ok');
  if (v.situacao === 'cancelada') return selo('cancelada', 'neutro');
  if (v.aberto <= 0.004) return selo('pago', 'ok');
  return selo(v.recebido > 0 ? `faltam ${brl0(v.aberto)}` : 'em aberto', 'aviso');
}

export async function formVenda(venda, aoSalvar) {
  const [clientes, mecanicos] = await Promise.all([GET('/clientes'), GET('/mecanicos')]);
  const v = venda || { data: hojeISO(), situacao: 'concluida' };
  modal(venda?.id ? `OS ${venda.numero || venda.id}` : 'Nova OS', (fechar) => {
    const lista = h('datalist', { id: 'lista-clientes' }, clientes.filter((c) => c.ativo).map((c) => h('option', { value: c.nome })));
    const nomeAtual = clientes.find((c) => c.id === v.cliente_id)?.nome || '';
    const resumo = h('div', { class: 'resumo-os' });
    const f = h('form', { class: 'formulario' },
      lista,
      h('div', { class: 'duas' },
        campo('Nº da OS', entrada('numero', v.numero || '', { inputmode: 'numeric' })),
        campo('Data', entrada('data', v.data, { type: 'date', required: true }))),
      campo('Cliente', entrada('clienteNome', nomeAtual, { list: 'lista-clientes', placeholder: 'digite para buscar ou criar' })),
      h('div', { class: 'duas' },
        campo('Placa', entrada('placa', v.placa || '', { style: 'text-transform:uppercase' })),
        campo('Veículo', entrada('veiculo', v.veiculo || ''))),
      campo('Mecânico', selecao('mecanicoId', [['', '—'], ...mecanicos.filter((m) => m.ativo || m.id === v.mecanico_id).map((m) => [m.id, m.nome])], v.mecanico_id)),
      campo('Situação', selecao('situacao', [['concluida', 'Concluída (vira faturamento)'], ['orcamento', 'Orçamento'], ['aberta', 'Em andamento'], ['cancelada', 'Cancelada']], v.situacao)),
      h('div', { class: 'duas' },
        campo('Valor total cobrado', entrada('valorTotal', v.valor_total ?? '', { inputmode: 'decimal', required: true })),
        campo('Mão de obra (dentro do total)', entrada('valorMaoObra', v.valor_mao_obra || ''))),
      h('div', { class: 'tres' },
        campo('Custo das peças', entrada('custoPecas', v.custo_pecas ?? '', { inputmode: 'decimal' }), 'o que você pagou'),
        campo('Frete', entrada('custoFrete', v.custo_frete || '')),
        campo('Insumos', entrada('custoInsumos', v.custo_insumos || ''))),
      resumo,
      h('div', { class: 'duas' },
        campo('Forma de pagamento', selecao('formaPagamento', FORMAS, v.forma_pagamento)),
        campo('Vencimento (se a prazo)', entrada('vencimento', v.vencimento || '', { type: 'date' }))),
      !venda?.id ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'pagoAgora' }), ' Cliente já pagou (registrar recebimento hoje)') : null,
      campo('Observação', entrada('obs', v.obs || '')),
      h('div', { class: 'botoes' },
        venda?.id ? h('button', { type: 'button', class: 'perigo', onclick: async () => { if (confirmar('Apagar esta OS e seus recebimentos?')) { await DEL(`/vendas/${venda.id}`); fechar(); aoSalvar(); } } }, 'Apagar') : null,
        h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    const atualizarResumo = () => {
      const d = lerForm(f);
      const total = numOuZero(d.valorTotal); const mo = numOuZero(d.valorMaoObra);
      const custo = numOuZero(d.custoPecas) + numOuZero(d.custoFrete) + numOuZero(d.custoInsumos);
      const lucro = total - custo;
      const pecasVenda = total - mo - numOuZero(d.custoFrete) - numOuZero(d.custoInsumos);
      const mk = numOuZero(d.custoPecas) > 0 ? pecasVenda / numOuZero(d.custoPecas) - 1 : null;
      montar(resumo, 
        h('span', null, 'Lucro bruto ', h('b', { class: lucro < 0 ? 'ruim' : 'bom' }, brl(lucro)), total ? ` (${pct(lucro / total)})` : ''),
        mk !== null ? h('span', null, ` · acréscimo sobre as peças ${pct(mk)}`) : null,
        d.custoPecas === '' && total - mo > 50 ? h('span', { class: 'ruim' }, ' · falta informar o custo das peças') : null);
    };
    f.addEventListener('input', atualizarResumo);
    atualizarResumo();
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const corpo = {
        numero: d.numero, data: d.data, clienteNome: d.clienteNome || undefined,
        clienteId: undefined, placa: d.placa, veiculo: d.veiculo, mecanicoId: d.mecanicoId ? Number(d.mecanicoId) : null,
        situacao: d.situacao, valorTotal: numOuZero(d.valorTotal), valorMaoObra: numOuZero(d.valorMaoObra),
        custoPecas: num(d.custoPecas), custoFrete: numOuZero(d.custoFrete), custoInsumos: numOuZero(d.custoInsumos),
        formaPagamento: d.formaPagamento || null, vencimento: d.vencimento || null, obs: d.obs,
      };
      if (!d.clienteNome) corpo.clienteId = null;
      else { const c = clientes.find((x) => x.nome === d.clienteNome.trim().toUpperCase()); if (c) corpo.clienteId = c.id; }
      if (d.pagoAgora) corpo.pagoAgora = { forma: d.formaPagamento || null };
      try {
        if (venda?.id) await PUT(`/vendas/${venda.id}`, corpo); else await POST('/vendas', corpo);
        toast('OS salva.'); fechar(); aoSalvar();
      } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

async function detalhe(v0, aoMudar) {
  const v = await GET(`/vendas/${v0.id}`);
  modal(`OS ${v.numero || v.id}`, (fechar) => {
    const linhas = [
      ['Data', dataBR(v.data)], ['Cliente', v.cliente || '—'], ['Veículo', `${v.veiculo || ''} ${v.placa || ''}`.trim() || '—'], ['Mecânico', v.mecanico || '—'],
      ['Total', brl(v.valor_total)], ['Mão de obra', brl(v.valor_mao_obra)], ['Custo das peças', v.custo_pecas === null ? 'não informado' : brl(v.custo_pecas)],
      ['Frete + insumos', brl(v.custo_frete + v.custo_insumos)], ['Lucro bruto', brl(v.lucro_bruto)], ['Recebido', brl(v.recebido)], ['Em aberto', brl(v.aberto)],
    ];
    const divergente = v.pecas_notas?.length && v.custo_pecas !== null && Math.abs(v.custo_pecas - v.custo_notas) > Math.max(5, v.custo_pecas * 0.05);
    const mudou = () => { fechar(); aoMudar(); detalhe(v0, aoMudar); };
    return h('div', null,
      h('dl', { class: 'detalhe' }, linhas.map(([k, val]) => [h('dt', null, k), h('dd', null, val)])),
      v.situacao === 'concluida' || v.situacao === 'aberta' ? [
        h('h3', null, `Peças com nota fiscal (${brl(v.custo_notas)})`),
        v.pecas_notas?.length ? v.pecas_notas.map((p) => h('div', { class: 'aloc' }, h('span', null, `${p.descricao} · nota ${p.nota} · ${p.fornecedor} · `, h('b', { class: p.situacao_boleto === 'sem boleto' ? 'ruim' : '' }, p.situacao_boleto)), h('b', null, brl(p.valor)))) : h('p', { class: 'dica' }, 'Nenhuma peça desta OS está ligada a uma nota de compra.'),
        divergente ? h('p', { class: 'ruim' }, `O custo digitado (${brl(v.custo_pecas)}) é diferente do que as notas somam (${brl(v.custo_notas)}).`) : null,
        h('div', { class: 'botoes' },
          divergente ? h('button', { class: 'pequeno', onclick: async () => { await POST(`/compras/os/${v.id}/usar-custo-das-notas`, {}); toast('Custo atualizado pelas notas.'); mudou(); } }, 'Usar o custo das notas') : null,
          h('button', { class: 'pequeno', onclick: () => escolherItemLivre(v, mudou) }, 'Ligar peça de uma nota…'))] : null,
      h('div', { class: 'botoes' },
        v.situacao === 'orcamento' ? h('button', { class: 'primario', onclick: async () => { await POST(`/vendas/${v.id}/aprovar`, {}); toast('Orçamento aprovado: virou OS concluída.'); fechar(); aoMudar(); } }, 'Aprovar orçamento') : null,
        v.aberto > 0.004 ? h('button', { class: 'primario', onclick: async () => { await POST(`/vendas/${v.id}/receber`, {}); toast('Recebimento registrado.'); fechar(); aoMudar(); } }, `Recebi ${brl(v.aberto)} hoje`) : null,
        v.situacao !== 'saldo' ? h('button', { onclick: () => { fechar(); formVenda(v, aoMudar); } }, 'Editar') : null));
  });
}

export async function escolherItemLivre(v, aoMudar) {
  const itens = await GET('/compras/itens-livres');
  modal('Qual peça foi usada nesta OS?', (fechar) => h('div', { class: 'lista' }, itens.length ? itens.map((i) => h('button', { class: 'linha-os', onclick: () => { fechar(); modalAplicarEmOsDireto(i, v, aoMudar); } },
    h('div', null, h('b', null, i.descricao)), h('div', { class: 'sub' }, `nota ${i.nota} · ${i.fornecedor} · ${dataBR(i.data_emissao)} · ${brl(i.restante_valor)}`))) : [h('p', { class: 'vazio' }, 'Nenhuma peça de nota está esperando destino. Importe ou lance a nota em Compras.')]));
}

function modalAplicarEmOsDireto(item, v, aoMudar) {
  modal(`Aplicar: ${item.descricao}`, (fechar) => {
    const qtd = entrada('quantidade', item.restante_qtd, { inputmode: 'decimal' });
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, `Em ${item.restante_qtd} unidade(s) ainda sem destino (${brl(item.restante_valor)}). Se a peça foi usada em mais de um carro, diminua a quantidade.`),
      campo(`Quantidade usada na OS ${v.numero ?? v.id}`, qtd),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Aplicar nesta OS')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await POST(`/compras/itens/${item.id}/alocar`, { destino: 'os', vendaId: v.id, quantidade: num(qtd.value) }); toast('Peça ligada à OS.'); fechar(); aoMudar(); } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

export async function vendas(el, estado) {
  const filtros = estado.filtroVendas || (estado.filtroVendas = { mes: estado.mes || hojeISO().slice(0, 7), q: '', modo: '' });
  async function carregar() {
    const lista = h('div', { class: 'lista' }, carregando());
    const qs = new URLSearchParams();
    if (filtros.modo !== 'orcamento' && !filtros.q) qs.set('mes', filtros.mes);
    if (filtros.q) qs.set('q', filtros.q);
    if (filtros.modo === 'aberto') qs.set('aberto', '1');
    if (filtros.modo === 'sem_custo') qs.set('sem_custo', '1');
    if (filtros.modo === 'orcamento') qs.set('situacao', 'orcamento');
    const barra = h('div', { class: 'barra-filtros' },
      h('div', { class: 'seletor-mes' },
        h('button', { class: 'icone', type: 'button', 'aria-label': 'Mês anterior', onclick: () => { filtros.mes = somarMes(filtros.mes, -1); carregar(); } }, '‹'),
        h('strong', null, nomeMes(filtros.mes)),
        h('button', { class: 'icone', type: 'button', 'aria-label': 'Próximo mês', onclick: () => { filtros.mes = somarMes(filtros.mes, 1); carregar(); } }, '›')),
      h('input', { type: 'search', placeholder: 'Buscar placa, OS, cliente…', value: filtros.q, oninput: (e) => { filtros.q = e.target.value; clearTimeout(carregar.t); carregar.t = setTimeout(carregar, 300); } }),
      h('div', { class: 'abas-mini' }, [['', 'Todas'], ['aberto', 'Em aberto'], ['sem_custo', 'Sem custo'], ['orcamento', 'Orçamentos']].map(([k, t]) =>
        h('button', { class: filtros.modo === k ? 'ativo' : '', onclick: () => { filtros.modo = k; carregar(); } }, t))));
    montar(el, barra, lista, h('button', { class: 'fab', 'aria-label': 'Nova OS', onclick: () => formVenda(null, carregar) }, '+'));
    try {
      const dados = await GET(`/vendas?${qs}`);
      const total = dados.filter((v) => v.situacao === 'concluida').reduce((a, v) => a + v.valor_total, 0);
      montar(lista, 
        dados.length ? h('p', { class: 'dica' }, `${dados.length} registros · faturado ${brl0(total)}`) : null,
        dados.length ? dados.map((v) => h('button', { class: 'linha-os', onclick: () => detalhe(v, carregar) },
          h('div', null, h('b', null, `${v.numero ? 'OS ' + v.numero : 'OS'} · ${v.cliente || 'sem cliente'}`), chip(v)),
          h('div', { class: 'sub' }, `${dataBR(v.data)} · ${v.veiculo || ''} ${v.placa || ''}`.trim(), v.sem_custo ? selo('sem custo de peças', 'critico') : null),
          h('div', { class: 'valores' }, h('b', null, brl(v.valor_total)), h('span', null, `lucro ${brl0(v.lucro_bruto)}`)))) : vazio('Nenhuma OS encontrada para este filtro.'));
    } catch (e) { montar(lista, vazio(e.message)); }
  }
  await carregar();
}
