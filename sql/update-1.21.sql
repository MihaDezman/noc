-- NOC 1.21: terminal routerjev v brskalniku (SSH s strežnika NOC)
ALTER TABLE devices ADD COLUMN IF NOT EXISTS term_enabled TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS term_sessions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  token_hash  CHAR(64) NOT NULL,
  client_ip   VARCHAR(45) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL,
  started_at  DATETIME NULL,
  ended_at    DATETIME NULL,
  bytes       INT UNSIGNED NOT NULL DEFAULT 0,
  status      ENUM('new','open','closed') NOT NULL DEFAULT 'new',
  note        VARCHAR(100) NOT NULL DEFAULT '',
  UNIQUE KEY ux_token (token_hash),
  KEY ix_dev (device_id, id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
