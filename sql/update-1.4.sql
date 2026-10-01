-- NOC v1.4 – mesečna poročila, nove naprave v LAN-u, napadi, DHCP pool
-- (update.sh ga požene samodejno; ročno: mysql -u noc -p noc < sql/update-1.4.sql)

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS report_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS report_emails  VARCHAR(500) NOT NULL DEFAULT '';

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS newdev_alert  TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS newdev_ignore VARCHAR(255) NOT NULL DEFAULT '';   -- DHCP strežniki brez obvestil (npr. gostujoči WiFi)

INSERT IGNORE INTO settings (k, v) VALUES
  ('th_attack', '5'), ('th_attack_min', '15'), ('th_pool', '90'), ('th_backup_days', '2');
