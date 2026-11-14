-- v1.37: MikroTik stikala za routerjem stranke (pregled portov, VLAN, varovalka)
ALTER TABLE devices ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE devices ADD COLUMN IF NOT EXISTS mgmt_ip VARCHAR(45) NOT NULL DEFAULT '' AFTER public_ip;
ALTER TABLE devices ADD KEY IF NOT EXISTS ix_parent (parent_id);
ALTER TABLE devices ADD CONSTRAINT fk_dev_parent FOREIGN KEY IF NOT EXISTS (parent_id) REFERENCES devices(id) ON DELETE SET NULL;

-- stanje L2 na port (zadnji push stikala)
CREATE TABLE IF NOT EXISTS sw_ports (
  device_id   INT UNSIGNED NOT NULL,
  port        VARCHAR(64) NOT NULL,
  bridge      VARCHAR(64) NOT NULL DEFAULT '',
  pvid        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  frame_types VARCHAR(40) NOT NULL DEFAULT '',
  mode        ENUM('access','trunk','none') NOT NULL DEFAULT 'none',
  untagged    VARCHAR(500) NOT NULL DEFAULT '',
  tagged      VARCHAR(500) NOT NULL DEFAULT '',
  poe_mode    VARCHAR(20) NOT NULL DEFAULT '',
  poe_status  VARCHAR(40) NOT NULL DEFAULT '',
  poe_w       DECIMAL(5,1) NULL,
  stp_role    VARCHAR(20) NOT NULL DEFAULT '',
  stp_state   VARCHAR(20) NOT NULL DEFAULT '',
  edge        TINYINT(1) NOT NULL DEFAULT 0,
  hosts_n     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  host_mac    VARCHAR(17) NOT NULL DEFAULT '',
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (device_id, port),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- /interface bridge vlan stikala
CREATE TABLE IF NOT EXISTS sw_vlans (
  device_id   INT UNSIGNED NOT NULL,
  bridge      VARCHAR(64) NOT NULL,
  vlan_id     SMALLINT UNSIGNED NOT NULL,
  comment     VARCHAR(255) NOT NULL DEFAULT '',
  tagged      VARCHAR(1000) NOT NULL DEFAULT '',
  untagged    VARCHAR(1000) NOT NULL DEFAULT '',
  dynamic     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (device_id, bridge, vlan_id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MAC tabela stikala (katera naprava je na katerem portu); vnosi starejši od 1 h se brišejo
CREATE TABLE IF NOT EXISTS sw_hosts (
  device_id   INT UNSIGNED NOT NULL,
  mac         VARCHAR(17) NOT NULL,
  vid         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  port        VARCHAR(64) NOT NULL,
  seen_at     DATETIME NOT NULL,
  PRIMARY KEY (device_id, mac, vid),
  KEY ix_port (device_id, port),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sprememba porta stikala (ukrep 'swport'); ime porta je lahko daljše od IP naslova
ALTER TABLE noc_actions MODIFY kind ENUM('block','unblock','upgrade','speed','term','wgsrv','wgdel','ntp','swport','swinit') NOT NULL;
ALTER TABLE noc_actions MODIFY ip VARCHAR(64) NOT NULL;
