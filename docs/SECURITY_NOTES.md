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
certificate's key. The check (`backend/helpers/signed_request.php`, the same
`CertificateManager::verifySignedRequest` that `notify_debit.php` uses) runs before the database
is opened. A request that fails it gets HTTP 401 and
`{"status":"ERROR","error":"UNAUTHORIZED","message":"..."}` and changes nothing. A body that is
empty or not a JSON object is turned away as before (`"No input data received"`), also before the
database. Answers to a correctly signed request are unchanged.

What the signature covers, and what it does not:

- Covered: every field of the body except `signature`, `certificate` and `requester`, top-level
  keys sorted, JSON-encoded the way VouchMorph signs. Changing, adding or removing a covered
  field, changing a value's type (`"150"` for `150.0`) or reordering keys inside a nested object
  breaks the signature. Reordering top-level keys does not (they are sorted) and changes nothing.
- Not covered: `requester`. It is stored on the hold and echoed back, but it is only a claim. The
  log line `HOLD: request verified by certificate (signer: ...)` names the certificate that really
  signed.
- Who passes: any certificate the scheme CA issued, not only VouchMorph's. So any scheme member
  can place, release or debit a hold here. `notify_debit.php` (and the other endpoints that use
  `verifySignedRequest`) behave the same way. Limiting this endpoint to VouchMorph's own
  certificate is a decision still to make; there is no revocation list either.
- Replay: the `timestamp` is signed but its age is not checked. Replaying a signed release or debit
  is harmless: the hold row is locked and its status is no longer `ACTIVE`, so the second one is
  refused and changes nothing. Replaying a signed place with a `reference` is refused as a
  duplicate (`hold_reference` is unique). A signed place **without** a `reference` gets a new
  reference each time, so a replay of it reserves the money again (until released or expired).

Other rules on this endpoint:

- Place refuses an amount that is not a number greater than zero (a negative amount used to lower
  `held_balance` and so free money held for something else).
- The balance check and the reservation are one `UPDATE ... WHERE balance - held_balance >= amount`
  statement. At PostgreSQL's default level (READ COMMITTED) a statement that waited for the row's
  lock checks the condition again on the updated row, so holds placed at the same moment cannot
  together reserve more than the balance.
- Nothing secret is logged: not the body (it can carry a PIN), not the signature, not the
  certificate. `CertificateManager` used to log every signed body it checked; it now logs the size
  only (this also applies to the other endpoints that use it).

Variables the host must have, or holds stop working:

| Variable | On | What happens when it is missing |
| --- | --- | --- |
| `VOUCHMORPH_CA_CERT_CONTENT` (PEM of the scheme CA) | saccussalis | Every hold, release and debit is refused (401). |
| `SACCUSSALIS_PRIVATE_KEY_CONTENT`, `SACCUSSALIS_CERT_CONTENT` | saccussalis | The hold is placed but the answer goes out unsigned, and VouchMorph rejects an unsigned answer from a bank - the money stays reserved with no swap. |
| `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD` (or `DATABASE_URL`) | saccussalis | Signed requests are answered with a database error. |
| `VOUCHMORPH_PRIVATE_KEY(_CONTENT)`, `VOUCHMORPH_CERT(_CONTENT)` | VouchMorph | VouchMorph sends holds without a certificate and releases unsigned; both are now refused. |

The `openssl` command must be on the host as well (`CertificateManager` calls it to check the
certificate chain); without it every certificate is refused.

### Test

`php tests/hold_signature_test.php` checks the signature rules without a database. With
`HOLD_E2E=1` it also runs the real endpoint against a local throwaway PostgreSQL (it refuses any
other host and drops and recreates its tables): every action unsigned and wrongly signed, odd
bodies, bad amounts, replays, a scan of the log for the PIN, signature and certificates, and
requests at the same moment. For those, the test locks the row they need, waits until PostgreSQL
shows every request waiting for that lock, then lets them go, so the result does not depend on
timing. PostgreSQL will not run as root; one way to get a throwaway one:

```sh
D=/var/tmp/holdtest_pg; mkdir -p $D; chown postgres $D; echo made-up > $D/pw; chown postgres $D/pw
su postgres -s /bin/sh -c "/usr/lib/postgresql/16/bin/initdb -D $D/data -U postgres --pwfile=$D/pw -A scram-sha-256 &&
  /usr/lib/postgresql/16/bin/pg_ctl -D $D/data -o '-p 55432 -k $D -c listen_addresses=127.0.0.1' -l $D/log start"
PGPASSWORD=made-up psql -h 127.0.0.1 -p 55432 -U postgres -c 'CREATE DATABASE hold_test'
HOLD_E2E=1 PGHOST=127.0.0.1 PGPORT=55432 PGDATABASE=hold_test PGUSER=postgres PGPASSWORD=made-up \
  php tests/hold_signature_test.php
```
