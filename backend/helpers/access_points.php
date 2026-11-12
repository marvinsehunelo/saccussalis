<?php
// backend/helpers/access_points.php
//
// The public list of this bank's access points (ATMs and branches) behind
// backend/api/v1/access_points.php. VouchMorph fetches that list on a schedule for its access
// point locator, so a person who has been sent money can find where to collect it. Nothing in
// it is customer data.
//
// ATMs come from the bank's own `atms` table, the one backend/atm/ATMService.php dispenses
// from. An ATM is listed while status = 'ACTIVE', the same test ATMService makes before it
// lets a terminal dispense. Its cash balance is read only to answer "is there cash?"; the
// amount itself is never put in the response.
//
// The bank had nowhere to record a public name, a town or opening hours for an ATM and had no
// table of branches, so ap_ensure_schema() adds three plain columns to `atms` and creates
// `branches` on first use; the same SQL is in database/migrations/2026_11_12_access_points.sql.
// It inserts nothing. What the bank has not recorded comes out as an empty string or null
// (coordinates are not recorded at all, so latitude and longitude are always null).

const AP_INSTITUTION = 'SACCUSSALIS';

/** A text column as the response wants it: trimmed, and '' when the bank recorded nothing. */
function ap_text($value): string
{
    return $value === null ? '' : trim((string)$value);
}

/**
 * Whether an ATM has cash, from its recorded cash balance: true above zero, false at zero (or
 * below), null when no balance is recorded. Only this yes / no / unknown leaves the bank.
 */
function ap_cash_available($cashBalance): ?bool
{
    if ($cashBalance === null || !is_numeric($cashBalance)) {
        return null;
    }
    return (float)$cashBalance > 0;
}

/**
 * One in-service row of `atms` as an access point. ATMs here pay out VouchMorph cash-out
 * vouchers (backend/atm); the terminal data has nothing about deposits, so none is claimed.
 */
function ap_atm_to_access_point(array $row): array
{
    $name = ap_text($row['name'] ?? null);

    return [
        'id'             => 'ATM-' . (int)$row['id'],
        'kind'           => 'ATM',
        'name'           => $name !== '' ? $name : ap_text($row['atm_code'] ?? null),
        'town'           => ap_text($row['town'] ?? null),
        'address'        => ap_text($row['location'] ?? null),
        'hours'          => ap_text($row['hours'] ?? null),
        'services'       => ['cash_out', 'atm_voucher'],
        'cash_available' => ap_cash_available($row['cash_balance'] ?? null),
        'latitude'       => null,
        'longitude'      => null,
    ];
}

/**
 * One in-service row of `branches` as an access point. The bank records no cash position and
 * no cash-out service for a branch, so cash_available is null and "cash_out" is not claimed.
 */
function ap_branch_to_access_point(array $row): array
{
    return [
        'id'             => 'BRANCH-' . (int)$row['id'],
        'kind'           => 'BRANCH',
        'name'           => ap_text($row['name'] ?? null),
        'town'           => ap_text($row['town'] ?? null),
        'address'        => ap_text($row['address'] ?? null),
        'hours'          => ap_text($row['hours'] ?? null),
        'services'       => ['deposit', 'account_opening'],
        'cash_available' => null,
        'latitude'       => null,
        'longitude'      => null,
    ];
}

/** The whole response body. $readAt is the Unix time the rows were read. */
function ap_response(array $atmRows, array $branchRows, int $readAt): array
{
    return [
        'institution'   => AP_INSTITUTION,
        'updated_at'    => gmdate('Y-m-d\TH:i:s\Z', $readAt),
        'access_points' => array_merge(
            array_map('ap_atm_to_access_point', array_values($atmRows)),
            array_map('ap_branch_to_access_point', array_values($branchRows))
        ),
    ];
}

/** Creates `branches` and the three extra `atms` columns if they are not there yet. */
function ap_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS branches (
            id         SERIAL PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            town       VARCHAR(100) NOT NULL,
            address    TEXT,
            hours      VARCHAR(100),
            status     VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    // ALTER TABLE takes an exclusive lock on `atms` even when the column is already there, and
    // ATM cash-outs lock rows of that table. So look first and alter only what is missing: in
    // practice once, on the first request after this is deployed.
    $st = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'atms'
    ");
    $st->execute();
    $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

    $add = [
        'name'  => "ALTER TABLE atms ADD COLUMN IF NOT EXISTS name VARCHAR(100)",
        'town'  => "ALTER TABLE atms ADD COLUMN IF NOT EXISTS town VARCHAR(100)",
        'hours' => "ALTER TABLE atms ADD COLUMN IF NOT EXISTS hours VARCHAR(100)",
    ];
    foreach ($add as $column => $sql) {
        if (!isset($have[$column])) {
            $pdo->exec($sql);
        }
    }
}

/**
 * The in-service ATMs and branches, as [atm rows, branch rows], oldest first. "In service"
 * is status = 'ACTIVE' for both.
 */
function ap_read_rows(PDO $pdo): array
{
    $atms = $pdo->prepare("
        SELECT id, atm_code, name, town, location, hours, cash_balance
        FROM atms
        WHERE status = :status
        ORDER BY id
    ");
    $atms->execute([':status' => 'ACTIVE']);

    $branches = $pdo->prepare("
        SELECT id, name, town, address, hours
        FROM branches
        WHERE status = :status
        ORDER BY id
    ");
    $branches->execute([':status' => 'ACTIVE']);

    return [$atms->fetchAll(PDO::FETCH_ASSOC), $branches->fetchAll(PDO::FETCH_ASSOC)];
}
