-- NOC 1.20: meritve hitrosti interneta
ALTER TABLE noc_actions MODIFY kind ENUM('block','unblock','upgrade','speed') NOT NULL;
ALTER TABLE devices ADD COLUMN IF NOT EXISTS speed_auto TINYINT(1) NOT NULL DEFAULT 0;

-- meritve hitrosti interneta (btest na strežnik NOC ali prenos datoteke)
CREATE TABLE IF NOT EXISTS speed_tests (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id  INT UNSIGNED NOT NULL,
  at         DATETIME NOT NULL,
  method     ENUM('btest','http') NOT NULL,
  down_mbps  DECIMAL(8,1) NULL,
  up_mbps    DECIMAL(8,1) NULL,
  ok         TINYINT(1) NOT NULL DEFAULT 0,
  note       VARCHAR(200) NOT NULL DEFAULT '',
  action_id  INT UNSIGNED NULL,
  source     ENUM('rocno','noc') NOT NULL DEFAULT 'rocno',
  KEY ix_dev (device_id, at),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
