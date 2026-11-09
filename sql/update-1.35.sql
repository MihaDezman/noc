-- v1.35: vklop NTP odjemalca iz NOC (ukrep 'ntp')
ALTER TABLE noc_actions MODIFY kind ENUM('block','unblock','upgrade','speed','term','wgsrv','wgdel','ntp') NOT NULL;
