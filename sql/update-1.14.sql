-- NOC v1.14: AI pomočnik (razlaga alarmov, pregled sprememb konfiguracije, samonadzor)
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS ai_note TEXT NULL;
ALTER TABLE config_backups
  ADD COLUMN IF NOT EXISTS ai_note TEXT NULL,
  ADD COLUMN IF NOT EXISTS ai_risk ENUM('ok','warn') NULL;
CREATE TABLE IF NOT EXISTS ai_log (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind     VARCHAR(20) NOT NULL,              -- alert, config, watch, test
  ref_id   INT UNSIGNED NULL,
  sig      VARCHAR(80) NULL,                  -- samonadzor: ista težava največ enkrat na 12 h
  model    VARCHAR(40) NOT NULL DEFAULT '',
  in_tok   INT UNSIGNED NOT NULL DEFAULT 0,
  out_tok  INT UNSIGNED NOT NULL DEFAULT 0,
  ok       TINYINT(1) NOT NULL DEFAULT 0,
  err      VARCHAR(300) NOT NULL DEFAULT '',
  result   TEXT NULL,
  KEY ix_at (at), KEY ix_kind (kind, at), KEY ix_sig (sig, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
