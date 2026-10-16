-- NOC 1.22: nastavitve prek ukaznega kanala (terminal, WireGuard strežnik), AI popravki v Safe Mode
ALTER TABLE noc_actions MODIFY kind ENUM('block','unblock','upgrade','speed','term','wgsrv','wgdel') NOT NULL;
ALTER TABLE noc_actions ADD COLUMN IF NOT EXISTS data TEXT NULL;   -- parametri ukaza (JSON), npr. WireGuard strežnik
CREATE TABLE IF NOT EXISTS ai_applies (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  review_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  keys_json   VARCHAR(500) NOT NULL DEFAULT '[]',   -- indeksi uveljavljenih ugotovitev
  script      MEDIUMTEXT NOT NULL,
  status      ENUM('queued','running','done','reverted','failed') NOT NULL DEFAULT 'queued',
  log         MEDIUMTEXT NULL,
  note        VARCHAR(300) NOT NULL DEFAULT '',
  check_token CHAR(32) NOT NULL DEFAULT '',
  check_seen  DATETIME NULL,                      -- router je med Safe Mode dosegel NOC
  created_at  DATETIME NOT NULL,
  started_at  DATETIME NULL,
  ended_at    DATETIME NULL,
  KEY ix_dev (device_id, id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
