-- NOC v1.18.2: uvoženi WireGuard ključi za routerje, ki še niso v NOC (ločena datoteka: update-1.18.sql je bil na strežniku že izveden)
CREATE TABLE IF NOT EXISTS wg_orphans (   -- uvoženi ključi, katerih router še ni v NOC: povežejo se sami, ko router pokaže peer
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  srv_pubkey     CHAR(44) NOT NULL,
  pubkey         CHAR(44) NOT NULL,
  router         VARCHAR(100) NOT NULL DEFAULT '',   -- ime routerja v generatorju (za prikaz)
  name           VARCHAR(100) NOT NULL DEFAULT '',
  kind           ENUM('phone','pc','other') NOT NULL DEFAULT 'other',
  ip             VARCHAR(45) NOT NULL DEFAULT '',
  dns            VARCHAR(100) NOT NULL DEFAULT '',
  client_allowed VARCHAR(500) NOT NULL DEFAULT '',
  endpoint       VARCHAR(255) NOT NULL DEFAULT '',
  priv_enc       TEXT NOT NULL,
  psk_enc        TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_pk (pubkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
