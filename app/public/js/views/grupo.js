// Empresas do mesmo grupo (locadora, oficina do sócio) e o acerto de contas entre elas e a oficina. Só o dono mexe aqui.
import { h, brl, brl0, dataBR, selo, vazio, toast, modal, acao, campo, entrada, selecao, lerForm, confirmar, montar, cnpjBR, numBR as num, paraCampo } from '../ui.js';
import { GET, POST, PUT, DEL } from '../api.js';

const hojeISO = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const PAPEIS = [['locadora', 'Locadora'], ['socio', 'Oficina do sócio'], ['outra', 'Outra empresa']];
const ORIGEM = { boleto: 'boleto pago pela oficina', peca: 'peças entregues', manual: 'lançado à mão' };

export function modalEmpresa(e0, aoMudar) {
  const e = e0 ?? { nome: '', cnpj: '', papel: 'locadora', ativo: 1 };
  modal(e0 ? 'Editar empresa' : 'Nova empresa do grupo', (fechar) => {
    const f = h('form', { class: 'formulario' },
      h('p', { class: 'dica' }, 'Cadastre as empresas de vocês que às vezes aparecem no lugar da oficina numa nota ou boleto (a locadora, a oficina do sócio). Nota ou boleto no CNPJ delas deixa de ser alerta grave e sai das contas a pagar da oficina.'),
      campo('Nome', entrada('nome', e.nome, { required: true, placeholder: 'ex.: IziCar Locadora' })),
      campo('CNPJ', entrada('cnpj', e.cnpj ? cnpjBR(e.cnpj) : '', { inputmode: 'text', placeholder: '00.000.000/0000-00', required: true, readOnly: !!e0 }), e0 ? 'O CNPJ não muda. Se foi cadastrado errado, desative e cadastre de novo.' : 'Precisa passar na validação e não pode ser o da própria oficina nem o de um fornecedor.'),
      campo('O que é', selecao('papel', PAPEIS, e.papel)),
      e0 ? h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'ativo', checked: !!e.ativo }), ' Ativa') : null,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Salvar')));
    f.addEventListener('submit', acao(async (ev) => {
      ev.preventDefault();
      const d = lerForm(f);
      if (e0) await PUT(`/compras/empresas/${e.id}`, { nome: d.nome, papel: d.papel, ativo: d.ativo });
      else await POST('/compras/empresas', { nome: d.nome, cnpj: d.cnpj, papel: d.papel });
      toast('Empresa salva.'); fechar(); aoMudar();
    }));
    return f;
  });
}

function modalAcertoManual(empresa, aoMudar) {
  modal(`Registrar acerto · ${empresa.nome}`, (fechar) => {
    const f = h('form', { class: 'formulario' },
      campo('O que aconteceu', selecao('sentido', [['a_receber', `A oficina pagou ou forneceu algo para a ${empresa.nome} (ela deve)`], ['a_pagar', `A ${empresa.nome} pagou algo da oficina (a oficina deve)`]], 'a_receber')),
      campo('Descrição', entrada('descricao', '', { required: true, placeholder: 'ex.: Pix para pagar o aluguel do pátio' })),
      h('div', { class: 'duas' }, campo('Valor', entrada('valor', '', { inputmode: 'decimal', required: true })), campo('Data', entrada('data', hojeISO(), { type: 'date', required: true }))),
      h('label', { class: 'marcar' }, h('input', { type: 'checkbox', name: 'caixa' }), ' O dinheiro saiu da conta da oficina nesta data (entra no saldo de caixa)'),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Registrar')));
    f.addEventListener('submit', acao(async (ev) => {
      ev.preventDefault();
      const d = lerForm(f);
      await POST('/compras/adiantamentos', { empresaId: empresa.id, sentido: d.sentido, descricao: d.descricao, valor: num(d.valor), data: d.data, caixa: !!d.caixa });
      toast('Registrado.'); fechar(); aoMudar();
    }));
    return f;
  });
}

function modalBaixa(item, empresa, aoMudar) {
  const devolve = item.sentido === 'a_receber';
  modal(devolve ? 'A empresa devolveu' : 'A oficina pagou', (fechar) => {
    const f = h('form', { class: 'formulario' },
      h('p', null, `${item.descricao}. Falta ${brl(item.saldo)}.`),
      h('div', { class: 'duas' }, campo(devolve ? 'Quanto voltou' : 'Quanto a oficina pagou', entrada('valor', paraCampo(item.saldo), { inputmode: 'decimal', required: true })), campo('Data', entrada('data', hojeISO(), { type: 'date', required: true, min: item.data }))),
      h('p', { class: 'dica' }, devolve ? 'Entra no saldo de caixa da oficina nesta data.' : 'Sai do saldo de caixa da oficina nesta data.'),
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Registrar')));
    f.addEventListener('submit', acao(async (ev) => {
      ev.preventDefault();
      const d = lerForm(f);
      await POST(`/compras/adiantamentos/${item.id}/baixar`, { valor: num(d.valor), data: d.data });
      toast('Registrado.'); fechar(); aoMudar();
    }));
    void empresa;
    return f;
  });
}

export async function abaGrupo(el, recarregar) {
  const acerto = await GET('/compras/entre-empresas');
  const cartao = (e) => {
    const abertos = e.itens.filter((i) => i.saldo > 0.04);
    const quitados = e.itens.filter((i) => i.saldo <= 0.04);
    return h('section', { class: `cartao${e.ativo ? '' : ' inativo'}` },
      h('div', { class: 'cliente-topo' }, h('h2', null, e.nome), selo(e.papel_rotulo, 'info'), e.ativo ? null : selo('inativa', 'neutro')),
      h('p', { class: 'dica' }, cnpjBR(e.cnpj)),
      h('div', { class: 'linhas' },
        h('div', null, h('span', null, 'Deve à oficina'), h('b', { class: e.aReceber > 0 ? 'ruim' : '' }, brl0(e.aReceber))),
        h('div', null, h('span', null, 'Oficina deve'), h('b', null, brl0(e.aPagar))),
        h('div', null, h('span', null, 'Saldo'), h('b', null, `${e.saldo >= 0 ? '+' : '−'}${brl0(Math.abs(e.saldo))}`))),
      abertos.map((i) => h('div', { class: 'linha-simples' },
        h('div', null, h('b', null, i.descricao), h('small', null, `${dataBR(i.data)} · ${i.sentido === 'a_receber' ? 'a receber' : 'a pagar'} · ${ORIGEM[i.origem]} · ${i.dias} dia(s)`),
          i.baixas.map((b) => h('small', null, `devolveu ${brl(b.valor)} em ${dataBR(b.data)} `, h('button', { class: 'icone pequeno', 'aria-label': 'Desfazer esta devolução', onclick: acao(async () => { await DEL(`/compras/adiantamentos/baixas/${b.id}`); recarregar(); }) }, '×')))),
        h('div', { class: 'acoes' }, h('b', null, brl(i.saldo)), i.atrasado ? selo('atrasado', 'critico') : null,
          h('button', { class: 'pequeno primario', onclick: () => modalBaixa(i, e, recarregar) }, i.sentido === 'a_receber' ? 'Devolveu' : 'Paguei'),
          i.origem === 'manual' && !i.baixas.length ? h('button', { class: 'pequeno', onclick: acao(async () => { if (confirmar('Apagar este lançamento?')) { await DEL(`/compras/adiantamentos/${i.id}`); recarregar(); } }) }, 'Apagar') : null))),
      !abertos.length ? h('p', { class: 'bom' }, 'Tudo acertado com esta empresa.') : null,
      quitados.length ? h('details', null, h('summary', null, `Já acertados (${quitados.length})`),
        quitados.slice(0, 20).map((i) => h('div', { class: 'linha-simples' }, h('div', null, h('b', null, i.descricao), h('small', null, `${dataBR(i.data)} · ${brl(i.valor)}`))))) : null,
      h('div', { class: 'botoes' },
        e.ativo ? h('button', { onclick: () => modalAcertoManual(e, recarregar) }, 'Registrar acerto') : null,
        h('button', { onclick: () => modalEmpresa(e, recarregar) }, 'Editar')));
  };
  montar(el,
    h('div', { class: 'botoes-topo' }, h('span', { class: 'dica' }, 'Nem toda nota sai no CNPJ da oficina: locadora e oficina do sócio entram aqui.'), h('button', { class: 'primario', onclick: () => modalEmpresa(null, recarregar) }, '+ Empresa')),
    acerto.empresas.length ? [
      h('section', { class: 'cartao' }, h('div', { class: 'resumo-contas' },
        h('div', null, h('span', null, 'Devem à oficina'), h('b', { class: acerto.aReceber > 0 ? 'ruim' : 'bom' }, brl0(acerto.aReceber))),
        h('div', null, h('span', null, 'A oficina deve'), h('b', null, brl0(acerto.aPagar))),
        h('div', null, h('span', null, `Passou de ${acerto.prazoDias} dias`), h('b', { class: acerto.atrasado > 0 ? 'ruim' : 'bom' }, brl0(acerto.atrasado))))),
      acerto.empresas.map(cartao)]
      : h('section', { class: 'cartao' }, h('h2', null, 'Nenhuma empresa cadastrada'),
        h('p', null, 'Se a nota ou o boleto às vezes sai no CNPJ da locadora ou da oficina do sócio, cadastre essas empresas aqui. Assim o sistema:'),
        h('ul', { class: 'passos' }, h('li', null, 'não trata como golpe a nota ou o boleto no CNPJ delas;'), h('li', null, 'tira esses boletos das contas a pagar da oficina;'),
          h('li', null, 'pergunta quem está pagando e, se a oficina pagar, guarda quanto cada empresa ainda deve a ela.')),
        h('div', { class: 'botoes' }, h('button', { class: 'primario', onclick: () => modalEmpresa(null, recarregar) }, 'Cadastrar a primeira'))));
}
