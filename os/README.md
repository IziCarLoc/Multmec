# Sistema de OS da Multmec Liberdade

Ordens de serviço da oficina, do recebimento do carro até a entrega, e o Financeiro. O sistema registra quem fez cada etapa e trava os dois portões:
- **nada é executado sem aceite** do cliente;
- **nada é entregue sem conferência** feita por quem não executou.

Mecânicos usam no celular; o atendimento usa no celular da oficina e no computador.

- **Tecnologia:** a mesma do Gestão de Frota da Izi Car.
  - Laravel 12, PHP 8.4 (a do servidor), Livewire 4 com Alpine e Tailwind já compilado.
  - PDF com dompdf; MySQL na Hostinger.
- **Onde fica:** pasta `os/` do repositório Multmec. O Financeiro também é daqui; o controle financeiro antigo em Node (`app/`) foi substituído e fica só como referência.
- **Convenções para quem mexe no código:** [`CLAUDE.md`](CLAUDE.md).

## Situação

| Entrega | O que tem | Situação |
|---|---|---|
| 0 | Acesso com e-mail e senha ("manter conectado"), perfis Gestor / Atendimento / Mecânico, usuários com senha provisória, Ajustes (oficina, numeração, regras, textos dos termos), Saúde do sistema com teste da hospedagem, app instalável no celular | Pronto |
| 1 | Clientes e veículos (busca por placa, frota Izi vinda do Gestão), fornecedores e prestadores, OS com etapas, travas, histórico e volta pelo gestor, quadro, lista e avisos por perfil, publicação automática | Pronto |
| 2 | Fotos pela câmera com hash conferido, original intacto, carimbo e miniatura; vistoria parte A (fotos guiadas, painel, acessórios, avarias marcadas na foto, autorizações, quem aprova) e parte B (14 itens); PDF da vistoria; envio pelo WhatsApp em dois toques ou assinatura na tela | Pronto |
| 3 | Diagnóstico com peças e serviços (horas, necessário/recomendado, origem da peça, prestador externo com custo), serviços frequentes, vídeo de até 20 MB; cotação COT-AAAA-NNNN pelo WhatsApp (sem dados do cliente), respostas por peça e vencedor | Pronto |
| 4 | Orçamento: preço de cada item (o gestor vê a margem), previsão e pagamento, prévia em PDF; "Enviar orçamento" congela a cópia com SHA-256; envio do PDF pelo WhatsApp, resumo e link para o cliente responder sem login; aceite de todos ou de parte dos itens (mensagem com print, presencial, assinatura, link ou alçada da Izi, acima dela só o gestor), autorização da peça não original, recusa com motivo e valor do diagnóstico; adendos depois do aceite | Pronto |
| 5 | Execução: apontamento (Iniciar/Pausar; um por vez; o esquecido fecha às 22h marcado para conferir), peça recebida ou não usada (aprovada com peça por chegar vai sozinha para "Aguardando peças"), foto da peça velha e da nova, torque das rodas em Nm com o nº do torquímetro, lacres, test drive exigido; conferência por quem não apontou tempo (Expresso ou Completo, OK / Não se aplica / CORRIGIR, foto de cada roda com a listra, test drive com km, hora, motorista e trajeto); CORRIGIR devolve para a execução e a conferência recomeça | Pronto |
| 6 | Entrega: fotos de saída ao lado das de entrada, km e combustível de saída, peças substituídas, pagamento (à vista ou em parcelas) e termo de retirada assinado na tela pelo cliente ou por pessoa autorizada por escrito, com o documento; na entrega nascem o A receber (valor aprovado, custo das peças, mão de obra) e o pagamento de cada prestador externo; recusada cobra só o diagnóstico. Nota fiscal (pendente/emitida, não trava) e "copiar resumo" para o sistema fiscal. PDF completo da OS (entrada × saída, peças velha × nova, conferência, test drive, retirada, garantia e privacidade, com os SHA-256). Por mês (entrada ou entrega) com relatório em PDF paisagem | Pronto |
| Financeiro 2a | Painel (vencimentos que abrem a lista filtrada, em aberto, movimento do mês e "Por mês" pela data do pagamento), A receber (parcelas em centavos exatos, pagamentos parciais com data, recebimento pela matriz, baixa do vencido como prejuízo), A pagar (boletos com parcelas e "marcar pago"), prestadores externos, inadimplentes e prejuízos; o atendimento não vê lucro; o A receber e os prestadores nascem na entrega da OS | Pronto |
| Importação | Ajustes > Importar dos artefatos (só gestor): fornecedores, boletos com as parcelas, A receber com os recebimentos, pagamentos do Douglas e prejuízos do artefato do Financeiro; prévia antes de gravar, SHA-256 do arquivo, pode rodar de novo sem duplicar (traz as baixas feitas depois no artefato). O artefato da OS não tem OS para trazer | Pronto |
| Financeiro 2b | **Parte A:** Meta do mês (só gestor: faturado × meta com ritmo e "quanto falta por dia útil", feriados nacionais e da Páscoa, cascata até o disponível para os sócios, ponto de equilíbrio, caixa, simulador OS × ticket, regras do dinheiro editáveis); contas fixas que geram o A pagar do mês sem duplicar (botão e agendador); crédito a prazo (tipo, prazo e limite no cadastro, situação com faixas de atraso, TRAVADO na entrega e no A receber, liberação do gestor com motivo na trilha, saldo anterior, "Recebi" que quita o mais antigo, extrato e cobrança para o WhatsApp); relatórios (avisos, próximos 7 dias, mês a mês, tamanho das OS, clientes, mecânicos, orçamentos sem resposta, CSV; o atendimento não vê lucro); importação da planilha CONTROLE SERVIÇOS com prévia e data de corte. **Parte B:** conferência de compras (linha abaixo) | Pronto |
| Conferência de compras | Financeiro > Compras (gestor e atendimento): notas por XML (vários de uma vez, XML guardado) ou sem XML (texto da DANFE ou a chave) com a consulta no portal da NF-e como prova; boleto pela linha digitável ou pelo texto do boleto, ligado sozinho à nota (ou à mão) e virando a conta do A pagar; destino de cada peça (OS, estoque, uso interno, devolvida, outra empresa) com o custo da OS vindo da nota; ocorrências da mais grave para a menor ("Está certo, conferi" do gestor, com motivo, vale enquanto o fato não mudar); trava no A pagar (boleto com problema grave ou sem conferir quem recebe no app do banco não é pago; "pagar mesmo assim" com motivo); fornecedor conferido pelo gestor e recebedores autorizados; empresas do grupo e acerto entre elas; DDA do banco (beta); parâmetros em Ajustes > Compras. CNPJ alfanumérico aceito | Pronto |
| Revisão (23/10) | Revisão independente de todo o código (entregas 0 a 6 e Financeiro), com as correções: envio da vistoria com o resumo do PDF enviado e só pelo atendimento, foto recusada não tira a anterior, resposta do vencedor da cotação atualiza o item, serviço sem horas não vai no orçamento, WhatsApp corrigido vale para a vistoria, vencedor da cotação não muda item aceito, torque só pela oficina, gestor que inicia não vira executor, depois do CORRIGIR rodas e test drive de novo, PDF enviado travado no servidor, crédito na edição, saldo anterior só do gestor, CNPJ com letras; avisos e painel lendo em lote; Ajustes agrupados | Pronto |

### Primeiros passos do gestor (uma vez, antes do piloto)
1. **Senhas:** em Ajustes › Usuários, cada pessoa com o próprio nome e uma senha nova (as contas genéricas vêm com a senha do `.env`).
2. **Crédito:** em Financeiro › Crédito, tipo, prazo e limite da Izi Car e das frotas, e o saldo anterior de quem já devia. Sem limite, a venda a prazo trava (à vista funciona; o gestor pode liberar com motivo).
3. **Regras do dinheiro:** em Financeiro › Meta do mês › Regras do dinheiro, meta, imposto, maquininha, reserva, retirada, feriados estaduais/municipais e o saldo do banco de um dia. Em branco, nada é estimado.
4. **Contas fixas:** em Financeiro › Contas fixas, folha, aluguel, energia, sistema, contador e pró-labore.
5. **Dados antigos:** em Ajustes › Importar dos artefatos (o arquivo da exportação) e, se quiser o histórico, Ajustes › Importar a planilha (com a data de corte).
6. **Compras:** os boletos importados aparecem em Financeiro › Compras como "sem passar pela conferência"; "Está certo, conferi" nos que estiverem certos. Parâmetros em Ajustes › Conferência de compras.
7. **Conferir:** Ajustes › Saúde do sistema toda verde.

## Rodar no computador

Precisa de PHP 8.2+ (com as extensões `gd`, `exif`, `intl`, `mbstring`, `pdo_sqlite`), Composer e Node (este só para recompilar o CSS).

```bash
cd os
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # 5 usuários, 3 fornecedores e 2 OS de exemplo (uma pronta para cotar), senha multmec123
php artisan db:seed --class=FinanceDemoSeeder   # lançamentos de exemplo do Financeiro (depois do DemoSeeder)
php artisan serve                        # http://127.0.0.1:8000
```

Usuários de exemplo:
- gabriel@exemplo.test e matheus@exemplo.test (gestor);
- giovana@exemplo.test (atendimento);
- robson@exemplo.test e gabriel.mecanico@exemplo.test (mecânico).

```bash
php artisan test        # testes
vendor/bin/pint         # arruma o estilo do código (o CI confere)
npm install && npm run build:css   # depois de mexer em classes do Tailwind nas telas; commitar public/css/app.css
```

## Publicar

O sistema fica no mesmo plano da Hostinger que o de infrações (plano Business, hospedagem compartilhada), no endereço **https://multmec.izicarlocadora.com**.

| O quê | Onde no servidor |
|---|---|
| Sistema (fora da pasta pública) | `~/domains/izicarlocadora.com/multmec-app/` |
| Pasta pública do subdomínio | `~/domains/izicarlocadora.com/public_html/multmec/` (cópia de `public/` + `deploy/index.php`, que aponta para o sistema) |
| Fotos, vídeos, assinaturas, PDFs | `multmec-app/storage/app/private/` |
| Configuração (senhas) | `multmec-app/.env` (nunca vai para o Git) |
| Cópias de segurança | `~/domains/izicarlocadora.com/multmec-backups/` |

A pasta pública é uma **cópia**, não um link: o LiteSpeed da Hostinger nem sempre segue links simbólicos.

**Como publica:** cada mudança aprovada entra na branch `main`. Com os testes verdes, o GitHub Actions (job **Publicar**, em `.github/workflows/os.yml`):
1. envia os arquivos por SSH, sem tocar no `.env` nem em `storage/`;
2. roda `deploy/publicar.sh` no servidor, que faz:
   - manutenção;
   - `migrate`;
   - JS do Livewire;
   - cópia da pasta pública;
   - `optimize`;
   - volta ao ar;
   - `sistema:conferir-deploy`, que tem que terminar em "Deploy conferido.".

**Publicar à mão** (do computador com a chave de deploy), depois de enviar os arquivos:

```bash
ssh -i ~/.ssh/infrasafecar_deploy -p 65002 u196070522@82.25.67.134 'bash domains/izicarlocadora.com/multmec-app/deploy/publicar.sh'
```

### Primeira vez (uma só)

1. **hPanel → Bancos de dados → Gerenciamento.**
   - Crie o banco e o usuário. A Hostinger põe o prefixo da conta, por exemplo `u196070522_multmec`.
   - Guarde a senha só com você.
2. **GitHub → Multmec → Settings → Secrets and variables → Actions:** segredo `HOSTINGER_SSH_KEY` com a chave **privada** de deploy, a mesma do sistema de infrações (arquivo `infrasafecar_deploy`, sem `.pub`).
3. **hPanel → Sites → multmec.izicarlocadora.com → Implantações:** se houver repositório conectado, desconecte. Aquela implantação copia o repositório inteiro para a pasta pública.
4. **hPanel → Avançado → Configuração do PHP:**
   - PHP 8.4;
   - `upload_max_filesize` e `post_max_size` = 25M;
   - `memory_limit` = 512M;
   - `max_execution_time` = 120.
5. **Primeira publicação.**
   - Ela cria o `multmec-app/.env` com uma chave nova e deixa o site em manutenção, porque ainda falta o banco.
   - No **Gerenciador de arquivos** do hPanel, abra `domains/izicarlocadora.com/multmec-app/.env` e preencha `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e `SENHA_INICIAL`.
   - Publique de novo: no GitHub, Actions → Sistema de OS → Run workflow, na `main`.
6. **Contas iniciais.** A publicação cria sozinha, só quando ainda não há nenhum usuário, as contas `gestor1@multmec.local`, `gestor2@`, `atendimento@`, `mecanico1@` e `mecanico2@`, todas com a `SENHA_INICIAL` do `.env`.
   - Cada pessoa usa sempre a mesma conta.
   - O nome e o e-mail se trocam depois em Ajustes > Usuários.
   - Cada pessoa troca a própria senha em "Trocar minha senha".
   - À mão, por SSH: `php artisan usuarios:iniciais`.
7. **hPanel → Avançado → Cron Jobs**, duas linhas (troque `u196070522` se o usuário for outro):

   ```
   * * * * *   php /home/u196070522/domains/izicarlocadora.com/multmec-app/artisan schedule:run
   0 3 * * *   bash /home/u196070522/domains/izicarlocadora.com/multmec-app/deploy/backup.sh
   ```

8. **Conferir.**
   - Entre como gestor e abra Ajustes > Saúde do sistema: tudo verde, inclusive o envio de 20 MB e o PDF de teste.
   - Depois abra uma OS de teste no celular.

### Voltar atrás

Reverta o commit na `main` (`git revert`) e envie: a publicação roda de novo. O banco não volta sozinho. Se uma migração precisar ser desfeita, use a cópia em `multmec-backups/`.

## Backup

`deploy/backup.sh` roda todo dia às 3h (cron do passo 7) e guarda em `~/domains/izicarlocadora.com/multmec-backups/`:

- **Banco:** `banco-AAAA-MM-DD.sql.gz`, um por dia, 14 dias. A senha é lida do `.env` e não aparece na lista de processos.
- **Fotos, vídeos, assinaturas e PDFs:**
  - `arquivos-AAAA-MM-DD-completa.tar.gz` aos domingos;
  - `arquivos-AAAA-MM-DD-dia.tar.gz` (só o que mudou) nos outros dias;
  - 3 semanas.

**Restaurar:**
- **Banco:** `gunzip -c banco-AAAA-MM-DD.sql.gz | mysql -u USUARIO -p NOME_DO_BANCO`.
- **Arquivos:** extraia a última completa e depois cada arquivo de dia, em ordem, dentro de `multmec-app/storage/app`:

  ```bash
  tar --listed-incremental=/dev/null -xzf arquivos-AAAA-MM-DD-completa.tar.gz -C ~/domains/izicarlocadora.com/multmec-app/storage/app
  ```

**Cópia fora do servidor.** O backup da Hostinger cobre o plano, mas tenha uma cópia sua, uma vez por semana:
- hPanel → Gerenciador de arquivos → `multmec-backups`: baixe o último `banco-*.sql.gz` e o último `arquivos-*-completa.tar.gz`;
- ou, do computador com a chave:

  ```bash
  scp -P 65002 -i ~/.ssh/infrasafecar_deploy -r u196070522@82.25.67.134:domains/izicarlocadora.com/multmec-backups ./multmec-backups
  ```

Em Ajustes > Saúde do sistema > Armazenamento aparece quanto espaço o sistema já ocupa.

## Dados reais fora do Git

Nunca vão para o repositório: `.env`, banco de dados, fotos e vídeos, PDFs de clientes. O `.gitignore` já bloqueia.
