# 07. Perguntas para você e o que ficou pendente

> Você disse que, se surgisse dúvida, era para mandar. Estão ordenadas pelo quanto **mudam o resultado**. Respondendo as 8 primeiras eu fecho os números que hoje estão como "hipótese".

## A. As 8 que mais mudam os números

| # | Pergunta | Por que importa | O que eu assumi enquanto isso |
|---|---|---|---|
| 1 | **Os dois links que não abriram** (pasta `1ZIZ41KfdB_...` e planilha `1ERhfPddkfj_...`): compartilha com a conta Google conectada aqui (izicarlocadora@gmail.com) ou me manda exportados em CSV/PDF? O que são (caixa? extrato? folha?) | Sem o caixa eu não vejo custo fixo, folha, boletos, retiradas | Custos fixos ≈ **R$ 39 mil/mês**, deduzidos de "só se paga com ~R$ 70 mil" |
| 2 | **Custos fixos reais por mês**: folha (por pessoa, com encargos), aluguel, energia/água/internet, sistema, contador, financiamentos, boletos recorrentes, imposto pago (DAS), taxa de maquininha. Quanto os sócios retiram hoje? | Calibra o ponto de equilíbrio, a cascata e a meta de retirada | R$ 39 mil e retirada-alvo de R$ 15 mil |
| 3 | **Quem trabalha na bancada e quanto custa cada um?** O que aconteceu com Luiz (some a partir de jan/26), Mateus, Gabriel e Douglas? Por que o campo operador ficou em branco desde ago/26? Mecânico recebe fixo, comissão (qual %) ou os dois? | Define se 100 mil é possível "sem aumentar a equipe" e o risco de depender de uma pessoa | Robson é o principal; os outros eventuais |
| 4 | **Quem são os sócios da oficina e da locadora? São os mesmos?** Qual o regime tributário (Simples?) e quem é o contador? | Muda a conversa jurídica e fiscal da locadora (mútuo, art. 50, limite do Simples) e a tributação dos lucros | Mesmos sócios; Simples Nacional |
| 5 | **Locadora: quanto ela deve hoje à oficina** (lista das OS ainda não pagas)? Qual o atraso médio de pagamento? Quantos carros a frota tem? Quem paga manutenção: a locadora ou o motorista? | Define o plano de quitação, o limite de crédito e o orçamento de manutenção da frota | Limite R$ 12 mil, trava 15 dias, plano em 8 a 12 semanas |
| 6 | **O que é "OK" na coluna LOCADORA?** (302 OS, 16% do faturamento, nem todas são da Izi). E "MARIO" (67 OS) é a revenda "Mario Automóveis"? Os nomes de pessoa que aparecem na coluna PROPRIETA são motoristas da locadora ou clientes avulsos? | Pode haver mais faturamento ligado à locadora do que os 17% que eu medi (chega a 32% se somar tudo) | Considerei só IZI/IZICAR como locadora |
| 7 | **Meta de R$ 100 mil: é o valor total das OS (peças + mão de obra)?** Ou só mão de obra + lucro? Para quando? | Se for outra definição, os cálculos mudam | Valor total das OS concluídas, em ~6 meses |
| 8 | **Hospedagem**: já existe site/domínio/servidor? Qual tipo (plano compartilhado com PHP/cPanel, VPS, Hostinger, Locaweb...)? Pode ter servidor Node? | Este sistema usa Node + SQLite. Em hospedagem só PHP/MySQL ele não roda | Hospedar em serviço de aplicações (Render/Railway/VPS) |

## B. Perguntas que melhoram o plano

9. **Sistema de OS e notas**: qual o nome do fornecedor? Existe exportação (planilha, API, banco) com os itens e valores? Hoje a OS não imprime custo de peça: quem digita o custo e quando?
10. **Quem vai usar o sistema** (sócios, atendente, mecânico)? Mecânico pode ver margem? Celular, computador ou os dois?
11. **Formas de pagamento**: quanto das vendas é Pix/dinheiro, débito, crédito parcelado, boleto? Qual maquininha e quais taxas contratadas? Hoje a taxa já entra no preço?
12. **Sexta-feira rende 32% menos** que seg–qui: é falta de serviço agendado, mecânico folga, ou as OS entram segunda?
13. **Orçamentos**: como são feitos no sistema de OS? Dá para saber quais viraram serviço? A planilha tem só 39 em 13 meses, parece que a maior parte não é registrada.
14. **Permutas** (aba PERMUTAS: MULTSYSTEM R$ 3.578,59 em 3 OS; MAGRÃO COMPRESSOR sem valor): o que é cada uma e deve entrar no financeiro como troca (receita sem entrada de caixa)?
15. **AGCAR** (aba CONTROLE SV AGCAR LOCADORA: R$ 3.743,44 em aberto na OS 625 e R$ 1.753,20 na OS 627): é outro cliente a prazo? Entra no sistema como "locadora" com limite?
16. **Aba "Página9"** (peças compradas por funcionário com 30%): ainda é usada? Quer essa venda no sistema?
17. **Preço de mão de obra**: a tabela da aba MÃO DE OBRA (valor Izi x valor normal) é a que vale hoje? Quem atualiza? Existe preço fechado para pacote de revisão?
18. **Estoque**: o sistema de OS controla estoque de peça? (Eu não vou duplicar isso no financeiro, só quero saber.)
19. **Quem faz o lançamento do custo das peças** e em que momento do dia? (Define se o botão "+" do celular basta ou se precisa de atalho.)
20. **Material gráfico** (logo, cores, nome fantasia): quando mandar eu aplico no sistema. O protótipo usa um "M" azul provisório.
21. **Dados de cliente**: posso manter nome e placa no sistema? (Há tratamento de dado pessoal; conversar com o contador/advogado sobre LGPD.)

## C. Decisões da política da locadora (aguardam você)

Estão marcadas como "decisão sua" em `04-locadora.md`: limite (R$ 12 mil), atraso máximo (15 dias), juros e multa (1% e 2%), plano de quitação do saldo antigo (8 a 12 semanas), valor a partir do qual a OS precisa de aprovação por escrito (R$ 1.500), desconto de frota condicional (10% a 12%).

## D. Para o contador

A lista de conferência está em `06-pesquisa.md`, seção 8. Os três pontos de maior valor: **simulação do DAS a R$ 100 mil/mês com segregação de PIS/Cofins monofásico nas peças**, **obrigatoriedade da NFS-e Nacional em 01/11/2026 em Santa Maria**, e **tratamento do dinheiro que a oficina adianta para a locadora** (mútuo).

## E. O que eu não fiz (e por quê)

- Não abri arquivos de caixa e planilhas da IziCar Locadora que aparecem no seu Drive (por exemplo "MOVIMENTAÇÃO CAIXA MAIO/JUNHO", "PGTO INVESTIDORES"). Você não pediu e são de outro negócio. Se quiser que eu use algo disso para o projeto da locadora, me avise.
- Não li as ~1.100 OS em PDF. Li duas para entender o formato. A planilha já traz o que o financeiro precisa.
- Não criei Pull Request. O trabalho está na branch `claude/compassionate-goodall-ktbl7n`.
- Não subi nada para hospedagem (precisa das suas respostas 8 e das credenciais).
