#!/bin/bash
# NOC – posodobitev iz zipa v /home/miha
#   sudo /var/www/noc/bin/update.sh          -> najnovejši /home/miha/noc-v*.zip
#   sudo /var/www/noc/bin/update.sh 1.4      -> /home/miha/noc-v1.4.zip
# Koraki: dump baze -> commit trenutnega stanja -> unzip -> pravice -> nove sql/update-*.sql -> commit + push
set -euo pipefail

APP=/var/www/noc
SRC=/home/miha
MYCNF=/root/.my-wifi.cnf            # isti kot pri nočnem backupu
BK=/var/backups/noc
STATE=/var/lib/noc/applied-sql.txt  # že izvedene sql/update-*.sql

ok()   { echo -e "  \e[32m✓\e[0m $*"; }
fail() { echo -e "  \e[31m✗ $*\e[0m"; exit 1; }
G()    { git -c safe.directory="$APP" "$@"; }   # repo je v lasti www-data, skripta teče kot root

[ "$(id -u)" = 0 ] || fail "Poženi kot root: sudo $0 $*"

if [ $# -ge 1 ]; then ZIP="$SRC/noc-v$1.zip"
else ZIP=$(ls -1 "$SRC"/noc-v*.zip 2>/dev/null | sort -V | tail -n 1 || true); fi
[ -n "${ZIP:-}" ] && [ -f "$ZIP" ] || fail "Ni paketa ${ZIP:-$SRC/noc-v*.zip}"
VER=$(basename "$ZIP" .zip); VER=${VER#noc-v}

echo "NOC posodobitev na v$VER  ($ZIP)"
unzip -tq "$ZIP" >/dev/null || fail "Zip je poškodovan"
LIST=$(unzip -Z1 "$ZIP")                      # seznam v spremenljivko (grep -q v cevi + pipefail = lažna napaka)
grep -qx "src/bootstrap.php" <<< "$LIST" || fail "Zip nima pričakovane strukture (src/ na vrhu)"
ok "paket preverjen"

cd "$APP"

# 1. dump baze
mkdir -p "$BK"; chown root:www-data "$BK"; chmod 750 "$BK"
DUMP="$BK/pred-v$VER-$(date +%F_%H%M).sql.gz"
( umask 077; mysqldump --defaults-extra-file="$MYCNF" --single-transaction --routines --triggers noc | gzip -9 > "$DUMP" ) \
    || { rm -f "$DUMP"; fail "dump baze ni uspel – posodobitev prekinjena, nič ni spremenjeno"; }
ok "baza shranjena: $DUMP ($(du -h "$DUMP" | cut -f1))"

# 2. trenutno stanje v git
G add -A
if ! G diff --cached --quiet; then G commit -qm "stanje pred v$VER"; ok "commit: stanje pred v$VER"; else ok "brez lokalnih sprememb"; fi

# 3. nova koda
unzip -oq "$ZIP" -d "$APP"
chown -R www-data:www-data "$APP"
chmod 640 "$APP/config.php"
chmod 755 "$APP"/bin/*.sh
ok "koda razširjena, pravice nastavljene"

# 4. spremembe baze (vsaka samo enkrat)
mkdir -p "$(dirname "$STATE")"; touch "$STATE"
for f in $(ls -1 sql/update-*.sql 2>/dev/null | sort -V); do
    b=$(basename "$f")
    grep -qx "$b" "$STATE" && continue
    mysql --defaults-extra-file="$MYCNF" noc < "$f" || fail "$b ni uspel – baza je shranjena v $DUMP"
    echo "$b" >> "$STATE"
    ok "baza: $b"
done

# 5. git commit + push
G add -A
if ! G diff --cached --quiet; then G commit -qm "NOC v$VER"; ok "commit: NOC v$VER"; fi
if G push -q 2>/dev/null; then ok "push na GitHub"; else echo -e "  \e[33m! git push ni uspel – poženi ročno: cd $APP && git push\e[0m"; fi

echo "Končano: v$VER  ·  preveri https://noc.dezman.net (noga menija: v$VER)"
