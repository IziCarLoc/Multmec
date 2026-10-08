// DDA do banco: o dono importa o arquivo que o banco gera e o sistema mostra o que não bate com os boletos cadastrados.
import { h, brl, brl0, dataBR, selo, vazio, toast, modal, acao, campo, selecao, lerForm, montar, cnpjBR } from '../ui.js';
import { GET, POST } from '../api.js';
import { modalBoleto, modalNovoBoleto } from './compras_modais.js';

/** Lê o arquivo escolhido; arquivo de banco costuma vir em ISO-8859-1, não em UTF-8. */
async function lerArquivoTexto(arquivo) {
  const bytes = await arquivo.arrayBuffer();
  try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch { return new TextDecoder('windows-1252').decode(bytes); }
}

function modalImportar(empresas, aoMudar) {
  modal('Importar o DDA do banco', (fechar) => {
    const arquivo = h('input', { type: 'file', accept: '.rem,.ret,.txt,.csv,.cnab,.xls,text/*', name: 'arquivo' });
    const texto = h('textarea', { name: 'texto', rows: 4, placeholder: 'ou cole aqui o texto do DDA' });
    const f = h('form', { class: 'formulario' },
      h('ol', { class: 'passos' },
        h('li', null, 'No internet banking da empresa, abra o ', h('b', null, 'DDA'), ' (Débito Direto Autorizado) e exporte a lista de boletos: arquivo de remessa/retorno CNAB 240, CSV ou planilha.'),
        h('li', null, 'Escolha o arquivo abaixo (ou cole o texto). Quem confere é o dono: o arquivo vem do banco, não de quem cadastra.'),
        h('li', null, 'Repita toda semana: o sistema compara com os boletos cadastrados.')),
      campo('Arquivo do banco', arquivo),
      texto,
      empresas.length ? campo('De quem é este DDA?', selecao('empresaId', [['', 'Da oficina'], ...empresas.filter((e) => e.ativo).map((e) => [e.id, e.nome])], ''), 'Se o arquivo traz o CNPJ no cabeçalho (CNAB 240), vale o do arquivo.') : null,
      h('div', { class: 'botoes' }, h('button', { type: 'submit', class: 'primario' }, 'Importar e conferir')));
    f.addEventListener('submit', acao(async (e) => {
      e.preventDefault();
      const d = lerForm(f);
      const arq = arquivo.files[0];
      const conteudo = arq ? await lerArquivoTexto(arq) : d.texto;
      if (!String(conteudo ?? '').trim()) { toast('Escolha o arquivo ou cole o texto.', true); return; }
      const r = await POST('/compras/dda/importar', { conteudo, arquivo: arq?.name ?? null, empresaId: d.empresaId ? Number(d.empresaId) : null });
      toast(`${r.qtd} boleto(s) no DDA (${r.novos} novos): ${r.ok} batem, ${r.semCadastro} sem cadastro, ${r.divergentes} divergentes.`, r.semCadastro + r.divergentes > 0);
      fechar(); aoMudar();
    }));
    return f;
  });
}

export async function abaDda(el, recarregar) {
  const [d, empresas] = await Promise.all([GET('/compras/dda'), GET('/compras/empresas')]);
  const sem = d.semCadastro;
  montar(el,
    h('div', { class: 'botoes-topo' }, h('span', { class: 'dica' }, 'O DDA é a lista que o banco tem de todo boleto emitido contra o CNPJ de vocês.'), h('button', { class: 'primario', onclick: () => modalImportar(empresas, recarregar) }, 'Importar DDA')),
    d.importacoes.length ? h('section', { class: 'cartao' },
      h('div', { class: 'resumo-contas' },
        h('div', null, h('span', null, 'Batem'), h('b', { class: 'bom' }, d.conferidos)),
        h('div', null, h('span', null, 'Sem cadastro'), h('b', { class: sem.length ? 'ruim' : 'bom' }, sem.length)),
        h('div', null, h('span', null, 'Divergentes'), h('b', { class: d.divergentes.length ? 'ruim' : 'bom' }, d.divergentes.length)),
        h('div', null, h('span', null, 'Fora do DDA'), h('b', null, d.foraDoDda.length))),
      d.importacoes.map((i) => h('p', { class: i.dias > 7 ? 'aviso-texto' : 'dica' }, `${i.empresa}: importado em ${dataBR(i.em.slice(0, 10))} (${i.dias === 0 ? 'hoje' : `há ${i.dias} dia(s)`}), ${i.qtd} boleto(s)${i.dias > 7 ? '. Importe de novo.' : '.'}`)))
      : h('section', { class: 'cartao' }, h('h2', null, 'Ainda não importou o DDA'),
        h('p', null, 'É a conferência que ninguém da oficina consegue enganar: o arquivo vem do banco. Com ele o sistema:'),
        h('ul', { class: 'passos' },
          h('li', null, 'avisa de boleto que o banco mostra contra o CNPJ de vocês e que ninguém cadastrou;'),
          h('li', null, 'avisa de boleto cadastrado com valor, vencimento ou recebedor diferente do banco;'),
          h('li', null, 'preenche sozinho quem recebe e quem paga, e dispensa a conferência no app do banco para o boleto que bate.'))),
    sem.length ? h('section', { class: 'cartao' }, h('h2', null, `Está no banco e ninguém cadastrou (${sem.length})`),
      h('div', { class: 'lista' }, sem.map((t) => h('div', { class: 'ocorrencia alta' },
        h('div', { class: 'ocorrencia-topo' }, selo('GRAVE', 'critico'), h('b', null, t.beneficiario_nome ?? 'Beneficiário não informado'), h('span', { class: 'valor' }, brl(t.valor))),
        h('p', null, `${t.beneficiario_cnpj ? `CNPJ ${cnpjBR(t.beneficiario_cnpj)} · ` : ''}vence ${dataBR(t.vencimento)}${t.numero_documento ? ` · doc ${t.numero_documento}` : ''} · ${t.empresa}`),
        h('div', { class: 'botoes' }, t.codigo_barras ? h('button', { class: 'pequeno primario', onclick: acao(() => modalNovoBoleto(recarregar, { linha: t.codigo_barras, beneficiarioCnpj: t.beneficiario_cnpj, beneficiarioNome: t.beneficiario_nome, pagadorCnpj: t.pagador_cnpj, numeroDocumento: t.numero_documento })) }, 'Cadastrar este boleto') : h('small', { class: 'dica' }, 'O banco não mandou o código de barras: peça o boleto ao fornecedor.'))))),
      h('p', { class: 'dica' }, 'Não pague nenhum destes até ter a nota fiscal e ter confirmado com o fornecedor.')) : null,
    d.divergentes.length ? h('section', { class: 'cartao' }, h('h2', null, `Cadastrado diferente do banco (${d.divergentes.length})`),
      h('div', { class: 'lista' }, d.divergentes.map((t) => h('div', { class: 'ocorrencia alta' },
        h('div', { class: 'ocorrencia-topo' }, selo('GRAVE', 'critico'), h('b', null, t.fornecedor ?? t.beneficiario_nome ?? 'Boleto'), h('span', { class: 'valor' }, brl(t.valor))),
        h('p', null, t.diferencas.join('; ')),
        h('div', { class: 'botoes' }, h('button', { class: 'pequeno primario', onclick: acao(() => modalBoleto(t.boleto_id, recarregar)) }, 'Abrir boleto')))))) : null,
    d.foraDoDda.length ? h('section', { class: 'cartao' }, h('h2', null, `Cadastrado, mas o banco não mostra (${d.foraDoDda.length})`),
      h('p', { class: 'dica' }, 'Pode ser boleto sem registro, já pago, atrasado na atualização do banco ou falso. Confirme com o fornecedor.'),
      h('div', { class: 'lista' }, d.foraDoDda.map((b) => h('button', { class: 'linha-os', onclick: acao(() => modalBoleto(b.boleto_id, recarregar)) },
        h('div', null, h('b', null, b.fornecedor ?? 'Fornecedor não informado')), h('div', { class: 'sub' }, `vence ${dataBR(b.vencimento)} · ${b.empresa}`), h('div', { class: 'valores' }, h('b', null, brl(b.valor))))))) : null,
    d.importacoes.length && !sem.length && !d.divergentes.length && !d.foraDoDda.length ? vazio('Tudo que o banco mostra está cadastrado e confere.') : null);
  void brl0;
}
