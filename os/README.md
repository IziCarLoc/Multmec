# Sistema de OS da Multmec Liberdade

Ordens de serviço da oficina, do recebimento do carro até a entrega. O sistema registra quem fez cada etapa e trava os dois portões:
- **nada é executado sem aceite** do cliente;
- **nada é entregue sem conferência** feita por quem não executou.

Mecânicos usam no celular; o atendimento usa no celular da oficina e no computador.

- **Tecnologia:** a mesma do Gestão de Frota da Izi Car.
  - Laravel 12, PHP 8.4 (a do servidor), Livewire 4 com Alpine e Tailwind já compilado.
  - PDF com dompdf; MySQL na Hostinger.
- **Onde fica:** pasta `os/` do repositório Multmec. O controle financeiro em Node (`app/`) é outro sistema.
- **Convenções para quem mexe no código:** [`CLAUDE.md`](CLAUDE.md).

## Situação

| Entrega | O que tem | Situação |
|---|---|---|
| 0 | Acesso com e-mail e senha ("manter conectado"), perfis Gestor / Atendimento / Mecânico, usuários com senha provisória, Ajustes (oficina, numeração, regras, textos dos termos), Saúde do sistema com teste da hospedagem, app instalável no celular | Pronto |
| 1 | Clientes e veículos (busca por placa, frota Izi vinda do Gestão), fornecedores e prestadores, OS com etapas, travas, histórico e volta pelo gestor, quadro, lista e avisos por perfil, publicação automática | Pronto |
| 2 | Fotos pela câmera com hash conferido, original intacto, carimbo e miniatura; vistoria parte A (fotos guiadas, painel, acessórios, avarias marcadas na foto, autorizações, quem aprova) e parte B (14 itens); PDF da vistoria; envio pelo WhatsApp em dois toques ou assinatura na tela | Pronto |
| 3 a 6 | Diagnóstico, cotação, orçamento e aceite, execução, conferência, entrega e PDFs | Até 5/11 (publicado e testável em 7/11) |
| 7 a 9 | Importação dos artefatos, Financeiro (artefato + sistema em Node juntos) | Depois do piloto |

## Rodar no computador

Precisa de PHP 8.2+ (com as extensões `gd`, `exif`, `intl`, `mbstring`, `pdo_sqlite`), Composer e Node (este só para recompilar o CSS).

```bash
cd os
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # 5 usuários, 3 fornecedores e 2 OS de exemplo, senha multmec123
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
6. **Contas iniciais**, por SSH (ou pelo Terminal do hPanel), uma vez:

   ```bash
   cd ~/domains/izicarlocadora.com/multmec-app && php artisan usuarios:iniciais
   ```

   Cria `gestor1@multmec.local`, `gestor2@`, `atendimento@`, `mecanico1@` e `mecanico2@`, todas com a `SENHA_INICIAL`. Cada pessoa usa sempre a mesma conta. Nome e e-mail se trocam depois em Ajustes > Usuários, e cada um troca a senha em "Trocar minha senha".
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
