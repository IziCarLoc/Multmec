import { h, brl, brl0, dataBR, selo, vazio, carregando, toast, montar, acao } from '../ui.js';
import { GET, DEL, ehDono } from '../api.js';
import { abaGrupo } from './grupo.js';
import { abaDda } from './dda.js';
import {
  listaOcorrencias, modalBoleto, modalNovoBoleto, modalBoletosEmLote, modalNota, modalNotaManual, modalImportarXml, modalFornecedor, modalExtratoFornecedor, chipSev,
} from './compras_modais.js';

const ABAS = [['auditoria', 'Conferência'], ['boletos', 'Boletos'], ['notas', 'Notas'], ['fornecedores', 'Fornecedores'], ['grupo', 'Grupo', true], ['dda', 'DDA', true]];
const abasVisiveis = () => ABAS.filter(([, , soDono]) => !soDono || ehDono());

async function abaAuditoria(el, estado, recarregar) {
  const [resumo, oc] = await Promise.all([GET('/compras/resumo'), GET('/compras/ocorrencias')]);
  const a = resumo.auditoria;
  const grupos = ['alta', 'media', 'baixa'].map((sev) => [sev, oc.ocorrencias.filter((o) => o.severidade === sev)]).filter(([, l]) => l.length);
  const TITULO = { alta: 'Graves: resolva antes de pagar', media: 'Para conferir', baixa: 'Detalhes' };
  const vazioTudo = resumo.totais.notas === 0 && resumo.totais.boletos === 0;
  montar(el,
    vazioTudo ? h('section', { class: 'cartao' }, h('h2', null, 'Como começar a conferir os boletos'),
      h('ol', { class: 'passos' },
        h('li', null, 'Em ', h('b', null, 'Metas'), ', informe o CNPJ da oficina.'),
        h('li', null, 'Em ', h('b', null, 'Notas'), ', importe os XML que os fornecedores mandam por e-mail (ou lance a nota à mão).'),
        h('li', null, 'Em ', h('b', null, 'Boletos'), ', cadastre cada boleto que chegar (cole a linha digitável). O sistema procura a nota.'),
        h('li', null, 'Boleto sem nota fica GRAVE aqui e o pagamento é travado em Contas até você resolver.'),
        h('li', null, 'Em cada nota, diga em qual OS cada peça foi aplicada: assim você sabe o custo real da OS.')),
      h('div', { class: 'botoes' }, h('a', { class: 'botao', href: '#/metas' }, 'Informar CNPJ'))) : null,
    !resumo.cnpjOficinaConfigurado ? h('div', { class: 'alerta aviso' }, 'Informe o CNPJ da oficina em ', h('a', { href: '#/metas' }, 'Metas'), ' para o sistema conferir o destinatário das notas e o pagador dos boletos.') : null,
    h('section', { class: 'cartao' },
      h('div', { class: 'resumo-contas' },
        h('div', null, h('span', null, 'Graves'), h('b', { class: a.alta ? 'ruim' : 'bom' }, a.alta)),
        h('div', null, h('span', null, 'Para conferir'), h('b', null, a.media)),
        h('div', null, h('span', null, 'Boletos em aberto'), h('b', null, `${resumo.boletosAbertos.qtd} · ${brl0(resumo.boletosAbertos.valor)}`)),
        h('div', null, h('span', null, 'Dinheiro em risco'), h('b', { class: a.valorEmRisco ? 'ruim' : 'bom' }, brl0(a.valorEmRisco)))),
      h('p', { class: 'dica' }, `Vencem em 7 dias: ${resumo.boletosSemana.qtd} boleto(s), ${brl0(resumo.boletosSemana.valor)}. Notas do mês: ${resumo.notasMes.qtd}, ${brl0(resumo.notasMes.valor)}. Custo de peças já ligado às OS pelas notas: ${brl0(resumo.custoLigadoAOs)}.`)),
    grupos.length ? grupos.map(([sev, lista]) => h('section', { class: 'cartao' },
      h('h2', null, `${TITULO[sev]} (${lista.length})`), listaComAbrir(lista.slice(0, estado.verTudo?.[sev] ? lista.length : 40), recarregar),
      lista.length > 40 && !estado.verTudo?.[sev] ? h('button', { class: 'pequeno', onclick: () => { (estado.verTudo ??= {})[sev] = true; recarregar(); } }, `Mostrar as outras ${lista.length - 40}`) : null))
      : h('section', { class: 'cartao' }, h('p', { class: 'bom' }, 'Tudo conferido: nenhuma ocorrência aberta.')),
    oc.aceitas.length ? h('details', { class: 'cartao' }, h('summary', null, `Conferidas e aceitas (${oc.aceitas.length})`),
      oc.aceitas.slice(0, 50).map((o) => h('div', { class: 'linha-simples' }, h('div', null, h('b', null, o.titulo), h('small', null, `${o.aceita.motivo} · ${dataBR(o.aceita.em.slice(0, 10))} ${o.aceita.em.slice(11, 16)}`)),
        ehDono() ? h('button', { class: 'pequeno', onclick: acao(async () => { await DEL(`/compras/ocorrencias/aceite?chave=${encodeURIComponent(o.chave)}`); recarregar(); }) }, 'Desfazer') : null))) : null);
}

// cada ocorrência ganha um botão que abre a nota, o boleto ou a OS relacionada
function listaComAbrir(lista, recarregar) {
  return h('div', { class: 'lista' }, lista.map((o) => {
    const bloco = listaOcorrencias([o], recarregar);
    const alvo = o.entidade === 'boleto' ? acao(() => modalBoleto(o.id, recarregar)) : (o.entidade === 'nota' ? acao(() => modalNota(o.id, recarregar)) : (o.notas?.length ? acao(() => modalNota(o.notas[0], recarregar)) : null));
    if (alvo) bloco.querySelector('.botoes')?.prepend(h('button', { class: 'pequeno primario', onclick: alvo }, o.entidade === 'boleto' ? 'Abrir boleto' : 'Abrir nota'));
    return bloco;
  }));
}

async function abaBoletos(el, estado, recarregar) {
  const filtro = estado.filtroBoletos ?? (estado.filtroBoletos = 'aberto');
  const qs = filtro === 'sem_nota' ? '?sem_nota=1' : filtro === 'todos' ? '' : `?situacao=${filtro}`;
  const lista = await GET(`/compras/boletos${qs}`);
  const SEL = { aberto: 'Em aberto', sem_nota: 'Sem nota', pago: 'Pagos', todos: 'Todos' };
  montar(el,
    h('div', { class: 'botoes-topo' }, h('div', { class: 'abas-mini' }, Object.entries(SEL).map(([k, t]) => h('button', { class: filtro === k ? 'ativo' : '', onclick: () => { estado.filtroBoletos = k; recarregar(); } }, t))),
      h('div', { class: 'acoes' }, h('button', { onclick: acao(() => modalBoletosEmLote(recarregar)) }, 'Colar vários'), h('button', { class: 'primario', onclick: acao(() => modalNovoBoleto(recarregar)) }, '+ Boleto'))),
    lista.length ? h('div', { class: 'lista' }, lista.map((b) => h('button', { class: 'linha-os', onclick: acao(() => modalBoleto(b.id, recarregar)) },
      h('div', null, h('b', null, b.fornecedor ?? 'Fornecedor não informado')),
      h('div', { class: 'sub' }, selo(b.veredito.curto, { ruim: 'critico', atencao: 'aviso', ok: 'ok', neutro: 'neutro' }[b.veredito.nivel]), b.empresa_nome ? selo(`da ${b.empresa_nome}`, 'info') : null, b.dda === 'confirmado' || b.dda === 'no_dda' ? selo('no DDA', 'ok') : null),
      h('div', { class: 'sub' }, `vence ${dataBR(b.vencimento)}`, selo(b.situacao, b.situacao === 'pago' ? 'ok' : b.situacao === 'aberto' ? 'info' : 'neutro'), b.ligacoes ? null : selo('sem nota', 'critico')),
      h('div', { class: 'valores' }, h('b', null, brl(b.valor)), h('span', null, b.numero_documento ? `doc ${b.numero_documento}` : `${b.ocorrencias} ocorrência(s)`))))) : vazio('Nenhum boleto neste filtro. Cadastre cada boleto que chegar com "+ Boleto".'));
}

async function abaNotas(el, estado, recarregar) {
  const q = estado.buscaNotas ?? '';
  const lista = await GET(`/compras/notas${q ? `?q=${encodeURIComponent(q)}` : ''}`);
  const busca = h('input', { type: 'search', placeholder: 'Buscar nº, fornecedor ou chave…', value: q });
  let t;
  busca.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { estado.buscaNotas = busca.value; recarregar(); }, 350); });
  montar(el,
    h('div', { class: 'botoes-topo' }, busca, h('div', { class: 'acoes' }, h('button', { onclick: () => modalImportarXml(recarregar) }, 'Importar XML'), h('button', { class: 'primario', onclick: acao(() => modalNotaManual(recarregar)) }, '+ Nota sem XML'))),
    lista.length ? h('div', { class: 'lista' }, lista.map((n) => h('button', { class: 'linha-os', onclick: acao(() => modalNota(n.id, recarregar)) },
      h('div', null, h('b', null, `Nota ${n.numero} · ${n.fornecedor}`), n.situacao === 'cancelada' ? selo('cancelada', 'neutro') : n.finalidade === 'devolucao' ? selo('devolução', 'info') : null),
      h('div', { class: 'sub' }, dataBR(n.data_emissao), n.saldo > 0.05 && n.finalidade !== 'devolucao' && n.situacao === 'ativa' ? selo(`sem boleto ${brl0(n.saldo)}`, 'aviso') : null, n.sem_destino ? selo('peças sem destino', 'aviso') : null, n.origem === 'manual' ? selo('digitada', 'neutro') : null, n.empresa ? selo(`em nome da ${n.empresa}`, 'info') : null),
      h('div', { class: 'valores' }, h('b', null, brl(n.valor_total)), h('span', null, n.chave ? 'XML' : 'manual'))))) : vazio('Nenhuma nota. Importe os XML que os fornecedores mandam por e-mail.'));
}

async function abaFornecedores(el, recarregar) {
  const lista = await GET('/compras/fornecedores');
  montar(el,
    h('div', { class: 'botoes-topo' }, h('span', { class: 'dica' }, 'O CNPJ permite conferir se o beneficiário do boleto é o fornecedor.'), h('button', { class: 'primario', onclick: () => modalFornecedor(null, recarregar) }, '+ Fornecedor')),
    lista.length ? h('div', { class: 'lista' }, lista.map((f) => h('div', { class: 'linha-os' },
      h('button', { class: 'linha-conta-info', onclick: acao(() => modalExtratoFornecedor(f.id, recarregar)) },
        h('div', null, h('b', null, f.nome), f.principal ? selo('principal', 'info') : null, f.cnpj ? null : selo('sem CNPJ', 'aviso'), f.confirmado_em ? null : selo('não conferido', 'aviso'), f.ativo ? null : selo('inativo', 'neutro')),
        h('div', { class: 'sub' }, `${f.notas} nota(s) · comprado ${brl0(f.comprado)} · em aberto ${brl0(f.boletos_abertos)}`, f.boletos_sem_nota ? selo(`${f.boletos_sem_nota} boleto(s) sem nota`, 'critico') : null)),
      ehDono() ? h('div', { class: 'botoes' }, h('button', { class: 'pequeno', onclick: () => modalFornecedor(f, recarregar) }, 'Editar')) : null))) : vazio('Cadastre o fornecedor principal e os menores. Também são criados sozinhos ao importar um XML.'));
}

export async function compras(el, estado) {
  const aba = abasVisiveis().some(([k]) => k === estado.abaCompras) ? estado.abaCompras : (estado.abaCompras = 'auditoria');
  const corpo = h('div');
  const recarregar = () => compras(el, estado);
  montar(el,
    h('div', { class: 'botoes-topo' }, h('h2', { class: 'titulo-pagina' }, 'Compras')),
    h('div', { class: 'abas-mini' }, abasVisiveis().map(([k, t]) => h('button', { class: aba === k ? 'ativo' : '', onclick: () => { estado.abaCompras = k; recarregar(); } }, t))),
    corpo);
  montar(corpo, carregando());
  try {
    if (aba === 'auditoria') await abaAuditoria(corpo, estado, recarregar);
    else if (aba === 'boletos') await abaBoletos(corpo, estado, recarregar);
    else if (aba === 'notas') await abaNotas(corpo, estado, recarregar);
    else if (aba === 'grupo' && ehDono()) await abaGrupo(corpo, recarregar);
    else if (aba === 'dda' && ehDono()) await abaDda(corpo, recarregar);
    else await abaFornecedores(corpo, recarregar);
  } catch (e) { montar(corpo, vazio(e.message)); }
}
