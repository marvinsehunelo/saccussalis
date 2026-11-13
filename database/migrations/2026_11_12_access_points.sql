-- Public access points (ATMs and branches) for GET /backend/api/v1/access_points.php.
--
-- VouchMorph reads that list for its access point locator. ATMs are the rows of the existing
-- atms table; the bank had nowhere to record a public name, a town or opening hours for one,
-- so three plain, optional columns are added (atms.location stays the address or landmark).
-- The bank had no table of branches, so branches is new. A row is listed while its status is
-- 'ACTIVE'. No rows are inserted here.
--
-- backend/helpers/access_points.php makes these changes itself on first use (the first request
-- to the endpoint). This file is the same SQL, for applying by hand:
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_access_points.sql
--
-- Idempotent, safe to re-run.

BEGIN;

ALTER TABLE atms ADD COLUMN IF NOT EXISTS name  VARCHAR(100);
ALTER TABLE atms ADD COLUMN IF NOT EXISTS town  VARCHAR(100);
ALTER TABLE atms ADD COLUMN IF NOT EXISTS hours VARCHAR(100);

CREATE TABLE IF NOT EXISTS branches (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    town       VARCHAR(100) NOT NULL,
    address    TEXT,
    hours      VARCHAR(100),
    status     VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

COMMIT;
