# NOC – namestitev na strežnik (noc.dezman.net)

Zahteve: PHP 8.1+ (pdo_mysql, mbstring, openssl, zip, iconv), MariaDB, Apache z mod_rewrite in mod_headers, composer, nfdump.

## 1. Baza

```bash
sudo mysql -e "CREATE DATABASE noc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'noc'@'localhost' IDENTIFIED BY 'MOČNO-GESLO';
GRANT ALL ON noc.* TO 'noc'@'localhost';"
```

## 2. Koda

```bash
sudo mkdir -p /var/www/noc
# razširi zip v /var/www/noc
cd /var/www/noc
sudo composer install                      # PHPMailer
mysql -u noc -p noc < sql/schema.sql
# schema.sql že vsebuje vse posodobitve – označi jih kot izvedene, da jih bin/update.sh ne poganja znova
sudo mkdir -p /var/lib/noc && ls sql/update-*.sql | xargs -n1 basename | sudo tee /var/lib/noc/applied-sql.txt > /dev/null
cp config.sample.php config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # vrednost za app_secret
nano config.php                             # geslo baze, app_secret, SMTP, noc_ip
# koda v lasti root, splet (www-data) jo samo bere – root skripte v bin/ ne smejo biti zapisljive za splet
sudo chown -R root:www-data /var/www/noc
sudo find /var/www/noc -path /var/www/noc/.git -prune -o -type d -exec chmod 750 {} + -o -type f -exec chmod 640 {} +
sudo chmod 750 /var/www/noc/bin/*.sh
```

`app_secret` po zagonu NE spreminjaj – z njim so šifrirani API ključi routerjev in Telegram token.

## 3. Apache vhost

`DocumentRoot` mora kazati na mapo `public`:

```apache
DocumentRoot /var/www/noc/public
<Directory /var/www/noc/public>
    AllowOverride All
    Require all granted
</Directory>
```

```bash
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

## 4. Prvi uporabnik (superadmin)

```bash
cd /var/www/noc
sudo -u www-data php bin/user.php add miha@dezman.net "Miha Dežman"   # vpraša za geslo (min. 10 znakov)
```

Ostali ukazi: `php bin/user.php passwd email` (novo geslo, odklene prijavo), `php bin/user.php list`.

## 5. Traffic-flow (promet po napravah v LAN-u)

```bash
sudo apt install nfdump
sudo systemctl disable --now nfdump 2>/dev/null   # paketni servis ne potrebujemo
sudo mkdir -p /var/lib/noc/nfcapd
sudo chown -R www-data:www-data /var/lib/noc
sudo cp deploy/nfcapd-noc.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfcapd-noc
```

UDP 2055 odpira `bin/ufw-sync.sh` samo za javne IP-je routerjev iz NOC (seznam piše aplikacija v `/var/lib/noc/flow-ips.txt`).

## 6. Cron

Glej `deploy/crontab.txt`:

```bash
sudo crontab -u www-data -e
```
```
* * * * *    php /var/www/noc/cron/minute.php
*/5 * * * *  php /var/www/noc/cron/flows.php
15 3 * * *   php /var/www/noc/cron/daily.php
```

```bash
sudo crontab -e
```
```
*/5 * * * *  /var/www/noc/bin/ufw-sync.sh
```

## 7. Preverjanje

1. Prijava na https://noc.dezman.net
2. **Sistem**: vsi trije cron-i morajo biti "OK", nfcapd "teče".
3. **Alarmi in obvestila**: vpiši e-naslov in klikni "Testni e-mail". Nato vpiši token bota, shrani, botu v Telegramu pošlji sporočilo, klikni "Poišči chat ID" in "Testno Telegram sporočilo".
4. **Naprave → Dodaj napravo**: naloži `/export`, preveri predlog, prenesi paket in ga uvozi na router (`/import noc-install.rsc`).

## Posodobitve

Zip naloži v `/home/miha` in poženi:

```bash
sudo /var/www/noc/bin/update.sh          # najnovejši /home/miha/noc-v*.zip
sudo /var/www/noc/bin/update.sh 1.5      # točno določena verzija
```

Skripta naredi dump baze (`/var/backups/noc/pred-vX-….sql.gz`), commit trenutnega stanja, razširi zip, nastavi pravice,
izvede nove `sql/update-*.sql` (vsakega samo enkrat, seznam v `/var/lib/noc/applied-sql.txt`) ter naredi commit in push na GitHub.

## Dvostopenjska prijava (2FA)

Moj račun → Dvostopenjska prijava → Vklopi 2FA → skeniraj QR kodo (Google Authenticator, Aegis, 2FAS …) → vpiši kodo.
Izgubljen telefon: `sudo -u www-data php /var/www/noc/bin/user.php 2fa-off miha@dezman.net` (ali superadmin pri Uporabnikih → Ponastavi 2FA).

## Telegram ukazi

Alarmi in obvestila → Telegram → **Vklopi ukaze v Telegramu** (nastavi webhook). V Telegramu pošlji botu `/pomoc`.
Ukazi: `/stanje`, `/alarmi`, `/naprava ime`, `/promet ime`, `/utisaj ime 2h`, `/porocilo ime`; pri alarmih gumba Potrdi in Utišaj 1 h.
Bot upošteva samo sporočila iz nastavljenega chat ID-ja. Ko so ukazi vklopljeni, "Poišči chat ID" ne deluje (Telegram ne dovoli obojega hkrati).

## Dodajanje routerja z enim ukazom

Naprave → Dodaj napravo → **Ustvari kodo in ukaz** → ukaz prilepi v terminal routerja → stran se sama nadaljuje → potrdi predlog → namestitev z ukazom s strani Paket.
Koda velja 30 minut in je enkratna.

## Zaščita omrežja (filtriranje)

Naprava → **Zaščita** → profil (Osnovna / Družinska), dodatna stikala, omrežja → Shrani → na routerju poženi namestitveni ukaz s strani Paket.
Varnostno IP listo (Spamhaus DROP) strežnik prenese vsak dan (`cron/daily.php`) v `/var/lib/noc/spamhaus-drop.txt`, routerji jo prevzamejo ob 5h.
Ob izklopu se vse nastavitve `noc-filter` odstranijo in DNS routerja vrne na prvotnega.

## Pregled požarnega zidu

Naprava → **Zaščita** → Pregled požarnega zidu. NOC pregleda zadnji nočni /export po pravilih dobre prakse (brez AI) in predlaga popravke.
Izbrane popravke uveljaviš z namestitvenim ukazom. Vsa pravila nosijo oznako `noc-fw`.
Varovalki: upravljalni naslovi (Alarmi in obvestila → Upravljalni naslovi) so vedno dovoljeni; če router po spremembi
v 5 minutah ne doseže NOC (`/api/fw-confirm`), sam povrne vse spremembe.

## Stanje portov in SFP

Vmesniki → Stanje porta (zadnja prekinitev/vzpostavitev, števec routerja, štetje NOC, časovnica) in SFP moduli
(RX/TX moč, temperatura, grafi 30 dni). Pragovi RX: 1G opozorilo −20 / kritično −23 dBm, 10G −12 / −14 dBm; nastavljivo po portu.

## Samodejne posodobitve routerjev

Router v vsakem pushu sporoči verzijo svoje skripte. Če se v NOC kaj spremeni (nova verzija NOC ali nastavitve naprave),
NOC v odgovoru sporoči, da je na voljo nova skripta, router pa jo sam prenese z `/api/install/auto` in uvozi
(največ en poziv na 30 min). Razdelek požarnega zidu se pri tem ne spreminja – ta gre na router samo z ročnim ukazom s strani Paket.
Stikalo je pri napravi v Nastavitvah ("Samodejne posodobitve skripte"), stanje pa v kartici Podatki in na strani Posodobitve.
Routerji s skripto pred v1.9 se morajo enkrat posodobiti ročno.

## Varnostna analiza (logi požarnega zidu + odhodni promet)

Naprava → **Zaščita** → Varnostna analiza → **Vklopi** (ali Alarmi in obvestila → Varnostna analiza → **Vklopi pri vseh**).
Router s samodejno posodobitvijo dobi: pravilo za beleženje pred končnim drop v input (`noc-log`, 20 zapisov/min na izvorni naslov),
beleženje zadetkov varnostne liste (če je vklopljena zaščita z IP listami) in pošiljanje tem `firewall` na NOC (syslog UDP 5514).
Na strežniku `bin/update.sh` sam pokliče `bin/fwlog-setup.sh` (rsyslog -> `/var/log/noc-fw/fw.log`, logrotate 7 dni),
`bin/ufw-sync.sh` odpre UDP 5514 samo za IP-je routerjev, `cron/minute.php` zapise povzame in vsakih 5 min analizira.
Odhodni promet (skeniranje interneta, SMTP mimo strežnika, varnostna lista) se analizira iz traffic-flow (cron/flows.php).
Rezultati: zavihek **Varnost** pri napravi. Hramba: povzetki 5 min 7 dni, dnevni 400 dni.

## Stikala za routerjem (v1.37)

Stikala MikroTik z RouterOS 7 (CRS3xx/5xx, netPower z ROS) so v NOC naprave vrste **Stikalo**, vezane na router stranke.

- **Dodajanje:** Naprave → Dodaj napravo → enkratni ukaz prilepiš v terminal stikala (Winbox). NOC iz konfiguracije prepozna stikalo; izberi **Za routerjem**. Stikalo mora prek routerja doseči noc.dezman.net (privzeta pot in DNS).
- **Pregled:** sprednja plošča (lebdenje = povzetek porta, klik = panel porta s stanjem, grafom, nastavitvami in dogodki). Pri routerju so njegova stikala prikazana eno pod drugim.
- **Urejanje porta:** ime, vklop/izklop, VLAN (access) ali trunk (netagiran + tagirani VLAN-i), PoE. Samo z vklopljenim **bridge VLAN filtering**. Pred pošiljanjem predogled; sprememba gre ob naslednjem pushu.
- **Varovalka:** stikalo pred spremembo shrani prejšnje stanje v skripto `noc-sw-rb-<id>`; če po spremembi ne doseže NOC, se čez 5 minut samo povrne. Sprememba se nikoli ne pošlje dvakrat.
- **Obvestila:** kljukica »Obvesti, ko port pade« pri portu (polje Porti z obvestilom). Izpad uplinka = alarm »stikalo ne javlja«; če ne javlja tudi router, pride samo alarm routerja.
- **Grafi:** 5-min promet za vse porte (porti brez obvestila 7 dni), minutni samo za uplink in porte z obvestilom, dnevni 400 dni.
- **Terminal:** prek routerja stranke (SSH skok). Router mora imeti vklopljen terminal in javni IP; namestitev terminala na routerju doda `/ip ssh set forwarding-enabled=local`.
- **Prva konfiguracija (v1.38):** pri praznem stikalu (brez VLAN filteringa in brez VLAN-ov) je na strani stikala gumb **Prva konfiguracija**. Izbereš uplink in vrstice »porti → VLAN« (`1-5`, `1,3,7-9`, `S1`). Uplink postane trunk (vsi VLAN-i tagirani), upravljanje ostane netagirano v VLAN 1, VLAN filtering se vklopi zadnji. Enaka varovalka kot pri portu. Nato NOC pokaže predlogo ukazov za router (VLAN vmesniki proti stikalu) – router nastaviš sam.
- **Luknje v grafih (v1.38):** ko naprava ne javlja, se črta v grafu prekine (prej je povezala točki čez izpad).

## Nenormalen promet v LAN (v1.44)

- Na vseh routerjih (samodejna posodobitev skripte; stikala ne): mangle pravila z oznako `noc-lan` za nove TCP povezave iz LAN omrežij naprave. Prometa ne blokirajo.
- Zaznave (naprava gre za 15 min na seznam `noc-lanw-<vrsta>`): DNS izbruh (> 20 hkratnih TCP/53), preveč povezav (> 1000 TCP), skeniranje portov routerja in LAN omrežij (`psd`), SMTP navzven (> 5 hkratnih TCP/25). DNS in SMTP štejeta v svoji verigi (samo te povezave). Samo IPv4 in TCP.
- Push sporoči vnose (največ 10 + skupno število); NOC odpre alarm `lan:<vrsta>:<ip>` z imenom naprave in portom storitve (pri povezavah/skeniranju 3 najpogostejši ciljni porti), zapre ga, ko vnosa 3 min ni več. Poštni strežnik s preusmeritvijo porta 25 (dst-nat) je samodejno izjema. `noc-remove.rsc` odstrani vse.
- Blokada SMTP (privzeto **izklopljena** – najprej opazovanje; vklop na napravi): naprava 24 h ne more na TCP/25 navzven (pravilo `noc-lan: blokada SMTP` v filter/forward). Odblokiranje: `/ip firewall address-list remove [find list=noc-lanw-smtp address=IP]`.
- Naprava → Zaščita → **Nenormalen promet v LAN**: vklop, blokada SMTP, izjeme (IP/omrežja). Alarme vrste »Nenormalen promet v LAN« izklopiš pri nastavitvah alarmov naprave.
- Omejitev: promet med napravami v istem omrežju gre mimo routerja – vidno je skeniranje routerja in drugih omrežij/VLAN-ov.
- AI razlaga alarmov dobi vrstico »ZAŠČITE NOC« (filtrirni DNS / DNS ni odprt na WAN, varnostna analiza, zaznavanje LAN, terminal).

## fail2ban (priporočeno)

NOC v Apache error log piše `noc-login-fail ip=…` (napačna prijava) in `noc-api-badkey ip=…` (neveljaven API ključ).

```bash
sudo apt install fail2ban
sudo cp deploy/fail2ban/filter.d/noc.conf /etc/fail2ban/filter.d/
sudo cp deploy/fail2ban/jail.d/noc.local /etc/fail2ban/jail.d/
sudo systemctl restart fail2ban
sudo fail2ban-client status noc
```

V `noc.local` preveri `logpath` (error log vhosta) in `ignoreip` (tvoji statični naslovi). Po 8 napakah v 10 minutah je IP za 1 uro blokiran prek ufw.

## Struktura

| Mapa | Vsebina |
|---|---|
| `public/` | index.php (usmerjanje, API), assets (CSS, JS, pisave, uPlot) |
| `src/` | logika: Ingest (push), Alerts, Notify, Flows, Metrics, MikrotikExport (analiza), MikrotikScript (paket), Diff |
| `views/` | predloge strani |
| `lang/en.php` | angleški prevod (ključ = slovenski niz) |
| `cron/` | minute, flows, daily |
| `bin/` | user.php, ufw-sync.sh |
| `sql/schema.sql` | shema baze |
| `deploy/` | systemd servis za nfcapd, crontab |

Hramba: minutni podatki 48 h, 5-minutni 30 dni, dnevni 400 dni, logi 90 dni, zadnjih 60 sprememb konfiguracije na napravo.
