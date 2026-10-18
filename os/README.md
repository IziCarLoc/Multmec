# Sistema de OS da Multmec Liberdade

Ordens de serviço da oficina, do recebimento do carro até a entrega. O sistema registra quem fez cada etapa e trava os dois portões:
- **nada é executado sem aceite** do cliente;
- **nada é entregue sem conferência** feita por quem não executou.

Mecânicos usam no celular; o atendimento usa no celular da oficina e no computador.

- **Tecnologia:** a mesma do Gestão de Frota da Izi Car.
  - Laravel 12, PHP 8.3, Livewire 4 com Alpine e Tailwind já compilado.
  - PDF com dompdf; MySQL na Hostinger.
- **Onde fica:** pasta `os/` do repositório Multmec. O controle financeiro em Node (`app/`) é outro sistema.
- **Convenções para quem mexe no código:** [`CLAUDE.md`](CLAUDE.md).

## Situação

| Entrega | O que tem | Situação |
|---|---|---|
| 0 | Acesso com e-mail e senha ("manter conectado"), perfis Gestor / Atendimento / Mecânico, usuários com senha provisória, Ajustes (oficina, numeração, regras, textos dos termos), Saúde do sistema com teste da hospedagem, app instalável no celular | Pronto |
| 1 | Clientes, veículos, OS com etapas, travas e histórico | Próxima |
| 2 a 6 | Vistoria com fotos, diagnóstico, cotação, orçamento e aceite, execução, conferência, entrega e PDFs | Até 4/11 |

## Rodar no computador

Precisa de PHP 8.2+ (com as extensões `gd`, `exif`, `intl`, `mbstring`, `pdo_sqlite`), Composer e Node (este só para recompilar o CSS).

```bash
cd os
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # 5 usuários de exemplo, senha multmec123
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

## Publicar na Hostinger (primeira vez)

O sistema roda na mesma conta da Hostinger do Gestão, num subdomínio próprio. Calcule uns 15 minutos.

### 1. No hPanel

1. **Domínios > Subdomínios:** criar o subdomínio, por exemplo `os.seudominio.com.br`.
   Se a Multmec tiver domínio próprio, use-o: o endereço aparece para o cliente nos links do WhatsApp.
2. **Bancos de dados > MySQL:** criar um banco e um usuário. Anote nome do banco, usuário e senha.
3. **Avançado > PHP Configuration**, para o subdomínio:
   - versão **8.3**;
   - em "Opções do PHP": `upload_max_filesize` **25M**, `post_max_size` **30M**, `memory_limit` **512M**, `max_execution_time` **120**;
   - extensões ligadas: `gd`, `exif`, `fileinfo`, `intl`, `mbstring`, `pdo_mysql`, `zip`.
4. **Segurança > SSL:** ativar o certificado do subdomínio.
5. **Desempenho > CDN:** se estiver ligado no domínio, desligar para o subdomínio (as telas do sistema não podem vir de cache).
6. **Avançado > Acesso SSH:** confirmar que está ativo (o Gestão já usa).

### 2. No terminal (SSH)

O código fica **fora** do `public_html`. Só a pasta `os/public` fica visível na internet; se ficasse tudo lá, o arquivo `.env` (com as senhas) e o financeiro ficariam expostos.

```bash
cd ~
git clone <endereço do repositório Multmec> multmec   # do mesmo jeito que o Gestão foi clonado
cd ~/multmec && git checkout <branch publicada>
cd ~/multmec/os

composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env
```

No `.env`, troque:
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://os.seudominio.com.br`;
- `DB_CONNECTION=mysql`, com `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` do banco criado (o host costuma ser `localhost`);
- `SESSION_SECURE_COOKIE=true`.

```bash
php artisan key:generate
php artisan migrate --force --seed          # cria as tabelas e as unidades Liberdade e Matriz
php artisan usuarios:criar seu@email.com --nome="Gabriel Cezimbra" --curto="Gabriel C." --perfil=gestor
#   → mostra a senha provisória do primeiro gestor; os outros usuários ele cria em Ajustes > Usuários
php artisan optimize
php artisan livewire:publish --assets
chmod -R 775 storage bootstrap/cache
```

**Ligar o subdomínio à pasta pública.** O hPanel cria uma pasta vazia para o subdomínio. Descubra qual é com `ls ~/domains/` e `ls ~/public_html` e veja onde fica a do Gestão para seguir o mesmo esquema. Depois troque essa pasta por um atalho:

```bash
ls -la <pasta-do-subdominio>     # confira que está vazia (ou só com o default.php da Hostinger)
rm -r <pasta-do-subdominio>
ln -s ~/multmec/os/public <pasta-do-subdominio>
```

Se a Hostinger não aceitar o atalho, edite o subdomínio no hPanel e aponte a pasta raiz dele para `multmec/os/public`.

### 3. Agendador e conferência

**hPanel > Avançado > Cron Jobs**, a cada minuto (`* * * * *`):

```
/usr/bin/php /home/SEU_USUARIO/multmec/os/artisan schedule:run
```

Use o caminho completo: no terminal, `pwd` dentro de `~/multmec/os` mostra o caminho.

```bash
php artisan sistema:conferir-deploy   # tem que terminar em "Deploy conferido."
```

Por último, abra o sistema no celular e entre com o gestor. Em **Ajustes > Saúde do sistema**:
- tudo tem que estar verde (o agendador aparece verde depois do primeiro minuto do cron);
- faça os três testes: envio de um vídeo do celular, foto carimbada e PDF com 30 fotos.

## Atualizar (cada nova entrega)

```bash
cd ~/multmec && git pull
cd os
composer install --no-dev --optimize-autoloader
php artisan migrate --force          # depois: php artisan migrate:status sem "Pending"
php artisan optimize
php artisan livewire:publish --assets
php artisan sistema:conferir-deploy  # "Deploy conferido."
```

Antes de uma atualização grande, faça a cópia do banco (abaixo).

## Backup

**Banco de dados (todo dia, automático).**

1. Crie `~/.my.cnf`, com as credenciais do banco, e rode `chmod 600 ~/.my.cnf`:

   ```
   [mysqldump]
   user=USUARIO_DO_BANCO
   password=SENHA_DO_BANCO
   ```

2. No hPanel, crie o cron das 3h (`0 3 * * *`). O comando abaixo guarda 30 dias de cópias em `~/backups`:

   ```
   mkdir -p ~/backups && mysqldump NOME_DO_BANCO | gzip > ~/backups/os-$(date +\%F).sql.gz && find ~/backups -name 'os-*.sql.gz' -mtime +30 -delete
   ```

3. Para restaurar: `gunzip -c ~/backups/os-AAAA-MM-DD.sql.gz | mysql NOME_DO_BANCO`.

**Fotos, vídeos, assinaturas e PDFs** ficam em `os/storage/app/private/`. O backup da Hostinger cobre essa pasta, mas é bom ter uma cópia fora:

```bash
tar czf ~/backups/arquivos-$(date +%F).tar.gz -C ~/multmec/os/storage/app private
```

Baixe esse arquivo de vez em quando pelo Gerenciador de Arquivos do hPanel. Em Ajustes > Saúde do sistema > Armazenamento aparece quanto espaço e quantos arquivos o sistema já ocupa.

## Dados reais fora do Git

Nunca vão para o repositório: `.env`, banco de dados, fotos e vídeos, PDFs de clientes. O `.gitignore` já bloqueia.
