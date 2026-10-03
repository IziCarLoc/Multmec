# 00. Resumo executivo (leia este primeiro)

> Multmec, análise de 03/10/2026. Base: 1.064 OS entre 25/08/2025 e 02/10/2026 (planilha CONTROLE SERVIÇOS).
> **Aviso importante:** dois dos quatro links que você mandou **não abriram** com a conta Google conectada aqui (uma pasta e uma planilha, provavelmente o caixa). Sem o caixa, os custos fixos da oficina são **hipótese** minha. Está tudo marcado.

## O que eu descobri

1. **Faturamento médio de R$ 65 mil/mês** (13 meses cheios), **R$ 71 mil** nos últimos 6. Melhor mês: R$ 87,6 mil. A meta de R$ 100 mil é +41% sobre os últimos 6 meses e +14% sobre o melhor mês.
2. **De cada R$ 100 faturados, R$ 33 vão para peças e R$ 67 sobram de lucro bruto** (R$ 27 de mão de obra + R$ 40 de ganho nas peças). A oficina é, na prática, **revenda de peça com serviço junto**: a mão de obra é só 25% do faturamento.
3. **Se hoje a oficina "só se paga" com ~R$ 70 mil, os custos fixos estão em torno de R$ 39 mil/mês** (hipótese). Cada R$ 100 acima disso deixam mais de R$ 50. **A R$ 100 mil sobrariam ~R$ 16,5 mil por mês antes da reserva**, contra zero hoje.
4. **38% das OS valem menos de R$ 300 e dão só 8% do faturamento.** As 9% acima de R$ 2 mil dão 41%. O acréscimo sobre a peça cai de 185% (peça barata) para 63% (peça acima de R$ 1.500): a margem percentual é menor justamente nas OS grandes.
5. **A locadora é 17% do faturamento** (~R$ 11,4 mil/mês), com ticket 41% menor, lucro bruto 9 pontos menor e **desconto médio de 28% na mão de obra**. Com 60 dias de atraso ela segura ~R$ 23 mil da oficina. É o cliente mais caro da casa.
6. **Pela planilha, quase tudo é produzido por um mecânico** (59 a 84 OS por mês desde jan/26). O gargalo para R$ 100 mil parece ser a bancada, não a falta de cliente, então o caminho é **mais dinheiro por OS**, não mais OS.
7. **A planilha não responde "quem já pagou"** (marcação parou em set/25), perdeu o cálculo de lucro desde jul/26 e o campo mecânico desde ago/26. Em 28% a 40% das OS recentes o custo da peça está em branco. O controle financeiro precisa começar por aí.

## O que eu recomendo

**Plano para R$ 100 mil** (detalhes em `02-plano-100k.md`): oito alavancas, três cenários.

| Alavanca | Ganho/mês |
|---|---:|
| Piso de acréscimo nas peças (80% a 100%) | R$ 2,7 mil a 4,9 mil |
| Mão de obra sempre cobrada e tabela revisada | R$ 2,5 mil a 3,5 mil |
| Checklist de entrada + orçamento adicional | R$ 3 mil a 4,8 mil |
| Encher a sexta-feira (rende 32% menos) | R$ 1,8 mil a 4,7 mil |
| Mais OS grandes (frotas, pacotes) | R$ 6 mil a 11,7 mil |
| Embutir taxa de maquininha no parcelado | R$ 1 mil a 1,5 mil |
| Taxa mínima nas OS pequenas (abaixo de R$ 150) | R$ 0,8 mil (e tempo de bancada) |
| Locadora com desconto condicional | R$ 0,4 mil (o ganho é caixa) |

Cenários: **baixo ~R$ 86 mil, base ~R$ 93 mil, alto ~R$ 103 mil**. Sendo honesto: R$ 100 mil é possível, mas só fecha com as OS grandes e a sexta funcionando. As regras de preço (as três primeiras) são as mais seguras.

**Controle financeiro** (`03-controle-financeiro.md`): só dois lançamentos (venda e conta), uma **cascata do mês** (faturamento → custos → imposto → folha → fixos → resultado → reserva → **disponível para os sócios**), quatro contas bancárias com **uma transferência por semana**, 7 indicadores e uma reunião de 30 minutos toda segunda.

**Locadora** (`04-locadora.md`): tratar como qualquer frota, com **prazo de 5 dias (pagamento toda quarta), limite de R$ 12 mil, trava com 15 dias de atraso**, desconto só para quem paga em dia, saldo antigo em plano de quitação, nota fiscal e conciliação mensal. Além do caixa, é proteção jurídica: caixa misturado é o que sustenta a "confusão patrimonial" (art. 50 do Código Civil).

## O sistema (já funciona)

`app/`: painel com a meta e a projeção do mês, cascata do mês, vendas (lançar OS pelo celular), contas a pagar com modelos fixos, clientes a prazo com **trava, extrato e texto de cobrança pronto para WhatsApp**, metas com simulador, relatórios e **importação da sua planilha** (com correção das datas erradas). Segurança de produção, 20 testes e backup do banco. Detalhes e limitações em `05-sistema.md`; como colocar no ar em `08-hospedagem.md`.

As telas (com dados inventados) estão em `05-sistema.md`. Testei também com a sua planilha real importada, mas esses dados não foram guardados no repositório.

## O que eu preciso de você (as 8 principais, completas em `07`)

1. Compartilhar os dois links que não abriram (ou exportar) e explicar o que são.
2. Custos fixos reais por mês (folha por pessoa, aluguel, energia, sistema, contador, financiamentos, DAS) e quanto os sócios retiram.
3. Quem trabalha na bancada, quanto custa cada um, e o que houve com Luiz, Mateus, Gabriel e Douglas.
4. Quem são os sócios da oficina e da locadora, regime tributário e contador.
5. Quanto a locadora deve hoje, atraso médio, tamanho da frota e quem paga manutenção.
6. O que significa "OK" na coluna LOCADORA (302 OS) e quem são "MARIO" e os nomes de pessoa.
7. Se a meta de R$ 100 mil é o valor total das OS, e para quando.
8. Que hospedagem você tem (se for só PHP/cPanel, este sistema precisa de outro lugar).

## Duas coisas com prazo para olhar com o contador

- **NFS-e Nacional** obrigatória para empresas do Simples a partir de **01/11/2026** pela regra federal; não ficou claro se vale para Santa Maria, que mantém emissor próprio.
- **Simulação do imposto a R$ 100 mil/mês**, incluindo a separação do PIS/Cofins das autopeças (monofásico), que muita empresa deixa de fazer e paga duas vezes.

## Próximos passos sugeridos

1. Você responde as perguntas; eu troco as hipóteses por números reais e refaço o plano.
2. Escolhemos a hospedagem e eu coloco o sistema no ar.
3. Você manda o material gráfico (logo, cores) e eu aplico.
4. Semana 1 do plano: importar a planilha, cadastrar contas fixas, definir regras da locadora e o piso de acréscimo nas peças.
