#!/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Odpre UDP port za traffic-flow samo za IP-je routerjev iz NOC (seznam piše aplikacija).
# Root cron:  */5 * * * * /var/www/noc/bin/ufw-sync.sh
LIST=/var/lib/noc/flow-ips.txt
PORT=2055
TAG="noc-flow"
[ -f "$LIST" ] || exit 0
want=$(grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' "$LIST" | sort -u)
have=$(ufw status | grep "$TAG" | awk '{print $3}' | sort -u)
for ip in $want; do
  echo "$have" | grep -qx "$ip" || ufw allow proto udp from "$ip" to any port $PORT comment "$TAG" >/dev/null
done
for ip in $have; do
  echo "$want" | grep -qx "$ip" || ufw delete allow proto udp from "$ip" to any port $PORT >/dev/null
done
