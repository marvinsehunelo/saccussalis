# KYC Bank integration

KYC Bank is a shared KYC service. Saccussalis staff ask for a customer's KYC by phone or
Omang. The customer approves in the KYC Bank app. KYC Bank then sends a signed webhook, and
Saccussalis fetches the verified profile. KYC Bank never says whether a person has a profile:
a number without one looks like any other pending request until it lapses.

## Environment variables

Set these on the Railway service. Never commit their values.

| Name | Used for |
| --- | --- |
| `KYCBANK_API_URL` | API base URL (staging or production) |
| `KYCBANK_CLIENT_ID` | OAuth client credentials |
| `KYCBANK_CLIENT_SECRET` | OAuth client credentials |
| `KYCBANK_WEBHOOK_SECRET` | Verifies the `x-kyc-signature` header on webhooks |

If the first three are missing, the admin page shows "KYC Bank is not configured" and records
already stored stay visible. If `KYCBANK_WEBHOOK_SECRET` is missing, the webhook answers 503,
so KYC Bank keeps retrying until it is set.

## Webhook URL

Register this URL with KYC Bank:

```
https://<bank domain>/backend/integrations/kycbank/webhook.php
```

With the domain the code uses as its default `SACCUSSALIS_PUBLIC_URL`, that is
`https://saccussalis-production.up.railway.app/backend/integrations/kycbank/webhook.php`.
The app is served by `php -S 0.0.0.0:$PORT -t .`, so the document root is the repository root.

- The endpoint accepts POST only. No admin session is needed; the signature is the
  authentication.
- The signature is HMAC-SHA256 over `<t>.<raw body>`. The endpoint rejects a missing or wrong
  signature, or a `t` more than 5 minutes off, with 401.
- It answers 200 for events it processed or deliberately ignored: duplicates (matched by event
  `id`), unknown consent ids and test pings. It answers 503 for a failure worth retrying (the
  database or KYC Bank is unreachable). KYC Bank retries any non-2xx answer for about a day.
- Responses and logs carry IDs only: event, consent, request and user IDs.

## Database

The integration uses two Postgres tables:

- `kycbank_requests` holds one row per request sent to KYC Bank: its state and dates, and the
  fetched profile (`profile_json`, the exact JSON received). The phone or Omang value used for a
  request is not stored, only its kind.
- `kycbank_webhook_events` holds the event IDs already processed.

`backend/integrations/kycbank/kycbank.php` creates both tables on first use, as
`backend/helpers/secondary_email.php` does for its own tables. Opening the admin page once, or
receiving the first webhook, is enough. To apply them by hand instead, run the idempotent SQL
file:

```
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_30_kycbank.sql
```

On Railway, use the Postgres service's connection URL: `DATABASE_PUBLIC_URL` from outside
Railway, or `railway run psql "$DATABASE_URL" ...` / `railway connect Postgres`. The repository
has no migration runner, and deploys do not run SQL files.

## Staff flow

The page is `frontend/dashboard/admin_kycbank.php`. To reach it, use **KYC BANK** in the admin
dashboard navigation, or the **KYC BANK** button on a customer's row. Every role that can see
users can open the page. Auditors can only view; the other roles can also act. Forms post to
`backend/admin/admin_kycbank_action.php` with a CSRF token.

1. **Request KYC from KYC Bank.** Identify the customer by phone or by Omang (9 digits). The
   phone field is prefilled from the phone on file in +267 form and can be edited. Add a
   purpose, which the customer sees (at most 200 characters), and choose the basic or standard
   level. The bank saves the request's idempotency key before calling KYC Bank. If KYC Bank
   cannot be reached, use **Send again**: it reuses the same key, so the request is never
   created twice.
2. **Status.** The page shows the state, a suspension reason in plain words and the dates.
   **Refresh status** asks KYC Bank for the current state, which helps when a webhook was
   missed. It also fetches the profile if the consent is active and no profile, or no newer
   version, has been fetched yet.
3. **View KYC Bank profile.** This appears once the consent is active and the profile has been
   fetched. The page shows personal details, identity documents, addresses and contacts first,
   then the rest of the profile. Each view is written to `audit_logs`.
4. **Approve KYC using this profile / Reject.** Only these buttons change `users.kyc_status`:
   approve sets `verified`, the value `backend/admin/admin_approve_kyc.php` writes, and reject
   sets `rejected`. The page records who reviewed the profile, when, and which version.
   Webhooks never change `kyc_status`.
5. **Updates.** When KYC Bank sends a newer profile version (`profile.updated`), it is fetched
   and flagged "Updated profile — needs staff review".
6. **Suspended.** The profile is hidden and must not be used until `consent.resumed` arrives.
   Suspension happens, for example, while the customer is not yet verified
   (`profile_not_verified`).
7. **Withdrawn.** The page shows "Withdrawn by the customer on <date> — data no longer in use".
   The stored profile is hidden and never fetched again; the copy is kept only for
   record-keeping. If staff had approved KYC on this consent, a warning says that the KYC basis
   was withdrawn. The KYC status is not changed automatically.

## Tests

```
php tests/kycbank_signature_test.php
```

This checks signature verification: a valid signature, a tampered body, a wrong secret, a
stale `t` (6 minutes old), a `t` in the future and a missing header. It runs from the command
line only and answers 404 over HTTP.
