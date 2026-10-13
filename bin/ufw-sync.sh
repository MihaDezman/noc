#!/bin/bash
# cron ima okrnjen PATH (brez /usr/sbin, kjer je ufw)
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Odpre UDP porta za traffic-flow (2055) in loge požarnega zidu (5514) samo za IP-je routerjev iz NOC (seznam piše aplikacija).
# Root cron:  */5 * * * * /var/www/noc/bin/ufw-sync.sh
LIST=/var/lib/noc/flow-ips.txt
[ -f "$LIST" ] || exit 0
want=$(grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' "$LIST" | sort -u)
status=$(ufw status)
# port:oznaka – 2055 traffic-flow, 5514 logi požarnega zidu (varnostna analiza)
for pt in 2055:noc-flow 5514:noc-fwlog; do
  PORT=${pt%%:*}; TAG=${pt#*:}
  have=$(echo "$status" | grep -E "# ${TAG}[[:space:]]*\$" | awk '{print $3}' | sort -u)
  for ip in $want; do
    echo "$have" | grep -qx "$ip" || ufw allow proto udp from "$ip" to any port $PORT comment "$TAG" >/dev/null
  done
  for ip in $have; do
    echo "$want" | grep -qx "$ip" || ufw delete allow proto udp from "$ip" to any port $PORT >/dev/null
  done
done
