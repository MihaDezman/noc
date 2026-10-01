-- NOC v1.7 – zaščita (filtriranje DNS, šifriran DNS, varnostne IP liste, lastne domene)
-- (update.sh ga požene samodejno)

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS filter_profile   ENUM('off','basic','family') NOT NULL DEFAULT 'off',
  ADD COLUMN IF NOT EXISTS filter_force_dns TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS filter_block_doh TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS filter_ip_lists  TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS filter_domains   TEXT NULL,                          -- lastne blokirane domene (ena na vrstico)
  ADD COLUMN IF NOT EXISTS filter_networks  VARCHAR(500) NOT NULL DEFAULT '',   -- prazno = vsa LAN omrežja
  ADD COLUMN IF NOT EXISTS dns_original     VARCHAR(255) NULL;                  -- DNS routerja pred zaščito (za povrnitev)
