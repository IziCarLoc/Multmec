# 04. A locadora: parar de usar a oficina como banco

> Escopo: só o que toca a relação **oficina x locadora**. O projeto de organização financeira da locadora em si é outro trabalho; aqui eu deixo a parte dele que a oficina precisa receber (seção 5).
> Premissa: Izi / IziCar é uma empresa **sua**, cliente da Multmec. Não li nenhum arquivo da locadora.

## 1. O que os números da oficina mostram

| | |
|---|---|
| Participação no faturamento | **17,3%** (277 OS, R$ 150,5 mil em 13 meses, ~R$ 11,4 mil por mês) |
| Ticket da locadora x demais clientes | R$ 543 x R$ 917 |
| Lucro bruto da locadora x demais | 60% x 69% |
| Desconto da tabela "valor Izi" na mão de obra | **28% em média** (mediana 25%) |
| Quando paga | **Desconhecido.** A planilha não registra pagamento desde set/25 |

O que isso custa em dinheiro parado (conta direta, sem hipótese sobre o atraso real): a locadora gera ~R$ 11,4 mil de OS por mês.

| Atraso médio de pagamento | Dinheiro da oficina na mão da locadora |
|---:|---:|
| 15 dias | ~R$ 5,7 mil |
| 30 dias | ~R$ 11,4 mil |
| 60 dias | ~R$ 22,7 mil |
| 90 dias | ~R$ 34 mil |

Com 60 dias de atraso, a locadora segura o equivalente a quase uma folha de pagamento da oficina (hipótese de R$ 25 mil). E ainda leva 28% de desconto de mão de obra e 9 pontos a menos de lucro bruto. **A locadora é o cliente mais caro da oficina** e o único em que o cliente também é o dono. Isso explica "a oficina só se paga".

## 2. O princípio

> A oficina trata a locadora como trata qualquer frota: com **prazo**, **limite** e **trava**, por escrito. O dono é o mesmo, a conta bancária e o contrato não são.

Por quê, além do caixa:

- **Risco jurídico.** O art. 50 do Código Civil (redação da Lei 13.874/2019) define confusão patrimonial como a falta de separação de fato, incluindo pagamento de obrigações de uma empresa pela outra e transferência de ativos ou passivos **sem contraprestação**. Se a locadora tiver problema com credores, caixa misturado é a prova mais comum para atingir o patrimônio dos sócios e da oficina. (Grupo econômico sozinho não basta; o STJ exige prova de abuso ou confusão. Fonte e ressalvas em `06-pesquisa.md`.)
- **Sócios diferentes?** Se a oficina tem sócios que **não** são sócios da locadora, cada real que a locadora atrasa é um empréstimo sem contrato de uma parte dos sócios da oficina para a locadora. Precisa estar escrito, com preço e prazo, e eles precisam concordar. Isso muda a conversa, e é a primeira pergunta em `07`.
- **Imposto.** Empréstimo entre empresas tem regra própria (IOF e IR sobre juros, discutidos em tribunais). Fugir do saldo devedor permanente evita o assunto. Detalhes para o contador em `06-pesquisa.md`.
- **Simples Nacional.** Se os sócios das duas empresas forem os mesmos, a receita das duas pode somar para o limite de R$ 4,8 milhões (LC 123, art. 3º, §4º). Não está perto do limite, mas confirme com o contador.

## 3. A política (proposta; o que está marcado "decisão sua" depende de você)

| Item | Regra proposta | Por quê |
|---|---|---|
| **Tabela de preço** | Tabela de frota: 10% a 12% de desconto na mão de obra, **só para quem está em dia**. Hoje é 25% a 28% fixo. Peça com o mesmo piso de acréscimo dos outros clientes. | Desconto vira prêmio por pagar em dia, não direito adquirido. |
| **Fechamento** | Toda **sexta** a oficina fecha o extrato (OS concluídas até quinta) e manda por WhatsApp. | Ciclo fixo e previsível, em vez de cobrar quando o caixa aperta. |
| **Vencimento** | **Quarta-feira seguinte** (5 dias), por Pix. | Dá tempo à locadora, mas sem prazo "solto". |
| **Limite de exposição** | **R$ 12 mil** em aberto (uma média mensal) (decisão sua) | Limita o prejuízo; é o que o sistema usa para travar. |
| **Atraso máximo** | **15 dias** vencido → status **TRAVADO** (decisão sua) | Depois disso só atende com pagamento adiantado. |
| **Exceção** | Segurança do carro (freio, direção, pneu) e carro parado que **gera receita** podem ser atendidos mesmo travados, com aprovação sua no sistema (campo "obs") | A locadora só ganha dinheiro com carro rodando. |
| **OS grandes** | Acima de R$ 1.500: orçamento por escrito aprovado antes e **pagamento das peças adiantado** | Peça cara parada é o que mais prende caixa. |
| **Nota e conciliação** | Nota fiscal em toda OS. Fechamento do mês assinado pelos dois lados (saldo a receber da oficina = saldo a pagar da locadora, item a item) | Prova de que são duas empresas e acaba com discussão de saldo. |
| **Juros e multa por atraso** | Multa de 2% e juros de 1% ao mês (decisão sua) | Valores citados em blogs jurídicos como práticas comuns entre empresas; o que vale é o contrato (conferir com advogado/contador). |
| **Saldo antigo** | Levantar OS em aberto hoje, assinar **plano de quitação** em parcelas semanais (ex.: 8 a 12 semanas) (decisão sua) | Zerar o passado sem derrubar o caixa da locadora de uma vez. |

### O que acontece se o dinheiro da locadora não aguenta

O sistema deixa isso visível e a decisão é sua:

1. **Reduzir o volume de manutenção** ao essencial (preventiva a cada 10.000 km e segurança). A manutenção corretiva de mau uso é do motorista nos modelos de locadora para aplicativo publicados (Kovi: preventiva e defeito técnico por conta da locadora; mau uso, acidente e funilaria por conta do motorista; fonte em `06`).
2. **Parcelar a conta** (ver plano de quitação).
3. **Mudar quem paga**: parte da manutenção cobrada do motorista (taxa semanal ou caução). Ver seção 5.
4. **Priorizar** carros que estão parados (receita zero) sobre carros rodando.

## 4. Como o sistema ajuda (já está no protótipo)

| Recurso | Onde |
|---|---|
| Cadastro com **prazo, limite e tipo** (locadora/frota/revenda) | Clientes a prazo > Editar |
| **Saldo anterior** (dívida de antes do sistema) | Clientes a prazo > Saldo anterior |
| **Recebi** (quita primeiro o saldo anterior, depois as OS mais antigas) | Clientes a prazo > Recebi |
| **Faixas de atraso** (a vencer, 1-7, 8-15, 16-30, 30+) | Cartão do cliente |
| Status **ok / atenção / TRAVADO** com o motivo | Cartão do cliente e Painel |
| **Extrato** com saldo corrente e **texto de cobrança pronto para WhatsApp** | Extrato / cobrança |
| Alerta no painel quando a locadora trava | Painel |

Texto de cobrança que o sistema gera (exemplo com dados fictícios):

```
*Multmec x LOCADORA EXEMPLO* - fechamento em 03/10/2026

OS 1089 (23/09/2026) ABC1D23: R$ 2.830,00 - vencida há 10 dia(s)
OS 1129 (28/09/2026) EFG2H34: R$ 3.395,00 - vencida há 5 dia(s)
OS 1149 (30/09/2026) HIJ3K45: R$ 260,62

*Total em aberto: R$ 6.485,62*
Vencido: R$ 6.225,00
Limite combinado: R$ 12.000,00
```

## 5. O que a oficina precisa que o projeto da locadora entregue

Isto é o ponto de encontro com o projeto de organização financeira da locadora:

1. **Um orçamento mensal de manutenção para a locadora**, para a oficina não ser o amortecedor. Fórmula: `número de carros x km por mês x custo por km`. Referência de blog (não é dado de mercado confirmado): R$ 0,18 a R$ 0,25 por km para carro popular; com 3.000 km/mês, R$ 540 a R$ 750 por carro por mês. **Carro de aplicativo roda mais que o carro particular.** Para calibrar com dado seu: R$ 11,4 mil por mês de OS dividido pelo número de carros da frota (eu não sei esse número, ver perguntas).
2. **Provisão semanal por carro** na locadora (mesmo que o motorista não pague nada diretamente): o valor do orçamento acima ÷ 4,3. Vira uma reserva própria da locadora, não dinheiro da oficina.
3. **Quem paga o quê** escrito no contrato do motorista (preventiva, defeito, mau uso, multa por não fazer revisão, caução). O modelo Kovi e o da LM Veículos para Apps são os dois públicos que achei; ver `06`.
4. **Relatório de custo por placa** (a oficina entrega por OS; falta a visão por carro). Está previsto na fase 2 do sistema.
5. Dia fixo de pagamento da oficina (quarta) na agenda de pagamentos da locadora.

## 6. Cláusulas mínimas do contrato de uma página (modelo de checklist, não é peça jurídica)

1. Partes, CNPJ, objeto (manutenção e reparo da frota).
2. Tabela de preços (anexa), com a regra de desconto condicional.
3. Aprovação prévia de orçamento por escrito (WhatsApp vale se o contrato disser) e valor a partir do qual precisa.
4. Fechamento semanal, vencimento, forma de pagamento.
5. Limite de exposição e trava; exceções de segurança.
6. Multa e juros de mora.
7. Nota fiscal por OS e conciliação mensal assinada.
8. Garantia legal de 90 dias do serviço (CDC art. 26) e fluxo de retorno.
9. Plano de quitação do saldo anterior (anexo).

**Peça a um advogado ou ao contador para revisar antes de assinar.** O ponto sobre sócios diferentes pode exigir um contrato de mútuo e mudar a tributação.

## 7. Indicadores da relação

Os três primeiros já aparecem no protótipo; prazo médio de recebimento e a comparação de lucro entram na fase 2 (`05-sistema.md`).

- % do faturamento da oficina (alvo: abaixo de 15%).
- Exposição em aberto x limite.
- Vencido e dias de atraso.
- Prazo médio de recebimento (meta: ≤ 7 dias).
- Lucro bruto da locadora x demais clientes (meta: diferença menor que 5 pontos).

## 8. Primeiras ações

| Quando | Ação |
|---|---|
| Esta semana | Listar todas as OS da locadora ainda não pagas (lançar como **saldo anterior** no sistema) |
| Esta semana | Definir limite, prazo e trava (itens "decisão sua" acima) |
| Semana 2 | Reunião oficina x locadora (você nas duas pontas): plano de quitação e dia de pagamento |
| Semana 2 | Primeiro fechamento de sexta com o texto do sistema |
| Semana 3 | Contrato de uma página assinado; conciliação do mês |
| Mês 2 | Orçamento de manutenção da frota na locadora e provisão semanal |
