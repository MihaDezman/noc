-- NOC v1.15: AI asistent v Telegramu (zgodovina pogovora), ukrepi z enim klikom (blokada IP)
ALTER TABLE ai_log ADD COLUMN IF NOT EXISTS prompt TEXT NULL;          -- vprašanje (pogovor v Telegramu)
CREATE TABLE IF NOT EXISTS noc_actions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  kind        ENUM('block','unblock') NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  hours       SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  status      ENUM('proposed','approved','sent','done','rejected','failed','cancelled') NOT NULL DEFAULT 'proposed',
  tries       TINYINT UNSIGNED NOT NULL DEFAULT 0,      -- kolikokrat poslano routerju (po 3 neuspehih: failed)
  reason      VARCHAR(300) NOT NULL DEFAULT '',
  source      VARCHAR(20) NOT NULL DEFAULT '',      -- ai, alarm, splet
  alert_id    INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at  DATETIME NULL,
  decided_by  VARCHAR(60) NOT NULL DEFAULT '',
  sent_at     DATETIME NULL,
  done_at     DATETIME NULL,
  KEY ix_dev_status (device_id, status),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
