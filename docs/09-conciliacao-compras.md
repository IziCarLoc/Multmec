# 09. Conferência de compras: nota fiscal × boleto × OS

> Problema que você descreveu: o fornecedor emite **nota fiscal** e **boleto**, mas o boleto traz só o valor. Hoje, se a Scherer (ou qualquer outro) mandar um boleto que não é da oficina, **ninguém percebe**. Este módulo fecha essa lacuna: cada real pago a fornecedor precisa ter uma **nota** por trás e cada peça da nota precisa ter um **destino** (uma OS, o estoque ou o uso interno).
> Fica na aba **Compras** do sistema. Os dados das telas abaixo são inventados.

## 1. A corrente que o sistema fecha

```
Fornecedor ──▶ Nota fiscal (XML) ──▶ Boleto ──▶ Pagamento (Contas)
                    │
                    └──▶ Peça (item da nota) ──▶ OS nº tal
```

Para cada ligação o sistema confere uma coisa que a oficina hoje não confere:

| Ligação | O que o sistema confere |
|---|---|
| Nota ↔ fornecedor | A nota existe de verdade (chave de acesso de 44 dígitos com dígito verificador certo), foi emitida **para o CNPJ da oficina**, não está cancelada. |
| Boleto ↔ nota | O valor e o vencimento batem com uma parcela da nota (ou com a soma de algumas notas). O beneficiário do boleto é o fornecedor da nota. O pagador é a oficina. |
| Boleto ↔ boleto | O mesmo boleto não entra duas vezes, nem dois boletos iguais para a mesma compra. |
| Nota ↔ peça ↔ OS | Cada item da nota vai para uma OS, para o estoque ou para uso interno, sem passar da quantidade comprada. O custo da peça na OS vem da nota. |
| Pagamento | **Boleto com problema grave não é pago sem uma justificativa registrada.** |

## 2. Como usar no dia a dia (10 minutos por dia)

1. **Chegou nota** (e-mail, WhatsApp, portal do fornecedor): em **Compras > Notas > Importar XML** (ou **+ Nota** para digitar sem XML), escolha o(s) arquivo(s). O sistema lê fornecedor, itens, parcelas e guarda o XML original. Vários arquivos de uma vez funcionam.
2. **Chegou boleto**: em **Compras > Boletos > + Boleto**, cole a **linha digitável** (ou o código de barras). O sistema lê banco, valor e vencimento sozinho e, ao apertar **Cadastrar e conferir**, já tenta ligar à nota certa. Se o boleto veio com vários na mesma folha, use **Colar vários** (até 60 linhas).
   - Digite também o **CNPJ do beneficiário** que aparece impresso no boleto, ao lado do nome. Esse campo é o que pega o boleto "de outra pessoa". Ele **não vem no código de barras**: só uma pessoa olhando o papel (ou o PDF) pode digitar.
3. **Peças**: em cada nota, **Aplicar em OS**. Se o fornecedor colocou o número da OS no pedido (`xPed`) ou a placa no texto da nota, o sistema já **sugere** a OS; basta confirmar. Na tela da OS, **Ligar peça de uma nota…** faz o caminho inverso. Em **Aplicar em OS…** (item da nota) você escolhe a OS e a quantidade.
4. **Antes de pagar**: em **Contas**, o botão *Paguei* de um boleto passa pela conferência. Sem problema grave, paga normal. Com problema grave, abre a tela dizendo o que está errado e exige **motivo escrito** para seguir.
5. **Olhe a aba Conferência** uma vez por dia (ou semana): é a lista de tudo que precisa de atenção, da mais grave para a menos.

Ao abrir o sistema pela primeira vez, vá em **Metas** e preencha o **CNPJ da oficina**. Sem ele o sistema não consegue dizer que "o boleto é de outro CNPJ" (aparece um aviso lembrando).

## 3. O que cada ocorrência significa e o que fazer

**Alta** (pare antes de pagar):

| Ocorrência | Significa | O que fazer |
|---|---|---|
| Boleto sem nota fiscal | Nenhuma nota explica esse boleto. | Peça a nota ao fornecedor. Sem nota, não pague. |
| Beneficiário do boleto NÃO é o fornecedor | O CNPJ que recebe o dinheiro é outro. Mesma raiz de CNPJ (outra filial) vira aviso médio. | Ligue para o fornecedor num telefone **que você já tinha**, não o que veio no boleto. Golpe de boleto adulterado costuma ser assim. |
| Boleto emitido contra outro CNPJ | O pagador impresso não é a oficina. | Pode ser boleto de outro cliente enviado por engano (ou de propósito). Não pague. |
| Nota emitida para outro CNPJ | A nota é de outro destinatário. | Pode ser nota de outro cliente. Peça a correta. |
| Boleto cobra mais do que as notas explicam | Sobra dinheiro: juros, nota faltando, ou cobrança indevida. | Peça o detalhamento. |
| Boletos somam mais que a nota | Mais boleto do que nota: possível cobrança em duplicidade. | Pague só o que a nota cobre. |
| Boleto de nota CANCELADA | O fornecedor cancelou a nota e ainda cobra. | Não pague. |
| Possível boleto em duplicidade | Dois boletos com mesmo fornecedor, valor e vencimento. | Pague um só, depois de confirmar. |

**Média** (resolver na semana): boleto vencido; parcela perto de vencer ou vencida **sem boleto**; custo digitado na OS diferente das notas; peças de uma nota sem destino (depois de alguns dias).

**Baixa** (organização): nota digitada à mão sem chave de acesso; fornecedor sem CNPJ; preço de peça bem acima do histórico; vencimento do boleto diferente da parcela; OS com custo de peça e nenhuma nota (resumo único).

### "Está certo, conferi" (aceite)

Algumas ocorrências são normais (nota paga à vista no Pix, preço subiu mesmo, boleto de filial). Aperte **Conferi, está certo** e escreva o motivo (mínimo 5 caracteres). A ocorrência some da lista e o motivo fica gravado; dá para desfazer.

### Pagamento travado

Se o boleto tem ocorrência **alta** não aceita, o sistema recusa o pagamento até alguém apertar **Pagar mesmo assim** com motivo escrito (o outro botão é **Não pagar agora**). Isso não impede o dono de pagar; impede de pagar **sem ver**. O motivo fica no boleto.

## 4. Como o sistema liga boleto a nota

O boleto não diz a qual nota pertence, então o sistema pontua os candidatos do mesmo fornecedor:

| Pontos | Quando |
|---:|---|
| 100 | Valor e vencimento batem com uma parcela da nota |
| 95 | O número do documento impresso no boleto é o da nota e o valor bate (outro vencimento, ou nota sem parcelas) |
| 90 / 70 | O boleto cobre o saldo de **todas** as parcelas juntas (90 se o número do documento também bate, 70 se não) |
| 85 | Valor igual a uma parcela, vencimento diferente |
| 80 | Nota sem parcelas e saldo igual ao boleto |
| 75 | O número bate mas o valor do boleto é outro (vira alerta de divergência) |
| 60 | Soma de 2 a 5 notas do fornecedor bate com o boleto (boleto agrupado) |

A ligação é feita **automaticamente só se a melhor opção tiver 90 pontos ou mais e estiver pelo menos 10 pontos acima da segunda**. Nos outros casos aparece como **sugestão** para você escolher. Você pode ligar à mão (**Ligar a outra nota…**) e desfazer.

## 5. O que o sistema NÃO consegue saber (limites honestos)

1. **Dígitos verificadores pegam erro de digitação, não fraude.** Um golpista gera um boleto com dígitos corretos. A defesa contra fraude é **comparar o CNPJ do beneficiário** com o do fornecedor e **ter a nota por trás**, não a validade do código.
2. **O CNPJ do beneficiário não está no código de barras.** O sistema só pode comparar o que alguém digitou do papel. Se o campo ficar vazio, a regra **não dispara** (e a tela mostra isso). Dica: peça ao banco a lista de boletos DDA (Débito Direto Autorizado), que já traz o CNPJ do beneficiário, e confira de lá.
3. **Nota digitada à mão não prova nada.** Uma nota lançada sem XML pode ter sido inventada ou errada. O sistema marca como "sem chave" e **não** a trata como confirmação forte.
4. **Não consulta a SEFAZ.** Hoje o XML vem de você. Dá para conferir a nota digitando a chave no portal nacional da NF-e (gratuito). A consulta e o download automáticos exigem **certificado digital da oficina (A1)** e ficam para uma segunda fase.
5. **Só vê o que foi lançado.** Se um boleto não for cadastrado, o sistema não sabe que ele existe. A rotina precisa ser: **todo boleto que chega é cadastrado antes de ir para pagamento**.
6. **Preço acima do histórico** compara com as suas compras anteriores no sistema; com pouca história, não alerta.
7. **A OS informada pelo fornecedor** (`xPed`) só aparece se o fornecedor preencher. Se ele não preenche, você aplica à mão (rápido, mas é trabalho).

## 6. Rotina que fecha o controle (processo, não só sistema)

Sugestões para combinar com os fornecedores, principalmente a Scherer:

- Pedir que o **número da OS (ou a placa)** vá no **pedido de compra** da nota (campo `xPed`) ou nas informações complementares. Quem emite por sistema consegue.
- Pedir que o **número da nota** apareça no campo **número do documento** do boleto (padrão na maioria dos bancos).
- Combinar **um único e-mail** da oficina para receber XML e boleto, e **um responsável** que cadastra no mesmo dia.
- Nunca pagar boleto recebido por **WhatsApp ou e-mail sem nota correspondente**, nem que seja "da Scherer".
- Telefone de confirmação do fornecedor guardado no cadastro.
- **Separação de funções** (quando a equipe permitir): quem cadastra a nota/boleto não é quem paga.

### Auditoria retroativa dos boletos que já existem

1. Em **Metas > Conferência de compras**, preencha o **CNPJ da oficina**. Em **Conferir OS sem nota a partir de**, ponha a data de início da auditoria (vazio = só o mês atual): ela controla apenas o aviso "OS com custo de peça e nenhuma nota ligada". O sistema só audita o que foi **cadastrado**: comece pelos boletos em aberto e dos últimos 2 ou 3 meses, em vez de tentar lançar o passado inteiro.
2. Peça à Scherer o **extrato/relatório de títulos** (notas e boletos) dos últimos meses e o XML das notas (muitos mandam um zip).
3. Importe os XMLs e cadastre os boletos em lote.
4. Comece pela aba **Conferência**: o que aparecer como *Boleto sem nota* ou *Boleto pago sem nota* é a lista do que cobrar explicação.
5. Compare o extrato do fornecedor com o que foi pago no banco, linha a linha. O sistema ajuda, mas o extrato é a prova.

## 7. XML da nota: onde conseguir

- O fornecedor envia por e-mail junto com o DANFE (PDF). **O PDF não serve**: precisa do arquivo `.xml`.
- Portais de fornecedores costumam ter "baixar XML".
- Sem o XML: consulta pela **chave de acesso** (44 dígitos impressa no DANFE) no portal nacional da NF-e.
- O sistema guarda o XML original comprimido no banco (a nota fiscal é documento fiscal: guarde por no mínimo 5 anos; fale com o contador). **O backup do banco já leva os XMLs.**
- Fase 2 (opcional): com o certificado digital da oficina, o sistema pode baixar sozinho todas as notas emitidas contra o CNPJ da oficina. Isso **elimina** a dependência de o fornecedor mandar e é a melhor defesa contra nota "sumida".

## 8. Parâmetros (Metas)

| Parâmetro (Metas > Conferência de compras) | Padrão | Para quê |
|---|---:|---|
| CNPJ da oficina | vazio | Base de duas regras de alta gravidade (nota e boleto no nome de outro CNPJ) |
| Conferir OS sem nota a partir de | mês atual | Só vale para o aviso "OS com custo de peça sem nota" |
| Dias para dar destino às peças | 7 | Prazo para aplicar em OS/estoque as peças de uma nota |
| Diferença aceita no valor (R$) | 0,05 | Centavos de arredondamento aceitos entre boleto e nota |
| Alerta de preço acima de (%) | 15 | Quanto acima da mediana das últimas compras do item dispara o aviso |

O prazo "nota sem boleto" (5 dias antes da parcela vencer) fica no banco (`dias_nota_sem_boleto`) e ainda não tem campo na tela.

## 9. O que mudou de pré-existente

Ao testar o módulo, apareceu um defeito que **já existia** na versão anterior: **apagar uma OS ou uma conta pela interface falhava** (o servidor recusava o pedido sem corpo). Foi corrigido, com teste, e a interface agora sempre envia o cabeçalho esperado.

## 10. Perguntas para você (as respostas mudam o que construir a seguir)

1. A Scherer e os outros mandam **XML**? Por e-mail, portal ou só o PDF?
2. O sistema de OS que vocês usam hoje tem **entrada de nota**? Daria para exportar? (Evitaria digitar duas vezes.)
3. A oficina tem **certificado digital A1** (o mesmo da emissão de nota)? Com ele, o download automático das notas é viável.
4. O banco oferece **DDA** (lista dos boletos emitidos contra o CNPJ da oficina) ou exportação em arquivo (CNAB)? É a melhor fonte para saber de boletos que ninguém cadastrou.
5. A Scherer coloca o **número da OS ou a placa** no pedido? Se não, é viável pedir?
6. **Quem** vai cadastrar notas e boletos, e quem vai pagar? (Separar as duas pessoas é o controle mais barato.)
7. Há compra **sem nota** (peça no balcão, "sem nota")? Como tratar: lançar como nota manual com motivo, ou proibir?
