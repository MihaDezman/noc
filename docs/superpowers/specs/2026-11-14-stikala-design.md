# NOC – MikroTik stikala za routerjem stranke (design)

Datum: 2026-11-14 · Stanje: osnutek za potrditev (po pregledu graditelja) · Osnova: NOC v1.36 (`/var/www/noc` na tigru, PHP 8 + MariaDB 11.8, push model)

## 1. Namen in zakaj

Miha (en sam serviser, stranke v Štajerski) ima pri strankah MikroTik routerje že v NOC. Za routerji so MikroTik stikala, ki jih danes vidi in ureja samo na lokaciji ali prek Winboxa. Cilj je **celoten pregled in upravljanje stikal iz NOC**, da vsakdanjih posegov (VLAN na portu, uplink, preverjanje, kaj visi na portu) ni treba delati na lokaciji, in da izve, ko pomemben port pade.

Uspeh: v NOC odpre router stranke, pod njim vidi njena stikala kot sprednje plošče, klikne port, vidi vse o njem in mu iz NOC spremeni VLAN ali ga nastavi kot trunk, ne da bi se kdaj zaklenil ven.

## 1a. Dejstva o stikalih pri strankah (Miha)

- **[M]** Večinoma 24 portov + ~2 dodatna (SFP/uplink); podprta morajo biti tudi **48 portov + 2 ali 4 dodatni**.
- **[M]** V NOC še ni dodano nobeno stikalo – migracija obstoječih ni potrebna.
- **[M]** Stikala so (skoraj) vsa vezana **neposredno na MikroTik router** stranke; zaporedna vezava (stikalo za stikalom) ni primarni primer.
- Ni potrjeno, ali stikala že uporabljajo bridge VLAN filtering (glej 7).

## 2. Cilji

1. **Stikalo z RouterOS kot naprava v NOC**, vezana na router stranke (in prek njega na stranko). Dodaja se enako kot router: prvič se Miha poveže z Winboxom in prilepi namestitveni ukaz iz NOC.
2. **Sprednja plošča** (odobren osnutek `stikalo-osnutek-v2.html`, postavitev A):
   - porti narisani kot na fizičnem stikalu (liho zgoraj, sodo spodaj, SFP/uplinki desno), lučka = stanje, barvna črtica pod portom = VLAN, trunk črtast, PoE strela, port v downu rdeč rob;
   - **lebdenje z miško** pokaže povzetek: številka in ime porta, naprava (ime + IP), VLAN z barvo ali trunk s seznamom, stanje s hitrostjo, PoE, opozorilo o napakah. **Brez** besedila »klikni za podrobnosti«;
   - **klik** odpre panel ob strani (na širokem zaslonu se stikalo umakne levo in ostane vidno; na telefonu panel čez cel zaslon; zapre se z ✕ ali Esc) s: stanjem (naprava, MAC, hitrost/dupleks, VLAN, PoE, napake CRC, zadnja sprememba, STP, pri SFP podatki modula), **grafom prometa porta** (1 h / 24 h / 7 d / 30 d), **nastavitvijo porta** in **zadnjimi dogodki porta**.
3. **Urejanje porta iz NOC** (prvotni del): ime, vklop/izklop, način **Access** (en VLAN) ali **Trunk/uplink** (netagiran native + tagirani VLAN-i); pri PoE portih PoE samodejno/izklopljeno in »Ponastavi PoE«. Pred pošiljanjem predogled RouterOS ukazov.
4. **Obvestila**: ko port pade in ko pride nazaj gor.
5. **Več stikal pri isti stranki**: pri routerju prikazana **eno pod drugim**.
6. Vse, kar NOC že dela pri routerjih in ima smisel tudi pri stikalu: alarm, ko stikalo ne javlja; backup configa in razlike; posodobitev RouterOS/firmwara (tudi skupna nočna); posodobitev skripte; NTP; AI pregled configa; terminal.

## 3. Ne-cilji (v tej verziji)

- Stikala s **SwOS** (CSS, nekateri netPower) – ne poganjajo skript.
- **Pretvorba** stikala s starim načinom VLAN (meni `/interface ethernet switch`) ali brez VLAN-ov v bridge VLAN filtering – tak stikalo NOC samo prikaže (pregled), urejanje je onemogočeno z razlago.
- **Predloge** za celo stikalo (»porti 1–8 kamere …«) – kasneje.
- Usklajevanje VLAN-ov z routerjem (NOC ne spreminja routerja zaradi stikala).
- LACP/bonding, mirroring, port isolation, urniki PoE, QoS.
- Topologija (kaj je na drugem koncu kabla med stikali) – razen da se uplink proti routerju prepozna (glej 6.6).

## 4. Anti-cilji (to bi bil neuspeh, četudi »deluje«)

- **Zaklenjeno stikalo** po spremembi iz NOC (napačen VLAN na uplinku ali upravljalnem portu) brez samodejne vrnitve.
- **Poplava obvestil** (vsak ugasnjen računalnik = sporočilo).
- Push stikala, ki obremeni stikalo ali tigra opazno bolj kot router.
- Stikalo, ki se v NOC obnaša kot router (prazni zavihki požarnega zidu, DHCP, Zaščite, WireGuard ...).

## 5. Načini (odločeno)

| | Kako | Prednosti | Slabosti |
|---|---|---|---|
| **1. Lastna push skripta stikala** ✅ | Stikalo prek routerja vsako minuto samo pošlje podatke; spremembe gredo kot akcije prek pusha | uporabi vse obstoječe (namestitev, samodejna posodobitev skripte, backup, ROS posodobitve, AI pregled); enak model kot routerji | na vsako stikalo enkrat ročno prilepi namestitveni ukaz |
| 2. Router bere stikalo (REST) | Router v svojem pushu bere stikala v LAN-u | stikalo ne rabi interneta | geslo stikala na routerju, veliko večja skripta routerja, backup/posodobitve/AI na novo |
| 3. NOC neposredno prek VPN | tiger ima WireGuard do vsake lokacije, bere API | takojšnje | drug model, VPN na vsako lokacijo, izpad tunela = slepo |

**Izbran 1** (Miha): »za prvič se prek Winboxa povežem na stikalo in ga dodam v NOC, enako kot router.«

## 6. Kako (odločeno in predlagano)

Oznake: **[M]** = Miha odločil, **[P]** = predlog v designu (sprejet z dokumentom, popravljiv), **[G]** = prepuščeno graditelju.

### 6.1 Model podatkov

- `devices.kind` že ima `'switch'`, `device_art()` in ikona že poznata stikalo. **[P]** Novi stolpci v `devices`:
  - `parent_id INT UNSIGNED NULL` → router stranke (vedno router, tudi če bi bilo stikalo kdaj za drugim stikalom) (FK na `devices.id`, ON DELETE SET NULL); `tenant_id` stikala = tenant routerja (ob dodajanju prevzame, pri spremembi routerja se ne sinhronizira samodejno – **[G]** ali naj se).
  - `mgmt_ip VARCHAR(45)` – naslov stikala v LAN-u (za terminal); izpolni se samodejno iz pusha (naslov na upravljalnem vmesniku), ročno popravljiv.
  - `sw_alert_ports VARCHAR(500)` – porti z obvestilom (glej 6.7). **[G]** ali se namesto tega uporabi obstoječi `down_ifaces` (enak pomen) – priporočeno je obstoječi stolpec, da `Alerts` deluje brez sprememb.
- Obstoječe tabele se uporabijo: `device_ifaces` (stanje portov), `iface_1m/5m/daily` (grafi), `iface_events` (up/down/hitrost), `sfp_state/sfp_5m`, `alerts`, `config_backups`, `noc_actions`.
- **[P]** Nova tabela `sw_ports` (trenutno stanje L2 na port, prepiše se ob pushu):
  `device_id, port, bridge, pvid, frame_types, mode ENUM('access','trunk','none'), untagged VARCHAR, tagged VARCHAR, poe_mode, poe_status, poe_w DECIMAL, stp_role, stp_state, edge, hosts_n, host_mac, updated_at`.
- **[P]** Nova tabela `sw_vlans` (device_id, vlan_id, name/comment, ports_tagged, ports_untagged) – vsebina `/interface bridge vlan`.
- Ime naprave na portu: MAC iz `/interface bridge host` stikala (samo dinamični, ne lokalni) → IP in ime iz `lan_hosts` **routerja-starša** (DHCP najemi). Ko je na portu več MAC-ov (stikalo/AP za njim), prikaži »N naprav« in prvo/najbolj prometno. **[G]** natančno pravilo izbire.

### 6.2 Dodajanje stikala

- **[M]** Enako kot router: v NOC »Dodaj napravo« → izbira vrste **Stikalo** + **za routerjem: [izbor routerja]** → NOC pokaže namestitveni ukaz `/tool fetch …; /import …` → Miha ga prilepi v terminal Winboxa na stikalu.
- Obstoječi enroll tok (`Enroll`, `/api/enroll/…`, `/export terse`) ostane; razlika je samo vrsta in starš. **[P]** Pri vrsti »stikalo« NOC iz exporta prepozna: bridge, ali ima `vlan-filtering=yes`, VLAN-e, upravljalni naslov.
- **Predpogoj**: stikalo ima privzeto pot in DNS prek routerja (dosega noc.dezman.net). Če ga nima, namestitveni ukaz to javi (`/tool fetch` ne uspe) – **[P]** NOC pri prikazu ukaza napiše opombo.

### 6.3 Push skripta za stikala

- **[P]** Ista ogrodje kot `MikrotikScript` (namestitev, scheduler, `nocUpd`, `nocFail`, `rbt`, akcije `act:1`, samodejna posodobitev, ntp, logi, upd, backup), **brez** delov za router: požarni zid/raw, DHCP najemi, IP poli, povezave (`conns`), hotspot, traffic-flow, Zaščita, WireGuard (`wg`, `wgx`, `wgi`), ping prehoda/interneta (ali samo ping routerja-starša – **[G]**), TLS preverjanje.
- **Dodatno** (vsako minuto, razen kjer piše drugače):
  - vsi porti `ether*`, `sfp*`, `combo*`, `qsfp*`: števci, running, disabled, link-downs, last up/down, napake (rx-fcs-error/CRC), hitrost (obstoječi `rates` z `monitor once`);
  - `/interface bridge port`: interface, bridge, pvid, frame-types, (STP) role, status, edge;
  - `/interface bridge vlan`: vlan-ids, tagged, untagged (dinamične + statične, **[G]** kako ločiti);
  - `/interface ethernet poe` (če obstaja): poe-out, status, poraba (`monitor once`) – **vsakih 5 min** zaradi obremenitve;
  - `/interface bridge host` (samo dinamični): mac, on-interface, vid – **vsakih 5 min**, omejeno na npr. 500 vnosov;
  - `/interface bridge` vlan-filtering (ali je urejanje mogoče);
  - SFP (obstoječi del) vsakih 5 min.
- **Grafi in obremenitev tigra** **[P]** (stikala do 48 + 4 porte, tiger ima 128 MB buffer pool):
  - **minutni** podatki (`iface_1m`, 48 h) samo za **uplink in porte z obvestilom**;
  - **5-minutni** (`iface_5m`) za **vse fizične porte**; za porte stikal brez obvestila hramba **7 dni** (uplink in označeni 35 dni kot pri routerjih);
  - **dnevni** (`iface_daily`, 400 dni) za vse porte;
  - graf v panelu: 1 h = minutni, če obstajajo, sicer 5-min; 24 h in 7 d = 5-min; 30 d = dnevni stolpci (za neoznačene porte).
  - Ocena za 10 stikal po 52 portov: `iface_5m` ≈ 1 mio. vrstic (~130 MB), `iface_1m` zanemarljivo. Brisanje: `Metrics::rollup` dobi pravilo za kratko hrambo portov stikal.
- Velikost pusha: pri 48 + 4 portih + MAC tabeli (meja ingesta 256 KB) mora ostati pod mejo, ki jo ingest sprejme (**[G]** preveri, po potrebi deli MAC tabelo na več pushov).

### 6.4 Prikaz v NOC

- **Pri routerju** (stran naprave, nov razdelek ali zavihek »Stikala«): vsa stikala z `parent_id` = router, **eno pod drugim**, vsako kot sprednja plošča (kompaktna, s povzetkom ob lebdenju; klik na port odpre panel).
- **Stran stikala** (`/devices/{id}` za `kind=switch`): glava kot pri routerju (model, RouterOS, push, »za routerjem X« s povezavo), velika sprednja plošča, pod njo zavihki, ki imajo smisel: Porti (tabelarično, za iskanje), Dogodki, Backup, Posodobitve, AI pregled, Terminal. **Skriti**: Varnost/požarni zid, Zaščita, WireGuard, DHCP/naprave v LAN, Traffic-flow, hitrost interneta.
- **Seznam naprav / nadzorna plošča**: stikala so vidna (z ikono stikala), **[P]** zamaknjena pod svojim routerjem.
- **Razporeditev portov po modelu** **[P]**: tabela za pogoste modele (CRS326-24G-2S+, CRS328-24P-4S+, **CRS354-48G-4S+2Q+, CRS354-48P-4S+2Q+**, CRS318-16P-2S+, CRS310-8G+2S+, CRS112-8P-4S, CRS305-1G-4S+, CRS309-1G-8S+, CRS510/CRS518); za neznan model **samodejno**: `ether` porti po številki v dveh vrstah (liho zgoraj) **v skupinah po 12**, `sfp/combo/qsfp` v ločeni skupini desno.
- **48 portov**: 4 skupine po 12 + uplinki; na zaslonu, ožjem od plošče, se skupine prelomijo v dve vrsti (brez vodoravnega drsenja); pri routerju (več stikal eno pod drugim) kompaktna velikost portov.
- Barve VLAN-ov: stalne po VLAN ID (enaka številka = enaka barva na vseh stikalih stranke); ime VLAN-a iz komentarja `/interface bridge vlan` ali VLAN vmesnika na routerju.
- Osnutek: `stikalo-osnutek-v2.html` (scratchpad seje) – vizualna referenca, ne koda.

### 6.5 Urejanje porta in varnost pred zaklepanjem

- **[P]** Sprememba je **akcija** (`noc_actions.kind = 'swport'`, podatki v JSON), ki jo stikalo izvede ob naslednjem pushu (≤ 1 min), kot obstoječe akcije.
- Ukazi (bridge VLAN filtering):
  - Access: `/interface bridge port set [find interface=X] pvid=V frame-types=admit-only-untagged-and-priority-tagged`; v `/interface bridge vlan` X **untagged** v V (če vnos za V ne obstaja, se ustvari) in odstranjen iz drugih vnosov;
  - Trunk: `pvid=N frame-types=admit-all` (ali `admit-only-vlan-tagged`, če native ni izbran – **[G]**), X **tagged** v izbranih VLAN-ih;
  - ime: `/interface ethernet set X comment=…` (ali `name` – **[G]**: priporočeno comment, ker ime vmesnika uporabljajo skripte in grafi);
  - vklop/izklop: `/interface ethernet set X disabled=…`;
  - PoE: `poe-out=auto-on|off`; »Ponastavi PoE« = off → 5 s → auto-on.
- **Varovalka (obvezno)**: pred spremembo skripta zapiše prejšnje stanje porta v rollback skripto in scheduler (+5 min). Ko NOC po spremembi dobi **uspešen push** stikala, ukaže odstranitev schedulerja (potrditev). Če push ne pride, scheduler vrne prejšnje stanje. Vzorec obstaja: `noc-fw-rollback` v `Firewall.php`.
- **Dodatna zaščita** **[P]**: NOC ve, kateri port je **uplink** (port, prek katerega stikalo vidi MAC routerja-starša, ali root port STP) in kateri VLAN nosi upravljalni naslov stikala. Sprememba, ki bi uplinku odvzela upravljalni VLAN, ali izklop uplinka → opozorilo »s tem lahko izgubiš dostop; varovalka bo spremembo vrnila čez 5 min« + izrecna potrditev.
- Predogled ukazov pred pošiljanjem (kot v osnutku). Po izvedbi se v dogodkih porta zapiše, kdo je kaj spremenil (audit).
- Pravice: samo superadmin (kot ostale akcije na napravah) – **[G]** če obstajajo druge vloge, enako kot `term`/`wgsrv`.
- Ko stikalo nima `vlan-filtering=yes`: nastavitev VLAN-a je onemogočena z razlago; ime, vklop/izklop in PoE delujejo.

### 6.6 Terminal

- **[P]** Prek routerja-starša kot skok: `ssh -J noc-term@<public_ip routerja>:<port> noc-term@<mgmt_ip stikala>`. Na routerju je potreben `/ip ssh set forwarding-enabled=local` (doda ga namestitev terminala pri routerju, z razlago). Na stikalu se ustvari uporabnik `noc-term` s ključem (obstoječi `Term::rsc`).
- Alternativa (zavrnjena): dst-nat na routerju (javni port → stikalo:22, samo z IP NOC) – odpre nov port na WAN.

### 6.7 Obvestila

- **[M]** Obvestilo, ko port pade in ko pride nazaj gor.
- **[P]** Samo za porte, označene kot **pomembne** (v panelu porta kljukica »Obvesti, ko pade«; privzeto vklopljeno za uplink; Miha označi NVR, AP-je, kamere ...). Ostali porti dogodke beležijo (vidni v »Zadnji dogodki«), obvestila pa ne pošiljajo.
- Uporabi obstoječi mehanizem `down_ifaces` + `Alerts` (alarm `iface:<port>`, ob vrnitvi zaprt = obvestilo »spet deluje«) in obstoječa pravila Telegram/povzetek (`notify_tg_min`, `tg_digest_hours`). **[P]** Resnost: uplink = critical (takoj), ostali označeni porti = warning (po nastavitvi Telegrama).
- **Uplink** **[P]**: stikalo izpada svojega uplinka ne more javiti (takrat ne more pošiljati). Izpad uplinka = alarm stikala »Office-SW1 ne javlja (uplink ali napajanje)«, **critical**, takoj na Telegram; ob vrnitvi »spet javlja«. Kljukica »obvesti« pri uplinku zato pomeni ta alarm.
- **[P]** Če hkrati ne javlja router-starš, se alarm stikala duši (pride samo alarm routerja).

### 6.8 Posodobitve, backup, AI

- Stran Posodobitve: stikala na seznamu z enakim stanjem (pripravljen / načrtovano ob 03:30 / najprej ročna namestitev …), »Posodobi vse ponoči« velja tudi zanje; NTP stolpec in »Vklopi NTP na vseh« tudi.
- Backup configa in razlike: enako kot router.
- AI pregled: **[P]** prompt za stikalo (VLAN-i, STP, upravljanje, storitve, uporabniki), brez požarnega zidu za promet skozi.

## 7. Omejitve

- RouterOS 7 na stikalu (enako kot routerji; skripta predpostavlja ROS 7.13+).
- Stikalo mora prek routerja doseči noc.dezman.net (privzeta pot, DNS).
- Urejanje VLAN-ov samo z bridge VLAN filtering (`vlan-filtering=yes`, HW offload na CRS3xx/5xx). **Ni potrjeno**, koliko stikal pri strankah ga že uporablja; če malo, je pretvorba (zdaj ne-cilj) prvi naslednji projekt.
- Push vsako minuto, sprememba se izvede v ≤ 1 min (ni takojšnja).
- Tiger: omejena strojna oprema (HP G7, baza 128 MB buffer pool) – nove tabele naj bodo majhne, težji podatki (PoE, MAC) vsakih 5 min.
- Izdaje: zip brez zgornje mape in brez `config.php`; `bin/update.sh` najprej `sql/update-*.sql` (ENUM `noc_actions.kind` v vseh update datotekah s polnim seznamom + `swport`), slovenski UI + prevodi v `lang/en.php`, generična imena v primerih (Office-SW1, Office-GW).

## 8. Prepuščeno graditelju

- Natančna struktura JSON v pushu in razdelitev med 1-min in 5-min podatke.
- Razporeditve portov za posamezne modele (začni s CRS326-24G-2S+ in samodejno razporeditvijo).
- Izbira naprave za prikaz, ko je na portu več MAC-ov.
- Ali ime porta = `comment` ali `name` (priporočeno comment).
- Razčlenitev 1-minutnega pusha, če preseže velikost.
- Vrstni red gradnje (predlog: 1. model + namestitev + push + pregled plošče, 2. grafi + dogodki + obvestila, 3. urejanje z varovalko, 4. terminal, 5. posodobitve/AI).
