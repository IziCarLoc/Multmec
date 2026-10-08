import Database from 'better-sqlite3';
import { readFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));

export const CONFIG_PADRAO = {
  meta_faturamento: '100000',
  // Quanto do faturamento costuma ir para imposto sobre venda e taxa de maquininha.
  // Valores de planejamento: ~9% (R$ 70 mil/mês) a ~10% (R$ 100 mil/mês) é a carga estimada do Simples (Anexo III serviços + Anexo I peças);
  // usei 9,5%. Confirmar com o contador e com a proposta da maquininha.
  imposto_pct: '0.095',
  taxa_cartao_pct: '0.02',
  reserva_pct: '0.03',
  retirada_socios_meta: '15000',
  sabado_conta: '0',                 // 0 = sábado não entra nos dias úteis; 0.5 = meio dia
  feriados: '[]',                    // JSON ["2026-11-02", ...]
  saldo_caixa_inicial: '0',
  saldo_caixa_inicial_data: '',
  dias_trava: '15',                  // atraso máximo antes de travar cliente a prazo
  pct_aviso_limite: '0.8',
  margem_contribuicao_pct: '',       // vazio = calcular dos últimos meses
  // auditoria de compras
  cnpj_oficina: '',                  // CNPJ da oficina: confere destinatário da nota e pagador do boleto
  auditoria_desde: '',               // OS anteriores a esta data não geram "OS sem nota" (vazio = 1º dia do mês atual)
  tolerancia_valor: '0.05',          // diferença aceita entre boleto e nota (centavos de arredondamento)
  variacao_preco_pct: '0.15',        // alerta quando o preço unitário passa disso sobre compras anteriores
  dias_nota_sem_destino: '7',        // dias para dar destino às peças de uma nota
  dias_nota_sem_boleto: '5',         // dias antes do vencimento da parcela sem boleto correspondente
};

const CATEGORIAS = [
  ['Peças e insumos (fornecedores)', 'pecas', 1],
  ['Impostos (Simples/DAS, ISS)', 'imposto', 2],
  ['Taxas de maquininha', 'imposto', 3],
  ['Salários e encargos', 'folha', 4],
  ['Comissões de mecânicos', 'folha', 5],
  ['Aluguel', 'fixo', 6],
  ['Energia, água e internet', 'fixo', 7],
  ['Sistema, contador e licenças', 'fixo', 8],
  ['Ferramentas e manutenção da oficina', 'fixo', 9],
  ['Financiamentos e empréstimos', 'fixo', 10],
  ['Pró-labore dos sócios', 'socios', 11],
  ['Distribuição de lucros', 'socios', 12],
  ['Reserva da oficina', 'reserva', 13],
  ['Outros', 'outros', 14],
];

export function abrirBanco(caminho = process.env.DB_PATH || join(aqui, '..', 'data', 'multmec.db')) {
  if (caminho !== ':memory:') mkdirSync(dirname(caminho), { recursive: true });
  const db = new Database(caminho);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');
  db.exec(readFileSync(join(aqui, 'schema.sql'), 'utf8'));
  db.exec(readFileSync(join(aqui, 'schema_compras.sql'), 'utf8'));
  migrar(db);
  const insCfg = db.prepare('INSERT OR IGNORE INTO config (chave, valor) VALUES (?, ?)');
  for (const [k, v] of Object.entries(CONFIG_PADRAO)) insCfg.run(k, v);
  const insCat = db.prepare('INSERT OR IGNORE INTO categorias (nome, grupo, ordem) VALUES (?, ?, ?)');
  for (const c of CATEGORIAS) insCat.run(...c);
  return db;
}

/** Colunas novas em tabelas que já existiam (CREATE TABLE IF NOT EXISTS não altera tabela antiga). */
function migrar(db) {
  const colunas = (tabela) => db.prepare(`PRAGMA table_info(${tabela})`).all().map((c) => c.name);
  if (!colunas('notas_compra').includes('xml_gz')) db.exec('ALTER TABLE notas_compra ADD COLUMN xml_gz BLOB');
  if (!colunas('notas_compra').includes('valor_com_tributos')) db.exec('ALTER TABLE notas_compra ADD COLUMN valor_com_tributos REAL');
  if (!colunas('notas_compra').includes('pago_no_ato')) db.exec('ALTER TABLE notas_compra ADD COLUMN pago_no_ato INTEGER NOT NULL DEFAULT 0');
  if (!colunas('notas_compra').includes('cnpj_receb')) db.exec('ALTER TABLE notas_compra ADD COLUMN cnpj_receb TEXT');
  if (!colunas('fornecedores').includes('beneficiarios_autorizados')) db.exec('ALTER TABLE fornecedores ADD COLUMN beneficiarios_autorizados TEXT');
  if (!colunas('vendas').includes('custo_pecas_auto')) {
    db.exec('ALTER TABLE vendas ADD COLUMN custo_pecas_auto INTEGER NOT NULL DEFAULT 0');   // 1 = custo veio das notas
  }
}

export function lerConfig(db) {
  const cfg = {};
  for (const { chave, valor } of db.prepare('SELECT chave, valor FROM config').all()) cfg[chave] = valor;
  return {
    metaFaturamento: Number(cfg.meta_faturamento),
    impostoPct: Number(cfg.imposto_pct),
    taxaCartaoPct: Number(cfg.taxa_cartao_pct),
    reservaPct: Number(cfg.reserva_pct),
    retiradaSociosMeta: Number(cfg.retirada_socios_meta),
    sabadoConta: Number(cfg.sabado_conta),
    feriados: JSON.parse(cfg.feriados || '[]'),
    saldoCaixaInicial: Number(cfg.saldo_caixa_inicial || 0),
    saldoCaixaInicialData: cfg.saldo_caixa_inicial_data || '',
    diasTrava: Number(cfg.dias_trava),
    pctAvisoLimite: Number(cfg.pct_aviso_limite),
    margemContribuicaoPct: cfg.margem_contribuicao_pct === '' ? null : Number(cfg.margem_contribuicao_pct),
    cnpjOficina: cfg.cnpj_oficina || '',
    auditoriaDesde: cfg.auditoria_desde || '',
    toleranciaValor: Number(cfg.tolerancia_valor),
    variacaoPrecoPct: Number(cfg.variacao_preco_pct),
    diasNotaSemDestino: Number(cfg.dias_nota_sem_destino),
    diasNotaSemBoleto: Number(cfg.dias_nota_sem_boleto),
  };
}

export function gravarConfig(db, parcial) {
  const mapa = {
    metaFaturamento: 'meta_faturamento', impostoPct: 'imposto_pct', taxaCartaoPct: 'taxa_cartao_pct',
    reservaPct: 'reserva_pct', retiradaSociosMeta: 'retirada_socios_meta', sabadoConta: 'sabado_conta',
    feriados: 'feriados', saldoCaixaInicial: 'saldo_caixa_inicial', saldoCaixaInicialData: 'saldo_caixa_inicial_data',
    diasTrava: 'dias_trava', pctAvisoLimite: 'pct_aviso_limite', margemContribuicaoPct: 'margem_contribuicao_pct',
    cnpjOficina: 'cnpj_oficina', auditoriaDesde: 'auditoria_desde', toleranciaValor: 'tolerancia_valor',
    variacaoPrecoPct: 'variacao_preco_pct', diasNotaSemDestino: 'dias_nota_sem_destino', diasNotaSemBoleto: 'dias_nota_sem_boleto',
  };
  const up = db.prepare('INSERT INTO config (chave, valor) VALUES (?, ?) ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor');
  db.transaction(() => {
    for (const [k, v] of Object.entries(parcial)) {
      if (!mapa[k]) continue;
      const valor = k === 'feriados' ? JSON.stringify(v) : (v === null || v === undefined ? '' : String(v));
      up.run(mapa[k], valor);
    }
  })();
}
