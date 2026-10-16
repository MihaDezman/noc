# btest strežnik za meritve hitrosti (NOC)

Routerji z `/tool bandwidth-test` merijo download in upload proti strežniku NOC.
Strežnik je odprtokodni **btest-rs** (MIT, https://git.manko.yoga/manawenuz/btest-rs) –
podpira prijavo EC-SRP5, ki jo uporablja RouterOS 6.43 in novejši.

## Namestitev (enkrat)

1. Prenesi Linux x86_64 binarno datoteko z strani Releases projekta in jo namesti:

       sudo install -m 0755 btest /usr/local/bin/btest
       /usr/local/bin/btest --help        # preveri, da teče

2. Uporabnik in geslo (samo črke, številke in . _ @ + -), enaka kot v NOC → Alarmi in obvestila → Meritve hitrosti:

       sudo sh -c 'printf "BTEST_USER=noc\nBTEST_PASS=MOJE-GESLO\n" > /etc/noc-btest.env'
       sudo chmod 600 /etc/noc-btest.env

3. Storitev in požarni zid:

       sudo cp /var/www/noc/deploy/btest/noc-btest.service /etc/systemd/system/
       sudo systemctl daemon-reload
       sudo systemctl enable --now noc-btest
       sudo ufw allow 2000/tcp comment 'NOC btest'
       systemctl status noc-btest

4. Test z routerja (v terminalu):

       /tool bandwidth-test address=IP-STREZNIKA user=noc password=MOJE-GESLO protocol=tcp direction=receive duration=5s

## Opombe
- NOC meri samo TCP (port 2000). UDP ni potreben.
- Meritve so omejene s povezavo strežnika (1 Gbit/s). Nočne meritve tečejo ena za drugo, da se ne motijo.
