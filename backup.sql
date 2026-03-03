--
-- PostgreSQL database dump
--

\restrict OuEmUhSQUIVAvcoJ4H8DCGOxwyEZF11UHQTP6yOMPcRIquML91awjoSwxRY47cb

-- Dumped from database version 16.11 (Ubuntu 16.11-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.11 (Ubuntu 16.11-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: prevent_hard_delete(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.prevent_hard_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  RAISE EXCEPTION 'Hard deletes are forbidden on financial records';
END;
$$;


ALTER FUNCTION public.prevent_hard_delete() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: account_freezes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.account_freezes (
    freeze_id integer NOT NULL,
    account_id integer NOT NULL,
    reason character varying(255),
    status character varying(32) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    released_at timestamp without time zone
);


ALTER TABLE public.account_freezes OWNER TO postgres;

--
-- Name: account_freezes_freeze_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.account_freezes_freeze_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.account_freezes_freeze_id_seq OWNER TO postgres;

--
-- Name: account_freezes_freeze_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.account_freezes_freeze_id_seq OWNED BY public.account_freezes.freeze_id;


--
-- Name: accounting_closures; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.accounting_closures (
    closure_date date NOT NULL,
    closed_by integer,
    closed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    closure_type character varying(20),
    remarks text,
    CONSTRAINT accounting_closures_closure_type_check CHECK (((closure_type)::text = ANY ((ARRAY['EOD'::character varying, 'EOM'::character varying, 'EOY'::character varying])::text[])))
);


ALTER TABLE public.accounting_closures OWNER TO postgres;

--
-- Name: accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.accounts (
    account_id integer NOT NULL,
    user_id integer NOT NULL,
    account_number character varying(64) NOT NULL,
    account_type character varying(32) DEFAULT 'savings'::character varying NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar NOT NULL,
    balance numeric(20,4) DEFAULT 0.0000 NOT NULL,
    is_frozen boolean DEFAULT false NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    held_balance numeric(20,4) DEFAULT 0.0000
);


ALTER TABLE public.accounts OWNER TO postgres;

--
-- Name: accounts_account_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.accounts_account_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.accounts_account_id_seq OWNER TO postgres;

--
-- Name: accounts_account_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.accounts_account_id_seq OWNED BY public.accounts.account_id;


--
-- Name: api_keys; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.api_keys (
    id bigint NOT NULL,
    client_name character varying(100) NOT NULL,
    api_key character varying(255) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.api_keys OWNER TO postgres;

--
-- Name: api_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.api_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.api_keys_id_seq OWNER TO postgres;

--
-- Name: api_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.api_keys_id_seq OWNED BY public.api_keys.id;


--
-- Name: atm_authorizations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.atm_authorizations (
    auth_id bigint NOT NULL,
    sat_code character varying(40),
    trace_number character varying(50),
    acquirer_bank character varying(30),
    amount numeric(12,2),
    response_code character varying(5),
    auth_code character varying(20),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    dispense_trace character varying(60)
);


ALTER TABLE public.atm_authorizations OWNER TO postgres;

--
-- Name: atm_authorizations_auth_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.atm_authorizations_auth_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.atm_authorizations_auth_id_seq OWNER TO postgres;

--
-- Name: atm_authorizations_auth_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.atm_authorizations_auth_id_seq OWNED BY public.atm_authorizations.auth_id;


--
-- Name: atm_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.atm_transactions (
    id integer NOT NULL,
    atm_id integer,
    user_id integer,
    transaction_reference character varying(100),
    amount numeric(15,2),
    status character varying(30),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.atm_transactions OWNER TO postgres;

--
-- Name: atm_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.atm_transactions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.atm_transactions_id_seq OWNER TO postgres;

--
-- Name: atm_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.atm_transactions_id_seq OWNED BY public.atm_transactions.id;


--
-- Name: atms; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.atms (
    id integer NOT NULL,
    atm_code character varying(50) NOT NULL,
    location text,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    cash_balance numeric(15,2) DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.atms OWNER TO postgres;

--
-- Name: atms_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.atms_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.atms_id_seq OWNER TO postgres;

--
-- Name: atms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.atms_id_seq OWNED BY public.atms.id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.audit_logs (
    id integer NOT NULL,
    entity character varying(64) NOT NULL,
    entity_id integer,
    category character varying(64) DEFAULT 'system'::character varying NOT NULL,
    action character varying(128) NOT NULL,
    performed_by integer,
    severity character varying(32) DEFAULT 'info'::character varying,
    ip_address character varying(45) DEFAULT NULL::character varying,
    user_agent text,
    old_value text,
    new_value text,
    performed_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.audit_logs OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.audit_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_logs_id_seq OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: bank_info; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.bank_info (
    id integer NOT NULL,
    bank_name character varying(128) DEFAULT NULL::character varying,
    bank_code character varying(64) DEFAULT NULL::character varying,
    branch_code character varying(64) DEFAULT NULL::character varying,
    central_account_number character varying(64) DEFAULT NULL::character varying,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.bank_info OWNER TO postgres;

--
-- Name: bank_info_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.bank_info_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bank_info_id_seq OWNER TO postgres;

--
-- Name: bank_info_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.bank_info_id_seq OWNED BY public.bank_info.id;


--
-- Name: cash_instruments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cash_instruments (
    instrument_id bigint NOT NULL,
    phone character varying(20) NOT NULL,
    instrument_type character varying(10) NOT NULL,
    wallet_id bigint,
    pin_id bigint,
    reserved_amount numeric(12,2) DEFAULT 0.00 NOT NULL,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    hold_reference character varying(50),
    cashed_out_at timestamp without time zone,
    foreign_atm_id character varying(50),
    failure_reason text
);


ALTER TABLE public.cash_instruments OWNER TO postgres;

--
-- Name: cash_instruments_instrument_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.cash_instruments_instrument_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.cash_instruments_instrument_id_seq OWNER TO postgres;

--
-- Name: cash_instruments_instrument_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.cash_instruments_instrument_id_seq OWNED BY public.cash_instruments.instrument_id;


--
-- Name: central_bank_link; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.central_bank_link (
    id integer NOT NULL,
    central_bank_code character varying(64) DEFAULT NULL::character varying,
    central_account_number character varying(64) DEFAULT NULL::character varying,
    linked_at timestamp without time zone DEFAULT now(),
    metadata jsonb
);


ALTER TABLE public.central_bank_link OWNER TO postgres;

--
-- Name: central_bank_link_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.central_bank_link_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.central_bank_link_id_seq OWNER TO postgres;

--
-- Name: central_bank_link_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.central_bank_link_id_seq OWNED BY public.central_bank_link.id;


--
-- Name: chart_of_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.chart_of_accounts (
    coa_code character varying(20) NOT NULL,
    coa_name character varying(255) NOT NULL,
    coa_type character varying(20),
    parent_coa_code character varying(20),
    is_customer_account boolean DEFAULT false,
    is_trust_account boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chart_of_accounts_coa_type_check CHECK (((coa_type)::text = ANY ((ARRAY['asset'::character varying, 'liability'::character varying, 'equity'::character varying, 'income'::character varying, 'expense'::character varying])::text[])))
);


ALTER TABLE public.chart_of_accounts OWNER TO postgres;

--
-- Name: clearing_positions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.clearing_positions (
    id bigint NOT NULL,
    debtor_bank character varying(30),
    creditor_bank character varying(30),
    amount numeric(12,2),
    reference character varying(50),
    status character varying(20) DEFAULT 'PENDING'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.clearing_positions OWNER TO postgres;

--
-- Name: clearing_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.clearing_positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.clearing_positions_id_seq OWNER TO postgres;

--
-- Name: clearing_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.clearing_positions_id_seq OWNED BY public.clearing_positions.id;


--
-- Name: ledger_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ledger_accounts (
    id integer NOT NULL,
    account_name character varying(128) NOT NULL,
    account_number character varying(64) NOT NULL,
    account_type character varying(32) DEFAULT 'user'::character varying,
    currency character varying(8) DEFAULT 'BWP'::character varying,
    balance numeric(20,4) DEFAULT 0.0000,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    owner_type character varying(32),
    owner_id integer
);


ALTER TABLE public.ledger_accounts OWNER TO postgres;

--
-- Name: daily_trial_balance; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.daily_trial_balance AS
 SELECT currency,
    sum(balance) AS total_balance,
    count(*) AS account_count
   FROM public.ledger_accounts
  GROUP BY currency;


ALTER VIEW public.daily_trial_balance OWNER TO postgres;

--
-- Name: data_retention_policies; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.data_retention_policies (
    entity_name character varying(100) NOT NULL,
    retention_years integer NOT NULL,
    legal_basis text,
    last_reviewed date
);


ALTER TABLE public.data_retention_policies OWNER TO postgres;

--
-- Name: disaster_recovery_tests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.disaster_recovery_tests (
    test_id bigint NOT NULL,
    test_date date NOT NULL,
    test_type character varying(50),
    systems_tested text[],
    result character varying(20),
    issues_found text,
    resolved boolean DEFAULT false,
    signed_off_by integer,
    CONSTRAINT disaster_recovery_tests_result_check CHECK (((result)::text = ANY ((ARRAY['pass'::character varying, 'fail'::character varying, 'partial'::character varying])::text[])))
);


ALTER TABLE public.disaster_recovery_tests OWNER TO postgres;

--
-- Name: disaster_recovery_tests_test_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.disaster_recovery_tests_test_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.disaster_recovery_tests_test_id_seq OWNER TO postgres;

--
-- Name: disaster_recovery_tests_test_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.disaster_recovery_tests_test_id_seq OWNED BY public.disaster_recovery_tests.test_id;


--
-- Name: ewallet_pins; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ewallet_pins (
    id bigint NOT NULL,
    transaction_id bigint,
    pin character varying(6) NOT NULL,
    is_redeemed boolean DEFAULT false,
    regenerated_by character varying(10),
    regeneration_fee numeric(10,2) DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    sat_purchased boolean DEFAULT false,
    sender_phone character varying(20) NOT NULL,
    recipient_phone character varying(20) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    amount numeric(15,2) NOT NULL,
    redeemed_at timestamp without time zone,
    redeemed_by character varying(255),
    generated_by bigint NOT NULL,
    sat_expires_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    sat_paid_by timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.ewallet_pins OWNER TO postgres;

--
-- Name: ewallet_pins_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ewallet_pins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ewallet_pins_id_seq OWNER TO postgres;

--
-- Name: ewallet_pins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ewallet_pins_id_seq OWNED BY public.ewallet_pins.id;


--
-- Name: ewallet_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ewallet_settings (
    id integer NOT NULL,
    expiry_minutes integer DEFAULT 1440,
    regeneration_fee numeric(12,4) DEFAULT 2.5000,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.ewallet_settings OWNER TO postgres;

--
-- Name: ewallet_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ewallet_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ewallet_settings_id_seq OWNER TO postgres;

--
-- Name: ewallet_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ewallet_settings_id_seq OWNED BY public.ewallet_settings.id;


--
-- Name: ewallet_verification_log; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ewallet_verification_log (
    id bigint NOT NULL,
    wallet_id bigint,
    phone character varying(20) NOT NULL,
    amount numeric(20,4) NOT NULL,
    foreign_bank character varying(50) NOT NULL,
    session_id character varying(100) NOT NULL,
    hold_reference character varying(50) NOT NULL,
    status character varying(30) DEFAULT 'VERIFIED'::character varying,
    verified_at timestamp without time zone DEFAULT now(),
    cashout_confirmed_at timestamp without time zone
);


ALTER TABLE public.ewallet_verification_log OWNER TO postgres;

--
-- Name: ewallet_verification_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ewallet_verification_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ewallet_verification_log_id_seq OWNER TO postgres;

--
-- Name: ewallet_verification_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ewallet_verification_log_id_seq OWNED BY public.ewallet_verification_log.id;


--
-- Name: external_banks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.external_banks (
    bank_id integer NOT NULL,
    bank_name character varying(128) NOT NULL,
    swift_code character varying(64) NOT NULL,
    country character varying(64) NOT NULL,
    contact_info jsonb,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.external_banks OWNER TO postgres;

--
-- Name: external_banks_bank_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.external_banks_bank_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.external_banks_bank_id_seq OWNER TO postgres;

--
-- Name: external_banks_bank_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.external_banks_bank_id_seq OWNED BY public.external_banks.bank_id;


--
-- Name: fees; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fees (
    fee_id integer NOT NULL,
    fee_type character varying(64) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying,
    amount numeric(12,4) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.fees OWNER TO postgres;

--
-- Name: fees_fee_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fees_fee_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fees_fee_id_seq OWNER TO postgres;

--
-- Name: fees_fee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fees_fee_id_seq OWNED BY public.fees.fee_id;


--
-- Name: financial_holds; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.financial_holds (
    id bigint NOT NULL,
    wallet_id bigint NOT NULL,
    amount numeric(20,4) NOT NULL,
    hold_reference character varying(50) NOT NULL,
    foreign_bank character varying(50) NOT NULL,
    session_id character varying(100),
    foreign_atm_id character varying(50),
    status character varying(30) DEFAULT 'HELD'::character varying,
    cashout_confirmed boolean DEFAULT false,
    failure_reason text,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    released_at timestamp without time zone
);


ALTER TABLE public.financial_holds OWNER TO postgres;

--
-- Name: financial_holds_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.financial_holds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.financial_holds_id_seq OWNER TO postgres;

--
-- Name: financial_holds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.financial_holds_id_seq OWNED BY public.financial_holds.id;


--
-- Name: interbank_claims; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.interbank_claims (
    id bigint NOT NULL,
    sat_code character varying(50) NOT NULL,
    issuer_institution character varying(50) NOT NULL,
    amount numeric(18,2) NOT NULL,
    fee numeric(18,2) DEFAULT 0,
    net_amount numeric(18,2) NOT NULL,
    status character varying(20) DEFAULT 'PENDING'::character varying,
    created_at timestamp without time zone DEFAULT now(),
    hold_reference character varying(50)
);


ALTER TABLE public.interbank_claims OWNER TO postgres;

--
-- Name: interbank_claims_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.interbank_claims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.interbank_claims_id_seq OWNER TO postgres;

--
-- Name: interbank_claims_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.interbank_claims_id_seq OWNED BY public.interbank_claims.id;


--
-- Name: interbank_net_positions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.interbank_net_positions (
    issuer_institution character varying(50) NOT NULL,
    total_receivable numeric(18,2) DEFAULT 0,
    last_updated timestamp without time zone DEFAULT now()
);


ALTER TABLE public.interbank_net_positions OWNER TO postgres;

--
-- Name: kyc_documents; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.kyc_documents (
    id integer NOT NULL,
    user_id integer NOT NULL,
    doc_type character varying(32) NOT NULL,
    doc_number character varying(128) DEFAULT NULL::character varying,
    doc_file character varying(255) DEFAULT NULL::character varying,
    status character varying(32) DEFAULT 'pending'::character varying,
    submitted_at timestamp without time zone DEFAULT now() NOT NULL,
    reviewed_at timestamp without time zone
);


ALTER TABLE public.kyc_documents OWNER TO postgres;

--
-- Name: kyc_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.kyc_documents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.kyc_documents_id_seq OWNER TO postgres;

--
-- Name: kyc_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.kyc_documents_id_seq OWNED BY public.kyc_documents.id;


--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ledger_accounts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ledger_accounts_id_seq OWNER TO postgres;

--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ledger_accounts_id_seq OWNED BY public.ledger_accounts.id;


--
-- Name: ledger_entries; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ledger_entries (
    id bigint NOT NULL,
    reference character varying(128) NOT NULL,
    debit_account character varying(64) NOT NULL,
    credit_account character varying(64) NOT NULL,
    amount numeric(20,4) NOT NULL,
    currency character varying(8) DEFAULT 'BWP'::character varying,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    reference_type character varying(32),
    reference_id bigint,
    narration text
);


ALTER TABLE public.ledger_entries OWNER TO postgres;

--
-- Name: ledger_entries_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ledger_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ledger_entries_id_seq OWNER TO postgres;

--
-- Name: ledger_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ledger_entries_id_seq OWNED BY public.ledger_entries.id;


--
-- Name: loan_repayments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.loan_repayments (
    repayment_id bigint NOT NULL,
    loan_id bigint NOT NULL,
    amount numeric(20,4) NOT NULL,
    due_date date NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    paid_at timestamp without time zone
);


ALTER TABLE public.loan_repayments OWNER TO postgres;

--
-- Name: loan_repayments_repayment_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.loan_repayments_repayment_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.loan_repayments_repayment_id_seq OWNER TO postgres;

--
-- Name: loan_repayments_repayment_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.loan_repayments_repayment_id_seq OWNED BY public.loan_repayments.repayment_id;


--
-- Name: loans; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.loans (
    loan_id bigint NOT NULL,
    user_id integer NOT NULL,
    principal numeric(20,4) NOT NULL,
    interest_rate numeric(6,4) NOT NULL,
    term_months integer NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.loans OWNER TO postgres;

--
-- Name: loans_loan_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.loans_loan_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.loans_loan_id_seq OWNER TO postgres;

--
-- Name: loans_loan_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.loans_loan_id_seq OWNED BY public.loans.loan_id;


--
-- Name: network_fee_ledger; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.network_fee_ledger (
    id bigint NOT NULL,
    sat_code character varying(40),
    payer character varying(20),
    amount numeric(12,2),
    collected_from character varying(20),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.network_fee_ledger OWNER TO postgres;

--
-- Name: network_fee_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.network_fee_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.network_fee_ledger_id_seq OWNER TO postgres;

--
-- Name: network_fee_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.network_fee_ledger_id_seq OWNED BY public.network_fee_ledger.id;


--
-- Name: network_request_log; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.network_request_log (
    id bigint NOT NULL,
    request_id character varying(100),
    response text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.network_request_log OWNER TO postgres;

--
-- Name: network_request_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.network_request_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.network_request_log_id_seq OWNER TO postgres;

--
-- Name: network_request_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.network_request_log_id_seq OWNED BY public.network_request_log.id;


--
-- Name: network_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.network_transactions (
    id bigint NOT NULL,
    trace_id character varying(50),
    type character varying(20),
    wallet_id bigint,
    amount numeric(18,2),
    status character varying(20),
    auth_code character varying(20),
    counterparty_bank character varying(50),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.network_transactions OWNER TO postgres;

--
-- Name: network_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.network_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.network_transactions_id_seq OWNER TO postgres;

--
-- Name: network_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.network_transactions_id_seq OWNED BY public.network_transactions.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.notifications (
    id bigint NOT NULL,
    user_id integer NOT NULL,
    type character varying(32) NOT NULL,
    message text NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.notifications OWNER TO postgres;

--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notifications_id_seq OWNER TO postgres;

--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: saccus_middleman; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.saccus_middleman (
    id integer NOT NULL,
    account_number character varying(64) NOT NULL,
    api_key character varying(128) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.saccus_middleman OWNER TO postgres;

--
-- Name: saccus_middleman_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.saccus_middleman_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.saccus_middleman_id_seq OWNER TO postgres;

--
-- Name: saccus_middleman_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.saccus_middleman_id_seq OWNED BY public.saccus_middleman.id;


--
-- Name: safeguarding_register; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.safeguarding_register (
    id bigint NOT NULL,
    account_number character varying(30),
    institution_name character varying(100),
    safeguarded_amount numeric(20,2),
    recorded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.safeguarding_register OWNER TO postgres;

--
-- Name: safeguarding_register_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.safeguarding_register_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.safeguarding_register_id_seq OWNER TO postgres;

--
-- Name: safeguarding_register_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.safeguarding_register_id_seq OWNED BY public.safeguarding_register.id;


--
-- Name: sat_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sat_tokens (
    sat_id bigint NOT NULL,
    instrument_id bigint,
    issuer_bank character varying(50),
    acquirer_network character varying(50),
    amount numeric(12,2) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    processing boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT now(),
    used_at timestamp without time zone,
    attempts integer DEFAULT 0,
    max_attempts integer DEFAULT 3,
    last_attempt_at timestamp without time zone,
    sat_number character(12) NOT NULL,
    pin character(6) NOT NULL
);


ALTER TABLE public.sat_tokens OWNER TO postgres;

--
-- Name: sat_tokens_sat_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.sat_tokens_sat_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sat_tokens_sat_id_seq OWNER TO postgres;

--
-- Name: sat_tokens_sat_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.sat_tokens_sat_id_seq OWNED BY public.sat_tokens.sat_id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    id bigint NOT NULL,
    user_id integer,
    token character varying(255) DEFAULT NULL::character varying,
    ip_address character varying(45) DEFAULT NULL::character varying,
    user_agent character varying(255) DEFAULT NULL::character varying,
    data text,
    last_activity timestamp without time zone DEFAULT now() NOT NULL,
    expires_at timestamp without time zone
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- Name: sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sessions_id_seq OWNER TO postgres;

--
-- Name: sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.sessions_id_seq OWNED BY public.sessions.id;


--
-- Name: settlement_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.settlement_accounts (
    settlement_account_id integer NOT NULL,
    account_name character varying(50) NOT NULL,
    account_type character varying(50) DEFAULT 'operational'::character varying NOT NULL,
    account_number character varying(20) NOT NULL,
    currency character varying(10) DEFAULT 'BWP'::character varying NOT NULL,
    balance numeric(18,4) DEFAULT 0 NOT NULL,
    is_frozen boolean DEFAULT false NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.settlement_accounts OWNER TO postgres;

--
-- Name: settlement_accounts_settlement_account_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.settlement_accounts_settlement_account_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settlement_accounts_settlement_account_id_seq OWNER TO postgres;

--
-- Name: settlement_accounts_settlement_account_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.settlement_accounts_settlement_account_id_seq OWNED BY public.settlement_accounts.settlement_account_id;


--
-- Name: settlement_batches; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.settlement_batches (
    id bigint NOT NULL,
    issuer_institution character varying(50),
    batch_total numeric(18,2),
    status character varying(20) DEFAULT 'OPEN'::character varying,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.settlement_batches OWNER TO postgres;

--
-- Name: settlement_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.settlement_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settlement_batches_id_seq OWNER TO postgres;

--
-- Name: settlement_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.settlement_batches_id_seq OWNED BY public.settlement_batches.id;


--
-- Name: settlements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.settlements (
    settlement_id bigint NOT NULL,
    settlement_ref character varying(50) NOT NULL,
    type character varying(32) NOT NULL,
    sat_number character(12),
    wallet_id bigint,
    amount numeric(20,4) NOT NULL,
    issuer_bank character varying(50),
    acquirer_bank character varying(50),
    status character varying(20) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT now(),
    recipient_type character varying(20),
    recipient_id bigint
);


ALTER TABLE public.settlements OWNER TO postgres;

--
-- Name: settlements_settlement_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.settlements_settlement_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settlements_settlement_id_seq OWNER TO postgres;

--
-- Name: settlements_settlement_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.settlements_settlement_id_seq OWNED BY public.settlements.settlement_id;


--
-- Name: swap_fee_tracking; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_fee_tracking (
    id bigint NOT NULL,
    phone character varying(20) NOT NULL,
    instrument_type character varying(10) NOT NULL,
    instrument_ref bigint NOT NULL,
    fee_paid boolean DEFAULT false,
    paid_by character varying(20),
    fee_amount numeric(12,2),
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.swap_fee_tracking OWNER TO postgres;

--
-- Name: swap_fee_tracking_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_fee_tracking_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_fee_tracking_id_seq OWNER TO postgres;

--
-- Name: swap_fee_tracking_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_fee_tracking_id_seq OWNED BY public.swap_fee_tracking.id;


--
-- Name: swap_ledgers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_ledgers (
    ledger_id bigint NOT NULL,
    swap_reference character varying(128) NOT NULL,
    from_participant character varying(128) NOT NULL,
    to_participant character varying(128) NOT NULL,
    from_type character varying(64) NOT NULL,
    to_type character varying(64) NOT NULL,
    from_account character varying(128) DEFAULT NULL::character varying,
    to_account character varying(128) DEFAULT NULL::character varying,
    original_amount numeric(20,4) DEFAULT 0.0000 NOT NULL,
    final_amount numeric(20,4) DEFAULT 0.0000 NOT NULL,
    swap_fee numeric(12,4) DEFAULT 0.0000 NOT NULL,
    creation_fee numeric(12,4) DEFAULT 0.0000 NOT NULL,
    admin_fee numeric(12,4) DEFAULT 0.0000 NOT NULL,
    sms_fee numeric(12,4) DEFAULT 0.0000 NOT NULL,
    currency_code character(3) DEFAULT 'BWP'::bpchar NOT NULL,
    token character varying(255) DEFAULT NULL::character varying,
    reverse_logic boolean DEFAULT false NOT NULL,
    notes text,
    status character varying(32) DEFAULT 'completed'::character varying,
    performed_by integer DEFAULT 1 NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.swap_ledgers OWNER TO postgres;

--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_ledgers_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNER TO postgres;

--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNED BY public.swap_ledgers.ledger_id;


--
-- Name: swap_middleman; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_middleman (
    id integer NOT NULL,
    account_number character varying(64) NOT NULL,
    api_key character varying(128) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.swap_middleman OWNER TO postgres;

--
-- Name: swap_middleman_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_middleman_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_middleman_id_seq OWNER TO postgres;

--
-- Name: swap_middleman_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_middleman_id_seq OWNED BY public.swap_middleman.id;


--
-- Name: swap_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_transactions (
    id bigint NOT NULL,
    ledger_id bigint NOT NULL,
    source character varying(128) NOT NULL,
    reference character varying(128) DEFAULT NULL::character varying,
    amount numeric(20,4) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    type character varying(32) NOT NULL,
    description text,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.swap_transactions OWNER TO postgres;

--
-- Name: swap_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_transactions_id_seq OWNER TO postgres;

--
-- Name: swap_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_transactions_id_seq OWNED BY public.swap_transactions.id;


--
-- Name: transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.transactions (
    transaction_id bigint NOT NULL,
    user_id integer NOT NULL,
    reference character varying(128) DEFAULT NULL::character varying,
    from_account character varying(128) DEFAULT NULL::character varying,
    to_account character varying(128) DEFAULT NULL::character varying,
    external_bank_id integer,
    amount numeric(20,4) NOT NULL,
    fee_amount numeric(12,4) DEFAULT 0.0000,
    type character varying(32) NOT NULL,
    direction character varying(8) DEFAULT NULL::character varying,
    channel character varying(64) DEFAULT NULL::character varying,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    transactions_id bigint NOT NULL,
    is_deleted boolean DEFAULT false,
    purpose character varying(255),
    beneficiary_name character varying(255),
    rapid_movement_flag boolean DEFAULT false,
    structuring_flag boolean DEFAULT false,
    unusual_behavior_flag boolean DEFAULT false,
    description text
);


ALTER TABLE public.transactions OWNER TO postgres;

--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.transactions_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transactions_transaction_id_seq OWNER TO postgres;

--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.transactions_transaction_id_seq OWNED BY public.transactions.transaction_id;


--
-- Name: transactions_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.transactions_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transactions_transactions_id_seq OWNER TO postgres;

--
-- Name: transactions_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.transactions_transactions_id_seq OWNED BY public.transactions.transactions_id;


--
-- Name: trust_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.trust_accounts (
    account_number character varying(30) NOT NULL,
    account_name character varying(255),
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(20),
    opened_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.trust_accounts OWNER TO postgres;

--
-- Name: trust_balances; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.trust_balances (
    account_number character varying(30) NOT NULL,
    available_balance numeric(20,2),
    last_updated timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.trust_balances OWNER TO postgres;

--
-- Name: trust_postings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.trust_postings (
    id bigint NOT NULL,
    account_number character varying(30),
    reference character varying(100),
    amount numeric(20,2),
    direction character varying(10),
    channel character varying(50),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.trust_postings OWNER TO postgres;

--
-- Name: trust_postings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.trust_postings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.trust_postings_id_seq OWNER TO postgres;

--
-- Name: trust_postings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.trust_postings_id_seq OWNED BY public.trust_postings.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    user_id integer NOT NULL,
    full_name character varying(128) NOT NULL,
    email character varying(128) NOT NULL,
    phone character varying(32) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role character varying(32) DEFAULT 'customer'::character varying NOT NULL,
    kyc_status character varying(32) DEFAULT 'pending'::character varying,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    pep boolean DEFAULT false,
    sanctions_checked boolean DEFAULT false,
    last_sanctions_check timestamp without time zone
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_user_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_user_id_seq OWNER TO postgres;

--
-- Name: users_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_user_id_seq OWNED BY public.users.user_id;


--
-- Name: wallet_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.wallet_transactions (
    id bigint NOT NULL,
    user_id integer NOT NULL,
    wallet_id bigint NOT NULL,
    linked_pin_id bigint,
    recipient_identifier character varying(128) DEFAULT NULL::character varying,
    transaction_type character varying(64) NOT NULL,
    amount numeric(20,4) NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    is_deleted boolean DEFAULT false
);


ALTER TABLE public.wallet_transactions OWNER TO postgres;

--
-- Name: wallet_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.wallet_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.wallet_transactions_id_seq OWNER TO postgres;

--
-- Name: wallet_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.wallet_transactions_id_seq OWNED BY public.wallet_transactions.id;


--
-- Name: wallets; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.wallets (
    wallet_id bigint NOT NULL,
    user_id integer,
    phone character varying(32) NOT NULL,
    wallet_type character varying(32) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar NOT NULL,
    balance numeric(20,4) DEFAULT 0.0000 NOT NULL,
    is_frozen boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    held_balance numeric(20,4) DEFAULT 0.0000
);


ALTER TABLE public.wallets OWNER TO postgres;

--
-- Name: wallets_wallet_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.wallets_wallet_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.wallets_wallet_id_seq OWNER TO postgres;

--
-- Name: wallets_wallet_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.wallets_wallet_id_seq OWNED BY public.wallets.wallet_id;


--
-- Name: webhook_notifications; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.webhook_notifications (
    id bigint NOT NULL,
    reference character varying(100),
    payload jsonb,
    delivered boolean DEFAULT false,
    delivered_at timestamp without time zone
);


ALTER TABLE public.webhook_notifications OWNER TO postgres;

--
-- Name: webhook_notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.webhook_notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.webhook_notifications_id_seq OWNER TO postgres;

--
-- Name: webhook_notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.webhook_notifications_id_seq OWNED BY public.webhook_notifications.id;


--
-- Name: account_freezes freeze_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_freezes ALTER COLUMN freeze_id SET DEFAULT nextval('public.account_freezes_freeze_id_seq'::regclass);


--
-- Name: accounts account_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts ALTER COLUMN account_id SET DEFAULT nextval('public.accounts_account_id_seq'::regclass);


--
-- Name: api_keys id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_keys ALTER COLUMN id SET DEFAULT nextval('public.api_keys_id_seq'::regclass);


--
-- Name: atm_authorizations auth_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_authorizations ALTER COLUMN auth_id SET DEFAULT nextval('public.atm_authorizations_auth_id_seq'::regclass);


--
-- Name: atm_transactions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_transactions ALTER COLUMN id SET DEFAULT nextval('public.atm_transactions_id_seq'::regclass);


--
-- Name: atms id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atms ALTER COLUMN id SET DEFAULT nextval('public.atms_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: bank_info id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bank_info ALTER COLUMN id SET DEFAULT nextval('public.bank_info_id_seq'::regclass);


--
-- Name: cash_instruments instrument_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cash_instruments ALTER COLUMN instrument_id SET DEFAULT nextval('public.cash_instruments_instrument_id_seq'::regclass);


--
-- Name: central_bank_link id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.central_bank_link ALTER COLUMN id SET DEFAULT nextval('public.central_bank_link_id_seq'::regclass);


--
-- Name: clearing_positions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.clearing_positions ALTER COLUMN id SET DEFAULT nextval('public.clearing_positions_id_seq'::regclass);


--
-- Name: disaster_recovery_tests test_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.disaster_recovery_tests ALTER COLUMN test_id SET DEFAULT nextval('public.disaster_recovery_tests_test_id_seq'::regclass);


--
-- Name: ewallet_pins id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_pins ALTER COLUMN id SET DEFAULT nextval('public.ewallet_pins_id_seq'::regclass);


--
-- Name: ewallet_settings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_settings ALTER COLUMN id SET DEFAULT nextval('public.ewallet_settings_id_seq'::regclass);


--
-- Name: ewallet_verification_log id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_verification_log ALTER COLUMN id SET DEFAULT nextval('public.ewallet_verification_log_id_seq'::regclass);


--
-- Name: external_banks bank_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_banks ALTER COLUMN bank_id SET DEFAULT nextval('public.external_banks_bank_id_seq'::regclass);


--
-- Name: fees fee_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fees ALTER COLUMN fee_id SET DEFAULT nextval('public.fees_fee_id_seq'::regclass);


--
-- Name: financial_holds id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.financial_holds ALTER COLUMN id SET DEFAULT nextval('public.financial_holds_id_seq'::regclass);


--
-- Name: interbank_claims id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.interbank_claims ALTER COLUMN id SET DEFAULT nextval('public.interbank_claims_id_seq'::regclass);


--
-- Name: kyc_documents id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_documents ALTER COLUMN id SET DEFAULT nextval('public.kyc_documents_id_seq'::regclass);


--
-- Name: ledger_accounts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_accounts ALTER COLUMN id SET DEFAULT nextval('public.ledger_accounts_id_seq'::regclass);


--
-- Name: ledger_entries id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_entries ALTER COLUMN id SET DEFAULT nextval('public.ledger_entries_id_seq'::regclass);


--
-- Name: loan_repayments repayment_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.loan_repayments ALTER COLUMN repayment_id SET DEFAULT nextval('public.loan_repayments_repayment_id_seq'::regclass);


--
-- Name: loans loan_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.loans ALTER COLUMN loan_id SET DEFAULT nextval('public.loans_loan_id_seq'::regclass);


--
-- Name: network_fee_ledger id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_fee_ledger ALTER COLUMN id SET DEFAULT nextval('public.network_fee_ledger_id_seq'::regclass);


--
-- Name: network_request_log id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_request_log ALTER COLUMN id SET DEFAULT nextval('public.network_request_log_id_seq'::regclass);


--
-- Name: network_transactions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_transactions ALTER COLUMN id SET DEFAULT nextval('public.network_transactions_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: saccus_middleman id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.saccus_middleman ALTER COLUMN id SET DEFAULT nextval('public.saccus_middleman_id_seq'::regclass);


--
-- Name: safeguarding_register id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.safeguarding_register ALTER COLUMN id SET DEFAULT nextval('public.safeguarding_register_id_seq'::regclass);


--
-- Name: sat_tokens sat_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sat_tokens ALTER COLUMN sat_id SET DEFAULT nextval('public.sat_tokens_sat_id_seq'::regclass);


--
-- Name: sessions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions ALTER COLUMN id SET DEFAULT nextval('public.sessions_id_seq'::regclass);


--
-- Name: settlement_accounts settlement_account_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_accounts ALTER COLUMN settlement_account_id SET DEFAULT nextval('public.settlement_accounts_settlement_account_id_seq'::regclass);


--
-- Name: settlement_batches id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_batches ALTER COLUMN id SET DEFAULT nextval('public.settlement_batches_id_seq'::regclass);


--
-- Name: settlements settlement_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlements ALTER COLUMN settlement_id SET DEFAULT nextval('public.settlements_settlement_id_seq'::regclass);


--
-- Name: swap_fee_tracking id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_fee_tracking ALTER COLUMN id SET DEFAULT nextval('public.swap_fee_tracking_id_seq'::regclass);


--
-- Name: swap_ledgers ledger_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers ALTER COLUMN ledger_id SET DEFAULT nextval('public.swap_ledgers_ledger_id_seq'::regclass);


--
-- Name: swap_middleman id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_middleman ALTER COLUMN id SET DEFAULT nextval('public.swap_middleman_id_seq'::regclass);


--
-- Name: swap_transactions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_transactions ALTER COLUMN id SET DEFAULT nextval('public.swap_transactions_id_seq'::regclass);


--
-- Name: transactions transaction_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transactions ALTER COLUMN transaction_id SET DEFAULT nextval('public.transactions_transaction_id_seq'::regclass);


--
-- Name: transactions transactions_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transactions ALTER COLUMN transactions_id SET DEFAULT nextval('public.transactions_transactions_id_seq'::regclass);


--
-- Name: trust_postings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trust_postings ALTER COLUMN id SET DEFAULT nextval('public.trust_postings_id_seq'::regclass);


--
-- Name: users user_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN user_id SET DEFAULT nextval('public.users_user_id_seq'::regclass);


--
-- Name: wallet_transactions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_transactions ALTER COLUMN id SET DEFAULT nextval('public.wallet_transactions_id_seq'::regclass);


--
-- Name: wallets wallet_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallets ALTER COLUMN wallet_id SET DEFAULT nextval('public.wallets_wallet_id_seq'::regclass);


--
-- Name: webhook_notifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.webhook_notifications ALTER COLUMN id SET DEFAULT nextval('public.webhook_notifications_id_seq'::regclass);


--
-- Data for Name: account_freezes; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.account_freezes (freeze_id, account_id, reason, status, created_at, released_at) FROM stdin;
\.


--
-- Data for Name: accounting_closures; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.accounting_closures (closure_date, closed_by, closed_at, closure_type, remarks) FROM stdin;
\.


--
-- Data for Name: accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.accounts (account_id, user_id, account_number, account_type, currency, balance, is_frozen, created_at, updated_at, held_balance) FROM stdin;
8	3	MIDDLEMAN_ESCROW_001	middleman_escrow	BWP	977166.2000	f	2025-11-10 21:35:25	2025-12-30 16:55:17.92077	0.0000
5	5	ZURU_SETTLE_001	partner_bank_settlement	BWP	999985.6000	f	2025-11-10 21:35:25	2025-12-30 16:55:17.92077	0.0000
3	2	SAV00000002	savings	BWP	596.0000	f	2025-12-29 14:18:29.500224	2025-12-29 14:18:29.500224	0.0000
4	2	CUR00000002	current	BWP	996.0000	f	2025-12-29 14:18:29.500224	2025-12-29 14:18:29.500224	0.0000
1	41	10000001	operational	BWP	1000500.0000	f	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	0.0000
2	42	10000002	fee	BWP	50004.8000	f	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	0.0000
7	4	SMS_PROVIDER_001	sms_provider_settlement	BWP	10000.5000	f	2025-11-10 21:35:25	2025-12-30 16:55:17.92077	0.0000
6	3	MIDDLEMAN_REV_001	middleman_revenue	BWP	50021.6000	f	2025-11-10 21:35:25	2025-12-30 16:55:17.92077	0.0000
\.


--
-- Data for Name: api_keys; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.api_keys (id, client_name, api_key, active, created_at) FROM stdin;
1	VouchMorph Sandbox	ad1927b1aaa09f04241646aa76b2c5d3886552e4f98d34a460602da26c588109	t	2026-02-25 11:45:23.0708
\.


--
-- Data for Name: atm_authorizations; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.atm_authorizations (auth_id, sat_code, trace_number, acquirer_bank, amount, response_code, auth_code, created_at, dispense_trace) FROM stdin;
1	574903440943	TRACE12345	ZuruBank	500.00	00	AUTH3491	2026-02-26 14:02:38.334899	TRACE12345
\.


--
-- Data for Name: atm_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.atm_transactions (id, atm_id, user_id, transaction_reference, amount, status, created_at) FROM stdin;
\.


--
-- Data for Name: atms; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.atms (id, atm_code, location, status, cash_balance, created_at) FROM stdin;
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.audit_logs (id, entity, entity_id, category, action, performed_by, severity, ip_address, user_agent, old_value, new_value, performed_at) FROM stdin;
\.


--
-- Data for Name: bank_info; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.bank_info (id, bank_name, bank_code, branch_code, central_account_number, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: cash_instruments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.cash_instruments (instrument_id, phone, instrument_type, wallet_id, pin_id, reserved_amount, status, created_at, updated_at, hold_reference, cashed_out_at, foreign_atm_id, failure_reason) FROM stdin;
1	+26770000000	VOUCHER	\N	\N	500.00	ACTIVE	2026-02-26 13:34:14.061409	2026-02-26 13:34:14.061409	\N	\N	\N	\N
4	+26770000000	VOUCHER	\N	\N	500.00	USED	2026-02-26 13:41:56.714612	2026-02-26 13:41:56.714612	\N	2026-02-26 14:02:38.334899	ATM001	\N
6	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 07:51:59.568909	2026-03-02 07:51:59.568909	\N	\N	\N	\N
7	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 07:55:45.683583	2026-03-02 07:55:45.683583	\N	\N	\N	\N
8	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 07:58:45.587972	2026-03-02 07:58:45.587972	\N	\N	\N	\N
9	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 08:05:47.96602	2026-03-02 08:05:47.96602	\N	\N	\N	\N
10	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 08:06:54.561169	2026-03-02 08:06:54.561169	\N	\N	\N	\N
11	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 08:16:32.680716	2026-03-02 08:16:32.680716	\N	\N	\N	\N
12	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 08:55:29.312212	2026-03-02 08:55:29.312212	\N	\N	\N	\N
13	26770000000	VOUCHER	1	\N	1500.00	ACTIVE	2026-03-02 09:02:54.573467	2026-03-02 09:02:54.573467	\N	\N	\N	\N
14	26770000000	VOUCHER	1	\N	1490.00	ACTIVE	2026-03-02 11:26:28.063345	2026-03-02 11:26:28.063345	\N	\N	\N	\N
\.


--
-- Data for Name: central_bank_link; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.central_bank_link (id, central_bank_code, central_account_number, linked_at, metadata) FROM stdin;
\.


--
-- Data for Name: chart_of_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.chart_of_accounts (coa_code, coa_name, coa_type, parent_coa_code, is_customer_account, is_trust_account, created_at) FROM stdin;
\.


--
-- Data for Name: clearing_positions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.clearing_positions (id, debtor_bank, creditor_bank, amount, reference, status, created_at) FROM stdin;
\.


--
-- Data for Name: data_retention_policies; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.data_retention_policies (entity_name, retention_years, legal_basis, last_reviewed) FROM stdin;
\.


--
-- Data for Name: disaster_recovery_tests; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.disaster_recovery_tests (test_id, test_date, test_type, systems_tested, result, issues_found, resolved, signed_off_by) FROM stdin;
\.


--
-- Data for Name: ewallet_pins; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.ewallet_pins (id, transaction_id, pin, is_redeemed, regenerated_by, regeneration_fee, created_at, sat_purchased, sender_phone, recipient_phone, expires_at, amount, redeemed_at, redeemed_by, generated_by, sat_expires_at, sat_paid_by) FROM stdin;
18	43	835864	t	\N	0.00	2026-02-09 09:18:05.054704	f	+26770010001	+26770000000	2026-02-10 08:33:05	87.80	2026-02-09 09:27:04.138445	2	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
9	17	959530	f	\N	0.00	2025-12-29 16:13:36.00764	f	+26770000000	+26770000000	2025-12-29 16:28:36.00764	100.00	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
13	27	516923	f	\N	0.00	2026-01-03 01:51:08.708865	t	+26770010001	+26770000000	2026-01-03 01:06:08	100.00	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
1	9	253353	f	\N	0.00	2025-12-29 15:59:26.338553	f	+26770000000	+26770000000	2025-12-31 16:14:26.338553	100.00	2025-12-30 20:23:33.274185	2	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
10	18	907310	t	\N	0.00	2025-12-29 16:13:36.008336	f	+26770000000	+26770000000	2026-12-29 16:28:36.008336	100.00	2026-01-03 10:12:58.057586	2	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
2	10	919638	t	\N	0.00	2025-12-29 15:59:26.344984	f	+26770000000	+26770000000	2026-12-29 16:14:26.344984	100.00	2026-01-25 10:50:33.607465	2	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
14	39	164310	f	\N	0.00	2026-02-04 23:53:45.127942	f	+26770010001	+26770000000	2026-02-04 23:08:45	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
15	40	385575	f	\N	0.00	2026-02-05 16:07:46.219732	f	+26770010001	+26770000000	2026-02-05 15:22:46	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
16	41	400204	f	\N	0.00	2026-02-07 18:37:47.858983	f	+26770010001	+26770000000	2026-02-07 17:52:47	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
17	42	218304	f	\N	0.00	2026-02-07 23:30:49.696892	f	+26770010001	+26770000000	2026-02-07 22:45:49	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
19	45	845649	f	\N	0.00	2026-02-09 09:38:41.991732	f	+26770010001	+267+70000000	2026-02-09 08:53:42	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
20	46	132823	f	\N	0.00	2026-02-09 12:32:45.584981	f	+26770010001	+267+70000000	2026-02-09 11:47:45	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
21	47	447856	f	\N	0.00	2026-02-09 15:52:34.419676	f	+26770010001	+267+70000000	2026-02-09 15:07:34	87.80	\N	\N	2	2026-02-12 09:45:03.866877	2026-02-12 09:45:03.866877
\.


--
-- Data for Name: ewallet_settings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.ewallet_settings (id, expiry_minutes, regeneration_fee, created_at, updated_at) FROM stdin;
1	1440	2.5000	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107
\.


--
-- Data for Name: ewallet_verification_log; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.ewallet_verification_log (id, wallet_id, phone, amount, foreign_bank, session_id, hold_reference, status, verified_at, cashout_confirmed_at) FROM stdin;
\.


--
-- Data for Name: external_banks; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.external_banks (bank_id, bank_name, swift_code, country, contact_info, created_at) FROM stdin;
\.


--
-- Data for Name: fees; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.fees (fee_id, fee_type, description, amount, created_at) FROM stdin;
\.


--
-- Data for Name: financial_holds; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.financial_holds (id, wallet_id, amount, hold_reference, foreign_bank, session_id, foreign_atm_id, status, cashout_confirmed, failure_reason, expires_at, created_at, released_at) FROM stdin;
20	4	100.0000	3d2a4daf65bdccc94327dbcf19fac445	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:12:13.347404	2026-02-28 12:12:13.347404	\N
21	4	50.0000	cb3dde2b6bf5bde13c6cfd13313d7032	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:12:13.46419	2026-02-28 12:12:13.46419	\N
22	4	100.0000	a9c17b27f446bdf338f81db29e97e0df	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:14:53.014499	2026-02-28 12:14:53.014499	\N
23	4	50.0000	8f1434b22b5689ddac0be8810a525765	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:14:53.124296	2026-02-28 12:14:53.124296	\N
24	4	100.0000	fe5a7decafbafc36f1575684cc749a3f	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:17:40.970473	2026-02-28 12:17:40.970473	\N
25	4	50.0000	d34fd0854df9ce27a95ec7b664a47311	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:17:41.087739	2026-02-28 12:17:41.087739	\N
26	4	100.0000	4a728e004905b8c07cbd42033c638ea0	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:17:42.158648	2026-02-28 12:17:42.158648	\N
27	4	50.0000	806a671844460d7b600bbd0a8bbed751	ZURUBANK	\N	\N	HELD	f	\N	2026-03-01 12:17:42.259967	2026-02-28 12:17:42.259967	\N
28	4	100.0000	e21198912463ca019cbe8c4232d72ecd	ZURUBANK	SESSION-69a2c1ec6c5da	\N	HELD	f	\N	2026-03-01 12:22:36.4439	2026-02-28 12:22:36.4439	\N
29	4	50.0000	93911ee8d3845eab2c201e476f45cf7d	ZURUBANK	SESSION-69a2c1ec82685	\N	HELD	f	\N	2026-03-01 12:22:36.534178	2026-02-28 12:22:36.534178	\N
30	4	100.0000	HOLD-69a2c202006fb	ZURUBANK	SESSION-69a2c20200701	\N	HELD	f	\N	2026-03-01 12:22:58.001902	2026-02-28 12:22:58.001902	\N
31	4	100.0000	be19ef9c028828b71d8295b21ff1d440	ZURUBANK	SESSION-69a2c2161cfdc	\N	HELD	f	\N	2026-03-01 12:23:18.118791	2026-02-28 12:23:18.118791	\N
32	4	50.0000	9ce2f9d0203af58a00b5499952799720	ZURUBANK	SESSION-69a2c21633f99	\N	HELD	f	\N	2026-03-01 12:23:18.212909	2026-02-28 12:23:18.212909	\N
33	4	100.0000	3d618d21fb9aa5b35e7c71b703444864	ZURUBANK	SESSION-69a2d42e7b20d	\N	HELD	f	\N	2026-03-01 13:40:30.504399	2026-02-28 13:40:30.504399	\N
34	4	50.0000	6e684380a4d6e592698cff9bd81e89dd	ZURUBANK	SESSION-69a2d42e921bd	\N	HELD	f	\N	2026-03-01 13:40:30.598498	2026-02-28 13:40:30.598498	\N
35	4	100.0000	8ff2183d080a61f22fe50092b828e0a3	ZURUBANK	SESSION-69a2d509c6f9f	\N	HELD	f	\N	2026-03-01 13:44:09.815054	2026-02-28 13:44:09.815054	\N
36	4	50.0000	85053e4d6503fb00f4b8984d19da7c86	ZURUBANK	SESSION-69a2d509ea6b0	\N	HELD	f	\N	2026-03-01 13:44:09.960324	2026-02-28 13:44:09.960324	\N
37	4	100.0000	8a46a9434b7e132f2771f684311cede8	ZURUBANK	SESSION-69a2d576e3981	\N	HELD	f	\N	2026-03-01 13:45:58.932264	2026-02-28 13:45:58.932264	\N
38	4	50.0000	ecf5e5e6188e5a4257460a582b5ff88c	ZURUBANK	SESSION-69a2d577075ef	\N	HELD	f	\N	2026-03-01 13:45:59.030232	2026-02-28 13:45:59.030232	\N
39	4	100.0000	d587b9a91ea834ccf3068569f30e6a0e	ZURUBANK	SESSION-69a2d714073f8	\N	HELD	f	\N	2026-03-01 13:52:52.029704	2026-02-28 13:52:52.029704	\N
40	4	50.0000	2a1de08f8ee354fa765c4ecfaca3cd7f	ZURUBANK	SESSION-69a2d7141f21a	\N	COMMITTED	t	\N	2026-03-01 13:52:52.12754	2026-02-28 13:52:52.12754	\N
41	4	100.0000	f0191648e17fd091eb2096d8dd4bd132	ZURUBANK	SESSION-69a2d7f5b38a4	\N	COMMITTED	t	\N	2026-03-01 13:56:37.735428	2026-02-28 13:56:37.735428	\N
42	4	50.0000	cfbe8dcc64f9371e0730a6ba8658b340	ZURUBANK	SESSION-69a2d7f5d7de1	\N	COMMITTED	t	\N	2026-03-01 13:56:37.884225	2026-02-28 13:56:37.884225	\N
43	4	100.0000	8bf2845dcb71e9de48ffb5df36f3a11b	UNKNOWN	SESSION-69a2da2446186	\N	HELD	f	\N	2026-03-01 14:05:56.287186	2026-02-28 14:05:56.287186	\N
44	4	100.0000	7847ef137e2e8fe4560717e4a416e21f	ZURUBANK	SESSION-69a2da2da9734	\N	COMMITTED	t	\N	2026-03-01 14:06:05.694125	2026-02-28 14:06:05.694125	\N
45	4	50.0000	c1853cebc78a0de4aabc93c671008af7	ZURUBANK	SESSION-69a2da2dc4726	\N	COMMITTED	t	\N	2026-03-01 14:06:05.804736	2026-02-28 14:06:05.804736	\N
46	4	100.0000	9785afc0372542926d15d23a6bbc4d87	UNKNOWN	SESSION-69a2da9c9e32a	\N	HELD	f	\N	2026-03-01 14:07:56.648008	2026-02-28 14:07:56.648008	\N
47	4	100.0000	5bee8363b479992df2d2ed74e06dd211	ZURUBANK	SESSION-69a2e9e604bfb	\N	HELD	f	\N	2026-03-01 15:13:10.01964	2026-02-28 15:13:10.01964	\N
48	4	100.0000	96b4a60914d364bf599b4397e21388c1	ZURUBANK	SESSION-69a2eafae8fe2	\N	HELD	f	\N	2026-03-01 15:17:46.954434	2026-02-28 15:17:46.954434	\N
49	4	100.0000	584c1e36f3a781334f9bb877a6faf5cc	ZURUBANK	SESSION-69a2eb05dd001	\N	HELD	f	\N	2026-03-01 15:17:57.905273	2026-02-28 15:17:57.905273	\N
50	4	100.0000	5fe30fc57a7c85c751c9af278385a2f2	ZURUBANK	SESSION-69a2ec18db38e	\N	HELD	f	\N	2026-03-01 15:22:32.89797	2026-02-28 15:22:32.89797	\N
51	4	100.0000	6d3ff7e319aebc6ef48eff35c70cf6aa	ZURUBANK	SESSION-69a2ec35545b3	\N	HELD	f	\N	2026-03-01 15:23:01.345549	2026-02-28 15:23:01.345549	\N
52	4	100.0000	ffb041b10986f827ddfa1dc5407e030c	ZURUBANK	SESSION-69a2efaf3aae9	\N	HELD	f	\N	2026-03-01 15:37:51.240385	2026-02-28 15:37:51.240385	\N
53	4	100.0000	629ff6f77767565598eb477510d217ef	ZURUBANK	SESSION-69a2f12dbb838	\N	HELD	f	\N	2026-03-01 15:44:13.768121	2026-02-28 15:44:13.768121	\N
54	4	100.0000	805a45ee0bf22b8cd566c778ce106c81	ZURUBANK	SESSION-69a2f32f732f8	\N	COMMITTED	t	\N	2026-03-01 15:52:47.471867	2026-02-28 15:52:47.471867	\N
55	4	100.0000	5d4eb39895362ca3cd137acaa5619c8f	ZURUBANK	SESSION-69a51a8c04f43	\N	HELD	f	\N	2026-03-03 07:05:16.020435	2026-03-02 07:05:16.020435	\N
56	4	100.0000	8c4dc51e09119dd0ee509a31c4eb6070	ZURUBANK	SESSION-69a53676ce2de	\N	COMMITTED	t	\N	2026-03-03 09:04:22.844539	2026-03-02 09:04:22.844539	\N
\.


--
-- Data for Name: interbank_claims; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.interbank_claims (id, sat_code, issuer_institution, amount, fee, net_amount, status, created_at, hold_reference) FROM stdin;
\.


--
-- Data for Name: interbank_net_positions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.interbank_net_positions (issuer_institution, total_receivable, last_updated) FROM stdin;
\.


--
-- Data for Name: kyc_documents; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.kyc_documents (id, user_id, doc_type, doc_number, doc_file, status, submitted_at, reviewed_at) FROM stdin;
\.


--
-- Data for Name: ledger_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.ledger_accounts (id, account_name, account_number, account_type, currency, balance, created_at, updated_at, owner_type, owner_id) FROM stdin;
1	Swap Middleman Float	SWAP-MID-FLOAT	liquidity	BWP	0.0000	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	\N	\N
2	Swap Fee Collector	SWAP-FEE	fee	BWP	0.0000	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	\N	\N
3	SmartShop Float Ledger	AGENT-001	liability	BWP	0.0000	2026-02-12 18:47:03.649608	2026-02-12 18:47:03.649608	AGENT	1
\.


--
-- Data for Name: ledger_entries; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.ledger_entries (id, reference, debit_account, credit_account, amount, currency, notes, created_at, reference_type, reference_id, narration) FROM stdin;
3	CASHIN-12345	TRUST-001	AGENT-001	5000.0000	BWP	Cash deposit by SmartShop Agent for eMoney float	2026-02-12 19:03:25.161861	\N	\N	\N
4	2a1de08f8ee354fa765c4ecfaca3cd7f	WALLET:4	ACCOUNT:10000001	50.0000	BWP	Hold settlement	2026-02-28 13:52:52.152447	\N	\N	\N
5	f0191648e17fd091eb2096d8dd4bd132	WALLET:4	ACCOUNT:10000001	100.0000	BWP	Hold settlement	2026-02-28 13:56:37.86042	\N	\N	\N
6	cfbe8dcc64f9371e0730a6ba8658b340	WALLET:4	ACCOUNT:10000001	50.0000	BWP	Hold settlement	2026-02-28 13:56:37.907479	\N	\N	\N
7	7847ef137e2e8fe4560717e4a416e21f	WALLET:4	ACCOUNT:10000001	100.0000	BWP	Hold settlement	2026-02-28 14:06:05.780609	\N	\N	\N
8	c1853cebc78a0de4aabc93c671008af7	WALLET:4	ACCOUNT:10000001	50.0000	BWP	Hold settlement	2026-02-28 14:06:05.832219	\N	\N	\N
9	805a45ee0bf22b8cd566c778ce106c81	WALLET:4	ACCOUNT:10000001	100.0000	BWP	Hold settlement	2026-02-28 15:52:47.637916	\N	\N	\N
10	8c4dc51e09119dd0ee509a31c4eb6070	WALLET:4	ACCOUNT:10000001	100.0000	BWP	Hold settlement	2026-03-02 09:04:22.982323	\N	\N	\N
\.


--
-- Data for Name: loan_repayments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.loan_repayments (repayment_id, loan_id, amount, due_date, status, created_at, paid_at) FROM stdin;
\.


--
-- Data for Name: loans; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.loans (loan_id, user_id, principal, interest_rate, term_months, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: network_fee_ledger; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.network_fee_ledger (id, sat_code, payer, amount, collected_from, created_at) FROM stdin;
\.


--
-- Data for Name: network_request_log; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.network_request_log (id, request_id, response, created_at) FROM stdin;
1	TEST123	{"status":"APPROVED","auth_code":194905}	2026-02-21 19:20:32.790928
2	SELF-001	{"status":"APPROVED","auth_code":464069}	2026-02-21 19:23:02.398704
3	REVERSAL-001	{"status":"APPROVED","auth_code":184803}	2026-02-21 19:23:02.420219
4	DUPLICATE-001	{"status":"APPROVED","auth_code":324100}	2026-02-21 19:23:02.464525
5	EXPIRE-001	{"status":"APPROVED","auth_code":817385}	2026-02-21 19:23:02.496733
6	SWAPFEE-001	{"status":"ERROR","message":"Missing counterparty_bank"}	2026-02-22 13:02:42.299392
7	FOREIGN-001	{"status":"ERROR","message":"Missing counterparty_bank"}	2026-02-22 13:10:27.137314
8	TEST002	{"status":"success","settlement_ref":"SET1772108640236","type":"SAT_TOKEN","amount":500,"message":"SAT token settlement recorded successfully"}	2026-02-26 14:24:00.670406
\.


--
-- Data for Name: network_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.network_transactions (id, trace_id, type, wallet_id, amount, status, auth_code, counterparty_bank, created_at) FROM stdin;
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.notifications (id, user_id, type, message, status, created_at) FROM stdin;
\.


--
-- Data for Name: saccus_middleman; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.saccus_middleman (id, account_number, api_key, created_at) FROM stdin;
1	SACCUS987654321	MIDDLEMAN_SACCUS_API_KEY	2025-12-12 11:45:04.023107
\.


--
-- Data for Name: safeguarding_register; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.safeguarding_register (id, account_number, institution_name, safeguarded_amount, recorded_at) FROM stdin;
\.


--
-- Data for Name: sat_tokens; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sat_tokens (sat_id, instrument_id, issuer_bank, acquirer_network, amount, expires_at, status, processing, created_at, used_at, attempts, max_attempts, last_attempt_at, sat_number, pin) FROM stdin;
1	1	SACCUS	ZuruBank	500.00	2026-02-27 12:34:14	ACTIVE	f	2026-02-26 13:34:14.061409	\N	0	3	\N	091876914318	361907
3	4	SACCUS	ZuruBank	500.00	2026-02-27 12:41:56	USED	f	2026-02-26 13:41:56.714612	2026-02-26 14:02:38.334899	0	3	2026-02-26 13:57:10.34015	574903440943	102375
4	6	SACCUS	ZURUBANK	1500.00	2026-03-03 06:51:59	ACTIVE	f	2026-03-02 07:51:59.568909	\N	0	3	\N	674233363046	659256
5	7	SACCUS	ZURUBANK	1500.00	2026-03-03 06:55:45	ACTIVE	f	2026-03-02 07:55:45.683583	\N	0	3	\N	113767295073	773631
6	8	SACCUS	ZURUBANK	1500.00	2026-03-03 06:58:45	ACTIVE	f	2026-03-02 07:58:45.587972	\N	0	3	\N	098809005767	176263
7	9	SACCUS	ZURUBANK	1500.00	2026-03-03 07:05:47	ACTIVE	f	2026-03-02 08:05:47.96602	\N	0	3	\N	411027606855	668214
8	10	SACCUS	ZURUBANK	1500.00	2026-03-03 07:06:54	ACTIVE	f	2026-03-02 08:06:54.561169	\N	0	3	\N	070042461890	925962
9	11	SACCUS	ZURUBANK	1500.00	2026-03-03 07:16:32	ACTIVE	f	2026-03-02 08:16:32.680716	\N	0	3	\N	876393263575	924230
10	12	SACCUS	ZURUBANK	1500.00	2026-03-03 07:55:29	ACTIVE	f	2026-03-02 08:55:29.312212	\N	0	3	\N	438022480748	751459
11	13	SACCUS	ZURUBANK	1500.00	2026-03-03 08:02:54	ACTIVE	f	2026-03-02 09:02:54.573467	\N	0	3	\N	381942164429	469943
12	14	SACCUS	ZURUBANK	1490.00	2026-03-03 10:26:28	ACTIVE	f	2026-03-02 11:26:28.063345	\N	0	3	\N	715478837754	454459
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sessions (id, user_id, token, ip_address, user_agent, data, last_activity, expires_at) FROM stdin;
2	2	0f0ae2e20480b71b7c7d39345a8d017f46a7087d9e174c4378c6f90bcb443741	127.0.0.1	Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0	{"full_name":"Motho"}	2025-12-29 14:13:05	2025-12-30 02:13:05
3	2	533557e74c147e21bdb82d054ec28a401a3fc634e0c85223308f9535d8354182	127.0.0.1	Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:147.0) Gecko/20100101 Firefox/147.0	{"full_name":"Motho"}	2026-02-19 21:44:16	2026-02-20 09:44:16
4	2	743f7ad21c6746cd50a954a4d91fe820747b583a99aeb592f9b9d2873d370048	127.0.0.1	Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:147.0) Gecko/20100101 Firefox/147.0	{"full_name":"Motho"}	2026-02-26 07:23:23	2026-02-26 19:23:23
\.


--
-- Data for Name: settlement_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.settlement_accounts (settlement_account_id, account_name, account_type, account_number, currency, balance, is_frozen, created_at, updated_at) FROM stdin;
1	MAIN_SETTLEMENT	operational	10000001	BWP	999800.0000	f	2026-02-26 15:19:46.216579	2026-02-26 15:19:46.216579
\.


--
-- Data for Name: settlement_batches; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.settlement_batches (id, issuer_institution, batch_total, status, created_at) FROM stdin;
\.


--
-- Data for Name: settlements; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.settlements (settlement_id, settlement_ref, type, sat_number, wallet_id, amount, issuer_bank, acquirer_bank, status, created_at, recipient_type, recipient_id) FROM stdin;
3	SET1772108640236	574903440943	574903440943	\N	500.0000	SACCUS	ZuruBank	pending	2026-02-26 14:24:00.665873	\N	\N
4	SET1772112467397	SWAP_CREDIT	\N	\N	100.0000	\N	\N	pending	2026-02-26 15:27:47.388824	\N	\N
5	SET1772112905203	SWAP_CREDIT	\N	\N	100.0000	ZuruBank	\N	pending	2026-02-26 15:35:05.175251	\N	\N
6	SET1772113150058	SWAP_CREDIT	\N	\N	100.0000	ZuruBank	\N	pending	2026-02-26 15:39:09.980564	ACCOUNT	1
7	SET1772113248973	SWAP_CREDIT	\N	\N	150.0000	ZuruBank	\N	pending	2026-02-26 15:40:48.962967	WALLET	4
8	DEP-123	SWAP_CREDIT	\N	\N	100.0000	SACCUSSALIS	\N	pending	2026-02-27 16:22:21.321672	ACCOUNT	1
9	2a1de08f8ee354fa765c4ecfaca3cd7f	HOLD_SETTLEMENT	\N	4	50.0000	SACCUSSALIS	\N	completed	2026-02-28 13:52:52.152447	\N	\N
10	f0191648e17fd091eb2096d8dd4bd132	HOLD_SETTLEMENT	\N	4	100.0000	SACCUSSALIS	\N	completed	2026-02-28 13:56:37.86042	\N	\N
11	cfbe8dcc64f9371e0730a6ba8658b340	HOLD_SETTLEMENT	\N	4	50.0000	SACCUSSALIS	\N	completed	2026-02-28 13:56:37.907479	\N	\N
12	7847ef137e2e8fe4560717e4a416e21f	HOLD_SETTLEMENT	\N	4	100.0000	SACCUSSALIS	\N	completed	2026-02-28 14:06:05.780609	\N	\N
13	c1853cebc78a0de4aabc93c671008af7	HOLD_SETTLEMENT	\N	4	50.0000	SACCUSSALIS	\N	completed	2026-02-28 14:06:05.832219	\N	\N
14	805a45ee0bf22b8cd566c778ce106c81	HOLD_SETTLEMENT	\N	4	100.0000	SACCUSSALIS	\N	completed	2026-02-28 15:52:47.637916	\N	\N
15	8c4dc51e09119dd0ee509a31c4eb6070	HOLD_SETTLEMENT	\N	4	100.0000	SACCUSSALIS	\N	completed	2026-03-02 09:04:22.982323	\N	\N
\.


--
-- Data for Name: swap_fee_tracking; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_fee_tracking (id, phone, instrument_type, instrument_ref, fee_paid, paid_by, fee_amount, created_at) FROM stdin;
\.


--
-- Data for Name: swap_ledgers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_ledgers (ledger_id, swap_reference, from_participant, to_participant, from_type, to_type, from_account, to_account, original_amount, final_amount, swap_fee, creation_fee, admin_fee, sms_fee, currency_code, token, reverse_logic, notes, status, performed_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: swap_middleman; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_middleman (id, account_number, api_key, created_at) FROM stdin;
1	SACCUS987654321	MIDDLEMAN_SACCUS_API_KEY	2025-12-12 11:45:04.023107
\.


--
-- Data for Name: swap_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_transactions (id, ledger_id, source, reference, amount, currency, type, description, created_at) FROM stdin;
\.


--
-- Data for Name: transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.transactions (transaction_id, user_id, reference, from_account, to_account, external_bank_id, amount, fee_amount, type, direction, channel, status, notes, created_at, updated_at, transactions_id, is_deleted, purpose, beneficiary_name, rapid_movement_flag, structuring_flag, unusual_behavior_flag, description) FROM stdin;
19	2	\N	SAV00000002	+26770000000	\N	100.0000	2.0000	wallet_transfer	out	ewallet	completed		2025-12-29 15:59:26.338553	2025-12-29 15:59:26.338553	9	f	\N	\N	f	f	f	\N
20	2	\N	SAV00000002	+26770000000	\N	100.0000	2.0000	wallet_transfer	out	ewallet	completed		2025-12-29 15:59:26.344984	2025-12-29 15:59:26.344984	10	f	\N	\N	f	f	f	\N
27	2	\N	CUR00000002	+26770000000	\N	100.0000	2.0000	wallet_transfer	out	ewallet	completed		2025-12-29 16:13:36.00764	2025-12-29 16:13:36.00764	17	f	\N	\N	f	f	f	\N
28	2	\N	CUR00000002	+26770000000	\N	100.0000	2.0000	wallet_transfer	out	ewallet	completed		2025-12-29 16:13:36.008336	2025-12-29 16:13:36.008336	18	f	\N	\N	f	f	f	\N
29	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2025-12-30 17:48:13.579766	2025-12-30 17:48:13.579766	19	f	\N	\N	f	f	f	\N
30	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2025-12-30 17:51:00.310565	2025-12-30 17:51:00.310565	20	f	\N	\N	f	f	f	\N
31	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2025-12-30 17:58:05.836786	2025-12-30 17:58:05.836786	21	f	\N	\N	f	f	f	\N
32	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2025-12-30 18:00:35.505984	2025-12-30 18:00:35.505984	22	f	\N	\N	f	f	f	\N
33	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2025-12-30 20:15:36.486065	2025-12-30 20:15:36.486065	23	f	\N	\N	f	f	f	\N
34	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2025-12-30 20:23:33.274185	2025-12-30 20:23:33.274185	24	f	\N	\N	f	f	f	\N
37	2	\N	8	+26770000000	\N	100.0000	0.0000	wallet_send	\N	\N	completed	\N	2026-01-03 01:51:08.708865	2026-01-03 01:51:08.708865	27	f	\N	\N	f	f	f	\N
38	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 02:11:54.272392	2026-01-03 02:11:54.272392	28	f	\N	\N	f	f	f	\N
39	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 02:29:26.491088	2026-01-03 02:29:26.491088	29	f	\N	\N	f	f	f	\N
40	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 09:29:46.70992	2026-01-03 09:29:46.70992	30	f	\N	\N	f	f	f	\N
41	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 09:38:20.916617	2026-01-03 09:38:20.916617	31	f	\N	\N	f	f	f	\N
42	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 09:43:14.28763	2026-01-03 09:43:14.28763	32	f	\N	\N	f	f	f	\N
43	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 09:58:54.250931	2026-01-03 09:58:54.250931	33	f	\N	\N	f	f	f	\N
44	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 10:12:14.992441	2026-01-03 10:12:14.992441	34	f	\N	\N	f	f	f	\N
45	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-03 10:12:58.057586	2026-01-03 10:12:58.057586	35	f	\N	\N	f	f	f	\N
46	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-25 01:46:10.473996	2026-01-25 01:46:10.473996	36	f	\N	\N	f	f	f	\N
47	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-25 02:42:06.003267	2026-01-25 02:42:06.003267	37	f	\N	\N	f	f	f	\N
48	2	\N	4	8	\N	100.0000	0.0000	withdrawal	\N	\N	completed	\N	2026-01-25 10:50:33.607465	2026-01-25 10:50:33.607465	38	f	\N	\N	f	f	f	\N
49	2	\N	8	+26770000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-04 23:53:45.127942	2026-02-04 23:53:45.127942	39	f	\N	\N	f	f	f	\N
50	2	\N	8	+26770000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-05 16:07:46.219732	2026-02-05 16:07:46.219732	40	f	\N	\N	f	f	f	\N
51	2	\N	8	+26770000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-07 18:37:47.858983	2026-02-07 18:37:47.858983	41	f	\N	\N	f	f	f	\N
52	2	\N	8	+26770000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-07 23:30:49.696892	2026-02-07 23:30:49.696892	42	f	\N	\N	f	f	f	\N
53	2	\N	8	+70000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-09 09:18:05.054704	2026-02-09 09:18:05.054704	43	f	\N	\N	f	f	f	\N
54	2	\N	4	8	\N	87.8000	0.0000	withdrawal	\N	\N	completed	\N	2026-02-09 09:27:04.138445	2026-02-09 09:27:04.138445	44	f	\N	\N	f	f	f	\N
55	2	\N	8	+70000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-09 09:38:41.991732	2026-02-09 09:38:41.991732	45	f	\N	\N	f	f	f	\N
56	2	\N	8	+70000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-09 12:32:45.584981	2026-02-09 12:32:45.584981	46	f	\N	\N	f	f	f	\N
57	2	\N	8	+70000000	\N	87.8000	0.0000	wallet_send	\N	\N	completed	\N	2026-02-09 15:52:34.419676	2026-02-09 15:52:34.419676	47	f	\N	\N	f	f	f	\N
60	2	\N	BANK_SETTLEMENT	WALLET:+26770000000	\N	87.8000	0.0000	wallet_deposit	\N	\N	completed	Funding wallet from settlement	2026-02-12 17:27:13.393718	2026-02-12 17:27:13.393718	50	f	\N	\N	f	f	f	\N
61	2	\N	\N	\N	\N	-100.0000	0.0000	Own Transfer	\N	\N	pending	\N	2026-02-19 22:45:28.245074	2026-02-19 22:45:28.245074	51	f	\N	\N	f	f	f	\N
62	2	\N	\N	\N	\N	100.0000	0.0000	Own Transfer Received	\N	\N	pending	\N	2026-02-19 22:45:28.245074	2026-02-19 22:45:28.245074	52	f	\N	\N	f	f	f	\N
63	2	\N	\N	\N	\N	-100.0000	0.0000	Own Transfer	\N	\N	pending	\N	2026-02-19 22:45:28.249231	2026-02-19 22:45:28.249231	53	f	\N	\N	f	f	f	\N
64	2	\N	\N	\N	\N	100.0000	0.0000	Own Transfer Received	\N	\N	pending	\N	2026-02-19 22:45:28.249231	2026-02-19 22:45:28.249231	54	f	\N	\N	f	f	f	\N
76	2	e46aa0d4d9d2148bf0039fb52a6bccca	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":4,"instrument_id":6,"source_institution":"ZURUBANK","source_hold_reference":"e46aa0d4d9d2148bf0039fb52a6bccca","source_asset_type":"VOUCHER"}	2026-03-02 07:51:59.568909	2026-03-02 07:51:59.568909	65	f	\N	\N	f	f	f	\N
77	2	79d323f9ba91a251a93dc12b9e983ce0	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":5,"instrument_id":7,"source_institution":"ZURUBANK","source_hold_reference":"79d323f9ba91a251a93dc12b9e983ce0","source_asset_type":"VOUCHER"}	2026-03-02 07:55:45.683583	2026-03-02 07:55:45.683583	66	f	\N	\N	f	f	f	\N
78	2	e9860d6bbda467c3aa295883bb8ed093	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":6,"instrument_id":8,"source_institution":"ZURUBANK","source_hold_reference":"e9860d6bbda467c3aa295883bb8ed093","source_asset_type":"VOUCHER"}	2026-03-02 07:58:45.587972	2026-03-02 07:58:45.587972	67	f	\N	\N	f	f	f	\N
79	2	3e5854e93afcc9e1b498aa2f6f56ec39	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":7,"instrument_id":9,"source_institution":"ZURUBANK","source_hold_reference":"3e5854e93afcc9e1b498aa2f6f56ec39","source_asset_type":"VOUCHER"}	2026-03-02 08:05:47.96602	2026-03-02 08:05:47.96602	68	f	\N	\N	f	f	f	\N
80	2	4a5f0d0b22eded1aef6ec731e75343a5	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":8,"instrument_id":10,"source_institution":"ZURUBANK","source_hold_reference":"4a5f0d0b22eded1aef6ec731e75343a5","source_asset_type":"VOUCHER"}	2026-03-02 08:06:54.561169	2026-03-02 08:06:54.561169	69	f	\N	\N	f	f	f	\N
81	2	3145a5cc507709e58a4767f6c7499984	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":9,"instrument_id":11,"source_institution":"ZURUBANK","source_hold_reference":"3145a5cc507709e58a4767f6c7499984","source_asset_type":"VOUCHER"}	2026-03-02 08:16:32.680716	2026-03-02 08:16:32.680716	70	f	\N	\N	f	f	f	\N
82	2	7577f648f361af3206bfad5180ea69cd	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":10,"instrument_id":12,"source_institution":"ZURUBANK","source_hold_reference":"7577f648f361af3206bfad5180ea69cd","source_asset_type":"VOUCHER"}	2026-03-02 08:55:29.312212	2026-03-02 08:55:29.312212	71	f	\N	\N	f	f	f	\N
83	2	897ba2207e18cc592d82fffa28be41ff	\N	\N	\N	1500.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":11,"instrument_id":13,"source_institution":"ZURUBANK","source_hold_reference":"897ba2207e18cc592d82fffa28be41ff","source_asset_type":"VOUCHER"}	2026-03-02 09:02:54.573467	2026-03-02 09:02:54.573467	72	f	\N	\N	f	f	f	\N
84	2	ee1c9f897a174639e584727e51268555	\N	\N	\N	1490.0000	0.0000	ATM_TOKEN_GENERATION	\N	\N	COMPLETED	{"sat_id":12,"instrument_id":14,"source_institution":"ZURUBANK","source_hold_reference":"ee1c9f897a174639e584727e51268555","source_asset_type":"VOUCHER"}	2026-03-02 11:26:28.063345	2026-03-02 11:26:28.063345	73	f	\N	\N	f	f	f	\N
\.


--
-- Data for Name: trust_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.trust_accounts (account_number, account_name, currency, status, opened_at) FROM stdin;
\.


--
-- Data for Name: trust_balances; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.trust_balances (account_number, available_balance, last_updated) FROM stdin;
\.


--
-- Data for Name: trust_postings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.trust_postings (id, account_number, reference, amount, direction, channel, created_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (user_id, full_name, email, phone, password_hash, role, kyc_status, status, created_at, updated_at, pep, sanctions_checked, last_sanctions_check) FROM stdin;
1	System Admin	admin@saccussalis.com	+2600000000	app_generated_hash_for_admin123	admin	verified	active	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	f	f	\N
41	Saccus Operational Account	operational@saccussalis.com	+26780000001		system	verified	active	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	f	f	\N
42	Saccus Fee Account	fee@saccussalis.com	+26780000002		system	verified	active	2025-12-12 11:45:04.023107	2025-12-12 11:45:04.023107	f	f	\N
2	Motho	mothoyo@saccussalisbank.com	+26770000000	$2y$10$Yc5DdU/kbkQtHMLabTm8v.Vyg0EjF6IJbCZ0S25smJvypI7rMuyCe	user	pending	active	2025-12-29 13:18:29	2025-12-29 14:18:29.500224	f	f	\N
3	Middleman Revenue	middleman@example.com	+26770000003	hash_placeholder	customer	verified	active	2025-12-30 16:55:17.92077	2025-12-30 16:55:17.92077	f	f	\N
4	SMS Provider	sms@example.com	+26770000004	hash_placeholder	customer	verified	active	2025-12-30 16:55:17.92077	2025-12-30 16:55:17.92077	f	f	\N
5	Zurubank Settlement	zurubank@example.com	+26770000005	hash_placeholder	customer	verified	active	2025-12-30 16:55:17.92077	2025-12-30 16:55:17.92077	f	f	\N
6	vouchmorph_system	system@vouchmorph.com	+26777700000	$2y$10$N9p3Y15dTYrJsgs4/fq8b.0dmfEjCYx1YoZMJRzGpsEi9WdmGhHTi 	user	pending	active	2026-02-26 10:52:32.187489	2026-02-26 10:52:32.187489	f	f	\N
\.


--
-- Data for Name: wallet_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.wallet_transactions (id, user_id, wallet_id, linked_pin_id, recipient_identifier, transaction_type, amount, status, created_at, updated_at, is_deleted) FROM stdin;
3	2	4	\N	\N	deposit	87.8000	completed	2026-02-12 17:27:13.393718	2026-02-12 17:27:13.393718	f
\.


--
-- Data for Name: wallets; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.wallets (wallet_id, user_id, phone, wallet_type, currency, balance, is_frozen, status, created_at, updated_at, held_balance) FROM stdin;
4	\N	+26770000000	default	BWP	998750.0000	f	active	2025-12-29 15:28:27.936544	2026-02-12 17:27:13.393718	3300.0000
1	2	+26770000003	fnb_style	BWP	0.0000	f	active	2025-12-29 14:18:29.500224	2025-12-29 14:18:29.500224	0.0000
\.


--
-- Data for Name: webhook_notifications; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.webhook_notifications (id, reference, payload, delivered, delivered_at) FROM stdin;
\.


--
-- Name: account_freezes_freeze_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.account_freezes_freeze_id_seq', 1, false);


--
-- Name: accounts_account_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.accounts_account_id_seq', 8, true);


--
-- Name: api_keys_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.api_keys_id_seq', 1, true);


--
-- Name: atm_authorizations_auth_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.atm_authorizations_auth_id_seq', 1, true);


--
-- Name: atm_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.atm_transactions_id_seq', 1, false);


--
-- Name: atms_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.atms_id_seq', 1, false);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.audit_logs_id_seq', 1, false);


--
-- Name: bank_info_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.bank_info_id_seq', 1, false);


--
-- Name: cash_instruments_instrument_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.cash_instruments_instrument_id_seq', 14, true);


--
-- Name: central_bank_link_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.central_bank_link_id_seq', 1, false);


--
-- Name: clearing_positions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.clearing_positions_id_seq', 1, false);


--
-- Name: disaster_recovery_tests_test_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.disaster_recovery_tests_test_id_seq', 1, false);


--
-- Name: ewallet_pins_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.ewallet_pins_id_seq', 29, true);


--
-- Name: ewallet_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.ewallet_settings_id_seq', 1, true);


--
-- Name: ewallet_verification_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.ewallet_verification_log_id_seq', 1, false);


--
-- Name: external_banks_bank_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.external_banks_bank_id_seq', 1, false);


--
-- Name: fees_fee_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.fees_fee_id_seq', 1, false);


--
-- Name: financial_holds_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.financial_holds_id_seq', 56, true);


--
-- Name: interbank_claims_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.interbank_claims_id_seq', 1, false);


--
-- Name: kyc_documents_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.kyc_documents_id_seq', 1, false);


--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.ledger_accounts_id_seq', 5, true);


--
-- Name: ledger_entries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.ledger_entries_id_seq', 10, true);


--
-- Name: loan_repayments_repayment_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.loan_repayments_repayment_id_seq', 1, false);


--
-- Name: loans_loan_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.loans_loan_id_seq', 1, false);


--
-- Name: network_fee_ledger_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.network_fee_ledger_id_seq', 1, false);


--
-- Name: network_request_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.network_request_log_id_seq', 8, true);


--
-- Name: network_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.network_transactions_id_seq', 1, false);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.notifications_id_seq', 1, false);


--
-- Name: saccus_middleman_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.saccus_middleman_id_seq', 1, true);


--
-- Name: safeguarding_register_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.safeguarding_register_id_seq', 1, false);


--
-- Name: sat_tokens_sat_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sat_tokens_sat_id_seq', 12, true);


--
-- Name: sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sessions_id_seq', 4, true);


--
-- Name: settlement_accounts_settlement_account_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.settlement_accounts_settlement_account_id_seq', 1, true);


--
-- Name: settlement_batches_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.settlement_batches_id_seq', 1, false);


--
-- Name: settlements_settlement_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.settlements_settlement_id_seq', 15, true);


--
-- Name: swap_fee_tracking_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_fee_tracking_id_seq', 1, false);


--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_ledgers_ledger_id_seq', 1, false);


--
-- Name: swap_middleman_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_middleman_id_seq', 1, true);


--
-- Name: swap_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_transactions_id_seq', 1, false);


--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.transactions_transaction_id_seq', 84, true);


--
-- Name: transactions_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.transactions_transactions_id_seq', 73, true);


--
-- Name: trust_postings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.trust_postings_id_seq', 1, false);


--
-- Name: users_user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.users_user_id_seq', 6, true);


--
-- Name: wallet_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.wallet_transactions_id_seq', 3, true);


--
-- Name: wallets_wallet_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.wallets_wallet_id_seq', 4, true);


--
-- Name: webhook_notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.webhook_notifications_id_seq', 1, false);


--
-- Name: account_freezes account_freezes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_freezes
    ADD CONSTRAINT account_freezes_pkey PRIMARY KEY (freeze_id);


--
-- Name: accounting_closures accounting_closures_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounting_closures
    ADD CONSTRAINT accounting_closures_pkey PRIMARY KEY (closure_date);


--
-- Name: accounts accounts_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_account_number_key UNIQUE (account_number);


--
-- Name: accounts accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (account_id);


--
-- Name: api_keys api_keys_api_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_api_key_key UNIQUE (api_key);


--
-- Name: api_keys api_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_pkey PRIMARY KEY (id);


--
-- Name: atm_authorizations atm_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_authorizations
    ADD CONSTRAINT atm_authorizations_pkey PRIMARY KEY (auth_id);


--
-- Name: atm_authorizations atm_authorizations_trace_number_acquirer_bank_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_authorizations
    ADD CONSTRAINT atm_authorizations_trace_number_acquirer_bank_key UNIQUE (trace_number, acquirer_bank);


--
-- Name: atm_transactions atm_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_transactions
    ADD CONSTRAINT atm_transactions_pkey PRIMARY KEY (id);


--
-- Name: atms atms_atm_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atms
    ADD CONSTRAINT atms_atm_code_key UNIQUE (atm_code);


--
-- Name: atms atms_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atms
    ADD CONSTRAINT atms_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: bank_info bank_info_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bank_info
    ADD CONSTRAINT bank_info_pkey PRIMARY KEY (id);


--
-- Name: cash_instruments cash_instruments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cash_instruments
    ADD CONSTRAINT cash_instruments_pkey PRIMARY KEY (instrument_id);


--
-- Name: central_bank_link central_bank_link_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.central_bank_link
    ADD CONSTRAINT central_bank_link_pkey PRIMARY KEY (id);


--
-- Name: chart_of_accounts chart_of_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chart_of_accounts
    ADD CONSTRAINT chart_of_accounts_pkey PRIMARY KEY (coa_code);


--
-- Name: clearing_positions clearing_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.clearing_positions
    ADD CONSTRAINT clearing_positions_pkey PRIMARY KEY (id);


--
-- Name: data_retention_policies data_retention_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.data_retention_policies
    ADD CONSTRAINT data_retention_policies_pkey PRIMARY KEY (entity_name);


--
-- Name: disaster_recovery_tests disaster_recovery_tests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.disaster_recovery_tests
    ADD CONSTRAINT disaster_recovery_tests_pkey PRIMARY KEY (test_id);


--
-- Name: ewallet_pins ewallet_pins_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_pins
    ADD CONSTRAINT ewallet_pins_pkey PRIMARY KEY (id);


--
-- Name: ewallet_settings ewallet_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_settings
    ADD CONSTRAINT ewallet_settings_pkey PRIMARY KEY (id);


--
-- Name: ewallet_verification_log ewallet_verification_log_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_verification_log
    ADD CONSTRAINT ewallet_verification_log_pkey PRIMARY KEY (id);


--
-- Name: external_banks external_banks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_banks
    ADD CONSTRAINT external_banks_pkey PRIMARY KEY (bank_id);


--
-- Name: external_banks external_banks_swift_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_banks
    ADD CONSTRAINT external_banks_swift_code_key UNIQUE (swift_code);


--
-- Name: fees fees_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fees
    ADD CONSTRAINT fees_pkey PRIMARY KEY (fee_id);


--
-- Name: financial_holds financial_holds_hold_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.financial_holds
    ADD CONSTRAINT financial_holds_hold_reference_key UNIQUE (hold_reference);


--
-- Name: financial_holds financial_holds_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.financial_holds
    ADD CONSTRAINT financial_holds_pkey PRIMARY KEY (id);


--
-- Name: interbank_claims interbank_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.interbank_claims
    ADD CONSTRAINT interbank_claims_pkey PRIMARY KEY (id);


--
-- Name: interbank_claims interbank_claims_sat_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.interbank_claims
    ADD CONSTRAINT interbank_claims_sat_code_key UNIQUE (sat_code);


--
-- Name: interbank_net_positions interbank_net_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.interbank_net_positions
    ADD CONSTRAINT interbank_net_positions_pkey PRIMARY KEY (issuer_institution);


--
-- Name: kyc_documents kyc_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_pkey PRIMARY KEY (id);


--
-- Name: ledger_accounts ledger_accounts_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_account_number_key UNIQUE (account_number);


--
-- Name: ledger_accounts ledger_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_pkey PRIMARY KEY (id);


--
-- Name: ledger_entries ledger_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_pkey PRIMARY KEY (id);


--
-- Name: loan_repayments loan_repayments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT loan_repayments_pkey PRIMARY KEY (repayment_id);


--
-- Name: loans loans_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT loans_pkey PRIMARY KEY (loan_id);


--
-- Name: network_fee_ledger network_fee_ledger_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_fee_ledger
    ADD CONSTRAINT network_fee_ledger_pkey PRIMARY KEY (id);


--
-- Name: network_request_log network_request_log_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_request_log
    ADD CONSTRAINT network_request_log_pkey PRIMARY KEY (id);


--
-- Name: network_request_log network_request_log_request_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_request_log
    ADD CONSTRAINT network_request_log_request_id_key UNIQUE (request_id);


--
-- Name: network_transactions network_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_transactions
    ADD CONSTRAINT network_transactions_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: saccus_middleman saccus_middleman_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.saccus_middleman
    ADD CONSTRAINT saccus_middleman_account_number_key UNIQUE (account_number);


--
-- Name: saccus_middleman saccus_middleman_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.saccus_middleman
    ADD CONSTRAINT saccus_middleman_pkey PRIMARY KEY (id);


--
-- Name: safeguarding_register safeguarding_register_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.safeguarding_register
    ADD CONSTRAINT safeguarding_register_pkey PRIMARY KEY (id);


--
-- Name: sat_tokens sat_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sat_tokens
    ADD CONSTRAINT sat_tokens_pkey PRIMARY KEY (sat_id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_token_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_token_key UNIQUE (token);


--
-- Name: settlement_accounts settlement_accounts_account_name_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_accounts
    ADD CONSTRAINT settlement_accounts_account_name_key UNIQUE (account_name);


--
-- Name: settlement_accounts settlement_accounts_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_accounts
    ADD CONSTRAINT settlement_accounts_account_number_key UNIQUE (account_number);


--
-- Name: settlement_accounts settlement_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_accounts
    ADD CONSTRAINT settlement_accounts_pkey PRIMARY KEY (settlement_account_id);


--
-- Name: settlement_batches settlement_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_batches
    ADD CONSTRAINT settlement_batches_pkey PRIMARY KEY (id);


--
-- Name: settlements settlements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlements
    ADD CONSTRAINT settlements_pkey PRIMARY KEY (settlement_id);


--
-- Name: settlements settlements_settlement_ref_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlements
    ADD CONSTRAINT settlements_settlement_ref_key UNIQUE (settlement_ref);


--
-- Name: swap_fee_tracking swap_fee_tracking_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_fee_tracking
    ADD CONSTRAINT swap_fee_tracking_pkey PRIMARY KEY (id);


--
-- Name: swap_ledgers swap_ledgers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers
    ADD CONSTRAINT swap_ledgers_pkey PRIMARY KEY (ledger_id);


--
-- Name: swap_ledgers swap_ledgers_swap_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers
    ADD CONSTRAINT swap_ledgers_swap_reference_key UNIQUE (swap_reference);


--
-- Name: swap_middleman swap_middleman_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_middleman
    ADD CONSTRAINT swap_middleman_account_number_key UNIQUE (account_number);


--
-- Name: swap_middleman swap_middleman_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_middleman
    ADD CONSTRAINT swap_middleman_pkey PRIMARY KEY (id);


--
-- Name: swap_transactions swap_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_pkey PRIMARY KEY (id);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: transactions transactions_transactions_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_transactions_id_key UNIQUE (transactions_id);


--
-- Name: trust_accounts trust_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trust_accounts
    ADD CONSTRAINT trust_accounts_pkey PRIMARY KEY (account_number);


--
-- Name: trust_balances trust_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trust_balances
    ADD CONSTRAINT trust_balances_pkey PRIMARY KEY (account_number);


--
-- Name: trust_postings trust_postings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trust_postings
    ADD CONSTRAINT trust_postings_pkey PRIMARY KEY (id);


--
-- Name: trust_postings trust_postings_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trust_postings
    ADD CONSTRAINT trust_postings_reference_key UNIQUE (reference);


--
-- Name: sat_tokens unique_sat_number; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sat_tokens
    ADD CONSTRAINT unique_sat_number UNIQUE (sat_number);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_phone_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_phone_key UNIQUE (phone);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: wallet_transactions wallet_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT wallet_transactions_pkey PRIMARY KEY (id);


--
-- Name: wallets wallets_phone_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT wallets_phone_key UNIQUE (phone);


--
-- Name: wallets wallets_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT wallets_pkey PRIMARY KEY (wallet_id);


--
-- Name: webhook_notifications webhook_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.webhook_notifications
    ADD CONSTRAINT webhook_notifications_pkey PRIMARY KEY (id);


--
-- Name: webhook_notifications webhook_notifications_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.webhook_notifications
    ADD CONSTRAINT webhook_notifications_reference_key UNIQUE (reference);


--
-- Name: uniq_dispense_once; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX uniq_dispense_once ON public.atm_authorizations USING btree (dispense_trace);


--
-- Name: transactions no_delete_transactions; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER no_delete_transactions BEFORE DELETE ON public.transactions FOR EACH ROW EXECUTE FUNCTION public.prevent_hard_delete();


--
-- Name: atm_transactions atm_transactions_atm_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_transactions
    ADD CONSTRAINT atm_transactions_atm_id_fkey FOREIGN KEY (atm_id) REFERENCES public.atms(id);


--
-- Name: cash_instruments cash_instruments_pin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cash_instruments
    ADD CONSTRAINT cash_instruments_pin_id_fkey FOREIGN KEY (pin_id) REFERENCES public.ewallet_pins(id);


--
-- Name: cash_instruments cash_instruments_wallet_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cash_instruments
    ADD CONSTRAINT cash_instruments_wallet_id_fkey FOREIGN KEY (wallet_id) REFERENCES public.wallets(wallet_id);


--
-- Name: ewallet_pins ewallet_pins_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_pins
    ADD CONSTRAINT ewallet_pins_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transactions_id) ON DELETE CASCADE;


--
-- Name: ewallet_verification_log ewallet_verification_log_wallet_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ewallet_verification_log
    ADD CONSTRAINT ewallet_verification_log_wallet_id_fkey FOREIGN KEY (wallet_id) REFERENCES public.wallets(wallet_id);


--
-- Name: financial_holds financial_holds_wallet_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.financial_holds
    ADD CONSTRAINT financial_holds_wallet_id_fkey FOREIGN KEY (wallet_id) REFERENCES public.wallets(wallet_id);


--
-- Name: account_freezes fk_account_freezes_account; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_freezes
    ADD CONSTRAINT fk_account_freezes_account FOREIGN KEY (account_id) REFERENCES public.accounts(account_id) ON DELETE CASCADE;


--
-- Name: accounts fk_accounts_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: kyc_documents fk_kyc_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: loans fk_loans_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.loans
    ADD CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: notifications fk_notifications_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: loan_repayments fk_repayments_loan; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.loan_repayments
    ADD CONSTRAINT fk_repayments_loan FOREIGN KEY (loan_id) REFERENCES public.loans(loan_id) ON DELETE CASCADE;


--
-- Name: sessions fk_sessions_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE SET NULL;


--
-- Name: swap_transactions fk_swap_tx_ledger; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT fk_swap_tx_ledger FOREIGN KEY (ledger_id) REFERENCES public.swap_ledgers(ledger_id) ON DELETE CASCADE;


--
-- Name: wallets fk_wallets_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallets
    ADD CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: wallet_transactions fk_wtx_user; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_wtx_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: wallet_transactions fk_wtx_wallet; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_transactions
    ADD CONSTRAINT fk_wtx_wallet FOREIGN KEY (wallet_id) REFERENCES public.wallets(wallet_id) ON DELETE CASCADE;


--
-- Name: sat_tokens sat_tokens_instrument_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sat_tokens
    ADD CONSTRAINT sat_tokens_instrument_id_fkey FOREIGN KEY (instrument_id) REFERENCES public.cash_instruments(instrument_id);


--
-- Name: settlements settlements_wallet_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlements
    ADD CONSTRAINT settlements_wallet_id_fkey FOREIGN KEY (wallet_id) REFERENCES public.wallets(wallet_id);


--
-- PostgreSQL database dump complete
--

\unrestrict OuEmUhSQUIVAvcoJ4H8DCGOxwyEZF11UHQTP6yOMPcRIquML91awjoSwxRY47cb

