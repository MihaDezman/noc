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
sudo -u www-data composer install          # PHPMailer
mysql -u noc -p noc < sql/schema.sql
cp config.sample.php config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # vrednost za app_secret
nano config.php                             # geslo baze, app_secret, SMTP, noc_ip
sudo chown -R www-data:www-data /var/www/noc
sudo chmod 640 config.php
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
