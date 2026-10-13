-- NOC v1.10 – varnostna analiza: logi požarnega zidu (syslog) in odhodni promet (traffic-flow)
-- (update.sh ga požene samodejno)

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS fwlog_enabled TINYINT(1) NOT NULL DEFAULT 0,   -- stikalo: beleženje požarnega zidu + analiza odhodnega prometa
  ADD COLUMN IF NOT EXISTS fwlog_seen_at DATETIME NULL;                  -- zadnji prejeti zapis požarnega zidu

-- zapisi požarnega zidu, povzeti po 5 min (7 dni)
--   in     = zavrnjen poskus z interneta (src = napadalec, port = ciljni port)
--   bl_in  = promet iz omrežja na varnostni listi
--   bl_out = naprava v LAN-u (src) proti omrežju na varnostni listi (dst)
CREATE TABLE IF NOT EXISTS fw_5m (
  device_id INT UNSIGNED NOT NULL,
  ts        DATETIME NOT NULL,
  kind      ENUM('in','bl_in','bl_out') NOT NULL,
  src       VARCHAR(45) NOT NULL,
  dst       VARCHAR(45) NOT NULL DEFAULT '',
  proto     VARCHAR(8) NOT NULL DEFAULT '',
  port      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  hits      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, ts, kind, src, dst, proto, port),
  KEY (device_id, kind, ts),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- dnevni povzetek (400 dni)
CREATE TABLE IF NOT EXISTS fw_daily (
  device_id INT UNSIGNED NOT NULL,
  day       DATE NOT NULL,
  kind      ENUM('in','bl_in','bl_out') NOT NULL,
  hits      INT UNSIGNED NOT NULL DEFAULT 0,
  sources   INT UNSIGNED NOT NULL DEFAULT 0,
  ports     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, day, kind),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- odhodni promet naprav v LAN-u (iz traffic-flow), samo izstopajoče naprave, 5 min (7 dni)
CREATE TABLE IF NOT EXISTS out_5m (
  device_id INT UNSIGNED NOT NULL,
  ts        DATETIME NOT NULL,
  ip        VARCHAR(45) NOT NULL,
  flows     INT UNSIGNED NOT NULL DEFAULT 0,
  dst_ips   INT UNSIGNED NOT NULL DEFAULT 0,      -- različnih ciljnih naslovov
  scan_port SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- TCP port z največ različnimi cilji (brez 80/443)
  scan_ips  INT UNSIGNED NOT NULL DEFAULT 0,      -- … število ciljev na tem portu
  smtp_ips  INT UNSIGNED NOT NULL DEFAULT 0,      -- različnih ciljev na TCP 25
  bl_hits   INT UNSIGNED NOT NULL DEFAULT 0,      -- tokov proti varnostni listi
  bl_sample VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (device_id, ts, ip),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (k, v) VALUES
  ('th_fw_ports', '50'), ('th_fw_hits', '200'), ('th_fw_spike', '5'), ('th_fw_spike_min', '1000'),
  ('th_out_ips', '100'), ('th_out_smtp', '10'), ('th_out_bl', '1');
