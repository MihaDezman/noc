# Prva konfiguracija stikala + luknje v grafih (v1.38) – načrt

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prazno stikalo nastaviti z enim obrazcem (uplink trunk + razponi access VLAN-ov) kot eno akcijo z varovalko; grafi se med izpadi prekinejo.

**Architecture:** Nova akcija `noc_actions.kind='swinit'` (en uvoz, brez ponovitev, varovalka kot `swport`). Logika v novem `src/SwInit.php` (`parse`, `plan`, `rsc`, `isBlank`, `routerHint`); obrazec `views/sw_init.php`, rute pod `/devices/{id}/swinit`. Luknje v grafih: `Metrics::cols` (že narejeno).

**Tech Stack:** PHP 8, MariaDB, RouterOS 7 skripta, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-11-14-stikalo-prva-konfiguracija-design.md`

## Global Constraints
- Verzija **1.38**, `sql/update-1.38.sql`; ENUM `noc_actions.kind` v vseh datotekah s polnim seznamom + `'swinit'`.
- Router push in paket bajtno enaka kot v1.37.
- UI slovensko + `lang/en.php`; polja poravnana; deluje na telefonu.
- Upravljanje ostane netagirano (uplink `pvid=1 frame-types=admit-all`); VLAN filtering se vklopi **zadnji**; povrnitev ga **najprej** izklopi.
- Varovalka: `noc-sw-rb-<id>` skripta + scheduler ob zagonu + `:execute` 300 s; potrditev `action-done`.
- Ena akcija `swport`/`swinit` na uvoz; nobena, dokler je druga `sent`; `swinit` se nikoli ne ponovi.

## Review Focus
1. Stikalo med pošiljanjem ni več prazno → stikalo ne spremeni ničesar, javi `fail` (`test_rsc_checks_blank_on_switch`).
2. Prekrivajoči razponi / uplink v vrstici / neobstoječ port / VLAN 1 → napaka v planu (`test_parse_*`).
3. 48 + 4 porti: oznake `S1`…`S4` in `Q1` (`test_parse_sfp_labels`).
4. Ime VLAN-a z narekovaji/`$` → ubežano v rsc (`test_rsc_escapes_names`).
5. Gumb se ne pokaže pri nastavljenem stikalu in ruta zavrne (`test_isblank`).

---

### Task 1: SwInit – razčlenitev, preverjanje, plan
**Files:** Create `src/SwInit.php`; Modify `src/bootstrap.php` (autoload), `sql/*` (ENUM), Test `tests/swinit_test.php`
**Produces:**
- `SwInit::isBlank(array $d): bool` – `!SwitchIngest::vlanFiltering($d)` in 0 vrstic `sw_vlans` z `dynamic=0`
- `SwInit::parsePorts(string $spec, array $ports): array{0: string[], 1: string}` – `[porti, napaka]`; `1-5`, `1,3,7-9`, `S1`, `Q1` → imena po `SwLayout::label`
- `SwInit::plan(array $d, string $uplink, array $rows, string $extra): array{err:string, warn:string[], cmds:string[], want:array}`; `$rows` = `[['ports'=>'1-5','vlan'=>10,'name'=>'pisarna'], …]`; `want` = `['uplink'=>..., 'bridge'=>..., 'vlans'=>[['id'=>10,'name'=>'pisarna','ports'=>['ether1',…]], …], 'tagged'=>[10,20,…]]`
- Napake: ni prazno, ni uplinka, VLAN izven 2–4094, port ne obstaja, port v dveh vrsticah, uplink v vrstici, brez vrstic, isti VLAN v dveh vrsticah z različnim imenom je dovoljen (porti se združijo)
- [ ] testi `test_isblank`, `test_parse_ranges`, `test_parse_sfp_labels`, `test_parse_errors`, `test_plan_ok`, `test_plan_errors` → FAIL → implementacija → PASS

### Task 2: SwInit::rsc + Actions
**Files:** Modify `src/SwInit.php`, `src/Actions.php` (veja `swinit`, ena na uvoz skupaj s `swport`, brez ponovitve, `done` → `verified_at`, brez Telegrama); Test `tests/swinit_test.php`
**Produces:** `SwInit::rsc(array $d, array $a, string $base, string $key, string $chk): string` – en `:do{}` blok: preverjanje praznosti na stikalu (`vlan-filtering` in nedinamični vnosi) → `:error` + `fail`; shrani pvid/frame-types spremenjenih portov; varovalka; `add` vnosov VLAN (tagged=uplink, untagged=porti, comment=ime); pvid; uplink `pvid=1 frame-types=admit-all`; `vlan-filtering=yes` zadnji; potrditev.
- [ ] testi `test_rsc_order_and_safeguard` (vrstni red: `vlan add` < `vlan-filtering=yes`; povrnitev začne z `vlan-filtering=no`), `test_rsc_checks_blank_on_switch`, `test_rsc_escapes_names`, `test_swinit_single_per_import` → FAIL → impl → PASS

### Task 3: Obrazec, rute, predloga za router
**Files:** Create `views/sw_init.php`; Modify `public/index.php` (GET/POST `/devices/{id}/swinit[/preview|/save]`), `views/device.php` (gumb »Prva konfiguracija« samo če `isBlank`; obvestilo o čakajoči/izvedeni akciji s predlogo za router), `public/assets/app.js` (+ vrstica, predogled), `public/assets/app.css`, `lang/en.php`
**Produces:** `SwInit::routerHint(array $d, array $vlans): string` – predloga `/interface vlan add interface=<vmesnik-proti-stikalu> name=vlanV vlan-id=V comment="…"` za vsak VLAN + opomba o bridge VLAN filteringu na routerju.
- [ ] Playwright: stran stikala (prazno) ima gumb; obrazec 2 vrstici → predogled → pošlji; stran brez napak, brez vodoravnega drsenja (1280 in 390); nastavljeno stikalo gumba nima, ruta vrne napako.

### Task 4: Izdaja 1.38
- [ ] `missing.php` = 0, `php -l`, vsi testi, smoke vseh strani, router paket = v1.37, `update-1.38.sql` dvakrat, zip `/mnt/user-data/outputs/noc-v1.38.zip` (brez `config.php`, `__pycache__`, `tests/`), neodvisen pregled (subagent) in popravki.
