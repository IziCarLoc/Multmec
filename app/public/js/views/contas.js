import { h, brl, brl0, dataBR, dataCurta, nomeMes, somarMes, selo, vazio, carregando, toast, modal, campo, entrada, selecao, lerForm, confirmar, montar, acao, numBR as num, paraCampo } from '../ui.js';
import { GET, POST, PUT, DEL } from '../api.js';
import { pagarComTrava, modalBoleto } from './compras_modais.js';

const hojeISO = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });

async function formConta(conta, aoSalvar) {
  const cats = await GET('/categorias');
  modal(conta?.id ? 'Editar conta' : 'Nova conta a pagar', (fechar) => {
    const c = conta || { vencimento: hojeISO() };
    const f = h('form', { class: 'formulario' },
      campo('Descrição', entrada('descricao', c.descricao || '', { required: true, placeholder: 'ex.: boleto Auto Peças Silva' })),
      campo('Categoria', selecao('categoriaId', cats.map((x) => [x.id, x.nome]), c.categoria_id)),
      h('div', { class: 'duas' },
        campo('Valor', entrada('valor', c.valor ?? '', { inputmode: 'decimal', required: true })),
        campo('Vencimento', entrada('vencimento', c.vencimento, { type: 'date', required: true }))),
      campo('Fornecedor', entrada('fornecedor', c.fornecedor || '')),
      !conta?.id ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'pagarAgora' }), ' Já paguei (registrar pagamento hoje)') : null,
      h('div', { class: 'botoes' },
        conta?.id ? h('button', { type: 'button', class: 'perigo', onclick: async () => { if (confirmar('Apagar esta conta?')) { await DEL(`/saidas/${conta.id}`); fechar(); aoSalvar(); } } }, 'Apagar') : null,
        h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const corpo = { descricao: d.descricao, categoriaId: Number(d.categoriaId), valor: num(d.valor), vencimento: d.vencimento, fornecedor: d.fornecedor, pagarAgora: !!d.pagarAgora };
      try { if (conta?.id) await PUT(`/saidas/${conta.id}`, corpo); else await POST('/saidas', corpo); toast('Conta salva.'); fechar(); aoSalvar(); } catch (err) { toast(err.message, true); }
    });
    return f;
  });
}

async function modelosFixos(aoMudar) {
  const [modelos, cats] = await Promise.all([GET('/recorrentes'), GET('/categorias')]);
  modal('Modelos fixos (geram a conta todo mês)', (fechar) => {
    const lista = h('div', { class: 'lista' }, modelos.map((m) => h('div', { class: 'linha-simples' },
      h('div', null, h('b', null, m.descricao), h('small', null, `${m.categoria} · todo dia ${m.dia_vencimento}`)),
      h('div', { class: 'acoes' }, h('b', null, brl0(m.valor)),
        h('button', { onclick: async () => { await PUT(`/recorrentes/${m.id}`, { ativo: !m.ativo }); fechar(); modelosFixos(aoMudar); aoMudar(); } }, m.ativo ? 'Pausar' : 'Reativar')))));
    const f = h('form', { class: 'formulario' },
      h('h3', null, 'Adicionar modelo'),
      campo('Descrição', entrada('descricao', '', { required: true, placeholder: 'ex.: Salário do Robson' })),
      campo('Categoria', selecao('categoriaId', cats.filter((x) => ['folha', 'fixo', 'imposto', 'socios', 'outros'].includes(x.grupo)).map((x) => [x.id, x.nome]), '')),
      h('div', { class: 'duas' }, campo('Valor mensal', entrada('valor', '', { inputmode: 'decimal', required: true })), campo('Dia do vencimento', entrada('diaVencimento', '5', { type: 'number', min: 1, max: 31 }))),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Adicionar')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      try { await POST('/recorrentes', { descricao: d.descricao, categoriaId: Number(d.categoriaId), valor: num(d.valor), diaVencimento: Number(d.diaVencimento) }); toast('Modelo criado. As contas do mês já foram geradas.'); fechar(); aoMudar(); } catch (err) { toast(err.message, true); }
    });
    return h('div', null, modelos.length ? lista : vazio('Nenhum modelo ainda. Cadastre salários, aluguel, energia, sistema, contador…'), f);
  });
}

export async function contas(el, estado) {
  const mes = estado.mesContas || (estado.mesContas = hojeISO().slice(0, 7));
  montar(el, carregando());
  const d = await GET(`/saidas?mes=${mes}`);
  const grupo = (titulo, linhas, tipo) => linhas.length ? h('section', { class: 'cartao' }, h('h2', null, `${titulo} · ${brl0(linhas.reduce((a, s) => a + s.valor, 0))}`),
    linhas.map((s) => h('div', { class: `linha-conta ${tipo}` },
      h('button', { class: 'linha-conta-info', onclick: acao(() => (s.boleto_id ? modalBoleto(s.boleto_id, recarregar) : formConta(s, recarregar))) },
        h('b', null, s.descricao), h('small', null, `${dataCurta(s.vencimento)} · ${s.categoria}${s.fornecedor && !s.boleto_id ? ' · ' + s.fornecedor : ''}`),
        s.veredito && !s.pago_em ? selo(s.veredito.texto.length > 40 ? `${s.veredito.texto.slice(0, 38)}…` : s.veredito.texto, { ruim: 'critico', atencao: 'aviso', ok: 'ok', neutro: 'neutro' }[s.veredito.nivel]) : null),
      h('div', { class: 'acoes' }, h('b', null, brl(s.valor)),
        s.pago_em ? h('button', { class: 'pequeno', title: 'Desfazer pagamento', onclick: acao(async () => { if (!confirmar('Desfazer este pagamento? Ele volta a ficar em aberto.')) return; await POST(`/saidas/${s.id}/pagar`, { desfazer: true }); recarregar(); }) }, 'Desfazer')
          : h('button', { class: 'pequeno primario', onclick: acao(() => pagarComTrava((extra) => POST(`/saidas/${s.id}/pagar`, extra), recarregar, { fornecedor: s.fornecedor })) }, 'Paguei'))))) : null;
  const recarregar = () => contas(el, estado);
  montar(el, 
    h('div', { class: 'seletor-mes' },
      h('button', { class: 'icone', type: 'button', 'aria-label': 'Mês anterior', onclick: () => { estado.mesContas = somarMes(mes, -1); recarregar(); } }, '‹'),
      h('strong', null, nomeMes(mes)),
      h('button', { class: 'icone', type: 'button', 'aria-label': 'Próximo mês', onclick: () => { estado.mesContas = somarMes(mes, 1); recarregar(); } }, '›')),
    h('section', { class: 'cartao resumo-contas' },
      h('div', null, h('span', null, 'Total do mês'), h('b', null, brl0(d.totais.total))),
      h('div', null, h('span', null, 'Já pago'), h('b', { class: 'bom' }, brl0(d.totais.pagas))),
      h('div', null, h('span', null, 'Falta pagar'), h('b', null, brl0(d.totais.aPagar))),
      h('div', null, h('span', null, 'Atrasado'), h('b', { class: d.totais.atrasadas > 0 ? 'ruim' : '' }, brl0(d.totais.atrasadas)))),
    h('div', { class: 'botoes-topo' }, h('button', { onclick: () => modelosFixos(recarregar) }, 'Modelos fixos'), h('button', { class: 'primario', onclick: () => formConta(null, recarregar) }, '+ Nova conta')),
    grupo('Atrasadas', d.linhas.filter((s) => s.situacao === 'atrasada'), 'atrasada'),
    grupo('A pagar', d.linhas.filter((s) => s.situacao === 'a_pagar'), ''),
    grupo('Pagas', d.linhas.filter((s) => s.situacao === 'paga'), 'paga'),
    d.linhas.length ? null : vazio('Nenhuma conta neste mês. Use "Modelos fixos" para cadastrar o que se repete todo mês.'));
}
