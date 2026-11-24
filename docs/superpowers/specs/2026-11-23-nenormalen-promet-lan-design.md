# v1.44 – nenormalen promet v LAN + zaščite NOC v AI razlagi (kratek design)

Datum: 2026-11-23 · Osnova: v1.43

## Namen
Miha želi, da ga NOC opozori, ko naprava v LAN-u stranke počne nekaj nenormalnega (izbruh DNS povezav, ogromno povezav,
skeniranje, spam), z imenom naprave, IP-jem in portom storitve. Okuženo napravo, ki pošilja spam, naj router sam blokira.

## Odločeno (Miha)
- Zaznave: DNS izbruh, preveč povezav, skeniranje, SMTP navzven.
- »Port« = port storitve (53, 25; pri povezavah/skeniranju najpogostejši ciljni porti).
- Samodejna blokada samo za SMTP (port 25 navzven, 24 h, samo ta naprava); ostalo samo alarm.
- Na vseh routerjih (samodejna posodobitev paketa), stikala nespremenjena. Telegram takoj.

## Izvedba
- Router (mangle, oznaka `noc-lan`): skok iz `prerouting` za nove povezave iz LAN omrežij v verigo `noc-lan`;
  izjeme (`noc-lan-ok`) → return; `add-src-to-address-list` v `noc-lanw-dns|conn|scan|smtp`:
  DNS TCP/53 `connection-limit=20,32`; TCP `connection-limit=300,32`; `psd=21,3s,3,1`; TCP/25 navzven `connection-limit=5,32`.
  Časovna omejitev 15 min (SMTP z blokado 24 h). Blokada: `filter forward` drop TCP/25 za `noc-lanw-smtp`.
- Push: vnosi `noc-lanw-*` (največ 10), pri conn/scan 3 najpogostejši ciljni porti iz tabele povezav.
- NOC: alarm `lan:<vrsta>:<ip>` (odprt, dokler je vnos na seznamu), ime iz `lan_hosts`; vrsta alarma `lan` se da izklopiti.
  Star alarm iz traffic-flow (`outsmtp`) se ne sproži, če je `lan:smtp` za isti IP odprt.
- Naprava: `lan_watch` (vklop), `lan_smtp_block`, `lan_ok` (izjeme).
- AI razlaga alarma dobi vrstico »ZAŠČITE NOC« (filtrirni DNS/DNS ni odprt na WAN, varnostna analiza, zaznavanje LAN).

## Omejitve (povedano Mihi)
Promet med napravami v istem omrežju gre mimo routerja – skeniranje znotraj istega omrežja ni vidno.

## Po neodvisnem pregledu
- Blokada SMTP privzeto izklopljena (najprej teden opazovanja), vklop na napravi; dst-nat 25 cilji samodejno izjema.
- DNS/SMTP štetje v ločenih verigah; meja povezav 1000; skeniranje samo proti routerju/LAN.
- Push: vsak get v :do, porti samo za prva 2 vnosa, skupno število; NOC zapre alarm po 3 min brez vnosa.
- Seznami v :do (podvojen vnos ne ustavi uvoza), normalizacija omrežij; noc-remove odstrani noc-lan.
