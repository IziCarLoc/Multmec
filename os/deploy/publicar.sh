#!/usr/bin/env bash
# Publica a versão que está em multmec-app: migrações, pasta pública do subdomínio e caches.
# Roda no servidor, chamado pelo GitHub Actions depois do rsync (ou à mão, por SSH; ver README).
set -euo pipefail

BASE="$HOME/domains/izicarlocadora.com"
APP="$BASE/multmec-app"
DOCROOT="$BASE/public_html/multmec"

cd "$APP"

# Primeira publicação: configuração de produção com APP_KEY nova (depois disso o .env não é tocado).
if [ ! -f .env ]; then
    cp deploy/.env.producao.example .env
    chmod 600 .env
    php artisan key:generate --force --no-interaction
    echo ".env de produção criado."
fi

mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private bootstrap/cache

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

# JS do Livewire estático e minificado em public/vendor/livewire (antes de copiar a pasta pública).
php artisan livewire:publish --assets --no-interaction >/dev/null
copiar_publica

php artisan optimize --no-interaction
php artisan up >/dev/null 2>&1 || true

echo "Publicado em $(date '+%d/%m/%Y %H:%M')."

# Confere o que o código pede (pacotes, migrations, caches, APP_DEBUG): termina em "Deploy conferido.".
php artisan sistema:conferir-deploy
