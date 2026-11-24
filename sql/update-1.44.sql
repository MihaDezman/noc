-- v1.44: zaznavanje nenormalnega prometa v LAN (noc-lan); blokada SMTP je privzeto izklopljena (najprej opazovanje)
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS lan_watch TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS lan_smtp_block TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS lan_ok VARCHAR(600) NOT NULL DEFAULT '';
