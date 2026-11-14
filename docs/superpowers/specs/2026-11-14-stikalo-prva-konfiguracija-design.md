# v1.38 – prva konfiguracija praznega stikala + luknje v grafih (kratek design)

Datum: 2026-11-14 · Osnova: v1.37 (stikala, `SwPort`, varovalka `noc-sw-rb-*`)

## Namen
Novo (prazno) stikalo, ki je že povezano z NOC, nastaviš z **enim obrazcem** namesto port za portom: uplink kot trunk s tagiranimi VLAN-i in razponi portov kot access VLAN-i. Router (tagirane VLAN-e proti stikalu) Miha nastavi sam, po pošiljanju obrazca.

## Odločeno (Miha)
- Obrazec je **samo za prazna stikala** (enkrat); kasneje se porti urejajo posamično na plošči.
- Uplink postane **trunk s tagiranimi VLAN-i**; Miha nato na routerju naredi tagirane VLAN-e.
- Izvedba **A**: ena celotna akcija prek NOC z varovalko.
- Grafi: med izpadom (ni podatkov) se črta **prekine** (vsi grafi).

## Predlogi v designu (popravljivi)
- **Upravljanje stikala ostane netagirano na uplinku** (VLAN 1 / pvid 1) – stikalo ves čas ostane v stiku z NOC, tudi preden je router nastavljen; zato se sprememba potrdi in varovalka ne sproži.
- **Prazno stikalo** = VLAN filtering izklopljen in nobenega nedinamičnega vnosa v `/interface bridge vlan`. Samo takrat se na strani stikala pokaže gumb **»Prva konfiguracija«**.
- **Porti, ki niso navedeni**, ostanejo v privzetem LAN-u (VLAN 1, netagirano).
- **Ime VLAN-a** (neobvezno) se zapiše kot komentar vnosa VLAN-a (vidno v legendi in povzetkih).
- **Pomoč za router:** po potrditvi NOC pokaže predlogo ukazov za router (VLAN vmesniki 10, 20 … na vmesniku proti stikalu), ki jih Miha prilagodi in prilepi; NOC routerja ne spreminja.

## Obrazec
```
Prva konfiguracija stikala – Office-SW1
  Uplink: [sfp-sfpplus1 ▾]  (predizbran zaznan uplink)
  Porti:  [1-5  ] → VLAN [10] ime [pisarna]
          [6-10 ] → VLAN [20] ime [kamere ]      [+ vrstica]
  Tagirani VLAN-i na uplinku: samodejno vsi iz vrstic (+ dodatni [   ])
  Ostali porti: ostanejo v VLAN 1 (upravljanje, kot zdaj)
  [Predogled] → vsi ukazi + opozorila → [Pošlji na stikalo]
```
Razponi: `1-5`, `1,3,7-9`; številka = `etherN`, `S1` = `sfp-sfpplus1` (oznake kot na plošči). Preverjanja: VLAN 2–4094, port obstaja, isti port ni v dveh vrsticah, uplink ni v vrsticah, vsaj ena vrstica.

## Izvedba na stikalu (akcija `swinit`, ena na uvoz, nikoli ponovljena)
1. Shrani stanje za povrnitev: pvid in frame-types vseh portov, ki se spreminjajo.
2. Varovalka kot v v1.37: skripta `noc-sw-rb-<id>`, scheduler ob zagonu, `:execute` 5 min; povrnitev najprej **izklopi VLAN filtering**, odstrani ustvarjene vnose VLAN in vrne pvid/frame-types.
3. Za vsak VLAN: `/interface bridge vlan add bridge=B vlan-ids=V tagged=<uplink> untagged=<porti> comment=<ime>`.
4. Access porti: `pvid=V frame-types=admit-only-untagged-and-priority-tagged`; uplink: `pvid=1 frame-types=admit-all`.
5. **Zadnje:** `/interface bridge set B vlan-filtering=yes`.
6. Potrditev prek `action-done` → varovalka se prekine; ob napaki takojšnja povrnitev in `?fail=1`.
Pred vsem: če stikalo ni več prazno (VLAN filtering vklopljen ali nedinamični vnosi obstajajo) → napaka brez sprememb.

## Luknje v grafih (že narejeno)
`Metrics::cols`: razmik med točkama > 2,5 × običajnega koraka (mediana) → vstavljena prazna točka; graf (uPlot, `spanGaps:false`) se tam prekine. Velja za promet, SFP, CPU, ping, temperaturo, povezave.

## Ne-cilji
Prepis že nastavljenega stikala; nastavitev routerja iz NOC; upravljanje v tagiranem VLAN-u; LACP/več uplinkov.

## Prepuščeno graditelju
Natančna oblika JSON akcije, razporeditev obrazca (polja poravnana, deluje na telefonu), besedilo predloge za router.
