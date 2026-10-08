-- Auditoria de compras: fornecedor -> nota fiscal (NF-e) -> boleto -> OS.
-- Regra de ouro: todo boleto precisa estar ligado a uma nota; toda peça da nota precisa ter destino (OS, estoque, uso interno, devolvida).

-- Empresas do mesmo dono/sócios além da oficina (locadora, oficina do sócio...): nota ou boleto no CNPJ delas não é golpe,
-- mas também não é despesa da oficina. A própria oficina é o CNPJ em Metas (cnpj_oficina).
CREATE TABLE IF NOT EXISTS empresas_grupo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  cnpj TEXT NOT NULL UNIQUE,                     -- 14 caracteres, sem pontuação
  papel TEXT NOT NULL DEFAULT 'outra' CHECK (papel IN ('locadora','socio','outra')),
  ativo INTEGER NOT NULL DEFAULT 1,
  criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS fornecedores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL UNIQUE COLLATE NOCASE,
  cnpj TEXT UNIQUE,                              -- 14 caracteres (numérico ou alfanumérico), sem pontuação
  principal INTEGER NOT NULL DEFAULT 0,
  ativo INTEGER NOT NULL DEFAULT 1,
  obs TEXT,
  confirmado_em TEXT,                            -- quando uma pessoa conferiu CNPJ e telefone deste fornecedor
  beneficiarios_autorizados TEXT                 -- outros CNPJs que podem receber os boletos deste fornecedor (filial, banco, factoring), separados por vírgula
);

CREATE TABLE IF NOT EXISTS notas_compra (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fornecedor_id INTEGER NOT NULL REFERENCES fornecedores(id),
  chave TEXT UNIQUE,                             -- 44 caracteres; NULL em nota lançada à mão sem a chave
  numero TEXT NOT NULL,
  serie TEXT NOT NULL DEFAULT '',
  data_emissao TEXT NOT NULL,
  valor_total REAL NOT NULL,                     -- vNF: o que realmente se paga
  pago_no_ato INTEGER NOT NULL DEFAULT 0,        -- a nota informa pagamento imediato (dinheiro, cartão, Pix) e não traz parcelas: não deve vir boleto
  cnpj_receb TEXT,                               -- CNPJ de quem recebe o boleto, quando a nota informa (detPag/card/CNPJReceb)
  empresa_id INTEGER REFERENCES empresas_grupo(id),   -- destinatário da nota, quando é outra empresa do grupo (NULL = oficina ou desconhecido)
  criado_por TEXT,                               -- perfil que cadastrou
  consulta_em TEXT,                              -- consulta da chave no portal da NF-e, feita por uma pessoa (prova da nota sem XML)
  consulta_situacao TEXT CHECK (consulta_situacao IN ('autorizada','cancelada','denegada','nao_encontrada')),
  consulta_valor REAL,                           -- valor que o portal mostrou
  consulta_por TEXT,
  valor_com_tributos REAL,                       -- vNFTot (IBS/CBS por fora), só quando difere de vNF: o boleto pode vir por qualquer um
  valor_produtos REAL, valor_frete REAL, valor_desconto REAL,
  cnpj_emitente TEXT, nome_emitente TEXT,
  cnpj_destinatario TEXT, nome_destinatario TEXT,
  finalidade TEXT NOT NULL DEFAULT 'normal' CHECK (finalidade IN ('normal','complementar','ajuste','devolucao')),
  situacao TEXT NOT NULL DEFAULT 'ativa' CHECK (situacao IN ('ativa','cancelada')),
  cancelada_por TEXT,                            -- 'sefaz' (protocolo ou evento: não pode ser desfeito aqui) ou 'manual'
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
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nota_id INTEGER NOT NULL REFERENCES notas_compra(id) ON DELETE CASCADE,
  numero TEXT,
  vencimento TEXT NOT NULL,
  valor REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS nota_itens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
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
  info_adic TEXT,                                -- informação adicional do item (muitos ERPs escrevem placa e OS aqui)
  UNIQUE (nota_id, n_item)
);
CREATE INDEX IF NOT EXISTS idx_itens_codigo ON nota_itens(codigo);

CREATE TABLE IF NOT EXISTS boletos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
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
  conferido_banco_em TEXT,                       -- quando alguém confirmou no app do banco quem recebe (nome e CNPJ)
  empresa_id INTEGER REFERENCES empresas_grupo(id),   -- NULL = boleto da oficina; senão, de outra empresa do grupo
  criado_por TEXT,                               -- perfil que cadastrou (dono ou lancamento)
  aprovado_motivo TEXT,                          -- liberado para pagar mesmo com ocorrência grave
  aprovado_em TEXT,
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_boletos_venc ON boletos(vencimento);

CREATE TABLE IF NOT EXISTS conciliacoes (        -- boleto <-> nota (um boleto pode cobrir várias notas e vice-versa)
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  boleto_id INTEGER NOT NULL REFERENCES boletos(id) ON DELETE CASCADE,
  nota_id INTEGER NOT NULL REFERENCES notas_compra(id) ON DELETE CASCADE,
  duplicata_id INTEGER REFERENCES nota_duplicatas(id) ON DELETE SET NULL,
  valor REAL NOT NULL,                           -- quanto deste boleto paga esta nota
  origem TEXT NOT NULL DEFAULT 'auto' CHECK (origem IN ('auto','manual')),
  criado_em TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (boleto_id, nota_id)
);

CREATE TABLE IF NOT EXISTS alocacoes (           -- item da nota -> destino (OS, estoque, uso interno, devolvido)
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL REFERENCES nota_itens(id) ON DELETE CASCADE,
  destino TEXT NOT NULL DEFAULT 'os' CHECK (destino IN ('os','estoque','uso_interno','devolvido','outra_empresa')),
  venda_id INTEGER REFERENCES vendas(id) ON DELETE CASCADE,
  empresa_id INTEGER REFERENCES empresas_grupo(id),   -- para destino 'outra_empresa'
  quantidade REAL NOT NULL CHECK (quantidade > 0),
  valor REAL NOT NULL,                           -- custo alocado
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (destino <> 'os' OR venda_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_aloc_item ON alocacoes(item_id);
CREATE INDEX IF NOT EXISTS idx_aloc_venda ON alocacoes(venda_id);

CREATE TABLE IF NOT EXISTS auditoria_aceites (   -- "conferi e está certo", com o motivo (situação atual; o histórico fica em auditoria_log)
  chave TEXT PRIMARY KEY,                        -- tipo:entidade:id
  motivo TEXT NOT NULL,
  estado TEXT NOT NULL DEFAULT '',               -- impressão digital do fato aceito (valor, boletos do grupo...): se o fato muda, o aceite deixa de valer
  em TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS auditoria_log (       -- só inclusão: nada aqui é alterado ou apagado
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  em TEXT NOT NULL DEFAULT (datetime('now')),
  perfil TEXT,                                   -- dono ou lancamento: quem fez (a senha é por perfil, não por pessoa)
  acao TEXT NOT NULL,                            -- aceitar, desfazer_aceite, pagar, pagar_liberado, desfazer_pagamento, cancelar, reabrir, conciliar, desconciliar, nota_situacao, nota_apagada...
  entidade TEXT,
  entidade_id INTEGER,
  detalhe TEXT
);
CREATE INDEX IF NOT EXISTS idx_log_entidade ON auditoria_log(entidade, entidade_id);

-- Acerto entre a oficina e as outras empresas do grupo (locadora, oficina do sócio).
-- a_receber: a oficina pagou ou forneceu algo que é da outra empresa e ainda não recebeu.
-- a_pagar: a outra empresa pagou algo que é da oficina.
CREATE TABLE IF NOT EXISTS adiantamentos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  empresa_id INTEGER NOT NULL REFERENCES empresas_grupo(id),
  sentido TEXT NOT NULL DEFAULT 'a_receber' CHECK (sentido IN ('a_receber','a_pagar')),
  origem TEXT NOT NULL DEFAULT 'manual' CHECK (origem IN ('boleto','peca','manual')),
  boleto_id INTEGER REFERENCES boletos(id) ON DELETE SET NULL,
  alocacao_id INTEGER,                           -- peça repassada (sem FK de propósito: a tabela de alocações pode ser refeita em migração)
  descricao TEXT NOT NULL,
  valor REAL NOT NULL CHECK (valor > 0),
  data TEXT NOT NULL,                            -- dia em que aconteceu
  caixa INTEGER NOT NULL DEFAULT 0,              -- 1 = saiu dinheiro da conta da oficina nesse dia (entra no cálculo do caixa)
  obs TEXT,
  criado_por TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_adiant_empresa ON adiantamentos(empresa_id);
CREATE TABLE IF NOT EXISTS adiantamento_baixas ( -- devoluções (parciais ou total)
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  adiantamento_id INTEGER NOT NULL REFERENCES adiantamentos(id) ON DELETE CASCADE,
  data TEXT NOT NULL,
  valor REAL NOT NULL CHECK (valor > 0),
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);

-- DDA (Débito Direto Autorizado): a lista que o BANCO tem de todo boleto registrado contra o CNPJ. O dono exporta do banco e importa aqui.
-- É a fonte independente: boleto que está no DDA e não foi cadastrado, ou cadastrado diferente do que o banco mostra, aparece como ocorrência.
CREATE TABLE IF NOT EXISTS dda_importacoes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  em TEXT NOT NULL DEFAULT (datetime('now')),
  empresa_id INTEGER REFERENCES empresas_grupo(id),   -- de quem é o DDA (NULL = oficina)
  arquivo TEXT,
  formato TEXT,                                  -- cnab240, csv ou texto
  gerado_em TEXT,                                -- data de geração que o próprio arquivo informa, quando informa
  qtd INTEGER NOT NULL DEFAULT 0,
  novos INTEGER NOT NULL DEFAULT 0,
  importado_por TEXT
);
CREATE TABLE IF NOT EXISTS dda_titulos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  empresa_id INTEGER REFERENCES empresas_grupo(id),
  escopo INTEGER NOT NULL DEFAULT 0,             -- empresa_id ou 0 (oficina): o UNIQUE não enxerga NULL
  chave TEXT NOT NULL,                           -- código de barras (44) ou, sem ele, valor|vencimento|CNPJ do beneficiário
  codigo_barras TEXT,
  banco TEXT,
  valor REAL NOT NULL,
  vencimento TEXT NOT NULL,
  beneficiario_cnpj TEXT,
  beneficiario_nome TEXT,
  sacador_cnpj TEXT,
  sacador_nome TEXT,
  pagador_cnpj TEXT,
  numero_documento TEXT,
  emissao TEXT,
  primeira_importacao_id INTEGER NOT NULL REFERENCES dda_importacoes(id),
  ultima_importacao_id INTEGER NOT NULL REFERENCES dda_importacoes(id),
  UNIQUE (escopo, chave)
);
CREATE INDEX IF NOT EXISTS idx_dda_codigo ON dda_titulos(codigo_barras);
