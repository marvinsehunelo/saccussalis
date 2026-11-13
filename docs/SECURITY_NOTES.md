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

## Holds (`backend/api/v1/hold.php`, and `notify_debit.php` for who may call)

Every action on this endpoint (place, release, debit) needs a request signed by a member of the
VouchMorph scheme: a `certificate` issued by the scheme CA and a `signature`, made with that
certificate's key. The check (`backend/helpers/signed_request.php`, the same
`CertificateManager::verifySignedRequest` that `notify_debit.php` uses) runs before the database
is opened. A request that fails it gets HTTP 401 and
`{"status":"ERROR","error":"UNAUTHORIZED","message":"..."}` and changes nothing. A correctly
signed request from a certificate that is not allowed (see `HOLD_ALLOWED_SIGNERS` below) gets
HTTP 403 and `{"status":"ERROR","error":"FORBIDDEN","message":"Caller not allowed"}` and changes
nothing. A body that is
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
- Replay: the `timestamp` is signed but its age is not checked. Replaying a signed release or debit
  is harmless: the hold row is locked and its status is no longer `ACTIVE`, so the second one is
  refused and changes nothing. Replaying a signed place is refused as a duplicate, because a place
  must carry a `reference` and `hold_reference` is unique.

### Decided on 13 November 2026

1. **Who may call: `HOLD_ALLOWED_SIGNERS`, off by default.** A comma-separated list of certificate
   common names (CN). Names are compared exactly, case included, after trimming spaces; a
   certificate must carry exactly one CN and it must be on the list. When it is set, `hold.php`
   and `notify_debit.php` refuse a correctly signed request from any other certificate with 403
   `Caller not allowed`, before the database is queried. When it is not set (or holds only spaces
   and commas) any certificate the scheme CA issued may call, as before: then any scheme member
   can place, release or debit a hold. The one check is `signer_is_allowed()` in
   `backend/helpers/signed_request.php`; both endpoints call it. It is off by default because
   nobody has yet read the CN in VouchMorph's live certificate, and a wrong name would refuse
   every hold. There is still no revocation list. The other endpoints that use
   `verifySignedRequest` (cash-out, credit, deposit and others) do not use the list.
2. **A place must carry a `reference`.** A signed place without one (or with an empty or non-text
   one) is refused with `Missing required field: reference` / `Invalid reference` and changes
   nothing. Before, each such call got a fresh reference, so a replay reserved the money again.
   VouchMorph's swap engine always sends one. No timestamp age check was added.
3. **`requester` stays a label, not proof.** It is not covered by the signature and nothing is
   decided on it. With the allow-list on, who signed is what is controlled.
4. **`notify_debit.php` no longer logs its input** (which held the certificate, the signature and
   any PIN), neither on arrival nor on an error: it logs the action and field names only, like
   `hold.php`. Nothing else changed in how it answers a correctly signed, allowed request.

To switch the allow-list on (after this is deployed):

1. Read the CN of the certificate VouchMorph really uses, from the log of the live saccussalis
   service: every verified call logs `HOLD: request verified by certificate (signer: <CN>, ...)`
   (and `notify_debit.php` logs `... using certificate (signer: <CN>)`). Or read it from
   VouchMorph's certificate itself: `openssl x509 -in vouchmorph.crt -noout -subject`.
2. Set `HOLD_ALLOWED_SIGNERS=<that CN>` on the saccussalis service and redeploy.
3. Place and release one test hold through VouchMorph. If it gets 403 `Caller not allowed`, the
   name is wrong: unset the variable (back to today's behaviour) and check the log again.

What VouchMorph sends today (checked against its real bank client, with a throwaway CA and key):

- Placing a hold the way the swap engine does it (`placeHoldSigned`): accepted.
- Release (`releaseHold`, action `RELEASE_HOLD`, to `hold.php`): accepted.
- Debit goes to `notify_debit.php`, not `hold.php` (`debitFunds`): accepted there by the same check.
- Refused, and not used against `hold.php` by the swap engine today: `GenericBankClient::placeHold()`
  called directly (it puts the certificate into the body before signing, so it signs different
  bytes from the ones checked here; only `authorize()` reaches it), and `reverse()` /
  `reverseTransaction()`, which sends unsigned. Either one would get 401 if it were wired up.
- VouchMorph signs only when its own key and certificate are configured; without them it sends
  unsigned and every call is refused.

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
| `HOLD_ALLOWED_SIGNERS` (optional; comma-separated CNs) | saccussalis | Nothing breaks: any certificate from the scheme CA may call `hold.php` and `notify_debit.php`. Set it, with the right name, to allow only VouchMorph. |

The `openssl` command must be on the host as well (`CertificateManager` calls it to check the
certificate chain); without it every certificate is refused.

### Test

`php tests/hold_signature_test.php` checks the signature rules without a database. With
`HOLD_E2E=1` it also runs the real endpoints against a local throwaway PostgreSQL (it refuses any
other host and drops and recreates its tables): every action unsigned and wrongly signed, odd
bodies, bad amounts, a place without a reference, replays, the allow-list on and off for both
`hold.php` and `notify_debit.php`, a scan of the log for PINs, signatures and certificates from
both endpoints, and requests at the same moment. For those, the test locks the row they need, waits until PostgreSQL
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
