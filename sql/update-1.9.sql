-- NOC v1.9 – samodejne posodobitve skripte na routerjih
-- (update.sh ga požene samodejno)

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS auto_update    TINYINT(1) NOT NULL DEFAULT 1,   -- router se sam posodobi, ko je na voljo nova skripta
  ADD COLUMN IF NOT EXISTS script_ver     VARCHAR(12) NULL,                -- verzija skripte, ki jo router sporoča
  ADD COLUMN IF NOT EXISTS upd_attempt_at DATETIME NULL;                   -- zadnji poziv k posodobitvi (največ enkrat na 30 min)
