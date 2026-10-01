-- NOC v1.3 – enkratno čiščenje (struktura baze se ne spreminja)
-- mysql -u noc -p noc < sql/update-1.3.sql

-- sprememba ure ob zagonu (IP Cloud) ni kritičen dogodek
UPDATE logs SET severity='info' WHERE message LIKE 'cloud change time%';

-- dvojniki v logih (ostane prvi primerek)
DELETE l1 FROM logs l1 JOIN logs l2
  ON l1.device_id=l2.device_id AND l1.ts=l2.ts AND l1.topics=l2.topics AND l1.message=l2.message AND l1.id > l2.id;
