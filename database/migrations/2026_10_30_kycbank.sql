-- KYC Bank integration: requests to KYC Bank and processed webhook events.
--
-- kycbank_requests holds one row per request Saccussalis sends to KYC Bank
-- (the shared KYC service): the consent id and state, the dates KYC Bank
-- reports and, once the customer approves, the fetched profile as received
-- (profile_json, the bank's own KYC record). The phone / Omang value used to
-- make the request is NOT stored, only its kind. needs_review flags a
-- profile KYC Bank updated after the first copy; reviewed_* and
-- review_decision record the staff decision that set users.kyc_status.
--
-- kycbank_webhook_events holds the ids of webhook events already received,
-- so a repeated delivery is not processed twice (processed_at is set once
-- an event has been handled or deliberately ignored).
--
-- backend/integrations/kycbank/kycbank.php creates these tables itself on
-- first use (the admin page or the webhook). This file is the same SQL, for
-- applying by hand:
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_30_kycbank.sql
--
-- Idempotent, safe to re-run.

BEGIN;

CREATE TABLE IF NOT EXISTS kycbank_requests (
    id                       BIGSERIAL PRIMARY KEY,
    user_id                  INTEGER NOT NULL REFERENCES users (user_id),
    consent_id               VARCHAR(100) NULL UNIQUE,
    idempotency_key          VARCHAR(100) NOT NULL UNIQUE,
    identifier_kind          VARCHAR(10) NOT NULL CHECK (identifier_kind IN ('mobile', 'omang')),
    purpose                  VARCHAR(200) NOT NULL,
    level                    VARCHAR(10) NOT NULL CHECK (level IN ('basic', 'standard')),
    state                    VARCHAR(20) NOT NULL DEFAULT 'not_sent',
    suspended_reason         VARCHAR(40) NULL,
    requested_at             TIMESTAMPTZ NULL,
    decided_at               TIMESTAMPTZ NULL,
    starts_at                TIMESTAMPTZ NULL,
    ends_at                  TIMESTAMPTZ NULL,
    lapses_at                TIMESTAMPTZ NULL,
    latest_profile_version   INTEGER NULL,
    profile_version          INTEGER NULL,
    profile_json             TEXT NULL,
    profile_fetched_at       TIMESTAMPTZ NULL,
    needs_review             BOOLEAN NOT NULL DEFAULT FALSE,
    reviewed_by              INTEGER NULL,
    reviewed_at              TIMESTAMPTZ NULL,
    reviewed_profile_version INTEGER NULL,
    review_decision          VARCHAR(10) NULL CHECK (review_decision IN ('approved', 'rejected')),
    requested_by             INTEGER NULL,
    last_error_code          VARCHAR(60) NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    withdrawn_at             TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_kycbank_requests_user
    ON kycbank_requests (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS kycbank_webhook_events (
    event_id     VARCHAR(100) PRIMARY KEY,
    event        VARCHAR(60) NOT NULL,
    consent_id   VARCHAR(100) NULL,
    received_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ NULL
);

COMMIT;
