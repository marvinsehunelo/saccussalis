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

## Holds (`backend/api/v1/hold.php`)

Every action on this endpoint (place, release, debit) needs a request signed by a member of the
VouchMorph scheme: a `certificate` issued by the scheme CA and a `signature`, made with that
certificate's key, over the rest of the JSON body. The check (`backend/helpers/signed_request.php`,
the same `CertificateManager::verifySignedRequest` that `notify_debit.php` uses) runs before the
database is touched. A request that fails it gets HTTP 401 and
`{"status":"ERROR","error":"UNAUTHORIZED","message":"..."}` and changes nothing.

Variables the host must have, or holds stop working:

| Variable | On | What happens when it is missing |
| --- | --- | --- |
| `VOUCHMORPH_CA_CERT_CONTENT` (PEM of the scheme CA) | saccussalis | Every hold, release and debit is refused (401). |
| `SACCUSSALIS_PRIVATE_KEY_CONTENT`, `SACCUSSALIS_CERT_CONTENT` | saccussalis | The hold is placed but the answer goes out unsigned, and VouchMorph rejects an unsigned answer from a bank - the money stays reserved with no swap. |
| `VOUCHMORPH_PRIVATE_KEY(_CONTENT)`, `VOUCHMORPH_CERT(_CONTENT)` | VouchMorph | VouchMorph sends holds without a certificate and releases unsigned; both are now refused. |

The `openssl` command must be on the host as well (`CertificateManager` calls it to check the
certificate chain); without it every certificate is refused.

Test: `php tests/hold_signature_test.php` (add `HOLD_E2E=1` and a local throwaway database in the
`PG*` variables to run the endpoint end to end).
