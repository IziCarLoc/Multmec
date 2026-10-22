#!/usr/bin/env bash
# Cópia diária do banco (MySQL) e dos arquivos (fotos, vídeos, assinaturas, PDFs) em multmec-backups.
# Banco: uma cópia por dia, 14 dias. Arquivos: completa aos domingos (ou na primeira vez) e só o que
# mudou nos outros dias, 3 semanas (para sempre sobrar uma completa com os dias seguintes).
# Agende no hPanel (Cron Jobs), uma vez por dia de madrugada:
#   bash /home/USUARIO/domains/izicarlocadora.com/multmec-app/deploy/backup.sh
set -euo pipefail

BASE="$HOME/domains/izicarlocadora.com"
APP="$BASE/multmec-app"
DEST="$BASE/multmec-backups"
DIA="$(date +%F)"

mkdir -p "$DEST"
chmod 700 "$DEST"

# Lê uma variável do .env sem executar o arquivo (aspas em volta saem).
env_valor() {
    grep -E "^$1=" "$APP/.env" | tail -n 1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

BANCO="$(env_valor DB_DATABASE)"
USUARIO="$(env_valor DB_USERNAME)"
SENHA="$(env_valor DB_PASSWORD)"
HOST="$(env_valor DB_HOST)"

# A senha vai num arquivo temporário só do usuário, não na linha de comando (que aparece no `ps`).
CNF="$(mktemp)"
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword="%s"\nhost=%s\n' "$USUARIO" "$SENHA" "${HOST:-localhost}" > "$CNF"

mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --no-tablespaces "$BANCO" | gzip > "$DEST/banco-$DIA.sql.gz.tmp"
mv "$DEST/banco-$DIA.sql.gz.tmp" "$DEST/banco-$DIA.sql.gz"

SNAP="$DEST/arquivos.snar"
if [ "$(date +%u)" = "7" ] || [ ! -f "$SNAP" ]; then
    rm -f "$SNAP"
    TIPO="completa"
else
    TIPO="dia"
fi
tar --listed-incremental="$SNAP" -czf "$DEST/arquivos-$DIA-$TIPO.tar.gz" -C "$APP/storage/app" private

find "$DEST" -name 'banco-*.sql.gz' -mtime +14 -delete
find "$DEST" -name 'arquivos-*.tar.gz' -mtime +21 -delete

echo "Backup de $DIA feito em $DEST."
