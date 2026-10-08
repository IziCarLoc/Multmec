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
   - **Atalho:** em vez da linha, você pode colar o **texto inteiro do boleto** (abra o PDF, selecione tudo, copie). O sistema acha a linha digitável sozinho e tenta separar o **CNPJ do beneficiário**, o **CNPJ do pagador** e o **número do documento** pelos rótulos do boleto. O que ele não tiver certeza, deixa em branco. **Confira os campos preenchidos com o boleto na mão**: o texto de um boleto adulterado também vem "certinho".
   - O **CNPJ do beneficiário** é o campo que pega o boleto "de outra pessoa". Ele **não vem no código de barras**: ou vem do texto/PDF, ou uma pessoa digita olhando o boleto (melhor ainda: o nome e o CNPJ que o **aplicativo do banco** mostra ao colar a linha, antes de pagar).
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
| Boleto de nota CANCELADA ou DENEGADA | O fornecedor cancelou a nota (ou a SEFAZ negou o uso dela) e ainda cobra. | Não pague; peça que o fornecedor cancele o título. |
| Possível boleto em duplicidade | Dois boletos com mesmo fornecedor, valor e vencimento. | Pague um só, depois de confirmar. |

**Média** (resolver na semana): boleto vencido; **banco diferente do que o fornecedor costuma usar** (depois de 2 boletos do mesmo fornecedor, um boleto de outro banco é o sinal mais comum de boleto adulterado, mas também acontece troca legítima de conta: confirme por telefone); parcela perto de vencer ou vencida **sem boleto**; custo digitado na OS diferente das notas; peças de uma nota sem destino (depois de alguns dias).

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

1. **Dígitos verificadores pegam erro de digitação, não fraude.** Um golpista gera um boleto com dígitos corretos. A defesa contra fraude é **comparar o CNPJ do beneficiário** com o do fornecedor e **ter a nota por trás**, não a validade do código. O sistema só recusa o que é matematicamente impossível (dígito errado, vencimento fora da faixa que os bancos aceitam).
2. **O CNPJ do beneficiário e o do pagador não estão no código de barras.** Vêm do texto/PDF do boleto, do app do banco ou do DDA. Se o campo ficar vazio, a regra **não dispara**.
3. **O XML sozinho não prova a nota.** Um arquivo `.xml` pode ser forjado ou alterado depois de autorizado; o sistema confere que a chave, o número, o CNPJ e o protocolo são coerentes entre si e guarda o arquivo, mas **não verifica a assinatura digital nem consulta a SEFAZ**. A prova real é consultar a chave no portal nacional da NF-e (grátis, com captcha) ou, na fase 2, pelo certificado da oficina. Faça isso nas notas grandes e em qualquer nota que o sistema marque como estranha.
4. **Nota digitada à mão não prova nada.** Pode ter sido inventada ou errada. O sistema marca como "sem chave" e não a trata como confirmação forte.
5. **Só vê o que foi lançado.** Se um boleto não for cadastrado, o sistema não sabe que ele existe. A rotina precisa ser: **todo boleto que chega é cadastrado antes de ir para pagamento**. O DDA do banco (seção 7) é o que fecha essa brecha.
6. **O XML não diz qual é o boleto.** O XML da nota traz o valor e as parcelas (quando o fornecedor preenche), mas nenhum campo identifica o boleto. A ligação é por fornecedor + valor + vencimento (e pelo "número do documento" do boleto, quando existe). Se o fornecedor **agrupa várias notas num boleto** (fatura do período), o sistema tenta achar a combinação de 2 a 5 notas; se não achar, pede ligação manual.
7. **Parcelas são opcionais na nota.** Muita nota sai sem as parcelas; nesse caso o sistema compara o boleto com o saldo da nota inteira. Em nota de fornecedor do Regime Normal (reforma tributária, 2026) o total pode vir com **IBS/CBS "por fora"**; o sistema aceita o boleto por qualquer um dos dois valores e avisa que há dois.
8. **Boleto vencido** costuma ter juros e multa que não aparecem no código de barras: a diferença na hora de pagar é encargo, não erro.
9. **Preço acima do histórico** compara com as suas compras anteriores no sistema; com pouca história, não alerta.
10. **A OS informada pelo fornecedor** (`xPed`, até 15 caracteres, ex.: `OS1043`) só aparece se o fornecedor preencher. Se ele não preenche, você aplica à mão (rápido, mas é trabalho).
11. **Nota de devolução, crédito ou ajuste** não gera boleto: o sistema as separa (um boleto ligado a elas é suspeito) mas **ainda não abate** o crédito do que você deve.
12. **Ainda não sabemos como a Scherer preenche as notas** (parcelas, pedido, texto livre). Veja os 10 a 20 primeiros XML dela e ajuste as regras com base neles.

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

## 7. XML da nota e DDA: onde conseguir

**XML da nota**

- O fornecedor envia por e-mail junto com o DANFE (PDF). **O PDF não serve**: precisa do arquivo `.xml`. O emitente deve enviar ou disponibilizar o XML ao destinatário; exija em toda compra.
- Portais de fornecedores costumam ter "baixar XML". O contador também costuma já baixar as notas de entrada: peça o lote.
- Só com a **chave de acesso** (44 dígitos do DANFE): consulta resumida no portal nacional da NF-e (grátis, com captcha). Serve para confirmar que a nota existe, o valor e a situação; não baixa o XML sem certificado.
- O sistema guarda o XML original comprimido no banco, com o SHA-256 (a nota fiscal é documento fiscal: guarde por no mínimo 5 anos; fale com o contador). **O backup do banco já leva os XMLs.**
- Se a SEFAZ **cancelar** a nota, importe o XML de cancelamento (o sistema aceita) ou marque a nota como cancelada. O prazo para o fornecedor cancelar é curto (24 horas pela regra nacional; no RS, 7 dias) e só vale se a mercadoria ainda não saiu; a **Carta de Correção não muda valor, data nem CNPJ**, então o valor do XML original continua valendo para o boleto.

**Fase 2: baixar sozinho todas as notas emitidas contra o CNPJ da oficina**

Com o **certificado digital A1 (e-CNPJ)** da oficina, o sistema pode consultar o serviço nacional de distribuição de DF-e e receber **todas as notas emitidas contra o CNPJ**, sem depender do fornecedor. Detalhes que mudam a decisão:
- Sem a **Ciência da Operação**, a SEFAZ entrega só o **resumo** (fornecedor, valor, data, situação). O resumo já responde a pergunta central da auditoria: *"existe nota deste fornecedor, deste valor, contra o meu CNPJ?"* Com a Ciência (um clique por nota, ou automático), vem o XML completo.
- A **Manifestação do Destinatário** não é obrigatória para oficina de autopeças, mas o **Desconhecimento da Operação** é o instrumento para "nota emitida contra o meu CNPJ que eu não reconheço". A **Confirmação** impede o fornecedor de cancelar a nota: só confirme depois de conferir peça e OS.
- Os documentos ficam **90 dias** na SEFAZ e **não há histórico antes do primeiro uso**: quanto antes começar, melhor. O passivo vem do fornecedor ou do contador.
- Limites do serviço: uma consulta por hora quando não há novidade (consultar demais bloqueia por uma hora); o certificado precisa ser e-CNPJ A1 (arquivo), não token.
- Futuro: a NT 2026.006 (produção a partir de 03/11/2026, ainda não obrigatória) cria um campo para o fornecedor **vincular a nota ao boleto/Pix** (`idTransacao`) e um evento de vinculação. Se um dia o fornecedor preencher, a ligação nota × boleto passa a ser exata. Hoje não dá para depender disso.

**DDA (Débito Direto Autorizado): a melhor defesa para a pergunta do dono**

O DDA é o serviço dos bancos que lista **todos os boletos registrados emitidos contra o CNPJ da oficina**, de qualquer banco, direto da base centralizada. A própria FEBRABAN diz que o boleto que vem do DDA não pode ser adulterado por golpista. Para a oficina:
- **Regra de ouro:** só pague boleto que (a) apareça no DDA ou tenha a oficina como pagador impresso **e** (b) case com uma nota recebida.
- Um boleto emitido por engano contra **outro** cliente da Scherer **não aparece** no DDA da oficina: é exatamente o caso que você descreveu.
- Limites: boleto **não registrado** não aparece (então a ausência exige conferência, não prova fraude); o DDA mostra beneficiário, valor, vencimento e, em alguns bancos, o número do documento.
- Peça ao gerente: *(1)* ativar o DDA para o CNPJ da oficina (e filiais); *(2)* se dá para **exportar** a lista (arquivo CNAB 240 de DDA ou relatório em planilha) para o sistema importar e comparar automaticamente. A oferta de exportação varia por banco: não confirmei.
- Antes de pagar qualquer boleto no app do banco, **leia na tela de confirmação o nome e o CNPJ do beneficiário** e confira com o fornecedor; confira também se os **3 primeiros dígitos do código de barras** são o banco que a tela mostra.

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
