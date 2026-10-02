-- NOC v1.8 – stanje portov in zgodovina prekinitev, SFP moduli, pregled požarnega zidu
-- (update.sh ga požene samodejno)

ALTER TABLE device_ifaces
  ADD COLUMN IF NOT EXISTS last_down      VARCHAR(32) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS link_downs_noc INT UNSIGNED NOT NULL DEFAULT 0;   -- prekinitve, ki jih je zaznal NOC (štejejo prek ponovnih zagonov)

-- prekinitve, vzpostavitve in spremembe hitrosti (365 dni)
CREATE TABLE IF NOT EXISTS iface_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id  INT UNSIGNED NOT NULL,
  iface      VARCHAR(64) NOT NULL,
  ts         DATETIME NOT NULL,
  kind       ENUM('down','up','speed','sfp_in','sfp_out','sfp_swap') NOT NULL,
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  KEY (device_id, iface, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SFP moduli: zadnje stanje + nastavitve po portu
CREATE TABLE IF NOT EXISTS sfp_state (
  device_id   INT UNSIGNED NOT NULL,
  iface       VARCHAR(64) NOT NULL,
  present     TINYINT(1) NOT NULL DEFAULT 1,
  vendor      VARCHAR(64) NOT NULL DEFAULT '',
  part        VARCHAR(64) NOT NULL DEFAULT '',
  serial      VARCHAR(64) NOT NULL DEFAULT '',
  stype       VARCHAR(64) NOT NULL DEFAULT '',
  wavelength  SMALLINT UNSIGNED NULL,
  length_km   DECIMAL(6,1) NULL,
  rx          DECIMAL(6,2) NULL,
  tx          DECIMAL(6,2) NULL,
  temp        DECIMAL(5,1) NULL,
  volt        DECIMAL(5,2) NULL,
  bias        DECIMAL(6,1) NULL,
  speed_class ENUM('1g','10g') NOT NULL DEFAULT '1g',
  th_warn     DECIMAL(6,2) NULL,     -- lastni pragovi (prazno = privzeto po hitrosti)
  th_crit     DECIMAL(6,2) NULL,
  th_high     DECIMAL(6,2) NULL,
  peer_device INT UNSIGNED NULL,     -- nasprotni konec, če je v NOC (za dušenje)
  peer_iface  VARCHAR(64) NOT NULL DEFAULT '',
  remote_tx   DECIMAL(6,2) NULL,     -- ročno vpisana TX moč nasprotne strani
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (device_id, iface),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SFP zgodovina: 5 min (35 dni)
CREATE TABLE IF NOT EXISTS sfp_5m (
  device_id INT UNSIGNED NOT NULL, iface VARCHAR(64) NOT NULL, ts DATETIME NOT NULL,
  rx DECIMAL(6,2) NULL, tx DECIMAL(6,2) NULL, temp DECIMAL(5,1) NULL,
  PRIMARY KEY (device_id, iface, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- pregled požarnega zidu
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS fw_fixes        TEXT NULL,          -- izbrani popravki (JSON)
  ADD COLUMN IF NOT EXISTS fw_confirmed_at DATETIME NULL;      -- router potrdil, da po spremembi še doseže NOC

INSERT IGNORE INTO settings (k, v) VALUES
  ('mgmt_ips', '109.123.0.2, 109.123.4.2, 109.123.4.3, 109.123.10.10, 109.123.19.19, 85.10.18.25'),
  ('th_flap', '3'), ('th_flap_min', '60');
