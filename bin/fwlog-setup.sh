#!/bin/bash
# Sprejem logov požarnega zidu z routerjev (syslog UDP) -> /var/log/noc-fw/fw.log, ki ga bere NOC (cron/minute.php).
# Poženi kot root. Je idempotenten: ob nespremenjeni nastavitvi ne naredi ničesar (update.sh ga požene ob vsaki posodobitvi).
# Port za routerje odpre bin/ufw-sync.sh (samo za IP-je routerjev iz NOC).
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
set -u
PORT=5514
DIR=/var/log/noc-fw
CONF=/etc/rsyslog.d/30-noc-fw.conf
ROT=/etc/logrotate.d/noc-fw

[ "$(id -u)" = 0 ] || { echo "poženi kot root"; exit 1; }
command -v rsyslogd >/dev/null || { echo "rsyslog ni nameščen (apt install rsyslog)"; exit 1; }
getent group www-data >/dev/null || { echo "skupina www-data ne obstaja"; exit 1; }

# rsyslog na Debian/Ubuntu teče kot uporabnik syslog (če obstaja); www-data datoteko samo bere
OWNER=root; id -u syslog >/dev/null 2>&1 && OWNER=syslog
mkdir -p "$DIR"; chown "$OWNER":www-data "$DIR"; chmod 2750 "$DIR"     # setgid: nove datoteke dobijo skupino www-data
[ -f "$DIR/fw.log" ] || touch "$DIR/fw.log"
chown "$OWNER":www-data "$DIR/fw.log"; chmod 640 "$DIR/fw.log"

# imudp naložimo samo, če ga ne nalaga že kdo drug (dvojni module(load=...) rsyslog zavrne)
MOD='module(load="imudp")'
OTHER=$(ls /etc/rsyslog.d/*.conf 2>/dev/null | grep -v "$CONF" || true)
if grep -hqsE '^[[:space:]]*(module\(load="imudp"|\$ModLoad[[:space:]]+imudp)' /etc/rsyslog.conf $OTHER; then MOD='# imudp je že naložen v drugi nastavitvi'; fi

# Ubuntu ima $RepeatedMsgReduction on: enake zaporedne vrstice bi se združile in štetje poskusov bi bilo napačno.
# rsyslog 8 upošteva zadnjo vrednost za vse akcije, zato velja za ves rsyslog (v syslog se ponovljene
# vrstice ne združujejo več v "message repeated N times" – drugih učinkov ni).

NEW=$(mktemp)
cat > "$NEW" <<EOF
# NOC – zapisi požarnega zidu z routerjev (UDP $PORT). Ne urejaj ročno – generira /var/www/noc/bin/fwlog-setup.sh
$MOD
\$RepeatedMsgReduction off
template(name="NocFw" type="string" string="%timegenerated:::date-unixtimestamp%|%fromhost-ip%|%rawmsg%\n")
ruleset(name="noc_fw") {
  action(type="omfile" file="$DIR/fw.log" template="NocFw" fileCreateMode="0640")
  stop
}
input(type="imudp" port="$PORT" ruleset="noc_fw")
EOF

ROTNEW=$(mktemp)
cat > "$ROTNEW" <<EOF
# NOC – zapisi požarnega zidu (generira bin/fwlog-setup.sh)
$DIR/fw.log {
    daily
    rotate 7
    maxsize 200M
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su $OWNER www-data
}
EOF
cmp -s "$ROTNEW" "$ROT" || { install -m 644 "$ROTNEW" "$ROT"; echo "logrotate: $ROT"; }
rm -f "$ROTNEW"

if cmp -s "$NEW" "$CONF"; then rm -f "$NEW"; echo "rsyslog: nespremenjeno"; exit 0; fi
OLD=""; [ -f "$CONF" ] && { OLD=$(mktemp); cp "$CONF" "$OLD"; }
install -m 644 "$NEW" "$CONF"; rm -f "$NEW"
if ! rsyslogd -N1 >/dev/null 2>&1; then      # neveljavna nastavitev -> povrni prejšnjo, rsyslog ostane nedotaknjen
    if [ -n "$OLD" ]; then cp "$OLD" "$CONF"; else rm -f "$CONF"; fi
    rm -f "$OLD"; echo "rsyslog zavrnil nastavitev (rsyslogd -N1) – nič ni spremenjeno"; exit 1
fi
rm -f "$OLD"
if command -v systemctl >/dev/null && systemctl is-active --quiet rsyslog 2>/dev/null; then systemctl restart rsyslog; else pkill -HUP rsyslogd 2>/dev/null || true; fi
echo "rsyslog: sprejem na UDP $PORT -> $DIR/fw.log"
