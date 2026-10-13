-- NOC v1.11: indeksi za nočno brisanje/povzetke (brez njih DELETE ... WHERE ts < ... prebere celo tabelo
-- in med tem zaklene vrstice, ki jih zapisujejo pushi) in za odprte alarme na pregledu
ALTER TABLE iface_1m     ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE health_1m    ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE iface_5m     ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE health_5m    ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE host_5m      ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE sfp_5m       ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE fw_5m        ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE out_5m       ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE iface_events ADD INDEX IF NOT EXISTS ix_ts (ts);
ALTER TABLE lan_hosts    ADD INDEX IF NOT EXISTS ix_last_seen (last_seen);
ALTER TABLE backup_parts ADD INDEX IF NOT EXISTS ix_at (at);
ALTER TABLE alerts       ADD INDEX IF NOT EXISTS ix_dev_open (device_id, ended_at, severity);
ALTER TABLE host_daily   ADD INDEX IF NOT EXISTS ix_dev_day (device_id, day);
