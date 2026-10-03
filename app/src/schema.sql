-- Multmec financeiro: esquema SQLite. Valores em reais (REAL, arredondados a 2 casas na aplicação).

CREATE TABLE IF NOT EXISTS config (
  chave TEXT PRIMARY KEY,
  valor TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS clientes (
  id INTEGER PRIMARY KEY,
  nome TEXT NOT NULL UNIQUE COLLATE NOCASE,
  -- avulso: paga na retirada | frota: empresa com prazo | locadora | revenda
  tipo TEXT NOT NULL DEFAULT 'avulso' CHECK (tipo IN ('avulso','frota','locadora','revenda')),
  prazo_dias INTEGER NOT NULL DEFAULT 0,        -- 0 = paga na retirada do carro
  limite_credito REAL NOT NULL DEFAULT 0,       -- máximo em aberto; 0 = sem crédito
  ativo INTEGER NOT NULL DEFAULT 1,
  obs TEXT
);

CREATE TABLE IF NOT EXISTS mecanicos (
  id INTEGER PRIMARY KEY,
  nome TEXT NOT NULL UNIQUE COLLATE NOCASE,
  ativo INTEGER NOT NULL DEFAULT 1
);

-- Uma linha por ordem de serviço (ou orçamento). O detalhamento fino fica no sistema de OS;
-- aqui entra só o que o financeiro precisa.
CREATE TABLE IF NOT EXISTS vendas (
  id INTEGER PRIMARY KEY,
  numero TEXT,                                  -- nº da OS no sistema de OS
  data TEXT NOT NULL,                           -- YYYY-MM-DD
  cliente_id INTEGER REFERENCES clientes(id),
  veiculo TEXT,
  placa TEXT,
  mecanico_id INTEGER REFERENCES mecanicos(id),
  -- saldo = dívida anterior ao sistema (lançada como "SALDO ANTERIOR"); não conta como faturamento
  situacao TEXT NOT NULL DEFAULT 'concluida' CHECK (situacao IN ('orcamento','aberta','concluida','cancelada','saldo')),
  valor_total REAL NOT NULL DEFAULT 0,          -- o que o cliente paga
  valor_mao_obra REAL NOT NULL DEFAULT 0,       -- parte do total que é mão de obra
  custo_pecas REAL,                             -- NULL = ainda não informado
  custo_frete REAL NOT NULL DEFAULT 0,
  custo_insumos REAL NOT NULL DEFAULT 0,
  forma_pagamento TEXT,
  vencimento TEXT,                              -- YYYY-MM-DD (cliente a prazo)
  data_estimada INTEGER NOT NULL DEFAULT 0,     -- 1 = data deduzida na importação
  origem TEXT NOT NULL DEFAULT 'manual',        -- manual | planilha
  chave_import TEXT UNIQUE,                     -- evita duplicar ao reimportar
  obs TEXT,
  criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_vendas_data ON vendas(data);
CREATE INDEX IF NOT EXISTS idx_vendas_cliente ON vendas(cliente_id);

CREATE TABLE IF NOT EXISTS recebimentos (
  id INTEGER PRIMARY KEY,
  venda_id INTEGER NOT NULL REFERENCES vendas(id) ON DELETE CASCADE,
  data TEXT NOT NULL,
  valor REAL NOT NULL CHECK (valor > 0),
  forma TEXT,
  obs TEXT
);
CREATE INDEX IF NOT EXISTS idx_receb_venda ON recebimentos(venda_id);
CREATE INDEX IF NOT EXISTS idx_receb_data ON recebimentos(data);

-- Pagamentos feitos a terceiros na ordem em que a "cascata" do mês os consome.
CREATE TABLE IF NOT EXISTS categorias (
  id INTEGER PRIMARY KEY,
  nome TEXT NOT NULL UNIQUE,
  -- pecas: fornecedores de peças/insumos | imposto | folha | fixo | socios | reserva | outros
  grupo TEXT NOT NULL CHECK (grupo IN ('pecas','imposto','folha','fixo','socios','reserva','outros')),
  ordem INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS recorrentes (
  id INTEGER PRIMARY KEY,
  descricao TEXT NOT NULL,
  categoria_id INTEGER NOT NULL REFERENCES categorias(id),
  valor REAL NOT NULL,
  dia_vencimento INTEGER NOT NULL DEFAULT 5 CHECK (dia_vencimento BETWEEN 1 AND 31),
  ativo INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS saidas (
  id INTEGER PRIMARY KEY,
  descricao TEXT NOT NULL,
  categoria_id INTEGER NOT NULL REFERENCES categorias(id),
  fornecedor TEXT,
  valor REAL NOT NULL CHECK (valor > 0),
  vencimento TEXT NOT NULL,
  pago_em TEXT,
  valor_pago REAL,
  recorrente_id INTEGER REFERENCES recorrentes(id),
  competencia TEXT,                             -- YYYY-MM da recorrência (evita gerar duas vezes)
  obs TEXT,
  UNIQUE (recorrente_id, competencia)
);
CREATE INDEX IF NOT EXISTS idx_saidas_venc ON saidas(vencimento);
CREATE INDEX IF NOT EXISTS idx_saidas_pago ON saidas(pago_em);
