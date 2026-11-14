-- v1.38: prva konfiguracija praznega stikala (ukrep 'swinit')
ALTER TABLE noc_actions MODIFY kind ENUM('block','unblock','upgrade','speed','term','wgsrv','wgdel','ntp','swport','swinit') NOT NULL;
