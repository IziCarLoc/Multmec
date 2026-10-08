// DDA (Débito Direto Autorizado): a lista que o BANCO tem de todo boleto registrado contra o CNPJ da empresa.
// O dono exporta do internet banking e importa aqui. Como o arquivo vem do banco (e não de quem cadastra os boletos), ele é a conferência independente:
// boleto no DDA que ninguém cadastrou, ou cadastrado diferente do que o banco mostra, vira ocorrência; e boleto que bate com o DDA
// já tem o beneficiário confirmado pelo banco.
//
// Formatos aceitos: CNAB 240 do DDA (leiaute FEBRABAN, segmentos G/H/Y-03, igual no Itaú e no Banrisul), CSV/planilha de qualquer banco
// (acha as colunas pelo nome) e texto colado (acha a linha digitável/código de barras e os CNPJ ao redor).
import { interpretarBoleto } from './boleto.js';
import { acharLinhaNoTexto } from './boleto_texto.js';
import { normalizarCnpj, cnpjValido, formatarCnpj } from './documentos.js';
import { ErroValidacao } from './validar.js';
import { r2, diasEntre, somarDias } from './util.js';
import { registrar, perfilAtual } from './trilha.js';
import { lerConfig } from './db.js';

const REGEX_CNPJ = /(?<![0-9A-Za-z])(?:\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}|[0-9A-Za-z]{2}\.[0-9A-Za-z]{3}\.[0-9A-Za-z]{3}\/[0-9A-Za-z]{4}-\d{2})(?![0-9A-Za-z])/g;
/** CNPJs válidos do trecho (numéricos e alfanuméricos), na ordem em que aparecem. */
const cnpjsDe = (trecho) => [...String(trecho).matchAll(REGEX_CNPJ)].map((m) => normalizarCnpj(m[0])).filter(cnpjValido);
const semAcento = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const ddmmaaaa = (s) => {
  const m = /^(\d{2})(\d{2})(\d{4})$/.exec(s);
  // 11111111 = "à vista" e 99999999 = "contra apresentação" no leiaute; ano fora de 2000-2100 não é vencimento
  if (!m || Number(m[3]) < 2000 || Number(m[3]) > 2100) return null;
  const iso = `${m[3]}-${m[2]}-${m[1]}`;
  const d = new Date(`${iso}T12:00:00Z`);
  return Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== iso ? null : iso;
};
/** Banco + campo livre (posições 1-3 e 20-44 do código de barras): identifica o título mesmo que valor ou vencimento tenham sido mexidos. */
export const chaveDoCodigo = (codigo) => (codigo && codigo.length === 44 ? `${codigo.slice(0, 3)}|${codigo.slice(19)}` : null);

// ------------------------------------------------------------------ CNAB 240

const eCnab240 = (linhas) => linhas.some((l) => l.length >= 230 && l.length <= 242 && /^\d{3}\d{4}3.{5}G/.test(l.padEnd(240).slice(0, 14)));

function lerCnab240(linhas, hojeStr) {
  const titulos = [];
  const avisos = [];
  let cnpjArquivo = null;
  let geradoEm = null;
  let atual = null;
  let loteCnpj = null;                                  // um lote por CNPJ (matriz e filiais): cada título pertence ao CNPJ do lote em que está
  for (const bruta of linhas) {
    const l = bruta.replace(/\x1a/g, '').padEnd(240);
    const tipo = l[7];
    if (tipo === '0') {
      if (l[17] === '2') cnpjArquivo = l.slice(18, 32);
      geradoEm = ddmmaaaa(l.slice(143, 151));
    } else if (tipo === '1') {
      loteCnpj = l[17] === '2' ? l.slice(19, 33) : null;
    } else if (tipo === '3') {
      const segmento = l[13];
      if (segmento === 'G') {
        if (atual) titulos.push(atual);
        const codigo = l.slice(17, 61).replace(/\D/g, '');
        const movimento = l.slice(15, 17);
        const cedTipo = l[61];
        const cedDoc = l.slice(62, 77);
        atual = {
          formato: 'cnab240', movimento, lote_cnpj: loteCnpj, codigo_barras: codigo.length === 44 ? codigo : null,
          beneficiario_cnpj: cedTipo === '2' ? cedDoc.slice(1) : null, beneficiario_nome: l.slice(77, 107).trim() || null,
          vencimento: ddmmaaaa(l.slice(107, 115)), valor: Number(l.slice(115, 130)) / 100, moeda: l.slice(145, 147),
          numero_documento: l.slice(147, 162).trim() || null, emissao: ddmmaaaa(l.slice(181, 189)),
        };
      } else if (segmento === 'H' && atual) {
        if (l[17] === '2') atual.sacador_cnpj = l.slice(19, 33);
        atual.sacador_nome = l.slice(33, 73).trim() || null;
      } else if (segmento === 'Y' && atual && l.slice(17, 19) === '03') {
        if (l[19] === '2') atual.pagador_cnpj = l.slice(21, 35);
      }
    }
  }
  if (atual) titulos.push(atual);
  const saida = [];
  // o arquivo pode trazer a história do título (entrada, alteração, baixa): vale a última movimentação de cada código de barras
  const ultimo = new Map();
  titulos.forEach((t, i) => { if (t.codigo_barras) ultimo.set(t.codigo_barras, i); });
  for (const [i, t] of titulos.entries()) {
    if (t.codigo_barras && ultimo.get(t.codigo_barras) !== i) continue;
    if (t.movimento === '02') continue;                       // baixa do título pelo cedente: não vale mais
    if (t.moeda && t.moeda !== '09' && t.moeda !== '00') { avisos.push(`Título de ${t.beneficiario_nome ?? 'beneficiário'} em moeda diferente de real foi ignorado.`); continue; }
    if (t.codigo_barras) {
      const b = interpretarBoleto(t.codigo_barras, hojeStr);
      if (b.ok) {
        t.banco = b.banco;
        if (b.valor > 0 && Math.abs(b.valor - t.valor) > 0.004) { avisos.push(`Título ${t.numero_documento ?? t.codigo_barras.slice(-8)}: o valor do arquivo (${t.valor.toFixed(2)}) difere do valor do código de barras (${b.valor.toFixed(2)}). Valeu o do código de barras.`); t.valor = b.valor; }
        if (b.vencimento && t.vencimento && b.vencimento !== t.vencimento) avisos.push(`Título ${t.numero_documento ?? t.codigo_barras.slice(-8)}: o vencimento do arquivo difere do que o código de barras indica.`);
        t.vencimento ??= b.vencimento;
      } else { avisos.push(`Um código de barras do arquivo não passou na validação (${b.erros?.[0] ?? 'inválido'}).`); t.codigo_barras = null; }
    }
    if (!t.vencimento || !(t.valor > 0)) { avisos.push('Um título do arquivo veio sem vencimento ou sem valor e foi ignorado.'); continue; }
    for (const k of ['beneficiario_cnpj', 'sacador_cnpj', 'pagador_cnpj', 'lote_cnpj']) if (t[k] && !cnpjValido(t[k])) t[k] = null;
    saida.push(t);
  }
  return { formato: 'cnab240', titulos: saida, avisos, cnpjArquivo: cnpjArquivo && cnpjValido(cnpjArquivo) ? cnpjArquivo : null, geradoEm };
}

// ------------------------------------------------------------------ CSV e texto

function dividirCsv(texto) {
  const primeira = texto.split(/\r?\n/).find((l) => l.trim()) ?? '';
  const delim = [';', '\t', '|', ','].map((d) => [d, primeira.split(d).length]).sort((a, b) => b[1] - a[1])[0];
  if (!delim || delim[1] < 3) return null;
  const d = delim[0];
  const linhas = [];
  let campo = '';
  let linha = [];
  let aspas = false;
  const fecharLinha = () => { linha.push(campo); campo = ''; if (linha.some((c) => c.trim())) linhas.push(linha); linha = []; };
  for (let i = 0; i < texto.length; i++) {
    const c = texto[i];
    if (aspas) { if (c === '"' && texto[i + 1] === '"') { campo += '"'; i++; } else if (c === '"') aspas = false; else campo += c; }
    else if (c === '"') aspas = true;
    else if (c === d) { linha.push(campo); campo = ''; }
    else if (c === '\n') fecharLinha();
    else if (c !== '\r') campo += c;
  }
  if (campo || linha.length) fecharLinha();
  return linhas;
}

const COLUNAS = {
  codigo: /c[oó]d.*barra|linha.*digit|barra/i,
  beneficiario: /benefici|cedente|favorecido|credor|raz[aã]o|emitente|nome/i,
  cnpj: /cnpj|cpf|inscri/i,
  valor: /valor|total/i,
  vencimento: /venc/i,
  documento: /documento|n[uú]m.*doc|n[ºo°]\s*doc|\bnf\b/i,
};

function dinheiro(v) {
  const s = String(v ?? '').replace(/[R$\s]/g, '');
  if (!s) return null;
  // "1.250" é mil duzentos e cinquenta (ponto de milhar); "1250.50" é decimal com ponto; "1.250,50" é o formato brasileiro
  const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : (/^\d{1,3}(\.\d{3})+$/.test(s) ? s.replace(/\./g, '') : s));
  return Number.isFinite(n) && n >= 0 ? n : null;
}
function dataQualquer(v) {
  const s = String(v ?? '').trim();
  let iso = null;
  let m = /^(\d{2})\/(\d{2})\/(\d{4}|\d{2})(?!\d)/.exec(s);
  if (m) iso = `${m[3].length === 2 ? `20${m[3]}` : m[3]}-${m[2]}-${m[1]}`;
  else if ((m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s))) iso = `${m[1]}-${m[2]}-${m[3]}`;
  const d = iso ? new Date(`${iso}T12:00:00Z`) : null;
  return d && !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === iso ? iso : null;     // data que não existe (31/02) não vale
}

function tituloDeCodigo(linha, hojeStr) {
  const achado = acharLinhaNoTexto(linha, hojeStr);
  if (!achado) return null;
  return { codigo_barras: achado.codigoBarras ?? null, banco: achado.banco ?? null, valor: achado.valor ?? null, vencimento: achado.vencimento ?? null };
}

function lerCsv(linhas, hojeStr) {
  const avisos = [];
  const cab = linhas[0].map((c) => semAcento(c));
  const col = {};
  for (const [nome, re] of Object.entries(COLUNAS)) {
    const i = cab.findIndex((c, k) => re.test(c) && !Object.values(col).includes(k));
    if (i >= 0) col[nome] = i;
  }
  const temCabecalho = linhas[0].every((c) => !/^\d[\d.,/ -]*$/.test(c.trim())) && ('valor' in col || 'vencimento' in col || 'codigo' in col);
  const dados = temCabecalho ? linhas.slice(1) : linhas;
  if (!temCabecalho) avisos.push('Não achei a linha de títulos das colunas; li cada linha procurando o código de barras ou a linha digitável.');
  const titulos = [];
  for (const cel of dados) {
    const linhaTxt = cel.join(' ; ');
    const doCodigo = (temCabecalho && col.codigo !== undefined ? tituloDeCodigo(cel[col.codigo], hojeStr) : null) ?? tituloDeCodigo(linhaTxt, hojeStr);
    const cnpjs = cnpjsDe(linhaTxt);
    const cnpjColuna = temCabecalho && col.cnpj !== undefined ? normalizarCnpj(cel[col.cnpj]) : null;
    const t = {
      formato: 'csv',
      codigo_barras: doCodigo?.codigo_barras ?? null, banco: doCodigo?.banco ?? null,
      valor: doCodigo?.valor > 0 ? doCodigo.valor : (temCabecalho && col.valor !== undefined ? dinheiro(cel[col.valor]) : null),
      vencimento: doCodigo?.vencimento ?? (temCabecalho && col.vencimento !== undefined ? dataQualquer(cel[col.vencimento]) : null),
      // havendo coluna de CNPJ, vale só ela (pessoa física ou CNPJ inválido ficam sem CNPJ: nunca pegar o do sacador ou de outra coluna da linha)
      beneficiario_cnpj: temCabecalho && col.cnpj !== undefined ? (cnpjColuna && cnpjValido(cnpjColuna) ? cnpjColuna : null) : (cnpjs[0] ?? null),
      beneficiario_nome: temCabecalho && col.beneficiario !== undefined ? String(cel[col.beneficiario] ?? '').trim().slice(0, 60) || null : null,
      numero_documento: temCabecalho && col.documento !== undefined ? String(cel[col.documento] ?? '').trim().slice(0, 30) || null : null,
    };
    if (!(t.valor > 0) || !t.vencimento) continue;            // linha de totais, de rodapé ou sem dados
    titulos.push(t);
  }
  return { formato: 'csv', titulos, avisos, cnpjArquivo: null, geradoEm: null };
}

function lerTextoLivre(texto, hojeStr) {
  const titulos = [];
  const avisos = [];
  const linhas = texto.split(/\r?\n/);
  // cada título é o trecho entre um código e o seguinte; os CNPJ do trecho (o primeiro é o de quem recebe)
  const marcas = [];
  for (let i = 0; i < linhas.length; i++) {
    const t = tituloDeCodigo(linhas[i], hojeStr);
    if (t) marcas.push({ i, t });
  }
  let semDados = 0;
  marcas.forEach((m, k) => {
    // o CNPJ de quem recebe costuma vir antes do código, na mesma linha ou perto; nunca depois (seria do título seguinte) e nunca passando por outro código
    const ini = k === 0 ? Math.max(0, m.i - 2) : Math.max(marcas[k - 1].i + 1, m.i - 2);
    const cnpjs = cnpjsDe(linhas.slice(ini, m.i + 1).join(' ; '));
    if (!(m.t.valor > 0) || !m.t.vencimento) { semDados += 1; return; }
    titulos.push({ formato: 'texto', ...m.t, beneficiario_cnpj: cnpjs.length === 1 ? cnpjs[0] : null, beneficiario_nome: null, numero_documento: null });
  });
  if (semDados) avisos.push(`${semDados} código(s) de barras sem valor ou sem vencimento foram ignorados.`);
  if (!titulos.length) avisos.push('Não encontrei nenhum código de barras nem linha digitável no texto.');
  else avisos.push('Li o texto procurando códigos de barras: confira o CNPJ de quem recebe, que pode não ter sido identificado em todos.');
  return { formato: 'texto', titulos, avisos, cnpjArquivo: null, geradoEm: null };
}

/** Lê o conteúdo de um arquivo ou texto de DDA. Devolve só o que conseguiu entender; o resto vira aviso. */
export function lerExportacaoDda(conteudo, hojeStr) {
  const txt = String(conteudo ?? '').replace(/^﻿/, '');
  const linhas = txt.split(/\r?\n/).filter((l) => l.trim());
  if (!linhas.length) throw new ErroValidacao('O arquivo está vazio.');
  let r;
  if (eCnab240(linhas)) r = lerCnab240(linhas, hojeStr);
  else {
    const csv = dividirCsv(txt);
    r = csv && csv.length >= 2 ? lerCsv(csv, hojeStr) : lerTextoLivre(txt, hojeStr);
    if (!r.titulos.length && csv) r = lerTextoLivre(txt, hojeStr);
  }
  // sem duplicados dentro do próprio arquivo
  const vistos = new Set();
  r.titulos = r.titulos.filter((t) => {
    const k = chaveDoTitulo(t);
    if (vistos.has(k)) return false;
    vistos.add(k);
    return true;
  });
  return r;
}

export const chaveDoTitulo = (t) => t.codigo_barras ?? `${t.valor.toFixed(2)}|${t.vencimento}|${t.beneficiario_cnpj ?? ''}`;

// ------------------------------------------------------------------ importação e cruzamento com os boletos

/** Qual empresa é dona do DDA: o CNPJ do cabeçalho do arquivo manda; sem ele, vale o que a pessoa escolheu (padrão: a oficina). */
function escopoDoArquivo(db, cfg, cnpjArquivo, empresaId) {
  if (!cnpjArquivo) return empresaId ?? null;
  const raiz = (c) => String(c).slice(0, 8);
  if (cfg.cnpjOficina && raiz(cnpjArquivo) === raiz(cfg.cnpjOficina)) {
    if (empresaId) throw new ErroValidacao('Este DDA é do CNPJ da própria oficina, mas você escolheu uma empresa do grupo. Escolha "oficina".');
    return null;
  }
  const emp = db.prepare('SELECT id, nome, cnpj FROM empresas_grupo WHERE ativo = 1').all().find((e) => raiz(e.cnpj) === raiz(cnpjArquivo)) ?? null;
  if (emp) {
    if (empresaId && empresaId !== emp.id) throw new ErroValidacao(`Este DDA é da ${emp.nome}, mas você escolheu outra empresa.`);
    return emp.id;
  }
  throw new ErroValidacao(`Este DDA é do CNPJ ${cnpjArquivo}, que não é o da oficina (Metas) nem de uma empresa cadastrada em Compras > Grupo. Confira o arquivo ou cadastre a empresa.`);
}

export function importarDda(db, { conteudo, arquivo = null, empresaId = null }, hojeStr, cfg = lerConfig(db)) {
  if (empresaId && !db.prepare('SELECT 1 FROM empresas_grupo WHERE id = ?').get(empresaId)) throw new ErroValidacao('Empresa não encontrada.');
  const lido = lerExportacaoDda(conteudo, hojeStr);
  if (!lido.titulos.length) throw new ErroValidacao(`Não encontrei boletos neste arquivo. ${lido.avisos[0] ?? ''}`.trim());
  // o CNPJ de cada lote manda; arquivo sem CNPJ (CSV, texto) vale para a empresa que a pessoa escolheu (padrão: a oficina)
  const cnpjs = [...new Set(lido.titulos.map((t) => t.lote_cnpj ?? lido.cnpjArquivo).filter(Boolean))];
  const porEscopo = new Map();
  const ignorados = new Map();                                 // CNPJ desconhecido -> quantos títulos
  let primeiroErro = null;
  for (const t of lido.titulos) {
    const cnpj = t.lote_cnpj ?? lido.cnpjArquivo;
    let esc;
    try { esc = cnpj ? escopoDoArquivo(db, cfg, cnpj, cnpjs.length === 1 ? empresaId : null) : (empresaId ?? null); } catch (e) {
      if (!(e instanceof ErroValidacao) || cnpjs.length === 1) throw e;           // arquivo de um CNPJ só: o erro vale; com vários lotes, um lote estranho não derruba os outros
      primeiroErro ??= e;
      ignorados.set(cnpj, (ignorados.get(cnpj) ?? 0) + 1);
      continue;
    }
    (porEscopo.get(esc) ?? porEscopo.set(esc, []).get(esc)).push(t);
  }
  if (!porEscopo.size && primeiroErro) throw primeiroErro;
  for (const [cnpj, n] of ignorados) lido.avisos.push(`Ignorei ${n} título(s) do CNPJ ${cnpj}: não é o da oficina nem o de uma empresa cadastrada em Compras > Grupo.`);
  const resultado = { importacoes: [], preenchidos: [], novos: 0 };
  db.transaction(() => {
    for (const [escopoEmpresa, titulos] of porEscopo) {
      const escopo = escopoEmpresa ?? 0;
      let novos = 0;
      // a data do envio é a "de hoje" do sistema (a mesma que o resto usa), não a do relógio do banco de dados
      const impId = Number(db.prepare('INSERT INTO dda_importacoes (em, empresa_id, arquivo, formato, gerado_em, qtd, importado_por) VALUES (?, ?, ?, ?, ?, ?, ?)')
        .run(`${hojeStr} ${new Date().toISOString().slice(11, 19)}`, escopoEmpresa, arquivo ? String(arquivo).slice(0, 120) : null, lido.formato, lido.geradoEm, titulos.length, perfilAtual()).lastInsertRowid);
      const achar = db.prepare('SELECT id FROM dda_titulos WHERE escopo = ? AND chave = ?');
      const ins = db.prepare(`INSERT INTO dda_titulos (empresa_id, escopo, chave, codigo_barras, banco, valor, vencimento, beneficiario_cnpj, beneficiario_nome, sacador_cnpj, sacador_nome,
          pagador_cnpj, numero_documento, emissao, primeira_importacao_id, ultima_importacao_id, formato) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
      const upd = db.prepare(`UPDATE dda_titulos SET ultima_importacao_id = ?, banco = COALESCE(?, banco), beneficiario_cnpj = COALESCE(?, beneficiario_cnpj), beneficiario_nome = COALESCE(?, beneficiario_nome),
          sacador_cnpj = COALESCE(?, sacador_cnpj), sacador_nome = COALESCE(?, sacador_nome), pagador_cnpj = COALESCE(?, pagador_cnpj), numero_documento = COALESCE(?, numero_documento), formato = ? WHERE id = ?`);
      for (const t of titulos) {
        const chave = chaveDoTitulo(t);
        const ja = achar.get(escopo, chave);
        if (ja) upd.run(impId, t.banco ?? null, t.beneficiario_cnpj ?? null, t.beneficiario_nome ?? null, t.sacador_cnpj ?? null, t.sacador_nome ?? null, t.pagador_cnpj ?? null, t.numero_documento ?? null, t.formato ?? lido.formato, ja.id);
        else {
          ins.run(escopoEmpresa, escopo, chave, t.codigo_barras ?? null, t.banco ?? null, r2(t.valor), t.vencimento, t.beneficiario_cnpj ?? null, t.beneficiario_nome ?? null,
            t.sacador_cnpj ?? null, t.sacador_nome ?? null, t.pagador_cnpj ?? null, t.numero_documento ?? null, t.emissao ?? null, impId, impId, t.formato ?? lido.formato);
          novos++;
        }
      }
      db.prepare('UPDATE dda_importacoes SET novos = ? WHERE id = ?').run(novos, impId);
      // o banco informa quem recebe e quem paga: preenche o que quem cadastrou deixou em branco (nunca sobrescreve o que foi digitado)
      const preencher = db.prepare(`UPDATE boletos SET beneficiario_cnpj = COALESCE(beneficiario_cnpj, ?), beneficiario_nome = COALESCE(beneficiario_nome, ?), pagador_cnpj = COALESCE(pagador_cnpj, ?),
          numero_documento = COALESCE(numero_documento, ?) WHERE id = ? AND (beneficiario_cnpj IS NULL OR pagador_cnpj IS NULL OR beneficiario_nome IS NULL OR numero_documento IS NULL)`);
      for (const t of titulos) {
        if (!t.codigo_barras || lido.formato !== 'cnab240') continue;                  // só o arquivo estruturado do banco preenche o cadastro; CSV e texto são lidos por aproximação
        const b = db.prepare("SELECT id FROM boletos WHERE codigo_barras = ? AND situacao <> 'cancelado' AND COALESCE(empresa_id, 0) = ?").get(t.codigo_barras, escopo);
        if (b && preencher.run(t.beneficiario_cnpj ?? null, t.beneficiario_nome ?? null, t.pagador_cnpj ?? null, t.numero_documento ?? null, b.id).changes) {
          resultado.preenchidos.push(b.id);
          registrar(db, 'dda_preencheu', 'boleto', b.id, { beneficiario: t.beneficiario_cnpj, pagador: t.pagador_cnpj });
        }
      }
      registrar(db, 'dda_importado', 'dda', impId, { arquivo, formato: lido.formato, qtd: titulos.length, novos, empresa_id: escopoEmpresa });
      resultado.importacoes.push({ id: impId, empresa_id: escopoEmpresa, qtd: titulos.length, novos });
      resultado.novos += novos;
    }
  })();
  return { importacao_id: resultado.importacoes[0].id, importacoes: resultado.importacoes, formato: lido.formato, qtd: lido.titulos.length, novos: resultado.novos, avisos: lido.avisos,
    empresa_id: resultado.importacoes[0].empresa_id, preenchidos: resultado.preenchidos, cruzamento: cruzarDda(db, hojeStr, cfg) };
}

/** Dias que um boleto cadastrado precisa ter antes do arquivo para o sumiço do DDA valer alerta, e quão vencido pode estar. */
export const CARENCIA_DDA_DIAS = 2;
/** O DDA que dispensa a conferência no app do banco precisa ter chegado há no máximo isto; arquivos dos últimos dias valem juntos para dizer o que o banco mostra. */
export const VALIDADE_CONFIRMACAO_DIAS = 7;
export const JANELA_IMPORTACOES_DIAS = 7;
const reaisBR = (v) => Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const dataBRtxt = (d) => String(d).split('-').reverse().join('/');
export const JANELA_VENCIDOS_DIAS = 60;
const mesmoValor = (a, b, tol) => Math.abs(a - b) <= tol;

/**
 * Cruza o último DDA importado de cada empresa com os boletos cadastrados.
 * ok = bate; diverge = achou o boleto mas valor/vencimento/quem recebe diferem; sem_cadastro = o banco mostra e ninguém cadastrou;
 * fora_do_dda = cadastrado e em aberto, mas o banco não mostra (não aparece no DDA).
 */
export function cruzarDda(db, hojeStr, cfg = lerConfig(db)) {
  const tol = cfg.toleranciaValor ?? 0.05;
  const imps = db.prepare('SELECT * FROM dda_importacoes ORDER BY id').all();
  const ultimaPorEscopo = new Map();
  for (const i of imps) ultimaPorEscopo.set(i.empresa_id ?? 0, i);
  const out = { ok: [], diverge: [], sem_cadastro: [], fora_do_dda: [], importacoes: [...ultimaPorEscopo.values()].map((i) => ({ ...i, dias: diasEntre(i.em.slice(0, 10), hojeStr) })) };
  if (!ultimaPorEscopo.size) return out;
  const boletos = db.prepare(`SELECT b.*, f.nome AS fornecedor, f.cnpj AS fornecedor_cnpj, f.beneficiarios_autorizados AS fornecedor_autorizados, f.confirmado_em AS fornecedor_confirmado
      FROM boletos b LEFT JOIN fornecedores f ON f.id = b.fornecedor_id`).all();
  // índices: o cruzamento não pode ficar quadrático com milhares de títulos
  const porCodigo = new Map();
  const porChave = new Map();
  for (const b of boletos) {
    if (!b.codigo_barras) continue;
    (porCodigo.get(b.codigo_barras) ?? porCodigo.set(b.codigo_barras, []).get(b.codigo_barras)).push(b);
    const k = chaveDoCodigo(b.codigo_barras);
    if (k) (porChave.get(k) ?? porChave.set(k, []).get(k)).push(b);
  }
  // entre boletos do mesmo título, vale o que está vivo (em aberto ou pago) e, entre esses, o da mesma empresa
  const ordem = { aberto: 0, pago: 1, contestado: 2, cancelado: 3 };
  const escolher = (lista, escopo) => (lista ?? []).slice().sort((a, b) => (ordem[a.situacao] - ordem[b.situacao]) || (((a.empresa_id ?? 0) === escopo ? 0 : 1) - ((b.empresa_id ?? 0) === escopo ? 0 : 1)))[0] ?? null;
  const impPorId = new Map(imps.map((i) => [i.id, i]));
  const casados = new Set();
  for (const [escopo, imp] of ultimaPorEscopo) {
    // vale tudo o que os arquivos dos últimos 7 dias mostraram: uma exportação parcial (filtrada, ou texto de uma linha) não apaga o que o arquivo completo trouxe
    const desde = somarDias(imp.em.slice(0, 10), -JANELA_IMPORTACOES_DIAS);
    const ids = imps.filter((i) => (i.empresa_id ?? 0) === escopo && i.em.slice(0, 10) >= desde).map((i) => i.id);
    const titulos = db.prepare(`SELECT * FROM dda_titulos WHERE escopo = ? AND ultima_importacao_id IN (${ids.map(() => '?').join(',')}) ORDER BY vencimento, id`).all(escopo, ...ids);
    const dosEscopo = boletos.filter((b) => (b.empresa_id ?? 0) === escopo);
    for (const t of titulos) {
      // 1º o código de barras inteiro; 2º banco + campo livre (se mexeram no valor ou no vencimento do código, o título é o mesmo e a diferença aparece);
      // 3º, título sem código de barras (muitos bancos não mostram): valor + vencimento (+ CNPJ de quem recebe, quando os dois têm), nunca confirma sozinho
      let b = t.codigo_barras ? escolher(porCodigo.get(t.codigo_barras), escopo) : null;
      if (!b && t.codigo_barras) b = escolher(porChave.get(chaveDoCodigo(t.codigo_barras)), escopo);
      if (!b && !t.codigo_barras) {
        b = dosEscopo.find((x) => !casados.has(x.id) && ['aberto', 'pago'].includes(x.situacao) && x.vencimento === t.vencimento && mesmoValor(x.valor, t.valor, tol)
          && (!t.beneficiario_cnpj || !x.beneficiario_cnpj || t.beneficiario_cnpj === x.beneficiario_cnpj)) ?? null;
      }
      if (!b) { out.sem_cadastro.push({ titulo: t, escopo }); continue; }
      casados.add(b.id);
      const difs = [];
      if (!mesmoValor(b.valor, t.valor, tol)) difs.push(`valor: cadastrado ${reaisBR(b.valor)}, banco ${reaisBR(t.valor)}`);
      if (b.vencimento !== t.vencimento) difs.push(`vencimento: cadastrado ${dataBRtxt(b.vencimento)}, banco ${dataBRtxt(t.vencimento)}`);
      if (t.beneficiario_cnpj && b.beneficiario_cnpj && t.beneficiario_cnpj !== b.beneficiario_cnpj) difs.push(`quem recebe: cadastrado ${formatarCnpj(b.beneficiario_cnpj)}, banco ${formatarCnpj(t.beneficiario_cnpj)}`);
      if ((b.empresa_id ?? 0) !== escopo) difs.push('empresa: o banco mostra este boleto no DDA de outro CNPJ');
      // o banco diz quem recebe; só vale como "confirmado" se o fornecedor foi conferido pelo DONO e o CNPJ é o dele (ou um recebedor que o dono autorizou).
      // O CNPJ que consta nas notas não entra: quem lança é quem importa as notas.
      const autorizados = String(b.fornecedor_autorizados ?? '').split(',').filter(Boolean);
      const beneficiarioOk = !!t.beneficiario_cnpj && !!b.fornecedor_confirmado && (t.beneficiario_cnpj === b.fornecedor_cnpj || autorizados.includes(t.beneficiario_cnpj));
      const doArquivo = impPorId.get(t.ultima_importacao_id) ?? imp;
      const recente = diasEntre(doArquivo.em.slice(0, 10), hojeStr) <= VALIDADE_CONFIRMACAO_DIAS
        && (!doArquivo.gerado_em || diasEntre(doArquivo.gerado_em, doArquivo.em.slice(0, 10)) <= VALIDADE_CONFIRMACAO_DIAS);
      // só arquivo estruturado do banco (CNAB 240) dispensa conferir no app do banco: CSV e texto colado são lidos por aproximação
      const confirmado = beneficiarioOk && recente && t.formato === 'cnab240' && !!t.codigo_barras && t.codigo_barras === b.codigo_barras && ['aberto', 'pago'].includes(b.situacao);
      if (difs.length) out.diverge.push({ titulo: t, boleto: b, diferencas: difs });
      else out.ok.push({ titulo: t, boleto: b, beneficiarioOk, confirmado });
    }
    // cadastrado, em aberto, e que o banco não mostra (só vale se o DDA é recente e o boleto já existia antes de ele ser gerado)
    if (diasEntre(imp.em.slice(0, 10), hojeStr) <= 7) {
      for (const b of dosEscopo) {
        if (b.situacao !== 'aberto' || casados.has(b.id)) continue;
        if (b.codigo_barras?.startsWith('8') || String(b.linha_digitavel ?? '').startsWith('8')) continue;          // arrecadação/convênio não passa pelo DDA
        if (b.criado_em.slice(0, 10) > somarDias(imp.em.slice(0, 10), -CARENCIA_DDA_DIAS)) continue;               // cadastrado há pouco: o banco pode ainda não ter
        if (b.vencimento < somarDias(imp.em.slice(0, 10), -JANELA_VENCIDOS_DIAS)) continue;                         // vencido há muito: provavelmente já pago por fora
        out.fora_do_dda.push({ boleto: b, importacao: imp });
      }
    }
  }
  return out;
}

/** O boleto está num DDA recente do banco (arquivo CNAB), com o mesmo código, valor e vencimento, e quem recebe é o fornecedor conferido pelo dono? Só então dispensa conferir de novo no app. */
export function confirmadoNoDda(db, boleto, hojeStr, cfg = lerConfig(db)) {
  if (!boleto.codigo_barras) return false;
  return cruzarDda(db, hojeStr, cfg).ok.some((x) => x.boleto.id === boleto.id && x.confirmado);
}

/** Mapa boleto -> situação no DDA, para mostrar na lista e no veredito. */
export function statusDdaPorBoleto(db, hojeStr, cfg = lerConfig(db)) {
  const c = cruzarDda(db, hojeStr, cfg);
  const mapa = new Map();
  for (const x of c.ok) mapa.set(x.boleto.id, x.confirmado ? 'confirmado' : 'no_dda');
  for (const x of c.diverge) mapa.set(x.boleto.id, 'diverge');
  for (const x of c.fora_do_dda) mapa.set(x.boleto.id, 'fora');
  return mapa;
}

/** Empresas (oficina e do grupo) que ainda não têm DDA importado: boleto delas fica sem a conferência do banco. */
export function empresasSemDda(db) {
  const com = new Set(db.prepare('SELECT DISTINCT COALESCE(empresa_id, 0) AS e FROM dda_importacoes').all().map((x) => x.e));
  const lista = [{ id: null, nome: 'Oficina' }, ...db.prepare('SELECT id, nome FROM empresas_grupo WHERE ativo = 1 ORDER BY nome').all()];
  return lista.filter((e) => !com.has(e.id ?? 0));
}

export function resumoDda(db, hojeStr, cfg = lerConfig(db)) {
  const c = cruzarDda(db, hojeStr, cfg);
  return {
    importado: c.importacoes.length > 0, importacoes: c.importacoes,
    ok: c.ok.length, diverge: c.diverge.length, semCadastro: c.sem_cadastro.length, foraDoDda: c.fora_do_dda.length,
  };
}

