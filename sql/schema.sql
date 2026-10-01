-- NOC – nadzorni center (noc.dezman.net) – osnovna shema
-- Uvoz: mysql noc < sql/schema.sql
SET NAMES utf8mb4;

CREATE TABLE tenants (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  contact     VARCHAR(255) NOT NULL DEFAULT '',
  notes       TEXT NULL,
  report_enabled TINYINT(1) NOT NULL DEFAULT 0,
  report_emails  VARCHAR(500) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) NOT NULL UNIQUE,
  name        VARCHAR(120) NOT NULL DEFAULT '',
  pass_hash   VARCHAR(255) NOT NULL,
  role        ENUM('superadmin','viewer') NOT NULL DEFAULT 'viewer',
  tenant_id   INT UNSIGNED NULL,                -- bralni uporabnik vidi samo naprave tega naročnika
  lang        CHAR(2) NOT NULL DEFAULT 'sl',
  totp_secret VARCHAR(255) NULL,                -- 2FA (šifrirano)
  totp_last   INT UNSIGNED NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  last_login  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (email, at), KEY (ip, at)
) ENGINE=InnoDB;

-- Naprave. driver = 'mikrotik' (kasneje: 'switch', 'unifi' …)
CREATE TABLE devices (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT UNSIGNED NULL,
  driver         VARCHAR(20) NOT NULL DEFAULT 'mikrotik',
  kind           ENUM('router','switch','ap','other') NOT NULL DEFAULT 'router',
  name           VARCHAR(120) NOT NULL,
  site           VARCHAR(160) NOT NULL DEFAULT '',     -- lokacija / naslov
  model          VARCHAR(80)  NOT NULL DEFAULT '',
  serial         VARCHAR(40)  NOT NULL DEFAULT '',
  os_version     VARCHAR(40)  NOT NULL DEFAULT '',
  firmware       VARCHAR(40)  NOT NULL DEFAULT '',
  arch           VARCHAR(20)  NOT NULL DEFAULT '',
  public_ip      VARCHAR(45)  NOT NULL DEFAULT '',     -- statični javni IP (ping s tigra, traffic-flow, ufw)
  wan_iface      VARCHAR(64)  NOT NULL DEFAULT '',
  wan_gateway    VARCHAR(45)  NOT NULL DEFAULT '',     -- rezerva, če skripta prehoda ne najde sama
  ping_target    VARCHAR(64)  NOT NULL DEFAULT '1.1.1.1',
  lan_networks   VARCHAR(500) NOT NULL DEFAULT '',     -- 192.168.1.0/24,10.0.0.0/23
  monitor_ifaces VARCHAR(500) NOT NULL DEFAULT '',     -- vmesniki z zgodovino prometa (prvi = WAN)
  down_ifaces    VARCHAR(500) NOT NULL DEFAULT '',     -- alarm, ko vmesnik pade
  wan_down_mbps  SMALLINT UNSIGNED NULL,
  wan_up_mbps    SMALLINT UNSIGNED NULL,
  flow_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  log_include    VARCHAR(255) NOT NULL DEFAULT 'critical|error|warning|system|account|interface|wireguard|health|script',
  log_exclude    VARCHAR(255) NOT NULL DEFAULT 'debug|packet|dhcp.info|hotspot.info',
  thresholds     TEXT NULL,                            -- JSON: odstopanja od globalnih pragov
  newdev_alert   TINYINT(1) NOT NULL DEFAULT 1,        -- obvestilo o novi napravi v LAN-u
  newdev_ignore  VARCHAR(255) NOT NULL DEFAULT '',     -- DHCP strežniki brez obvestil
  filter_profile   ENUM('off','basic','family') NOT NULL DEFAULT 'off',   -- zaščita
  filter_force_dns TINYINT(1) NOT NULL DEFAULT 1,
  filter_block_doh TINYINT(1) NOT NULL DEFAULT 1,
  filter_ip_lists  TINYINT(1) NOT NULL DEFAULT 1,
  filter_domains   TEXT NULL,
  filter_networks  VARCHAR(500) NOT NULL DEFAULT '',
  dns_original     VARCHAR(255) NULL,
  api_key_hash   CHAR(64) NULL UNIQUE,
  api_key_enc    TEXT NULL,
  export_raw     MEDIUMTEXT NULL,                      -- /export, iz katerega je bila naprava dodana
  analysis_json  MEDIUMTEXT NULL,
  notes          TEXT NULL,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  mute_until     DATETIME NULL,                        -- utišani alarmi (vzdrževanje)
  last_seen_at   DATETIME NULL,
  last_seen_ip   VARCHAR(45) NOT NULL DEFAULT '',
  last_uptime    INT UNSIGNED NULL,
  status_json    MEDIUMTEXT NULL,                      -- zadnji push (za prikaz)
  tiger_ping_ms  SMALLINT UNSIGNED NULL,
  tiger_ping_ok  TINYINT(1) NULL,
  tiger_ping_at  DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trenutno stanje vmesnikov (zadnji push)
CREATE TABLE device_ifaces (
  device_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(64) NOT NULL,
  type        VARCHAR(32) NOT NULL DEFAULT '',
  comment     VARCHAR(255) NOT NULL DEFAULT '',
  mac         VARCHAR(17) NOT NULL DEFAULT '',
  running     TINYINT(1) NOT NULL DEFAULT 0,
  disabled    TINYINT(1) NOT NULL DEFAULT 0,
  rate        VARCHAR(16) NOT NULL DEFAULT '',     -- 1Gbps, 10Gbps …
  rx_byte     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_byte     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_bps      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_bps      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_error    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_error    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  link_downs  INT UNSIGNED NOT NULL DEFAULT 0,
  last_up     VARCHAR(32) NOT NULL DEFAULT '',
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (device_id, name),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promet vmesnikov: 1 min (48 h), 5 min (30 dni), dnevno (400 dni)
CREATE TABLE iface_1m (
  device_id INT UNSIGNED NOT NULL, iface VARCHAR(64) NOT NULL, ts DATETIME NOT NULL,
  rx_bps BIGINT UNSIGNED NOT NULL, tx_bps BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (device_id, iface, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE iface_5m (
  device_id INT UNSIGNED NOT NULL, iface VARCHAR(64) NOT NULL, ts DATETIME NOT NULL,
  rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_peak BIGINT UNSIGNED NOT NULL DEFAULT 0, tx_peak BIGINT UNSIGNED NOT NULL DEFAULT 0,
  secs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, iface, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE iface_daily (
  device_id INT UNSIGNED NOT NULL, iface VARCHAR(64) NOT NULL, day DATE NOT NULL,
  rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_peak BIGINT UNSIGNED NOT NULL DEFAULT 0, tx_peak BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, iface, day),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Zdravje + ping: 1 min (48 h), 5 min (30 dni)
CREATE TABLE health_1m (
  device_id INT UNSIGNED NOT NULL, ts DATETIME NOT NULL,
  cpu TINYINT UNSIGNED NULL, mem_pct TINYINT UNSIGNED NULL, hdd_pct TINYINT UNSIGNED NULL,
  temp DECIMAL(5,1) NULL, volt DECIMAL(5,1) NULL,
  gw_ms DECIMAL(7,2) NULL, gw_loss TINYINT UNSIGNED NULL,
  ext_ms DECIMAL(7,2) NULL, ext_loss TINYINT UNSIGNED NULL,
  conns INT UNSIGNED NULL, leases SMALLINT UNSIGNED NULL,
  PRIMARY KEY (device_id, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE health_5m (
  device_id INT UNSIGNED NOT NULL, ts DATETIME NOT NULL,
  cpu_avg TINYINT UNSIGNED NULL, cpu_max TINYINT UNSIGNED NULL, mem_pct TINYINT UNSIGNED NULL,
  temp_max DECIMAL(5,1) NULL,
  gw_ms DECIMAL(7,2) NULL, gw_loss TINYINT UNSIGNED NULL, ext_ms DECIMAL(7,2) NULL, ext_loss TINYINT UNSIGNED NULL,
  tiger_ms DECIMAL(7,2) NULL, tiger_loss TINYINT UNSIGNED NULL,
  conns_max INT UNSIGNED NULL, n SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Naprave v LAN-u (DHCP najemi)
CREATE TABLE lan_hosts (
  device_id  INT UNSIGNED NOT NULL,
  mac        VARCHAR(17) NOT NULL,
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  hostname   VARCHAR(120) NOT NULL DEFAULT '',
  server     VARCHAR(64) NOT NULL DEFAULT '',
  status     VARCHAR(20) NOT NULL DEFAULT '',
  label      VARCHAR(120) NOT NULL DEFAULT '',     -- tvoje ime naprave (npr. "Tiskalnik recepcija")
  first_seen DATETIME NOT NULL,
  last_seen  DATETIME NOT NULL,
  PRIMARY KEY (device_id, mac), KEY (device_id, ip),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promet po napravah v LAN-u (traffic-flow): 5 min (7 dni), dnevno (400 dni)
CREATE TABLE host_5m (
  device_id INT UNSIGNED NOT NULL, ip VARCHAR(45) NOT NULL, ts DATETIME NOT NULL,
  up_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, down_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, ip, ts), KEY (device_id, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE host_daily (
  device_id INT UNSIGNED NOT NULL, ip VARCHAR(45) NOT NULL, day DATE NOT NULL,
  up_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, down_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, ip, day),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Logi z routerjev (90 dni)
CREATE TABLE logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id  INT UNSIGNED NOT NULL,
  ts         DATETIME NOT NULL,
  topics     VARCHAR(120) NOT NULL DEFAULT '',
  severity   ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
  message    VARCHAR(1000) NOT NULL,
  KEY (device_id, ts), KEY (ts), KEY (severity, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alarmi
CREATE TABLE alerts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  akey        VARCHAR(120) NOT NULL,                 -- offline, cpu, temp, gw_loss, iface:ether1, host:192.168.1.5 …
  severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  message     VARCHAR(500) NOT NULL,
  started_at  DATETIME NOT NULL,
  ended_at    DATETIME NULL,
  acked_by    INT UNSIGNED NULL,
  acked_at    DATETIME NULL,
  notified    TINYINT(1) NOT NULL DEFAULT 0,
  KEY (device_id, akey, ended_at), KEY (ended_at), KEY (started_at),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nočni backup konfiguracije (/export brez gesel)
CREATE TABLE config_backups (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  sha1        CHAR(40) NOT NULL,
  bytes       INT UNSIGNED NOT NULL,
  added       INT UNSIGNED NOT NULL DEFAULT 0,
  removed     INT UNSIGNED NOT NULL DEFAULT 0,
  content     MEDIUMTEXT NOT NULL,
  KEY (device_id, created_at),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kosi backupa med prenosom (router pošlje /export v delih po 30 kB)
CREATE TABLE backup_parts (
  device_id  INT UNSIGNED NOT NULL,
  upload_id  VARCHAR(32) NOT NULL,
  part       SMALLINT UNSIGNED NOT NULL,
  data       MEDIUMTEXT NOT NULL,
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id, upload_id, part),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dodajanje routerja z enkratno kodo
CREATE TABLE IF NOT EXISTS enroll_codes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_hash   CHAR(64) NOT NULL UNIQUE,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  status      ENUM('new','receiving','received','used') NOT NULL DEFAULT 'new',
  router_ip   VARCHAR(45) NOT NULL DEFAULT '',
  export_raw  MEDIUMTEXT NULL,
  device_id   INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enroll_parts (
  enroll_id  INT UNSIGNED NOT NULL,
  upload_id  VARCHAR(32) NOT NULL,
  part       SMALLINT UNSIGNED NOT NULL,
  data       MEDIUMTEXT NOT NULL,
  PRIMARY KEY (enroll_id, upload_id, part),
  FOREIGN KEY (enroll_id) REFERENCES enroll_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id   INT UNSIGNED NULL,
  action    VARCHAR(80) NOT NULL,
  detail    VARCHAR(500) NOT NULL DEFAULT '',
  ip        VARCHAR(45) NOT NULL DEFAULT '',
  at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cron_runs (
  name     VARCHAR(40) PRIMARY KEY,
  last_at  DATETIME NOT NULL,
  info     VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

INSERT INTO settings (k, v) VALUES
 ('th_cpu', '85'), ('th_cpu_min', '5'), ('th_temp', '70'), ('th_mem', '90'), ('th_hdd', '90'),
 ('th_gw_loss', '20'), ('th_gw_ms', '50'), ('th_ext_loss', '20'), ('th_ext_ms', '120'), ('th_ping_min', '3'),
 ('th_offline_min', '3'), ('th_host_gb_h', '10'), ('th_host_mbps', '200'), ('th_host_min', '15'),
 ('notify_emails', ''), ('notify_mail_min', 'warning'), ('notify_tg_min', 'warning'), ('notify_resolved', '1'),
 ('tg_token_enc', ''), ('tg_chat_id', ''),
 ('th_attack', '5'), ('th_attack_min', '15'), ('th_pool', '90'), ('th_backup_days', '2');
