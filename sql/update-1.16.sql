-- NOC v1.16: zapiski za AI, povratna informacija, incidenti, AI povzetek poročila, napovedi, AI pregled konfiguracije
CREATE TABLE IF NOT EXISTS ai_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NULL,                     -- NULL = velja za vse naprave
  note        VARCHAR(500) NOT NULL,
  source      VARCHAR(20) NOT NULL DEFAULT 'splet',  -- splet, telegram
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  VARCHAR(60) NOT NULL DEFAULT '',
  KEY ix_dev (device_id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE ai_log ADD COLUMN IF NOT EXISTS feedback TINYINT NULL;   -- 1 = koristno, 0 = ni koristno (Telegram)
CREATE TABLE IF NOT EXISTS incidents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  started_at  DATETIME NOT NULL,
  ended_at    DATETIME NULL,
  severity    ENUM('warning','critical') NOT NULL DEFAULT 'critical',
  title       VARCHAR(200) NOT NULL,
  ai_summary  TEXT NULL,
  KEY ix_dev (device_id, started_at), KEY ix_open (ended_at),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS incident_id INT UNSIGNED NULL, ADD INDEX IF NOT EXISTS ix_incident (incident_id);
CREATE TABLE IF NOT EXISTS report_ai (
  tenant_id   INT UNSIGNED NOT NULL,
  ym          CHAR(7) NOT NULL,
  text        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, ym),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE health_5m
  ADD COLUMN IF NOT EXISTS hdd_pct TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS pool_pct TINYINT UNSIGNED NULL;            -- najbolj zaseden IP pool (za napovedi)
CREATE TABLE IF NOT EXISTS ai_reviews (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status      ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  findings    MEDIUMTEXT NULL,                       -- JSON: [{title, severity, why, fix, state}]
  note        VARCHAR(300) NOT NULL DEFAULT '',
  KEY ix_dev (device_id, id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
