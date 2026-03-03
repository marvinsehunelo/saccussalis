<?php

UPDATE sat_tokens
SET status='EXPIRED'
WHERE expires_at < NOW() AND status='ACTIVE';

UPDATE cash_instruments
SET status='AVAILABLE'
WHERE status='RESERVED_FOR_SWAP'
AND instrument_id IN (
   SELECT instrument_id FROM sat_tokens WHERE status='EXPIRED'
);
