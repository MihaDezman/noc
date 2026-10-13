-- NOC v1.13
-- čakalna vrsta obvestil (e-pošta, Telegram): pošilja cron, več hkratnih alarmov gre v en povzetek
CREATE TABLE IF NOT EXISTS notify_queue (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  device_id  INT UNSIGNED NOT NULL,
  dname      VARCHAR(200) NOT NULL DEFAULT '',
  sev        ENUM('info','warning','critical') NOT NULL,
  resolved   TINYINT(1) NOT NULL DEFAULT 0,
  msg        VARCHAR(600) NOT NULL DEFAULT '',
  alert_id   INT UNSIGNED NULL,
  sent_at    DATETIME NULL,
  KEY ix_pending (sent_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
