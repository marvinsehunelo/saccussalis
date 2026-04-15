-- 🚀 Cleaned PostgreSQL Schema for 'saccussalis'
-- =====================================================================================
-- Converted from MySQL to PostgreSQL syntax.
-- =====================================================================================

-- Temporarily disable foreign key constraints for clean table dropping/creation
SET session_replication_role = replica;

-- Drop all tables if they exist
DROP TABLE IF EXISTS wallet_transactions CASCADE;
DROP TABLE IF EXISTS wallets CASCADE;
DROP TABLE IF EXISTS transactions CASCADE;
DROP TABLE IF EXISTS swap_transactions CASCADE;
DROP TABLE IF EXISTS swap_ledgers CASCADE;
DROP TABLE IF EXISTS swap_middleman CASCADE;    
DROP TABLE IF EXISTS saccus_middleman CASCADE;
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS loan_repayments CASCADE;
DROP TABLE IF EXISTS loans CASCADE;
DROP TABLE IF EXISTS ledger_entries CASCADE;
DROP TABLE IF EXISTS ledger_accounts CASCADE;
DROP TABLE IF EXISTS kyc_documents CASCADE;
DROP TABLE IF EXISTS fees CASCADE;
DROP TABLE IF EXISTS external_banks CASCADE;
DROP TABLE IF EXISTS ewallet_settings CASCADE;
DROP TABLE IF EXISTS ewallet_pins CASCADE;
DROP TABLE IF EXISTS central_bank_link CASCADE;
DROP TABLE IF EXISTS bank_info CASCADE;
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS account_freezes CASCADE;
DROP TABLE IF EXISTS accounts CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- Re-enable foreign key constraints after dropping
SET session_replication_role = default;

-- ========================
-- 1. CORE TABLES
-- ========================

CREATE TABLE users (
  user_id SERIAL PRIMARY KEY,
  full_name VARCHAR(128) NOT NULL,
  email VARCHAR(128) NOT NULL UNIQUE,
  phone VARCHAR(32) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'customer',
  kyc_status VARCHAR(32) DEFAULT 'pending',
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE accounts (
  account_id SERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  account_number VARCHAR(64) NOT NULL UNIQUE,
  account_type VARCHAR(32) NOT NULL DEFAULT 'savings',
  currency CHAR(3) NOT NULL DEFAULT 'BWP',
  balance DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
  is_frozen BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);

CREATE TABLE transactions (
  transaction_id BIGSERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  reference VARCHAR(128) DEFAULT NULL,
  from_account VARCHAR(128) DEFAULT NULL,
  to_account VARCHAR(128) DEFAULT NULL,
  external_bank_id INT DEFAULT NULL,
  amount DECIMAL(20,4) NOT NULL,
  fee_amount DECIMAL(12,4) DEFAULT 0.0000,
  type VARCHAR(32) NOT NULL,
  direction VARCHAR(8) DEFAULT NULL,
  channel VARCHAR(64) DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE sessions (
  id BIGSERIAL PRIMARY KEY,
  user_id INT NULL,
  token VARCHAR(255) UNIQUE DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  data TEXT DEFAULT NULL,
  last_activity TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  expires_at TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT NULL,
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
);

-- ========================
-- 2. WALLET MODULE
-- ========================

CREATE TABLE wallets (
  wallet_id BIGSERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  wallet_type VARCHAR(32) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BWP',
  balance DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
  is_frozen BOOLEAN NOT NULL DEFAULT FALSE,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);

CREATE TABLE wallet_transactions (
  id BIGSERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  wallet_id BIGINT NOT NULL,
  linked_pin_id BIGINT DEFAULT NULL,
  recipient_identifier VARCHAR(128) DEFAULT NULL,
  transaction_type VARCHAR(64) NOT NULL,
  amount DECIMAL(20,4) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  CONSTRAINT fk_wtx_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_wtx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (wallet_id) ON DELETE CASCADE
);

CREATE TABLE ewallet_pins (
    id SERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL,
    pin VARCHAR(6) NOT NULL,
    is_redeemed BOOLEAN DEFAULT FALSE,
    regenerated_by VARCHAR(10) CHECK (regenerated_by IN ('sender','recipient')),
    regeneration_fee NUMERIC(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    swap_enabled BOOLEAN DEFAULT FALSE NOT NULL,
    generated_by BIGINT NOT NULL,
    sender_phone VARCHAR(20) NOT NULL,
    recipient_phone VARCHAR(20) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    amount NUMERIC(15,2) DEFAULT 0.00 NOT NULL,
    redeemed_at TIMESTAMP,
    redeemed_by VARCHAR(255)
);


CREATE TABLE ewallet_settings (
  id SERIAL PRIMARY KEY,
  expiry_minutes INT DEFAULT 1440,
  regeneration_fee DECIMAL(12,4) DEFAULT 2.5000,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

-- ========================
-- 3. LEDGER & AUDIT
-- ========================

CREATE TABLE ledger_accounts (
  id SERIAL PRIMARY KEY,
  account_name VARCHAR(128) NOT NULL,
  account_number VARCHAR(64) NOT NULL UNIQUE,
  account_type VARCHAR(32) DEFAULT 'user',
  currency VARCHAR(8) DEFAULT 'BWP',
  balance DECIMAL(20,4) DEFAULT 0.0000,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE ledger_entries (
  id BIGSERIAL PRIMARY KEY,
  reference VARCHAR(128) NOT NULL,
  debit_account VARCHAR(64) NOT NULL,
  credit_account VARCHAR(64) NOT NULL,
  amount DECIMAL(20,4) NOT NULL,
  currency VARCHAR(8) DEFAULT 'BWP',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE audit_logs (
  id SERIAL PRIMARY KEY,
  entity VARCHAR(64) NOT NULL,
  entity_id INT NULL,
  category VARCHAR(64) NOT NULL DEFAULT 'system',
  action VARCHAR(128) NOT NULL,
  performed_by INT NULL,
  severity VARCHAR(32) DEFAULT 'info',
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  old_value TEXT,
  new_value TEXT,
  performed_at TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT NOW()
);

-- ========================
-- 4. LOANS & FEES
-- ========================

CREATE TABLE loans (
  loan_id BIGSERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  principal DECIMAL(20,4) NOT NULL,
  interest_rate DECIMAL(6,4) NOT NULL,
  term_months INT NOT NULL,
  status VARCHAR(32) DEFAULT 'pending',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);

CREATE TABLE loan_repayments (
  repayment_id BIGSERIAL PRIMARY KEY,
  loan_id BIGINT NOT NULL,
  amount DECIMAL(20,4) NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(32) DEFAULT 'pending',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  paid_at TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT NULL,
  CONSTRAINT fk_repayments_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id) ON DELETE CASCADE
);

CREATE TABLE fees (
  fee_id SERIAL PRIMARY KEY,
  fee_type VARCHAR(64) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  amount DECIMAL(12,4) NOT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

-- ========================
-- 5. SWAP / MIDDLEMANS
-- ========================

CREATE TABLE saccus_middleman (
  id SERIAL PRIMARY KEY,
  account_number VARCHAR(64) NOT NULL UNIQUE,
  api_key VARCHAR(128) NOT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE swap_middleman (
  id SERIAL PRIMARY KEY,
  account_number VARCHAR(64) NOT NULL UNIQUE,
  api_key VARCHAR(128) NOT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE swap_ledgers (
  ledger_id BIGSERIAL PRIMARY KEY,
  swap_reference VARCHAR(128) NOT NULL UNIQUE,
  from_participant VARCHAR(128) NOT NULL,
  to_participant VARCHAR(128) NOT NULL,
  from_type VARCHAR(64) NOT NULL,
  to_type VARCHAR(64) NOT NULL,
  from_account VARCHAR(128) DEFAULT NULL,
  to_account VARCHAR(128) DEFAULT NULL,
  original_amount DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
  final_amount DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
  swap_fee DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  creation_fee DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  admin_fee DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  sms_fee DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  currency_code CHAR(3) NOT NULL DEFAULT 'BWP',
  token VARCHAR(255) DEFAULT NULL,
  reverse_logic BOOLEAN NOT NULL DEFAULT FALSE,
  notes TEXT DEFAULT NULL,
  status VARCHAR(32) DEFAULT 'completed',
  performed_by INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE swap_transactions (
  id BIGSERIAL PRIMARY KEY,
  ledger_id BIGINT NOT NULL,
  source VARCHAR(128) NOT NULL,
  reference VARCHAR(128) DEFAULT NULL,
  amount DECIMAL(20,4) NOT NULL,
  currency CHAR(3) DEFAULT 'BWP',
  type VARCHAR(32) NOT NULL,
  description TEXT DEFAULT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  CONSTRAINT fk_swap_tx_ledger FOREIGN KEY (ledger_id) REFERENCES swap_ledgers (ledger_id) ON DELETE CASCADE
);

-- ========================
-- 6. MISC / SUPPORT TABLES
-- ========================

CREATE TABLE kyc_documents (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  doc_type VARCHAR(32) NOT NULL,
  doc_number VARCHAR(128) DEFAULT NULL,
  doc_file VARCHAR(255) DEFAULT NULL,
  status VARCHAR(32) DEFAULT 'pending',
  submitted_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  reviewed_at TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT NULL,
  CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);

CREATE TABLE notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(32) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(32) DEFAULT 'pending',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);

CREATE TABLE account_freezes (
  freeze_id SERIAL PRIMARY KEY,
  account_id INT NOT NULL,
  reason VARCHAR(255),
  status VARCHAR(32) DEFAULT 'active',
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
  released_at TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT NULL,
  CONSTRAINT fk_account_freezes_account FOREIGN KEY (account_id) REFERENCES accounts (account_id) ON DELETE CASCADE
);

CREATE TABLE external_banks (
  bank_id SERIAL PRIMARY KEY,
  bank_name VARCHAR(128) NOT NULL,
  swift_code VARCHAR(64) NOT NULL UNIQUE,
  country VARCHAR(64) NOT NULL,
  contact_info JSONB DEFAULT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE bank_info (
  id SERIAL PRIMARY KEY,
  bank_name VARCHAR(128) DEFAULT NULL,
  bank_code VARCHAR(64) DEFAULT NULL,
  branch_code VARCHAR(64) DEFAULT NULL,
  central_account_number VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE TABLE central_bank_link (
  id SERIAL PRIMARY KEY,
  central_bank_code VARCHAR(64) DEFAULT NULL,
  central_account_number VARCHAR(64) DEFAULT NULL,
  linked_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
  metadata JSONB DEFAULT NULL
);

-- ========================
-- 7. DEFAULT DATA INSERTS
-- ========================

-- Admin user
INSERT INTO users (user_id, full_name, email, phone, password_hash, role, kyc_status, status)
VALUES (1, 'System Admin', 'admin@saccussalis.com', '2600000000', 'app_generated_hash_for_admin123', 'admin', 'verified', 'active')
ON CONFLICT (user_id) DO NOTHING;

-- System users/accounts
INSERT INTO users (user_id, full_name, email, phone, password_hash, role, kyc_status, status)
VALUES
(41, 'Saccus Operational Account', 'operational@saccussalis.com', '+26780000001', '', 'system', 'verified', 'active'),
(42, 'Saccus Fee Account', 'fee@saccussalis.com', '+26780000002', '', 'system', 'verified', 'active')
ON CONFLICT (user_id) DO NOTHING;

INSERT INTO accounts (user_id, account_type, account_number, balance)
VALUES
(41, 'operational', '10000001', 1000000.00),
(42, 'fee', '10000002', 50000.00)
ON CONFLICT (account_number) DO NOTHING;

INSERT INTO ewallet_settings (expiry_minutes, regeneration_fee)
VALUES (1440, 2.50)
ON CONFLICT DO NOTHING;

INSERT INTO saccus_middleman (account_number, api_key)
VALUES ('SACCUS987654321', 'MIDDLEMAN_SACCUS_API_KEY')
ON CONFLICT (account_number) DO UPDATE SET api_key = EXCLUDED.api_key;

INSERT INTO swap_middleman (account_number, api_key)
VALUES ('SACCUS987654321', 'MIDDLEMAN_SACCUS_API_KEY')
ON CONFLICT (account_number) DO UPDATE SET api_key = EXCLUDED.api_key;

INSERT INTO ledger_accounts (account_name, account_number, account_type, balance)
VALUES
('Swap Middleman Float', 'SWAP-MID-FLOAT', 'liquidity', 0.0000),
('Swap Fee Collector', 'SWAP-FEE', 'fee', 0.0000)
ON CONFLICT (account_number) DO UPDATE SET account_name = EXCLUDED.account_name;


ALTER TABLE transactions ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE;
ALTER TABLE ledger_entries ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE;
ALTER TABLE wallet_transactions ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE;

CREATE OR REPLACE FUNCTION prevent_hard_delete()
RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'Hard deletes are forbidden on financial records';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER no_delete_transactions
BEFORE DELETE ON transactions
FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE TRIGGER no_delete_ledger
BEFORE DELETE ON ledger_entries
FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

ALTER TABLE ledger_entries ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT NOW();

ALTER TABLE accounts ALTER COLUMN balance TYPE NUMERIC(20,4);
ALTER TABLE wallets ALTER COLUMN balance TYPE NUMERIC(20,4);
ALTER TABLE ledger_accounts ALTER COLUMN balance TYPE NUMERIC(20,4);

CREATE TABLE chart_of_accounts (
    coa_code VARCHAR(20) PRIMARY KEY,
    coa_name VARCHAR(255) NOT NULL,
    coa_type VARCHAR(20) CHECK (coa_type IN ('asset','liability','equity','income','expense')),
    parent_coa_code VARCHAR(20),
    is_customer_account BOOLEAN DEFAULT FALSE,
    is_trust_account BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE accounting_closures (
    closure_date DATE PRIMARY KEY,
    closed_by INTEGER,
    closed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closure_type VARCHAR(20) CHECK (closure_type IN ('EOD','EOM','EOY')),
    remarks TEXT
);

CREATE TABLE data_retention_policies (
    entity_name VARCHAR(100) PRIMARY KEY,
    retention_years INT NOT NULL,
    legal_basis TEXT,
    last_reviewed DATE
);

CREATE TABLE disaster_recovery_tests (
    test_id BIGSERIAL PRIMARY KEY,
    test_date DATE NOT NULL,
    test_type VARCHAR(50),
    systems_tested TEXT[],
    result VARCHAR(20) CHECK (result IN ('pass','fail','partial')),
    issues_found TEXT,
    resolved BOOLEAN DEFAULT FALSE,
    signed_off_by INTEGER
);

-- 1. KYC & Sanctions (Crucial for Bank of Botswana)
ALTER TABLE users
  ADD COLUMN pep BOOLEAN DEFAULT FALSE,
  ADD COLUMN sanctions_checked BOOLEAN DEFAULT FALSE,
  ADD COLUMN last_sanctions_check TIMESTAMP;

-- 2. Transaction Purpose & Beneficiary (ECB/PSD2 Audit Requirement)
ALTER TABLE transactions
  ADD COLUMN purpose VARCHAR(255),
  ADD COLUMN beneficiary_name VARCHAR(255);

-- 3. AML Detection Flags (To catch "Structuring" and "Smurfing")
ALTER TABLE transactions
  ADD COLUMN rapid_movement_flag BOOLEAN DEFAULT FALSE,
  ADD COLUMN structuring_flag BOOLEAN DEFAULT FALSE,
  ADD COLUMN unusual_behavior_flag BOOLEAN DEFAULT FALSE;

-- 4. Regulatory Reporting View (Trial Balance)
-- This gives the regulator a "one-click" look at the bank's health.
CREATE OR REPLACE VIEW daily_trial_balance AS
SELECT
  currency,
  SUM(balance) AS total_balance,
  COUNT(*) as account_count
FROM ledger_accounts
GROUP BY currency;

-- 5. Data Integrity: The "Public" Lock
-- We remove permissions from PUBLIC to ensure only the app logic works.
-- Note: Replace 'ledger_entries' with 'swap_ledger' when running on ZuruBank.

-- FOR SACCUSSALIS:
REVOKE UPDATE, DELETE ON ledger_entries FROM PUBLIC;

-- FOR ZURUBANK:
-- REVOKE UPDATE, DELETE ON swap_ledger FROM PUBLIC;


-- FOR CAZACOM CAZAMOMONEY TRUST ACCOUNTS:

CREATE TABLE trust_accounts (
    account_number VARCHAR(30) PRIMARY KEY,
    account_name VARCHAR(255),
    currency CHAR(3) DEFAULT 'BWP',
    status VARCHAR(20),
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE trust_postings (
    id BIGSERIAL PRIMARY KEY,
    account_number VARCHAR(30),
    reference VARCHAR(100) UNIQUE,
    amount NUMERIC(20,2),
    direction VARCHAR(10), -- CREDIT = deposit, DEBIT = withdrawal
    channel VARCHAR(50), -- branch, atm, settlement
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE trust_balances (
    account_number VARCHAR(30) PRIMARY KEY,
    available_balance NUMERIC(20,2),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE webhook_notifications (
    id BIGSERIAL PRIMARY KEY,
    reference VARCHAR(100) UNIQUE,
    payload JSONB,
    delivered BOOLEAN DEFAULT false,
    delivered_at TIMESTAMP
);


CREATE TABLE safeguarding_register (
    id BIGSERIAL PRIMARY KEY,
    account_number VARCHAR(30),
    institution_name VARCHAR(100),
    safeguarded_amount NUMERIC(20,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create ledger account for SmartShop Agent
INSERT INTO ledger_accounts (account_name, account_number, account_type, balance, currency)
VALUES 
('SmartShop Float Ledger', 'AGENT-001', 'liability', 0.00, 'BWP')
ON CONFLICT (account_number) DO UPDATE 
SET account_name = EXCLUDED.account_name;

INSERT INTO ledger_accounts (
    account_number, account_name, account_type, currency
)
VALUES (
    'AGENT-001',
    'SmartShop Float Ledger',
    'liability',
    'BWP'
)
ON CONFLICT (account_number)
DO UPDATE SET 
    account_name = EXCLUDED.account_name,
    account_type = EXCLUDED.account_type,
    currency = EXCLUDED.currency;

DROP TABLE IF EXISTS ledger_entries CASCADE;

CREATE TABLE ledger_entries (
  id BIGSERIAL PRIMARY KEY,
  reference VARCHAR(128) NOT NULL,
  debit_account VARCHAR(64) NOT NULL,
  credit_account VARCHAR(64) NOT NULL,
  amount DECIMAL(20,4) NOT NULL,
  currency VARCHAR(8) DEFAULT 'BWP',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

ALTER TABLE ledger_entries
ADD COLUMN reference_type VARCHAR(32),
ADD COLUMN reference_id BIGINT,
ADD COLUMN narration TEXT;

ALTER TABLE ledger_accounts
ADD COLUMN owner_type VARCHAR(32),
ADD COLUMN owner_id INT;

-- Example Trust account
UPDATE ledger_accounts
SET owner_type='TRUST', owner_id=NULL
WHERE account_number='TRUST-001';

-- Example Agent account
UPDATE ledger_accounts
SET owner_type='AGENT', owner_id=1
WHERE account_number='AGENT-001';

INSERT INTO ledger_entries (
    reference,
    debit_account,
    credit_account,
    amount,
    currency,
    notes
)
VALUES (
    'CASHIN-12345',  -- <-- this is the required NOT NULL reference
    'TRUST-001',
    'AGENT-001',
    5000.00,
    'BWP',
    'Cash deposit by SmartShop Agent for eMoney float'
);


CREATE TABLE cash_instruments (
    instrument_id      BIGSERIAL PRIMARY KEY,
    owner_phone        VARCHAR(20) NOT NULL,
    source_type        VARCHAR(20) NOT NULL, -- EWALLET | VOUCHER
    source_ref         VARCHAR(100),
    amount             NUMERIC(12,2) NOT NULL,
    currency           VARCHAR(5) DEFAULT 'BWP',

    status             VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE',
    -- AVAILABLE | RESERVED_FOR_SWAP | AUTHORIZED | DISPENSED | EXPIRED | REVERSED

    sat_fee_status     VARCHAR(20) DEFAULT 'PAY_ON_SWAP', 
    -- PREPAID | PAY_ON_SWAP | CONSUMED | WAIVED

    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sat_tokens (
    sat_id           BIGSERIAL PRIMARY KEY,
    sat_code         VARCHAR(40) UNIQUE NOT NULL,
    instrument_id    BIGINT REFERENCES cash_instruments(instrument_id),

    issuer_bank      VARCHAR(30) NOT NULL,
    acquirer_network VARCHAR(30) NOT NULL,

    amount           NUMERIC(12,2) NOT NULL,
    pin_hash         VARCHAR(200) NOT NULL,

    expires_at       TIMESTAMP NOT NULL,
    status           VARCHAR(30) DEFAULT 'ACTIVE',
    -- ACTIVE | USED | EXPIRED | CANCELLED

    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE atm_authorizations (
    auth_id        BIGSERIAL PRIMARY KEY,
    sat_code       VARCHAR(40),
    trace_number   VARCHAR(50),
    acquirer_bank  VARCHAR(30),
    amount         NUMERIC(12,2),

    response_code  VARCHAR(5),
    auth_code      VARCHAR(20),

    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(trace_number, acquirer_bank)
);

CREATE TABLE clearing_positions (
    id            BIGSERIAL PRIMARY KEY,
    debtor_bank   VARCHAR(30),
    creditor_bank VARCHAR(30),
    amount        NUMERIC(12,2),
    reference     VARCHAR(50),
    status        VARCHAR(20) DEFAULT 'PENDING',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE network_fee_ledger (
    id            BIGSERIAL PRIMARY KEY,
    sat_code      VARCHAR(40),
    payer         VARCHAR(20),
    amount        NUMERIC(12,2),
    collected_from VARCHAR(20),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE cash_instruments
ADD COLUMN wallet_id BIGINT,
ADD COLUMN pin_id BIGINT,
ADD COLUMN reserved_amount NUMERIC(12,2),
ADD CONSTRAINT fk_wallet FOREIGN KEY(wallet_id) REFERENCES wallets(wallet_id),
ADD CONSTRAINT fk_pin FOREIGN KEY(pin_id) REFERENCES ewallet_pins(id);

CREATE TABLE swap_fee_tracking (
    id BIGSERIAL PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    instrument_type VARCHAR(10) NOT NULL, -- WALLET | VOUCHER
    instrument_ref BIGINT NOT NULL,
    fee_paid BOOLEAN DEFAULT FALSE,
    paid_by VARCHAR(20), -- SENDER | RECEIVER | THIRD_PARTY
    fee_amount NUMERIC(12,2),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Delete table if it already exists
DROP TABLE IF EXISTS cash_instruments CASCADE;

-- Recreate table
CREATE TABLE cash_instruments (
    instrument_id BIGSERIAL PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    instrument_type VARCHAR(10) NOT NULL, -- WALLET | VOUCHER
    wallet_id BIGINT,
    pin_id BIGINT,
    reserved_amount NUMERIC(12,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE', -- ACTIVE, USED, EXPIRED
    created_at TIMESTAMP DEFAULT NOW()
);

DROP TABLE IF EXISTS sat_tokens CASCADE;

CREATE TABLE sat_tokens (
    sat_id BIGSERIAL PRIMARY KEY,
    instrument_id BIGINT REFERENCES cash_instruments(instrument_id),
    sat_code VARCHAR(20) UNIQUE NOT NULL,
    auth_code VARCHAR(12) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE', -- ACTIVE, USED, EXPIRED
    network VARCHAR(20),
    created_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE sat_tokens
ADD COLUMN processing BOOLEAN DEFAULT FALSE;

ALTER TABLE atm_authorizations
ADD COLUMN dispense_trace VARCHAR(60);

CREATE UNIQUE INDEX uniq_dispense_once
ON atm_authorizations(dispense_trace);

DROP TABLE IF EXISTS financial_holds CASCADE;

CREATE TABLE financial_holds (
    id BIGSERIAL PRIMARY KEY,
    wallet_id BIGINT NOT NULL,
    amount NUMERIC(18,2) NOT NULL,
    auth_code VARCHAR(20) UNIQUE,
    status TEXT CHECK (status IN ('HELD','COMMITTED','RELEASED')) DEFAULT 'HELD',
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS network_transactions CASCADE;

CREATE TABLE network_transactions (
    id BIGSERIAL PRIMARY KEY,
    trace_id VARCHAR(50),
    type VARCHAR(20),
    wallet_id BIGINT,
    amount NUMERIC(18,2),
    status VARCHAR(20),
    auth_code VARCHAR(20),
    counterparty_bank VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS network_request_log CASCADE;

CREATE TABLE network_request_log (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(100) UNIQUE,
    response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE atms (
    id SERIAL PRIMARY KEY,
    atm_code VARCHAR(50) UNIQUE NOT NULL,
    location TEXT,
    status VARCHAR(20) DEFAULT 'ACTIVE',
    cash_balance NUMERIC(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE atm_transactions (
    id SERIAL PRIMARY KEY,
    atm_id INT REFERENCES atms(id),
    user_id INT,
    transaction_reference VARCHAR(100),
    amount NUMERIC(15,2),
    status VARCHAR(30),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE interbank_claims (
    id BIGSERIAL PRIMARY KEY,
    sat_code VARCHAR(50) UNIQUE NOT NULL,
    issuer_institution VARCHAR(50) NOT NULL,
    amount NUMERIC(18,2) NOT NULL,
    fee NUMERIC(18,2) DEFAULT 0,
    net_amount NUMERIC(18,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'PENDING', -- PENDING, SETTLED
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE interbank_net_positions (
    issuer_institution VARCHAR(50) PRIMARY KEY,
    total_receivable NUMERIC(18,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT NOW()
);

CREATE TABLE settlement_batches (
    id BIGSERIAL PRIMARY KEY,
    issuer_institution VARCHAR(50),
    batch_total NUMERIC(18,2),
    status VARCHAR(20) DEFAULT 'OPEN',
    created_at TIMESTAMP DEFAULT NOW()
);

-- Only drop if you truly want to reset
DROP TABLE IF EXISTS cash_instruments CASCADE;
DROP TABLE IF EXISTS sat_tokens CASCADE;

-- Recreate cash_instruments
CREATE TABLE cash_instruments (
    instrument_id BIGSERIAL PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    instrument_type VARCHAR(10) NOT NULL, -- WALLET | VOUCHER
    wallet_id BIGINT REFERENCES wallets(wallet_id),
    pin_id BIGINT REFERENCES ewallet_pins(id),
    reserved_amount NUMERIC(12,2) DEFAULT 0.00 NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE', -- ACTIVE, USED, EXPIRED
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Recreate sat_tokens
CREATE TABLE sat_tokens (
    sat_id BIGSERIAL PRIMARY KEY,
    instrument_id BIGINT REFERENCES cash_instruments(instrument_id),
    sat_code VARCHAR(40) UNIQUE NOT NULL,
    auth_code VARCHAR(12) NOT NULL,
    issuer_bank VARCHAR(50),
    acquirer_network VARCHAR(50),
    amount NUMERIC(12,2) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE', -- ACTIVE, USED, EXPIRED, CANCELLED
    processing BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);
ALTER TABLE sat_tokens
ADD COLUMN used_at TIMESTAMP,
ADD COLUMN attempts INT DEFAULT 0,
ADD COLUMN max_attempts INT DEFAULT 3,
ADD COLUMN last_attempt_at TIMESTAMP;

-- Only add dispense_trace if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_name='atm_authorizations' 
          AND column_name='dispense_trace'
    ) THEN
        ALTER TABLE atm_authorizations
        ADD COLUMN dispense_trace VARCHAR(60);

        CREATE UNIQUE INDEX uniq_dispense_once
        ON atm_authorizations(dispense_trace);
    END IF;
END $$;

-- Add held_balance to wallets
ALTER TABLE wallets ADD COLUMN IF NOT EXISTS held_balance DECIMAL(20,4) DEFAULT 0.0000;

-- Enhance financial_holds table
DROP TABLE IF EXISTS financial_holds CASCADE;
CREATE TABLE financial_holds (
    id BIGSERIAL PRIMARY KEY,
    wallet_id BIGINT NOT NULL REFERENCES wallets(wallet_id),
    amount DECIMAL(20,4) NOT NULL,
    hold_reference VARCHAR(50) UNIQUE NOT NULL,
    foreign_bank VARCHAR(50) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    foreign_atm_id VARCHAR(50),
    status VARCHAR(30) DEFAULT 'HELD', -- HELD, RELEASED, RELEASED_FAILED, EXPIRED
    cashout_confirmed BOOLEAN DEFAULT FALSE,
    failure_reason TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    released_at TIMESTAMP
);

-- Enhance cash_instruments
ALTER TABLE cash_instruments 
ADD COLUMN IF NOT EXISTS hold_reference VARCHAR(50),
ADD COLUMN IF NOT EXISTS cashed_out_at TIMESTAMP,
ADD COLUMN IF NOT EXISTS foreign_atm_id VARCHAR(50),
ADD COLUMN IF NOT EXISTS failure_reason TEXT;

-- Create ewallet verification log
CREATE TABLE ewallet_verification_log (
    id BIGSERIAL PRIMARY KEY,
    wallet_id BIGINT REFERENCES wallets(wallet_id),
    phone VARCHAR(20) NOT NULL,
    amount DECIMAL(20,4) NOT NULL,
    foreign_bank VARCHAR(50) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    hold_reference VARCHAR(50) NOT NULL,
    status VARCHAR(30) DEFAULT 'VERIFIED',
    verified_at TIMESTAMP DEFAULT NOW(),
    cashout_confirmed_at TIMESTAMP
);

-- Add hold_reference to interbank_claims
ALTER TABLE interbank_claims ADD COLUMN IF NOT EXISTS hold_reference VARCHAR(50);

CREATE TABLE IF NOT EXISTS api_keys (
    id BIGSERIAL PRIMARY KEY,
    client_name VARCHAR(100) NOT NULL,
    api_key VARCHAR(255) NOT NULL UNIQUE,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE transactions 
ADD COLUMN description TEXT DEFAULT NULL;

-- Make transaction_id nullable in ewallet_pins
ALTER TABLE ewallet_pins 
ALTER COLUMN transaction_id DROP NOT NULL;

-- Then you can insert with NULL instead of 0

ALTER TABLE sat_tokens
ADD COLUMN sat_number CHAR(12),
ADD COLUMN pin CHAR(6);

ALTER TABLE sat_tokens
DROP COLUMN sat_code,
DROP COLUMN auth_code;

ALTER TABLE sat_tokens
ALTER COLUMN sat_number SET NOT NULL,
ALTER COLUMN pin SET NOT NULL;

ALTER TABLE sat_tokens
ADD CONSTRAINT unique_sat_number UNIQUE (sat_number);

CREATE TABLE IF NOT EXISTS settlements (
    settlement_id BIGSERIAL PRIMARY KEY,
    settlement_ref VARCHAR(50) NOT NULL UNIQUE,
    type VARCHAR(32) NOT NULL, -- SAT_TOKEN | EWALLET_SWAP
    sat_number CHAR(12),
    wallet_id BIGINT REFERENCES wallets(wallet_id),
    amount NUMERIC(20,4) NOT NULL,
    issuer_bank VARCHAR(50),
    acquirer_bank VARCHAR(50),
    status VARCHAR(20) DEFAULT 'pending', -- pending | completed
    created_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE settlements
ADD COLUMN recipient_type VARCHAR(20),
ADD COLUMN recipient_id BIGINT;

CREATE TABLE IF NOT EXISTS settlement_accounts (
    settlement_account_id SERIAL PRIMARY KEY,
    account_name VARCHAR(50) UNIQUE NOT NULL,  -- e.g., 'MAIN_SETTLEMENT'
    account_type VARCHAR(50) NOT NULL DEFAULT 'operational', -- operational, fee, reserve etc.
    account_number VARCHAR(20) UNIQUE NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'BWP',
    balance NUMERIC(18,4) NOT NULL DEFAULT 0,
    is_frozen BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

INSERT INTO settlement_accounts (account_name, account_type, account_number, balance)
VALUES ('MAIN_SETTLEMENT', 'operational', '10000001', 1000000.00)
ON CONFLICT (account_number) DO NOTHING;

ALTER TABLE financial_holds ALTER COLUMN session_id DROP NOT NULL;


INSERT INTO ledger_accounts (account_name, account_number, account_type)
VALUES
('ATM Cash Float', 'ATM-001', 'asset'),
('Customer Wallet Control', 'WALLET-CONTROL', 'liability');
