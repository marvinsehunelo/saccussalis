-- database/access_points_demo_seed.sql
--
-- MADE-UP DEMO DATA FOR THE SANDBOX. OPTIONAL. APPLIED BY HAND ONLY.
--
-- A handful of invented ATMs and branches for this simulated bank, so that
-- GET /backend/api/v1/access_points.php has something to list. None of them exists: the names
-- say "Demo", the addresses are placeholders, there are no coordinates and the cash figures
-- are invented. Only the towns are real place names, so a locator has something to group by.
--
-- Nothing in the code runs this file and no deployment applies it. To use it, first apply
-- database/migrations/2026_11_12_access_points.sql (or call the endpoint once, which makes the
-- same changes), then:
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/access_points_demo_seed.sql
--
-- Safe to re-run: a row that is already there (same atm_code, same branch name) is left alone.
--
-- Before applying, know that the demo ATMs are ordinary rows of the bank's atms table:
--   - one that is ACTIVE and has a cash balance is a working terminal for backend/atm like any
--     other, and its invented cash is not matched by anything in the ledger;
--   - the ATM simulator (frontend/atm) uses ATM id 1, so on a database that has no ATMs yet
--     DEMO-ATM-01 gets id 1 and becomes the simulator's terminal.
--
-- To take the demo data out again (an ATM that has dispensed is referenced by
-- atm_transactions and cannot be deleted; set its status to 'INACTIVE' instead):
--     DELETE FROM atms WHERE atm_code LIKE 'DEMO-ATM-%';
--     DELETE FROM branches WHERE name LIKE 'Saccussalis Demo Branch - %';

BEGIN;

-- ATMs. Listed: 01 (has cash), 02 (empty), 03 (cash, address and hours not recorded).
-- Not listed: 04 (out of service).
INSERT INTO atms (atm_code, name, town, location, hours, status, cash_balance) VALUES
    ('DEMO-ATM-01', 'Saccussalis Demo ATM - Station', 'Gaborone',    'Demo address - inside the station hall', '24 hours',            'ACTIVE',   5000.00),
    ('DEMO-ATM-02', 'Saccussalis Demo ATM - Market',  'Francistown', 'Demo address - market entrance',         'Mon-Sun 06:00-22:00', 'ACTIVE',   0.00),
    ('DEMO-ATM-03', 'Saccussalis Demo ATM - Campus',  'Maun',        NULL,                                     NULL,                  'ACTIVE',   NULL),
    ('DEMO-ATM-04', 'Saccussalis Demo ATM - Depot',   'Gaborone',    'Demo address - depot gate',              '24 hours',            'INACTIVE', 0.00)
ON CONFLICT (atm_code) DO NOTHING;

-- Branches. Listed: Central and North. Not listed: West (closed).
INSERT INTO branches (name, town, address, hours, status)
SELECT demo.name, demo.town, demo.address, demo.hours, demo.status
FROM (VALUES
    ('Saccussalis Demo Branch - Central', 'Gaborone',    'Demo address - ground floor', 'Mon-Fri 08:30-16:30, Sat 08:30-12:00', 'ACTIVE'),
    ('Saccussalis Demo Branch - North',   'Francistown', NULL,                          'Mon-Fri 08:30-16:30',                  'ACTIVE'),
    ('Saccussalis Demo Branch - West',    'Maun',        'Demo address - first floor',  NULL,                                   'CLOSED')
) AS demo (name, town, address, hours, status)
WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.name = demo.name);

COMMIT;
