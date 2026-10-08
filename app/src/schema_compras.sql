-- Auditoria de compras: fornecedor -> nota fiscal (NF-e) -> boleto -> OS.
-- Regra de ouro: todo boleto precisa estar ligado a uma nota; toda peça da nota precisa ter destino (OS, estoque, uso interno, devolvida).

CREATE TABLE IF NOT EXISTS fornecedores (
  id INTEGER PRIMARY KEY,
  nome TEXT NOT NULL UNIQUE COLLATE NOCASE,
  cnpj TEXT UNIQUE,                              -- 14 caracteres (numérico ou alfanumérico), sem pontuação
  principal INTEGER NOT NULL DEFAULT 0,
  ativo INTEGER NOT NULL DEFAULT 1,
  obs TEXT
);

CREATE TABLE IF NOT EXISTS notas_compra (
  id INTEGER PRIMARY KEY,
  fornecedor_id INTEGER NOT NULL REFERENCES fornecedores(id),
  chave TEXT UNIQUE,                             -- 44 caracteres; NULL em nota lançada à mão sem a chave
  numero TEXT NOT NULL,
  serie TEXT NOT NULL DEFAULT '',
  data_emissao TEXT NOT NULL,
  valor_total REAL NOT NULL,                     -- vNF: o que realmente se paga
  valor_com_tributos REAL,                       -- vNFTot (IBS/CBS por fora), só quando difere de vNF: o boleto pode vir por qualquer um
  valor_produtos REAL, valor_frete REAL, valor_desconto REAL,
  cnpj_emitente TEXT, nome_emitente TEXT,
  cnpj_destinatario TEXT, nome_destinatario TEXT,
  finalidade TEXT NOT NULL DEFAULT 'normal' CHECK (finalidade IN ('normal','complementar','ajuste','devolucao')),
  situacao TEXT NOT NULL DEFAULT 'ativa' CHECK (situacao IN ('ativa','cancelada')),
  protocolo_status TEXT,                         -- cStat do protocolo (100 = autorizada)
  natureza TEXT,
  info_compl TEXT,
  origem TEXT NOT NULL DEFAULT 'xml' CHECK (origem IN ('xml','manual')),
  xml_hash TEXT,
  xml_gz BLOB,                                   -- XML original comprimido: é a prova da nota (guardar por 5 anos)
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (fornecedor_id, numero, serie)
);
CREATE INDEX IF NOT EXISTS idx_notas_emissao ON notas_compra(data_emissao);

CREATE TABLE IF NOT EXISTS nota_duplicatas (
  id INTEGER PRIMARY KEY,
  nota_id INTEGER NOT NULL REFERENCES notas_compra(id) ON DELETE CASCADE,
  numero TEXT,
  vencimento TEXT NOT NULL,
  valor REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS nota_itens (
  id INTEGER PRIMARY KEY,
  nota_id INTEGER NOT NULL REFERENCES notas_compra(id) ON DELETE CASCADE,
  n_item INTEGER NOT NULL,
  codigo TEXT,
  descricao TEXT NOT NULL,
  ncm TEXT, cfop TEXT, unidade TEXT,
  quantidade REAL NOT NULL,
  valor_unitario REAL NOT NULL,
  valor_total REAL NOT NULL,                     -- vProd
  valor_desconto REAL NOT NULL DEFAULT 0,
  custo_total REAL NOT NULL,                     -- parte deste item no vNF (inclui frete, impostos e desconto rateados)
  x_ped TEXT,                                    -- nº do pedido que o cliente informou na compra (se a nota trouxer)
  UNIQUE (nota_id, n_item)
);
CREATE INDEX IF NOT EXISTS idx_itens_codigo ON nota_itens(codigo);

CREATE TABLE IF NOT EXISTS boletos (
  id INTEGER PRIMARY KEY,
  fornecedor_id INTEGER REFERENCES fornecedores(id),
  codigo_barras TEXT UNIQUE,                     -- 44 dígitos; identifica o boleto e barra duplicidade
  linha_digitavel TEXT,
  banco TEXT,
  valor REAL NOT NULL,
  vencimento TEXT NOT NULL,
  numero_documento TEXT,                         -- "Nº do documento" impresso (às vezes é o nº da nota)
  beneficiario_nome TEXT,
  beneficiario_cnpj TEXT,                        -- como aparece no app do banco ou no papel
  pagador_cnpj TEXT,
  situacao TEXT NOT NULL DEFAULT 'aberto' CHECK (situacao IN ('aberto','pago','contestado','cancelado')),
  saida_id INTEGER REFERENCES saidas(id) ON DELETE SET NULL,
  aprovado_motivo TEXT,                          -- liberado para pagar mesmo com ocorrência grave
  aprovado_em TEXT,
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_boletos_venc ON boletos(vencimento);

CREATE TABLE IF NOT EXISTS conciliacoes (        -- boleto <-> nota (um boleto pode cobrir várias notas e vice-versa)
  id INTEGER PRIMARY KEY,
  boleto_id INTEGER NOT NULL REFERENCES boletos(id) ON DELETE CASCADE,
  nota_id INTEGER NOT NULL REFERENCES notas_compra(id) ON DELETE CASCADE,
  duplicata_id INTEGER REFERENCES nota_duplicatas(id) ON DELETE SET NULL,
  valor REAL NOT NULL,                           -- quanto deste boleto paga esta nota
  origem TEXT NOT NULL DEFAULT 'auto' CHECK (origem IN ('auto','manual')),
  criado_em TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (boleto_id, nota_id)
);

CREATE TABLE IF NOT EXISTS alocacoes (           -- item da nota -> destino (OS, estoque, uso interno, devolvido)
  id INTEGER PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES nota_itens(id) ON DELETE CASCADE,
  destino TEXT NOT NULL DEFAULT 'os' CHECK (destino IN ('os','estoque','uso_interno','devolvido')),
  venda_id INTEGER REFERENCES vendas(id) ON DELETE CASCADE,
  quantidade REAL NOT NULL CHECK (quantidade > 0),
  valor REAL NOT NULL,                           -- custo alocado
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (destino <> 'os' OR venda_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_aloc_item ON alocacoes(item_id);
CREATE INDEX IF NOT EXISTS idx_aloc_venda ON alocacoes(venda_id);

CREATE TABLE IF NOT EXISTS auditoria_aceites (   -- "conferi e está certo", com o motivo (trilha de auditoria)
  chave TEXT PRIMARY KEY,                        -- tipo:entidade:id
  motivo TEXT NOT NULL,
  em TEXT NOT NULL DEFAULT (datetime('now'))
);
