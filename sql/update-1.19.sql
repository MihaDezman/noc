-- NOC v1.19: države in omrežja (DB-IP Lite), varnostne liste abuse.ch, profili naprav v LAN, nadzor WireGuard prijav, varnostna ocena, posodobitve RouterOS
CREATE TABLE IF NOT EXISTS geo_cc (   -- DB-IP Lite država (IPv4), polni se enkrat mesečno
  ip_from INT UNSIGNED NOT NULL PRIMARY KEY,
  ip_to   INT UNSIGNED NOT NULL,
  cc      CHAR(2) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS geo_asn (  -- DB-IP Lite ASN (IPv4)
  ip_from INT UNSIGNED NOT NULL PRIMARY KEY,
  ip_to   INT UNSIGNED NOT NULL,
  asn     INT UNSIGNED NOT NULL,
  org     VARCHAR(120) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mac_vendor (   -- IEEE OUI: proizvajalec po prvih 3 bajtih MAC
  prefix CHAR(6) NOT NULL PRIMARY KEY,
  vendor VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE lan_hosts
  ADD COLUMN IF NOT EXISTS vendor      VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS kind        VARCHAR(16) NOT NULL DEFAULT '',    -- camera, nvr, printer, phone, pc, iot, network, tv, ''
  ADD COLUMN IF NOT EXISTS kind_manual TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS geo_daily (   -- promet naprave po državah in omrežjih (traffic-flow)
  device_id INT UNSIGNED NOT NULL,
  day       DATE NOT NULL,
  cc        CHAR(2) NOT NULL DEFAULT '',
  asn       INT UNSIGNED NOT NULL DEFAULT 0,
  up_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  down_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, day, cc, asn),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS host_geo (    -- s katerimi državami komunicira posamezna naprava v LAN (za opozorilo "nova država")
  device_id  INT UNSIGNED NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  cc         CHAR(2) NOT NULL,
  first_seen DATE NOT NULL,
  last_seen  DATE NOT NULL,
  bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, ip, cc),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE wg_peers
  ADD COLUMN IF NOT EXISTS last_hs_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS last_ep    VARCHAR(45) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS last_cc    CHAR(2) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS last_rx    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_tx    BIGINT UNSIGNED NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS wg_peer_geo (   -- od kod se uporabnik prijavlja
  peer_id    INT UNSIGNED NOT NULL,
  cc         CHAR(2) NOT NULL,
  asn        INT UNSIGNED NOT NULL DEFAULT 0,
  org        VARCHAR(120) NOT NULL DEFAULT '',
  first_seen DATETIME NOT NULL,
  last_seen  DATETIME NOT NULL,
  PRIMARY KEY (peer_id, cc, asn),
  FOREIGN KEY (peer_id) REFERENCES wg_peers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wg_daily (      -- promet in čas povezave uporabnika po dnevih
  peer_id    INT UNSIGNED NOT NULL,
  day        DATE NOT NULL,
  rx_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  online_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (peer_id, day),
  FOREIGN KEY (peer_id) REFERENCES wg_peers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE noc_actions MODIFY kind ENUM('block','unblock','upgrade','speed','term','wgsrv','wgdel') NOT NULL;   -- celoten seznam: ponovni zagon na novejši bazi ne sme skrčiti ENUM
ALTER TABLE noc_actions ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL;   -- posodobitev: router po ponovnem zagonu poroča novo verzijo
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS sec_score TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS sec_at    DATETIME NULL;
ALTER TABLE geo_asn ADD INDEX IF NOT EXISTS ix_asn (asn);
ALTER TABLE host_geo ADD INDEX IF NOT EXISTS ix_first (first_seen);
