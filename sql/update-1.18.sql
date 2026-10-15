-- NOC v1.18: upravljanje uporabnikov WireGuard (ključi, config/QR, samodejno dodajanje peerjev na router)
ALTER TABLE devices ADD COLUMN IF NOT EXISTS wg_endpoint VARCHAR(255) NULL;   -- endpoint za config (domena[:port]); prazno = javni IP naprave
CREATE TABLE IF NOT EXISTS wg_peers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id      INT UNSIGNED NOT NULL,
  iface          VARCHAR(64) NOT NULL,
  name           VARCHAR(100) NOT NULL DEFAULT '',
  kind           ENUM('phone','pc','other') NOT NULL DEFAULT 'other',
  pubkey         CHAR(44) NOT NULL,
  old_pubkey     CHAR(44) NULL,                    -- pri menjavi ključa: stari ključ, dokler router ne prevzame novega
  priv_enc       TEXT NULL,                        -- zasebni ključ uporabnika (šifriran); NULL = config ni na voljo
  psk_enc        TEXT NULL,                        -- preshared key (šifriran)
  ip             VARCHAR(45) NOT NULL DEFAULT '',  -- naslov uporabnika v tunelu (Address v configu)
  allowed        VARCHAR(2000) NOT NULL DEFAULT '', -- allowed-address na routerju
  client_allowed VARCHAR(500) NOT NULL DEFAULT '0.0.0.0/0, ::/0',   -- AllowedIPs v configu uporabnika
  dns            VARCHAR(100) NOT NULL DEFAULT '',
  enabled        TINYINT(1) NOT NULL DEFAULT 1,
  status         ENUM('ok','sync','delete','missing','failed','delfail') NOT NULL DEFAULT 'ok',
  source         ENUM('noc','import','router') NOT NULL DEFAULT 'noc',
  tries          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at        DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     VARCHAR(60) NOT NULL DEFAULT '',
  UNIQUE KEY ux_dev_pk (device_id, pubkey),
  KEY ix_status (status),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
