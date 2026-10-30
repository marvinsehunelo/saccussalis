-- users.kyc_status uses 'pending' | 'approved' | 'rejected'. An older admin action wrote
-- 'verified' for approved customers; this makes them consistent. Safe to run more than once:
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_30_kyc_status_approved.sql
UPDATE users SET kyc_status = 'approved' WHERE kyc_status = 'verified';
