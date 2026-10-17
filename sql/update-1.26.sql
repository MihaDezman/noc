-- NOC 1.26: ping prehoda pri napravi (samodejno / vpisan naslov / izklopljen)
ALTER TABLE devices ADD COLUMN gw_ping ENUM('auto','fixed','off') NOT NULL DEFAULT 'auto' AFTER wan_gateway;
