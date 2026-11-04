-- NOC 1.32: pri novi državi si zapomnimo nekaj zunanjih naslovov (prikaz pri opozorilu)
ALTER TABLE host_geo ADD COLUMN peers VARCHAR(400) NOT NULL DEFAULT '' AFTER bytes;
