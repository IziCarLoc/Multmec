#!/usr/bin/env bash
# Publica a versão que está em multmec-app: migrações, pasta pública do subdomínio e caches.
# Roda no servidor, chamado pelo GitHub Actions depois do rsync (ou à mão, por SSH; ver README).
set -euo pipefail

BASE="$HOME/domains/izicarlocadora.com"
APP="$BASE/multmec-app"
DOCROOT="$BASE/public_html/multmec"

cd "$APP"

# Pastas de trabalho antes de qualquer comando do Laravel: o envio (rsync) não leva storage/ nem
# bootstrap/cache/, para nunca apagar fotos e PDFs; na primeira vez elas ainda não existem.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private bootstrap/cache

# Primeira publicação: configuração de produção (depois disso o .env não é tocado).
if [ ! -f .env ]; then
    cp deploy/.env.producao.example .env
    chmod 600 .env
    echo ".env de produção criado."
fi

# APP_KEY nova só se estiver vazia (também cobre uma primeira publicação que parou no meio).
if ! grep -qE '^APP_KEY=.+' .env; then
    php artisan key:generate --force --no-interaction
fi

# Página padrão da Hostinger sai da pasta pública (fica guardada fora dela).
if [ -f "$DOCROOT/default.php" ]; then
    mv "$DOCROOT/default.php" "$BASE/multmec-hostinger-default.php.txt"
fi

# Pasta pública: os arquivos de public/ mais o index.php que aponta para a pasta do app. Cópia, não
# link simbólico: o LiteSpeed da Hostinger nem sempre segue links.
copiar_publica() {
    mkdir -p "$DOCROOT"
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete --exclude index.php public/ "$DOCROOT/"
    else
        cp -R public/. "$DOCROOT/"
    fi
    cp deploy/index.php "$DOCROOT/index.php"
}

# Sem a senha do banco no .env (primeira vez) não há como migrar: deixa o site em manutenção e avisa.
if ! grep -qE '^DB_PASSWORD=.+' .env; then
    php artisan down --retry=60 >/dev/null 2>&1 || true
    copiar_publica
    echo "::warning::Falta o banco em multmec-app/.env (DB_DATABASE, DB_USERNAME e DB_PASSWORD). Preencha pelo Gerenciador de arquivos do hPanel e publique de novo (README, Publicar)."
    exit 0
fi

php artisan down --retry=15 >/dev/null 2>&1 || true

php artisan migrate --force --no-interaction
# Unidades e checklist padrão (não duplica nem desfaz o que foi mudado em Ajustes).
php artisan db:seed --force --no-interaction
# Primeira publicação: as 5 contas genéricas com a SENHA_INICIAL do .env (sem a senha, só avisa).
php artisan usuarios:iniciais --somente-sem-usuarios --no-interaction || echo "::warning::Contas iniciais não criadas: falta SENHA_INICIAL em multmec-app/.env."

# JS do Livewire estático e minificado em public/vendor/livewire (antes de copiar a pasta pública).
php artisan livewire:publish --assets --no-interaction >/dev/null
copiar_publica

php artisan optimize --no-interaction
php artisan up >/dev/null 2>&1 || true

echo "Publicado em $(date '+%d/%m/%Y %H:%M')."

# Confere o que o código pede (pacotes, migrations, caches, APP_DEBUG): termina em "Deploy conferido.".
php artisan sistema:conferir-deploy
