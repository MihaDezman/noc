# Terminal routerjev v NOC

Brskalnik ↔ (WebSocket, /term/ws) ↔ Apache ↔ most `bin/noc-term.py` (127.0.0.1:8765) ↔ SSH ↔ router.
Strežnik NOC se z routerjem poveže kot uporabnik `noc-term` s ključem NOC. Na routerju ga nastavi
ročni namestitveni ukaz (Paket), ko je pri napravi vklopljen terminal (Nastavitve → Terminal).

## Namestitev na strežniku (enkrat)

1. Paketa:

       sudo apt install python3-websockets openssh-client

2. Most kot storitev:

       sudo cp /var/www/noc/deploy/term/noc-term.service /etc/systemd/system/
       sudo systemctl daemon-reload
       sudo systemctl enable --now noc-term
       systemctl status noc-term          # "NOC terminal most posluša na 127.0.0.1:8765"

3. Apache (WebSocket proxy) – vrstici iz `deploy/term/apache-term.conf` dodaj v vhost noc.dezman.net (443):

       sudo a2enmod proxy proxy_wstunnel
       sudo nano /etc/apache2/sites-enabled/noc*.conf      # dodaj ProxyPass vrstici
       sudo apachectl configtest && sudo systemctl reload apache2

4. Ključa NOC: NOC → Alarmi in obvestila → Terminal routerjev → "Ustvari ključa zdaj"
   (ali samodejno ob prvem vklopu terminala pri napravi). Shranjena sta v /var/lib/noc/ssh (samo www-data).

## Na routerju
Pri napravi: Nastavitve → Terminal → Vklopi, nato na routerju poženi namestitveni ukaz s strani Paket.
Ukaz doda uporabnika `noc-term` (skupina full, naključno geslo, ki se nikjer ne shrani) s ključem NOC,
pravilo `noc-term` (SSH z IP-ja strežnika NOC) na vrh verige input in, če je SSH omejen na naslove,
v `available-from` doda IP strežnika NOC. Izklop + namestitveni ukaz uporabnika in pravilo odstrani.

## Varnost
- Sejo odpre samo superadmin; žeton velja 60 s, enkrat, samo z istega IP-ja kot brskalnik.
- Vsaka seja je v revizijski sledi, izpis (brez vnosa gesel – RouterOS jih ne izpiše) v /var/lib/noc/term (180 dni).
- Seja se zapre po 30 min brez vnosa ali po 4 h.
- Prstni odtis routerja se ob prvi povezavi shrani v /var/lib/noc/ssh/known_hosts; če se kasneje spremeni,
  povezava ne uspe (zaščita pred podtaknjenim strežnikom). Po zamenjavi routerja izbriši njegovo vrstico.
