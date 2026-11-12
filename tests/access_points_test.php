<?php
// tests/access_points_test.php
//
// Checks how rows of atms / branches become the public access point list served by
// backend/api/v1/access_points.php: the cash_available rule, the row mapping and the response
// body. No database. Command line only:
//     php tests/access_points_test.php
// Every row below is made up for the test.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/helpers/access_points.php';

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    $failed += $ok ? 0 : 1;
    printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
}
function show($value): string
{
    return $value === null ? 'null' : var_export($value, true);
}

// --- cash_available: true above zero, false at zero, null when not recorded ---------------
$cashCases = [
    'balance "1500.00" (as the database returns it)' => ['1500.00', true],
    'balance "0.01"'                                 => ['0.01', true],
    'balance 250.5 (number)'                         => [250.5, true],
    'balance "0.00"'                                 => ['0.00', false],
    'balance "0"'                                    => ['0', false],
    'balance 0 (number)'                             => [0, false],
    'balance "-20.00" (should never happen)'         => ['-20.00', false],
    'balance not recorded (null)'                    => [null, null],
    'balance empty string'                           => ['', null],
    'balance not a number'                           => ['n/a', null],
];
foreach ($cashCases as $name => [$balance, $expect]) {
    $got = ap_cash_available($balance);
    check(sprintf('cash_available: %-47s expected %s, got %s', $name, show($expect), show($got)), $got === $expect);
}

// --- an ATM row --------------------------------------------------------------------------
$atmRow = [
    'id'           => '12',
    'atm_code'     => 'TEST-ATM-12',
    'name'         => '  Saccussalis Test ATM - Station ',
    'town'         => 'Testville',
    'location'     => 'Test address - inside the station hall',
    'hours'        => '24 hours',
    'cash_balance' => '98765.43',
];
$atm = ap_atm_to_access_point($atmRow);
check('ATM: mapped exactly as the contract lays it out', $atm === [
    'id'             => 'ATM-12',
    'kind'           => 'ATM',
    'name'           => 'Saccussalis Test ATM - Station',
    'town'           => 'Testville',
    'address'        => 'Test address - inside the station hall',
    'hours'          => '24 hours',
    'services'       => ['cash_out', 'atm_voucher'],
    'cash_available' => true,
    'latitude'       => null,
    'longitude'      => null,
]);
check('ATM: the cash amount is nowhere in the output', strpos(json_encode($atm), '98765') === false);
check('ATM: no deposit service is claimed', !in_array('deposit', $atm['services'], true));

$bareAtm = ap_atm_to_access_point([
    'id' => 3, 'atm_code' => 'TEST-ATM-03', 'name' => null, 'town' => null,
    'location' => null, 'hours' => null, 'cash_balance' => null,
]);
check('ATM with nothing recorded: name falls back to the ATM code', $bareAtm['name'] === 'TEST-ATM-03');
check('ATM with nothing recorded: town, address and hours are empty strings',
    $bareAtm['town'] === '' && $bareAtm['address'] === '' && $bareAtm['hours'] === '');
check('ATM with nothing recorded: cash_available is null', $bareAtm['cash_available'] === null);
check('ATM with nothing recorded: id is still ATM-3', $bareAtm['id'] === 'ATM-3');

$emptyAtm = ap_atm_to_access_point(['id' => 4, 'atm_code' => 'TEST-ATM-04', 'name' => '   ', 'cash_balance' => '0.00']);
check('ATM with a blank name: name falls back to the ATM code', $emptyAtm['name'] === 'TEST-ATM-04');
check('ATM with a zero balance: cash_available is false', $emptyAtm['cash_available'] === false);

// --- a branch row ------------------------------------------------------------------------
$branch = ap_branch_to_access_point([
    'id'      => '3',
    'name'    => 'Saccussalis Test Branch - Central',
    'town'    => 'Testville',
    'address' => null,
    'hours'   => 'Mon-Fri 08:30-16:30',
]);
check('branch: mapped exactly as the contract lays it out', $branch === [
    'id'             => 'BRANCH-3',
    'kind'           => 'BRANCH',
    'name'           => 'Saccussalis Test Branch - Central',
    'town'           => 'Testville',
    'address'        => '',
    'hours'          => 'Mon-Fri 08:30-16:30',
    'services'       => ['deposit', 'account_opening'],
    'cash_available' => null,
    'latitude'       => null,
    'longitude'      => null,
]);
check('branch: no cash_out service is claimed', !in_array('cash_out', $branch['services'], true));

// --- the response body -------------------------------------------------------------------
$contractKeys = ['id', 'kind', 'name', 'town', 'address', 'hours', 'services', 'cash_available', 'latitude', 'longitude'];
$allowedServices = ['cash_out', 'atm_voucher', 'deposit', 'account_opening'];

$response = ap_response([$atmRow], [['id' => 3, 'name' => 'Saccussalis Test Branch - Central', 'town' => 'Testville']], 1_800_000_000);
check('response: institution is SACCUSSALIS', $response['institution'] === 'SACCUSSALIS');
check('response: updated_at is the read time in ISO 8601 UTC', $response['updated_at'] === '2027-01-15T08:00:00Z');
check('response: top-level keys are institution, updated_at, access_points',
    array_keys($response) === ['institution', 'updated_at', 'access_points']);
check('response: ATMs first, then branches',
    array_column($response['access_points'], 'id') === ['ATM-12', 'BRANCH-3']);

$shapeOk = true;
foreach ($response['access_points'] as $point) {
    $shapeOk = $shapeOk
        && array_keys($point) === $contractKeys
        && in_array($point['kind'], ['ATM', 'BRANCH'], true)
        && array_is_list($point['services'])
        && array_diff($point['services'], $allowedServices) === []
        && in_array($point['cash_available'], [true, false, null], true);
}
check('response: every access point has exactly the contract fields and allowed values', $shapeOk);

$json = json_encode($response);
check('response: access_points and services are JSON arrays',
    strpos($json, '"access_points":[{') !== false && strpos($json, '"services":["cash_out","atm_voucher"]') !== false);
check('response: cash_available is a JSON boolean or null, never a number',
    strpos($json, '"cash_available":true') !== false && strpos($json, '"cash_available":null') !== false);
check('response: the cash amount is nowhere in the JSON', strpos($json, '98765') === false && strpos($json, 'cash_balance') === false);

$empty = ap_response([], [], 0);
check('response: no rows gives an empty list', $empty['access_points'] === []);
check('response: an empty list is a JSON array, not an object', strpos(json_encode($empty), '"access_points":[]') !== false);
check('response: updated_at of time 0 is 1970-01-01T00:00:00Z', $empty['updated_at'] === '1970-01-01T00:00:00Z');

echo $failed === 0 ? "\nAll checks passed.\n" : "\n{$failed} check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
