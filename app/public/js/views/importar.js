import { h, brl0, mesCurto, toast, campo, entrada, lerForm, montar } from '../ui.js';
import { POST } from '../api.js';

export async function importar(el) {
  const saida = h('div');
  let csv = '';
  const arquivo = h('input', { type: 'file', accept: '.csv,text/csv' });
  const hoje = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
  const corte = campo('Considerar quitadas as OS até (data de corte)', entrada('quitadasAte', hoje.slice(0, 8) + '01', { type: 'date' }), 'Tudo até essa data entra como já recebido, para o controle começar zerado. Depois lance o saldo anterior dos clientes que ainda devem.');
  const form = h('form', { class: 'formulario cartao' },
    h('h2', null, 'Importar a planilha CONTROLE SERVIÇOS'),
    h('ol', { class: 'passos' },
      h('li', null, 'Na planilha, abra a aba SERVIÇOS e use Arquivo > Fazer download > Valores separados por vírgula (.csv).'),
      h('li', null, 'Escolha o arquivo aqui e confira a prévia: os totais por mês devem bater com a planilha.'),
      h('li', null, 'Defina a data de corte e importe. Pode reimportar depois: OS já importadas não duplicam.')),
    campo('Arquivo CSV', arquivo), corte, saida,
    h('div', { class: 'botoes' }, h('button', { type: 'button', id: 'previa' }, 'Ver prévia'), h('button', { type: 'submit', class: 'primario' }, 'Importar')));
  const ler = async () => {
    const f = arquivo.files[0];
    if (!f) throw new Error('Escolha o arquivo CSV.');
    if (f.size > 5_000_000) throw new Error('Arquivo grande demais.');
    csv = await f.text();
  };
  form.querySelector('#previa').addEventListener('click', async () => {
    try {
      await ler();
      const p = await POST('/importar/previa', { csv });
      montar(saida, 
        h('p', null, `${p.linhas} linhas (${p.orcamentos} orçamentos). ${p.datasEstimadas} tiveram a data corrigida pelas vizinhas.`),
        h('div', { class: 'rolagem' }, h('table', { class: 'tabela' }, h('thead', null, h('tr', null, h('th', null, 'Mês'), h('th', null, 'Faturamento'))),
          h('tbody', null, Object.entries(p.faturamentoPorMes).sort().map(([m, v]) => h('tr', null, h('td', null, mesCurto(m)), h('td', { class: 'num' }, brl0(v))))))),
        p.avisos.length ? h('ul', null, p.avisos.slice(0, 10).map((a) => h('li', null, a))) : null);
    } catch (e) { toast(e.message, true); }
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await ler();
      const d = lerForm(form);
      const r = await POST('/importar', { csv, quitadasAte: d.quitadasAte || null });
      montar(saida, h('p', { class: 'bom' }, `Pronto: ${r.novas} OS novas (${r.jaExistiam} já existiam), ${r.orcamentos} orçamentos, ${r.clientesNovos} clientes e ${r.mecanicosNovos} mecânicos criados, ${r.quitadasPorCorte} OS quitadas pelo corte.`));
      toast('Importação concluída.');
    } catch (err) { toast(err.message, true); }
  });
  montar(el, form);
}
