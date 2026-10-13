-- NOC v1.12: preverjanje certifikata NOC na routerju (stikalo pri napravi)
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS tls_check   TINYINT(1) NOT NULL DEFAULT 0,   -- router preverja certifikat NOC (prenos skripte vedno, push z rezervo)
  ADD COLUMN IF NOT EXISTS tls_ok      TINYINT(1) NULL,                 -- zadnje sporočilo routerja: 1 = certifikat preverjen, 0 = ni uspelo
  ADD COLUMN IF NOT EXISTS tls_seen_at DATETIME NULL;
