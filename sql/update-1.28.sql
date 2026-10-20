-- NOC 1.28: Telegram – takoj samo pomembno, ostalo v povzetku ob izbranih urah
ALTER TABLE notify_queue ADD COLUMN tg_digest TINYINT(1) NOT NULL DEFAULT 0 AFTER sent_at, ADD KEY ix_digest (tg_digest, id);
-- privzeto: takoj samo kritični, povzetek ob 8. in 16. uri (lahko spremeniš v Alarmi in obvestila → Telegram)
INSERT INTO settings (k, v) VALUES ('notify_tg_min', 'critical') ON DUPLICATE KEY UPDATE v = 'critical';
INSERT IGNORE INTO settings (k, v) VALUES ('tg_digest_hours', '8,16');
