# Security notes

## Database password

`backend/db.php` reads the `PG*` variables (or `DATABASE_URL` when `PGPASSWORD` is not set).
There is no password in the code any more. The password that used to be there is in this public
repository's history, so rotate it: in Railway, Postgres service → change the `postgres` user's
password, then update `PGPASSWORD` and `DATABASE_URL` on every service that uses it
(`saccussalis`, `saccussalis-expiry-jobs`).

## What the web server serves

The app runs with `php -S 0.0.0.0:$PORT -t . router.php`. `router.php` answers 404 for database
dumps and migrations (`*.sql`, `database/`), logs and text files, tests and debug pages (any
`*test*.php`, `phpinfo.php`), config, cron and include folders, dotfiles (`.git`, `.env`),
Composer and build files. Everything else is served as before. If you change the start command in
Railway, keep `router.php` at the end.

## Admin endpoints

- `backend/admin/admin_get_users.php` now needs a signed-in staff session.
- `backend/admin/admin_approve_kyc.php` takes `{ client_id, status: "approved" | "rejected" }`,
  refuses auditors, and writes an `audit_logs` row with the old and new status.
- `database/migrations/2026_10_30_kyc_status_approved.sql` turns old `verified` statuses into
  `approved`.
