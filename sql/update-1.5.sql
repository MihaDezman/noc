-- NOC v1.5 – dodajanje routerja z enkratno kodo, 2FA
-- (update.sh ga požene samodejno)

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

ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last INT UNSIGNED NULL;   -- zadnji uporabljen časovni korak (proti ponovni uporabi kode)
