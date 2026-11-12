<?php
// tests/hold_signature_test.php
//
// Checks that backend/api/v1/hold.php only acts on requests signed by a member of the
// VouchMorph scheme. Command line only:
//
//     php tests/hold_signature_test.php
//
// Part 1 (always runs) needs no database: it makes a throwaway certificate authority, signs
// requests the way VouchMorph's CertificateManager::createSignedRequest() does, and checks
// verify_signed_caller() accepts those and refuses everything else.
//
// Part 2 runs only with HOLD_E2E=1 and a LOCAL throwaway PostgreSQL database in the PG*
// variables (it refuses any host other than localhost, and DROPS AND RECREATES the tables it
// uses). It starts hold.php on a local port and checks, for place, release and debit, that an
// unsigned or wrongly signed request changes nothing and a signed one works:
//
//     HOLD_E2E=1 PGHOST=127.0.0.1 PGPORT=5432 PGDATABASE=hold_test PGUSER=postgres \
//         PGPASSWORD=made-up php tests/hold_signature_test.php
//
// Every key, certificate, account and amount here is made up for the test.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/helpers/signed_request.php';

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    $failed += $ok ? 0 : 1;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, ($ok || $detail === '') ? '' : "  [$detail]");
}

// ---------------------------------------------------------------------------------------------
// Throwaway PKI
// ---------------------------------------------------------------------------------------------
$tmpConf = tempnam(sys_get_temp_dir(), 'holdtest_cnf_');
file_put_contents($tmpConf, implode("\n", [
    '[req]', 'distinguished_name = dn', 'prompt = no', '[dn]', 'CN = placeholder',
    '[v3_ca]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign',
    '[v3_leaf]', 'basicConstraints = CA:FALSE', 'keyUsage = critical,digitalSignature', '',
]));
register_shutdown_function(static function () use ($tmpConf) { @unlink($tmpConf); });

function new_key()
{
    return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

/** @return array{0: string, 1: OpenSSLAsymmetricKey} certificate PEM and its private key */
function make_cert(string $cn, ?string $caCertPem, $caKey, bool $isCa): array
{
    global $tmpConf;
    $key  = new_key();
    $opts = ['config' => $tmpConf, 'digest_alg' => 'sha256', 'x509_extensions' => $isCa ? 'v3_ca' : 'v3_leaf'];
    $csr  = openssl_csr_new(['commonName' => $cn], $key, $opts);
    $cert = openssl_csr_sign($csr, $caCertPem, $caKey ?? $key, 30, $opts, random_int(1000, PHP_INT_MAX));
    openssl_x509_export($cert, $pem);
    return [$pem, $key];
}

/** Signs a payload exactly as VouchMorph does (timestamp added, requester left out, keys sorted). */
function vm_sign(array $payload, $privateKey, string $certPem, string $requester = 'VOUCHMORPH'): array
{
    $toSign = array_merge($payload, ['timestamp' => time()]);
    unset($toSign['requester']);
    ksort($toSign);
    openssl_sign(json_encode($toSign, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $sig, $privateKey, OPENSSL_ALGO_SHA256);
    return array_merge($toSign, ['signature' => base64_encode($sig), 'requester' => $requester, 'certificate' => $certPem]);
}

/** What the bank sees: the signed request after a trip through JSON, as it arrives over HTTP. */
function over_the_wire(array $request): array
{
    return json_decode(json_encode($request, JSON_UNESCAPED_SLASHES), true);
}

[$caPem, $caKey]         = make_cert('Test Scheme CA (made up)', null, null, true);
[$vmPem, $vmKey]         = make_cert('VOUCHMORPH-TEST', $caPem, $caKey, false);
[$otherCaPem, $otherCaKey] = make_cert('Some Other CA (made up)', null, null, true);
[$strangerPem, $strangerKey] = make_cert('STRANGER', $otherCaPem, $otherCaKey, false);
[$selfPem, $selfKey]     = make_cert('SELF-SIGNED', null, null, false);

putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $caPem);

$place = [
    'action' => 'PLACE_HOLD', 'reference' => 'HOLD_TEST_0001', 'asset_type' => 'ACCOUNT', 'amount' => 150.0,
    'currency' => 'BWP', 'source_identifier' => '9000000001', 'account_number' => '9000000001',
    'hold_reason' => 'PENDING_SWAP', 'expiry' => '2030-01-01 00:00:00', 'source_institution' => 'SACCUSSALIS',
    'nested' => ['note' => 'café / übung', 'list' => [1, 2.5, 'x']],
];
$release = ['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_TEST_0001', 'reference' => 'HOLD_TEST_0001'];
$debit   = ['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_TEST_0001', 'amount' => 150.0];

echo "== Part 1: verify_signed_caller ==\n";

$accept = [
    'signed PLACE_HOLD'   => vm_sign($place, $vmKey, $vmPem),
    'signed RELEASE_HOLD' => vm_sign($release, $vmKey, $vmPem),
    'signed DEBIT_FUNDS'  => vm_sign($debit, $vmKey, $vmPem),
];
foreach ($accept as $name => $req) {
    $r = verify_signed_caller(over_the_wire($req));
    check("accepted: $name", $r['verified'] === true && $r['requester'] === 'VOUCHMORPH', $r['message']);
}

$signedPlace = vm_sign($place, $vmKey, $vmPem);
$wrongKeySig = vm_sign($place, $strangerKey, $vmPem);           // VouchMorph's certificate, someone else's key
$refuse = [
    'no certificate, no signature'      => $place,
    'signature but no certificate'      => array_diff_key($signedPlace, ['certificate' => 1]),
    'certificate but no signature'      => array_diff_key($signedPlace, ['signature' => 1]),
    'empty certificate'                 => array_merge($signedPlace, ['certificate' => '']),
    'empty signature'                   => array_merge($signedPlace, ['signature' => '']),
    'certificate is not a string'       => array_merge($signedPlace, ['certificate' => ['x']]),
    'signature is not a string'         => array_merge($signedPlace, ['signature' => ['x']]),
    'amount changed after signing'      => array_merge($signedPlace, ['amount' => 9999.0]),
    'account changed after signing'     => array_merge($signedPlace, ['source_identifier' => '9000000002']),
    'field added after signing'         => array_merge($signedPlace, ['extra' => 'x']),
    'release: reference changed'        => array_merge(vm_sign($release, $vmKey, $vmPem), ['hold_reference' => 'HOLD_TEST_0002']),
    'action changed after signing'      => array_merge(vm_sign($release, $vmKey, $vmPem), ['action' => 'DEBIT']),
    'signed with a different key'       => $wrongKeySig,
    'certificate from another CA'       => vm_sign($place, $strangerKey, $strangerPem),
    'self-signed certificate'           => vm_sign($place, $selfKey, $selfPem),
    'CA certificate used as the signer' => vm_sign($place, $otherCaKey, $otherCaPem),
    'garbage certificate'               => array_merge($signedPlace, ['certificate' => 'not a certificate']),
    'garbage signature'                 => array_merge($signedPlace, ['signature' => 'AAAA']),
    'only the certificate, copied'      => array_merge($place, ['certificate' => $vmPem, 'signature' => base64_encode('copied')]),
];
foreach ($refuse as $name => $req) {
    $r = verify_signed_caller(over_the_wire($req));
    check("refused: $name", $r['verified'] === false && $r['message'] !== '', 'was accepted');
}
foreach (['null body' => null, 'string body' => 'PLACE_HOLD', 'empty array' => []] as $name => $body) {
    $r = verify_signed_caller($body);
    check("refused: $name", $r['verified'] === false);
}

// A check that breaks or answers in an unexpected shape must refuse, not pass.
$throws = new class('SACCUSSALIS') extends CertificateManager {
    public function verifySignedRequest(array $request): array { throw new RuntimeException('boom'); }
};
$oddShape = new class('SACCUSSALIS') extends CertificateManager {
    public function verifySignedRequest(array $request): array { return ['valid' => true, 'requester' => 'X']; }
};
$truthy = new class('SACCUSSALIS') extends CertificateManager {
    public function verifySignedRequest(array $request): array { return ['verified' => 1, 'requester' => 'X']; }
};
check('refused: verification throws', verify_signed_caller(over_the_wire($signedPlace), $throws)['verified'] === false);
check('refused: result without "verified"', verify_signed_caller(over_the_wire($signedPlace), $oddShape)['verified'] === false);
check('refused: "verified" is not exactly true', verify_signed_caller(over_the_wire($signedPlace), $truthy)['verified'] === false);

// With no CA configured on the host nothing can be trusted.
putenv('VOUCHMORPH_CA_CERT_CONTENT');
check('refused: valid request but no CA configured', verify_signed_caller(over_the_wire($signedPlace))['verified'] === false);
putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $otherCaPem);
check('refused: valid request but a different CA configured', verify_signed_caller(over_the_wire($signedPlace))['verified'] === false);
putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $caPem);

// hold.php itself: the check comes before the database is used and cannot be skipped.
$src = file_get_contents(__DIR__ . '/../backend/api/v1/hold.php');
$gate = strpos($src, 'verify_signed_caller($input)');
$firstDb = strpos($src, '$pdo->');
check('hold.php calls verify_signed_caller', $gate !== false);
check('hold.php verifies before first database use', $gate !== false && $firstDb !== false && $gate < $firstDb);
check('hold.php has no class_exists() guard around the check', strpos($src, 'class_exists') === false);
check('CertificateManager class is found', class_exists('CertificateManager'));

// ---------------------------------------------------------------------------------------------
// Part 2: the real endpoint against a local throwaway database
// ---------------------------------------------------------------------------------------------
if (getenv('HOLD_E2E') === '1') {
    echo "== Part 2: hold.php end to end ==\n";
    $host = (string)getenv('PGHOST');
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && ($host === '' || $host[0] !== '/')) {
        fwrite(STDERR, "HOLD_E2E refuses PGHOST '$host': local throwaway databases only.\n");
        exit(2);
    }
    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . (getenv('PGPORT') ?: '5432') . ';dbname=' . getenv('PGDATABASE'),
        (string)getenv('PGUSER'), (string)getenv('PGPASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Only the columns hold.php uses.
    $pdo->exec("
        DROP TABLE IF EXISTS financial_holds, cards, wallets, accounts, users CASCADE;
        CREATE TABLE users (user_id BIGSERIAL PRIMARY KEY, full_name TEXT, email TEXT, kyc_status TEXT);
        CREATE TABLE accounts (account_id BIGSERIAL PRIMARY KEY, user_id BIGINT, account_number TEXT UNIQUE,
            account_type TEXT, currency TEXT, balance NUMERIC(20,4) DEFAULT 0, held_balance NUMERIC(20,4) DEFAULT 0,
            is_frozen BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT NOW());
        CREATE TABLE wallets (wallet_id BIGSERIAL PRIMARY KEY, user_id BIGINT, phone TEXT, wallet_type TEXT, currency TEXT,
            balance NUMERIC(20,4) DEFAULT 0, held_balance NUMERIC(20,4) DEFAULT 0, is_frozen BOOLEAN DEFAULT FALSE,
            status TEXT DEFAULT 'active');
        CREATE TABLE cards (card_id BIGSERIAL PRIMARY KEY, account_id BIGINT, card_number TEXT, status TEXT, is_frozen BOOLEAN);
        CREATE TABLE financial_holds (id BIGSERIAL PRIMARY KEY, account_id BIGINT, wallet_id BIGINT, amount NUMERIC(20,4),
            hold_reference TEXT UNIQUE NOT NULL, session_id TEXT, status TEXT, expires_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT NOW(), asset_type TEXT, requester TEXT, foreign_bank TEXT);
        INSERT INTO users (full_name, email, kyc_status) VALUES ('Test Person (made up)', 'person@example.test', 'approved');
        INSERT INTO accounts (user_id, account_number, account_type, currency, balance) VALUES
            (1, '9000000001', 'savings', 'BWP', 1000), (1, '9000000002', 'savings', 'BWP', 100);
    ");

    [$bankPem, $bankKey] = make_cert('SACCUSSALIS-TEST', $caPem, $caKey, false);
    openssl_pkey_export($bankKey, $bankKeyPem);

    $port = 18000 + random_int(0, 999);
    $env = array_merge(getenv(), [
        'VOUCHMORPH_CA_CERT_CONTENT' => $caPem, 'SACCUSSALIS_PRIVATE_KEY_CONTENT' => $bankKeyPem,
        'SACCUSSALIS_CERT_CONTENT' => $bankPem, 'PHP_CLI_SERVER_WORKERS' => '6',
    ]);
    $log = tempnam(sys_get_temp_dir(), 'holdtest_log_');
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__), dirname(__DIR__) . '/router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes, dirname(__DIR__), $env
    );
    register_shutdown_function(static function () use ($server, $log) { proc_terminate($server); @unlink($log); });
    $url = "http://127.0.0.1:$port/backend/api/v1/hold.php";
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

    $post = static function (array $body) use ($url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES)]);
        $raw = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$raw, true) ?? ['_raw' => $raw]];
    };
    $state = static function () use ($pdo): string {
        return json_encode([
            $pdo->query("SELECT account_number, balance, held_balance FROM accounts ORDER BY account_id")->fetchAll(),
            $pdo->query("SELECT hold_reference, status, amount FROM financial_holds ORDER BY id")->fetchAll(),
        ]);
    };
    $acct = static function (string $n) use ($pdo): array {
        $s = $pdo->prepare("SELECT balance::float AS balance, held_balance::float AS held FROM accounts WHERE account_number = ?");
        $s->execute([$n]);
        return $s->fetch();
    };
    $holdStatus = static function (string $ref) use ($pdo) {
        $s = $pdo->prepare("SELECT status FROM financial_holds WHERE hold_reference = ?");
        $s->execute([$ref]);
        return $s->fetchColumn();
    };
    $placeFor = static fn(string $ref, float $amt, string $acc = '9000000001') => [
        'action' => 'PLACE_HOLD', 'reference' => $ref, 'asset_type' => 'ACCOUNT', 'amount' => $amt, 'currency' => 'BWP',
        'source_identifier' => $acc, 'account_number' => $acc, 'expiry' => '2030-01-01 00:00:00',
        'source_institution' => 'SACCUSSALIS',
    ];
    // Every way of asking without a valid signature must be refused and leave the data as it was.
    $refusedUnchanged = static function (string $label, array $payload) use ($post, $state, $vmKey, $vmPem, $strangerKey, $strangerPem): void {
        $signed = vm_sign($payload, $vmKey, $vmPem);
        $attempts = [
            'unsigned'                 => $payload,
            'signed by a stranger'     => vm_sign($payload, $strangerKey, $strangerPem),
            'right cert, wrong key'    => vm_sign($payload, $strangerKey, $vmPem),
            'signature for other body' => array_merge($signed, ['tampered' => 'x']),
            'certificate only'         => array_merge($payload, ['certificate' => $vmPem]),
        ];
        foreach ($attempts as $how => $body) {
            $before = $state();
            [$code, $res] = $post($body);
            check("$label, $how: refused with 401 and JSON error",
                $code === 401 && ($res['status'] ?? '') === 'ERROR' && ($res['error'] ?? '') === 'UNAUTHORIZED' && !empty($res['message']),
                "HTTP $code " . json_encode($res));
            check("$label, $how: nothing changed", $state() === $before);
        }
    };

    // PLACE
    $refusedUnchanged('place', $placeFor('HOLD_E2E_A', 150.0));
    [$code, $res] = $post(vm_sign($placeFor('HOLD_E2E_A', 150.0), $vmKey, $vmPem));
    check('place, signed: hold placed', $code === 200 && ($res['hold_placed'] ?? false) === true && ($res['hold_reference'] ?? '') === 'HOLD_E2E_A', json_encode($res));
    check('place, signed: response is signed by the bank', !empty($res['signature']) && !empty($res['certificate']));
    check('place, signed: 150 reserved', $acct('9000000001') == ['balance' => 1000.0, 'held' => 150.0] && $holdStatus('HOLD_E2E_A') === 'ACTIVE');
    [$code, $res] = $post(vm_sign($placeFor('HOLD_E2E_A', 150.0), $vmKey, $vmPem));
    check('place, signed duplicate reference: refused, no second reservation', ($res['hold_placed'] ?? null) === false && $acct('9000000001')['held'] == 150.0);
    [$code, $res] = $post(vm_sign($placeFor('HOLD_E2E_TOO_MUCH', 900.0), $vmKey, $vmPem));
    check('place, signed but over available balance: refused', ($res['hold_placed'] ?? null) === false && $acct('9000000001')['held'] == 150.0, json_encode($res));

    // RELEASE
    $rel = ['action' => 'RELEASE_HOLD', 'hold_reference' => 'HOLD_E2E_A', 'reference' => 'HOLD_E2E_A', 'source_identifier' => '9000000001'];
    $refusedUnchanged('release', $rel);
    [$code, $res] = $post(vm_sign($rel, $vmKey, $vmPem));
    check('release, signed: released', $code === 200 && ($res['released'] ?? false) === true, json_encode($res));
    check('release, signed: reservation returned', $acct('9000000001') == ['balance' => 1000.0, 'held' => 0.0] && $holdStatus('HOLD_E2E_A') === 'RELEASED');

    // DEBIT
    $post(vm_sign($placeFor('HOLD_E2E_B', 200.0), $vmKey, $vmPem));
    $deb = ['action' => 'DEBIT_FUNDS', 'hold_reference' => 'HOLD_E2E_B', 'amount' => 200.0];
    $refusedUnchanged('debit', $deb);
    [$code, $res] = $post(vm_sign($deb, $vmKey, $vmPem));
    check('debit, signed: debited', $code === 200 && ($res['debited'] ?? false) === true, json_encode($res));
    check('debit, signed: balance reduced, reservation cleared', $acct('9000000001') == ['balance' => 800.0, 'held' => 0.0] && $holdStatus('HOLD_E2E_B') === 'DEBITED');

    // Ten holds of 30 at the same moment on an account holding 100: three fit, never more.
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 10; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(vm_sign($placeFor("HOLD_E2E_RACE_$i", 30.0, '9000000002'), $vmKey, $vmPem), JSON_UNESCAPED_SLASHES)]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);
    $placed = 0;
    foreach ($handles as $ch) {
        $placed += (json_decode((string)curl_multi_getcontent($ch), true)['hold_placed'] ?? false) === true ? 1 : 0;
    }
    $race = $acct('9000000002');
    check("simultaneous holds: 3 of 10 placed (got $placed)", $placed === 3);
    check("simultaneous holds: reserved 90 of 100 (got {$race['held']})", $race['held'] == 90.0);
} else {
    echo "(Part 2 skipped: set HOLD_E2E=1 with a local throwaway database to run it)\n";
}

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
