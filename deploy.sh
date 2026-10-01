#!/bin/sh
# Spielt den aktuellen Git-Stand (app/ und public/) auf den Server.
# Die Datenbank in data/ auf dem Server wird dabei nicht angefasst.
#
#   ./deploy.sh                 -> Standardserver
#   THEKE_HOST=root@1.2.3.4 ./deploy.sh
set -eu

HOST="${THEKE_HOST:-root@212.227.109.165}"
TARGET=/var/www/theke-sgr

cd "$(dirname "$0")"

if [ -n "$(git status --porcelain -- app public)" ]; then
    echo "Achtung: Es gibt nicht committete Änderungen in app/ oder public/."
    echo "Übertragen wird nur der zuletzt committete Stand ($(git rev-parse --short HEAD))."
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
git archive HEAD app public | tar -x -C "$TMP"

echo "Übertrage $(git rev-parse --short HEAD) nach $HOST …"
rsync -rlptz --delete "$TMP/app/" "$HOST:$TARGET/app/"
rsync -rlptz --delete "$TMP/public/" "$HOST:$TARGET/public/"

ssh "$HOST" "chown -R root:root $TARGET/app $TARGET/public \
  && find $TARGET/app $TARGET/public -type d -exec chmod 755 {} + \
  && find $TARGET/app $TARGET/public -type f -exec chmod 644 {} + \
  && echo $(git rev-parse --short HEAD) > $TARGET/REVISION \
  && systemctl reload php8.3-fpm"

echo "Fertig."
