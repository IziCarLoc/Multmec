# 03. Controle financeiro simples para a Multmec

> Objetivo: responder todo dia **três perguntas**, sem planilha de 17 colunas.
> 1. Quanto faturei no mês e onde isso me deixa em relação à meta?
> 2. O que sobra depois de peças, impostos, folha e contas fixas, e quanto disso é dos sócios?
> 3. Quem me deve (principalmente a locadora) e quanto eu preciso pagar nos próximos 7 dias?

O sistema de OS e notas fiscais que vocês já usam continua sendo o dono da OS e da nota. O controle novo **não repete** o que ele já guarda. Ele guarda só o que falta: **custo das peças, recebimento, contas a pagar e regras do dinheiro.**

## 1. Só dois tipos de lançamento

| Lançamento | O que é | Campos (e só eles) |
|---|---|---|
| **Venda** (uma por OS) | O que a oficina vendeu | nº da OS, data, cliente, placa, mecânico, **valor total**, **parte que é mão de obra**, **custo das peças**, frete, insumos, forma de pagamento, vencimento (se a prazo) |
| **Saída** (uma por conta) | Tudo que a oficina paga | descrição, categoria, valor, vencimento, pago em |

Mais um terceiro, que nasce das vendas: o **recebimento** (data, valor, forma), que dá baixa na venda. Uma venda pode ter vários recebimentos (parcelas, pagamento em partes).

Tudo o mais é **cálculo**: lucro bruto, margem, saldo do cliente, caixa, meta.

Regra de ouro do cadastro: **a OS só fecha com o custo das peças preenchido.** Em jul a set/26, 28% a 40% das OS ficaram sem custo e a margem real sumiu.

## 2. A cascata do mês

Em vez de várias telas de relatório, uma pergunta só: "para onde foi o dinheiro deste mês?"

```
Faturamento do mês (OS concluídas)                        R$ 100.000
 (-) Peças, frete e insumos                      (-33%)   -R$  33.000
 (-) Impostos e taxas de maquininha              (-11,5%) -R$  11.500
 = Sobra depois dos custos da venda                         R$  55.500
 (-) Folha e comissões                                     -R$  25.000   <- hipótese
 (-) Custos fixos (aluguel, luz, sistema, contador...)     -R$  14.000   <- hipótese
 = Resultado do mês                                         R$  16.500
 (-) Reserva da oficina (3% do faturamento)                -R$   3.000
 = Disponível para os sócios                                R$  13.500
```

(Folha e fixos são **hipótese** até eu ver o caixa; somam os R$ 39 mil deduzidos em `02-plano-100k.md`.)

Como o sistema calcula cada linha (código em `app/src/finance.js`):

| Linha | Cálculo |
|---|---|
| Faturamento | soma do valor das OS **concluídas** com data no mês (competência) |
| Peças, frete, insumos | custo informado em cada OS. OS sem custo de peça entram com o **CMV médio dos últimos meses fechados** (hoje ~33%) e ficam sinalizadas, para não inflar a sobra |
| Impostos e taxas | faturamento x (% de imposto + % de maquininha). Valores de planejamento: 9,5% e 2%. **Confirmar com o contador** |
| Folha, custos fixos | contas do mês das categorias Folha / Fixo / Outros (vencimento no mês) |
| Reserva | 3% do faturamento (configurável) |
| Disponível para os sócios | resultado menos reserva. Compara com a **meta de retirada** e com o que já foi retirado |

**Ponto de equilíbrio do mês** = (folha + custos fixos) ÷ margem de contribuição (%). O painel mostra se o mês já passou dele ("a partir de agora, cada real que entra é seu").

## 3. Plano de contas enxuto (14 categorias)

| Grupo | Categorias | Entra na cascata em |
|---|---|---|
| Peças | Peças e insumos (fornecedores) | Custo da venda (o custo já vem da OS; esta categoria serve só para o **caixa**: pagar boleto de fornecedor) |
| Impostos | Impostos (DAS, ISS); Taxas de maquininha | Impostos e taxas |
| Folha | Salários e encargos; Comissões de mecânicos | Folha |
| Fixos | Aluguel; Energia, água e internet; Sistema, contador e licenças; Ferramentas e manutenção da oficina; Financiamentos e empréstimos | Custos fixos |
| Sócios | Pró-labore; Distribuição de lucros | Retirada |
| Reserva | Reserva da oficina | Reserva |
| Outros | Outros | Custos fixos |

Se uma conta não couber em nenhuma, é "Outros" e no fechamento do mês alguém decide onde ela devia estar. Não crie categoria nova antes disso.

## 4. A regra do dinheiro: quatro contas e uma transferência por semana

Adaptação do método Profit First (Mike Michalowicz) para uma oficina com peça cara. A ideia: **separar o dinheiro no dia em que ele entra**, para que o imposto, a folha e a parte dos sócios não sejam gastos em peça por engano. Fonte e limites em `06-pesquisa.md`.

Contas bancárias (podem ser subcontas do mesmo banco, sem custo):

| Conta | Para que serve | Quanto entra |
|---|---|---|
| 1. **Entradas** | Tudo que o cliente paga cai aqui. Só serve de passagem. | 100% |
| 2. **Peças e impostos** | Reposição de peças e fornecedores + DAS/ISS | ~44% do que entrou (33% peças + ~11% impostos/maquininha) |
| 3. **Operação** | Folha e custos fixos | O valor fixo do mês dividido por 4,3 semanas (hipótese: R$ 39 mil ÷ 4,3 = R$ 9 mil/semana), **antes de qualquer sobra** |
| 4. **Reserva e sócios** | O resto | Primeiro 3% de reserva. Até a reserva chegar a **1 mês de custo fixo**. Depois, o resto para os sócios |

Todo **sexta à tarde** (ou segunda de manhã): olhar quanto entrou na semana e fazer as transferências. É a única hora que o dinheiro muda de conta.

Por que isso resolve o problema "só pago funcionário e boleto":

- A **conta 3 é paga primeiro**, com valor fixo. Se a semana é fraca, ela ainda cabe. Se é boa, o excesso vai para os sócios, não some em despesa pequena.
- A **conta 4 só recebe sobra de verdade**, depois de peça, imposto e folha.
- Sem essa separação, o saldo do banco parece grande no dia 10 e acaba no dia 25, porque o dinheiro de imposto e de fornecedor estava misturado com o dos sócios.

**Pró-labore e lucros dos sócios.** O pró-labore é custo fixo (categoria "Sócios", valor fixo, pago na data combinada). A distribuição de lucros sai da conta 4 e do que o sistema mostra em "Disponível para os sócios". Pontos que dependem do contador (detalhes em `06-pesquisa.md`):

- Retirada de R$ 10 a R$ 20 mil por mês fica abaixo do limite de R$ 50 mil por sócio/mês em que a lei nova de dividendos (15.270/2025) exige retenção de 10%.
- No Simples, o lucro que pode ser distribuído **sem escrituração contábil** é limitado (depende do mix de serviço e peça; na ordem de R$ 10 a R$ 22 mil por mês para R$ 70 mil de faturamento). Para distribuir mais, precisa de contabilidade que comprove o lucro.
- Não retirar mais que o "Disponível para os sócios" do mês. Quando o mês é fraco, retirar menos, não tirar do caixa de folha.

## 5. Contas a receber e a locadora

- Cliente avulso: **paga na retirada do carro** (sem pagamento, o carro fica; ou entra a prazo com sinal). O sistema cria um alerta com todas as OS de cliente avulso sem recebimento registrado.
- Cliente a prazo (locadora, empresas): tem **prazo, limite e conta corrente**. Quando passa do limite ou de 15 dias de atraso, o sistema mostra **TRAVADO** e a regra passa a ser pagamento antecipado.
- Mais detalhes e a política sugerida para a locadora em `04-locadora.md`.

## 6. Oito indicadores (o painel mostra os cinco primeiros e o de compras)

| # | Indicador | Meta / alerta | Onde |
|---|---|---|---|
| 1 | Faturamento do mês x meta e **projeção** | projeção ≥ meta | Painel |
| 2 | Disponível para os sócios x meta de retirada | ≥ meta | Painel |
| 3 | Saldo do banco x 1 mês de custo fixo (cobertura) | ≥ 1 mês | Painel (caixa) |
| 4 | A receber vencido, **locadora separada** | locadora < limite; vencido < 15 dias | Painel e Clientes |
| 5 | OS sem custo de peça | 0 | Painel (alerta) |
| 6 | Ticket médio e OS abaixo de R$ 300 | ticket sobe; pequenas OS caem | Relatórios |
| 7 | Retorno em até 7 dias (retrabalho) e aprovação de orçamento | retrabalho < 3% (blog); aprovação > 55% | Relatórios (fase 2) |
| 8 | Ocorrências **graves** de compras (boleto sem nota, beneficiário diferente, valor a mais) | 0 abertas antes de pagar | Painel e Compras (`09-conciliacao-compras.md`) |

## 7. Regras fixas (para colar na parede)

1. **OS sem custo de peça não fecha.**
2. **Sem pagamento, sem chave**, exceto cliente a prazo cadastrado e dentro do limite.
3. **Cliente travado só leva carro com pagamento adiantado.**
4. **Toda saída tem categoria e vencimento** no dia em que o boleto chega.
5. **Retirada dos sócios só no dia combinado e só até o valor do "Disponível para os sócios".**
6. **A oficina não paga conta de outra empresa** (nem da locadora) sem lançamento e prazo de devolução. Se acontecer, é contrato de mútuo (ver `04-locadora.md`).
7. **Toda venda para a locadora tem nota e preço de tabela**, como qualquer cliente.
8. Orçamento sempre por escrito, com validade; vale 10 dias (CDC art. 40).
9. **Boleto de fornecedor só é pago com nota fiscal por trás.** Todo boleto que chega é cadastrado em Compras no mesmo dia; o que aparecer como grave (sem nota, beneficiário ou pagador diferente, valor a mais) não é pago antes de confirmar com o fornecedor por um telefone que a oficina já tinha.

## 8. Como começar (uma semana)

| Dia | O que fazer | Tempo |
|---|---|---|
| 1 | Subir o sistema (`08-hospedagem.md`) e entrar com a senha | 30 min |
| 1 | Importar a planilha CONTROLE SERVIÇOS com data de corte = hoje (tudo antes vira "já recebido") | 10 min |
| 2 | Cadastrar as contas fixas (folha, aluguel, energia, sistema, contador, pró-labore) em Contas > Modelos fixos | 30 min |
| 2 | Informar o saldo do banco de hoje em Metas | 2 min |
| 3 | Cadastrar a locadora (prazo, limite) e lançar o **saldo anterior** que ela deve hoje | 15 min |
| 3 | Cadastrar empresas e frotas que compram a prazo | 15 min |
| 4 | Abrir as contas bancárias/subcontas e fazer a primeira transferência da regra do dinheiro | 30 min |
| 5 | Primeira reunião de segunda-feira de 30 min | 30 min |

## 9. Fechamento do mês (dia 1 a 3)

1. Todas as OS do mês concluídas e com custo de peça.
2. Todos os recebimentos lançados; "Em aberto" revisado.
3. Todas as contas do mês pagas ou com vencimento marcado.
4. Conferir saldo do banco x saldo do sistema.
5. Ler a cascata: resultado, reserva, disponível para os sócios. Retirar.
6. Definir a meta do mês e 3 ligações (orçamentos parados e clientes sumidos).
